<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * The settings of MerTools are, as they always were, the parameters of its system plugin
 * (#__extensions): the plugin reads them and nothing about that changes. This class reads them, builds
 * the form of a page from the fieldsets of the plugin's manifest, and saves through Joomla's own plugin
 * model, so everything that follows a save of the plugin in Joomla follows here too (the plugin's
 * onExtensionAfterSave empties the kept pages, Joomla cleans its cache of the plugins, the action log
 * notes who saved).
 */

namespace Merserwis\Component\MerTools\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Settings
{
    /** the row of the plugin (false: not read yet) */
    private static object|false|null $plugin = false;

    private static ?\SimpleXMLElement $manifest = null;

    private static ?array $defaults = null;

    /** the plugin's row: extension_id, enabled, params; null when it is not installed */
    public static function plugin(): ?object
    {
        if (self::$plugin === false) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName(['extension_id', 'enabled', 'params']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('mertools'));
            self::$plugin = $db->setQuery($query)->loadObject() ?: null;
        }

        return self::$plugin;
    }

    public static function params(): Registry
    {
        return new Registry((string) (self::plugin()->params ?? ''));
    }

    /** the texts of the settings are the plugin's: loaded where a page of MerTools shows them */
    public static function loadLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_system_mertools', JPATH_ADMINISTRATOR)
            || $lang->load('plg_system_mertools', JPATH_PLUGINS . '/system/mertools');
    }

    /** the plugin's manifest, whose <config> holds every setting */
    public static function manifest(): ?\SimpleXMLElement
    {
        if (self::$manifest === null) {
            $file = JPATH_PLUGINS . '/system/mertools/mertools.xml';
            $xml  = is_file($file) ? simplexml_load_file($file) : false;
            if ($xml === false || !isset($xml->config->fields)) {
                return null;
            }
            self::$manifest = $xml;
        }

        return self::$manifest;
    }

    /**
     * The tabs of a page: fieldset name => its label (a language key), in the order of the manifest.
     *
     * @return array<string, string>
     */
    public static function fieldsets(string $page): array
    {
        $out = [];
        foreach (self::manifest()?->config->fields->fieldset ?? [] as $fieldset) {
            if ((string) $fieldset['page'] === $page) {
                $out[(string) $fieldset['name']] = (string) $fieldset['label'];
            }
        }

        return $out;
    }

    /**
     * The default value of every setting, from the manifest (the plugin uses the same ones when a
     * setting was never saved).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            self::$defaults = [];
            foreach (self::manifest()?->config->fields->fieldset ?? [] as $fieldset) {
                foreach ($fieldset->field as $field) {
                    if (isset($field['default'])) {
                        self::$defaults[(string) $field['name']] = (string) $field['default'];
                    }
                }
            }
        }

        return self::$defaults;
    }

    /** the form of a page (its fieldsets of the manifest under jform[params]), or null for a page without settings */
    public static function form(string $page): ?Form
    {
        $fields = self::manifest()?->config->fields;
        if (!$fields) {
            return null;
        }
        $xml = '';
        foreach ($fields->fieldset as $fieldset) {
            if ((string) $fieldset['page'] === $page) {
                $xml .= $fieldset->asXML();
            }
        }
        if ($xml === '') {
            return null;
        }
        $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm('com_mertools.page.' . $page, ['control' => 'jform']);
        $form->load('<form><fields name="params" addfieldprefix="' . htmlspecialchars((string) $fields['addfieldprefix'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
            . $xml . '</fields></form>');

        return $form;
    }

    /**
     * The names of the settings of a form: the fields of its fieldsets, not those inside a list of rows. Form::getGroup()
     * also returns the fields of a subform: a list of rows with a field named like a setting of another page would make
     * every save of this page set that setting back to its default.
     */
    public static function fieldNames(Form $form): array
    {
        $out = [];
        foreach ($form->getXml()->xpath('//fields[@name="params"]/fieldset/field[@name]') ?: [] as $field) {
            $out[] = (string) $field['name'];
        }

        return array_values(array_unique($out));
    }

    /**
     * Saves the settings of a page: the values sent are filtered and checked by the page's form; a
     * setting of the page that was not sent (a list of checkboxes with none ticked) goes back to its
     * default, as it did when the plugin's own form replaced all its settings; the settings of other
     * pages are kept as they are.
     *
     * @param array<string, mixed> $posted jform[params]
     *
     * @return list<string> the errors (none: saved)
     */
    public static function savePage(string $page, array $posted): array
    {
        $form = self::form($page);
        if (!$form) {
            return [Text::_('COM_MERTOOLS_ERROR_PAGE')];
        }
        $data  = $form->filter(['params' => $posted]);
        $valid = $form->validate($data);
        if ($valid !== true) {
            $errors = [];
            foreach ($form->getErrors() as $error) {
                $errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            }

            return $errors ?: [Text::_('COM_MERTOOLS_ERROR_SAVE')];
        }
        $values = (array) ($data['params'] ?? []);

        return self::store($values, array_diff(self::fieldNames($form), array_keys($values)));
    }

    /**
     * Writes settings into the plugin's parameters through Joomla's plugin model.
     *
     * @param array<string, mixed> $set   setting => value
     * @param list<string>         $unset settings that go back to their default
     *
     * @return list<string> the errors (none: saved)
     */
    public static function store(array $set, array $unset = []): array
    {
        // read again just before writing: another administrator may have saved another page meanwhile
        self::$plugin = false;
        $plugin       = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_MERTOOLS_ERROR_PLUGIN')];
        }
        $params = json_decode((string) $plugin->params, true);
        $params = \is_array($params) ? $params : [];
        foreach ($unset as $key) {
            unset($params[$key]);
        }
        $params = array_replace($params, $set);

        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            if (!$model || !$model->save(['extension_id' => (int) $plugin->extension_id, 'params' => $params])) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_MERTOOLS_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** turns the plugin on (Joomla's plugin model, so the change of state is seen by other extensions) */
    public static function enable(): array
    {
        $plugin = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_MERTOOLS_ERROR_PLUGIN')];
        }
        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            $pks   = [(int) $plugin->extension_id];
            if (!$model || !$model->publish($pks, 1)) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_MERTOOLS_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** the site has a Gridbox store (an app of products) */
    public static function hasStore(): bool
    {
        try {
            // a site without Gridbox has no such table: the query fails and that is "no store"
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select('1')
                ->from($db->quoteName('#__gridbox_app'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('products'));

            return (bool) $db->setQuery($query, 0, 1)->loadResult();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Gridbox is installed and on */
    public static function hasGridbox(): bool
    {
        return \Joomla\CMS\Component\ComponentHelper::isEnabled('com_gridbox');
    }

    /**
     * A fresh installation: the installer's script marks it (setup_pending) and the quick setup has not
     * been saved yet, so the control panel offers the quick setup first. A site updated from an older
     * version has its settings and is not asked (Joomla saves the defaults of a new install, so the
     * number of settings says nothing).
     */
    public static function isNew(Registry $params): bool
    {
        return (int) $params->get('setup_pending', 0) === 1 && !(int) $params->get('setup_done', 0);
    }
}
