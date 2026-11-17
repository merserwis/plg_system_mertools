<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Saving in the Gridbox editor: a warning before the editor is closed with changes not saved, and a
 * save of its own every few minutes while there are any (media/js/mertools-save.js). Also the editor's
 * Link Picker, which opens with the folders open the last time (media/js/mertools-linktree.js).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;

final class EditorSave extends Feature
{
    /** minutes after the first change not saved, 0 = no save of its own */
    public function autosave(): int
    {
        return min(120, max(0, (int) $this->plugin->params->get('editor_autosave', 0)));
    }

    public function warn(): bool
    {
        return (bool) (int) $this->plugin->params->get('editor_unsaved_warn', 1);
    }

    /** The Gridbox editor of a logged-in user: its script, with the text Gridbox answers a save with. */
    public function saveScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if ((!$this->warn() && !$this->autosave()) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor'
            || $input->getInt('id', 0) <= 0) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $this->plugin->loadLanguage();
        $this->plugin->editorScript('save', [
            'warn'     => $this->warn(),
            'autosave' => $this->autosave() * 60,
            'saved'    => $this->savedTexts(),
            'lang'     => $app->getLanguage()->getTag(),
            'texts'    => ['unsaved' => Text::_('PLG_SYSTEM_MERTOOLS_EDITOR_UNSAVED'), 'autosaved' => Text::_('PLG_SYSTEM_MERTOOLS_EDITOR_AUTOSAVED')],
        ]);
    }

    /**
     * The Link Picker of the Gridbox editor (its tree of apps, categories, pages and menus is made anew
     * at every opening, all closed): opened with the folders the user had open, kept in the browser.
     */
    public function linkTreeScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!(int) $this->plugin->params->get('editor_link_tree', 1) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest || !str_contains((string) $app->getBody(), 'id="link-select-modal"')) {
            return;
        }
        $this->plugin->editorScript('linktree', ['user' => (int) $user->id]);
    }

    /**
     * The settings Gridbox keeps per device marked so in its editor (mertools-devices.js): a mark beside
     * their names, blue with the devices that have a value of their own.
     */
    public function devicesScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!(int) $this->plugin->params->get('editor_devices', 1) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest || !str_contains((string) $app->getBody(), 'responsive-context-menu')) {
            return;
        }
        $this->plugin->loadLanguage();
        $this->plugin->editorScript('devices', ['texts' => ['PER' => Text::_('PLG_SYSTEM_MERTOOLS_EDITOR_DEVICES_PER'), 'OWN' => Text::_('PLG_SYSTEM_MERTOOLS_EDITOR_DEVICES_OWN')]]);
    }

    /**
     * Gridbox's answer to a save that went through: in the language of the editor page, which its save
     * request should have too, and in the site's default language and English in case it has not.
     */
    private function savedTexts(): array
    {
        $current = $this->plugin->app()->getLanguage();
        $tags    = [$current->getTag(), (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB'), 'en-GB'];
        $texts   = [];
        foreach (array_unique($tags) as $tag) {
            try {
                $lang = $tag === $current->getTag() ? $current : Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
                $lang->load('com_gridbox', JPATH_SITE);
                $text = trim($lang->_('GRIDBOX_SAVED'));
            } catch (\Throwable $e) {
                continue;
            }
            if ($text !== '' && $text !== 'GRIDBOX_SAVED') {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }
}
