<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Importing a Markdown document into the Gridbox editor of a blog post: a button next to the
 * "Advanced Editor" switch (media/js/mertools-mdimport.js). The script finds out in the editor whether
 * the page is a post; here it is only put on the editor of a logged-in user.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;

final class EditorImport extends Feature
{
    public function enabled(): bool
    {
        return (bool) (int) $this->plugin->params->get('blog_md_import', 1);
    }

    public function importScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!$this->enabled() || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $this->plugin->loadLanguage();
        $this->plugin->editorScript('mdimport', [
            'placeholders' => $this->placeholders(),
            'texts'        => [
                'button'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_BUTTON'),
                'side'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_SIDE'),
                'working' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_WORKING'),
                'done'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_DONE'),
                'rest'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_REST'),
                'overwrite' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_OVERWRITE'),
                'failed'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_FAILED'),
                'filled'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_FILLED'),
                'kept'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_KEPT'),
                'restoreBar' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_RESTORE_BAR'),
                'restore'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_RESTORE'),
                'restored' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_RESTORED'),
                'changed'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_CHANGED'),
                'close'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_CLOSE'),
                'export'   => Text::_('PLG_SYSTEM_MERTOOLS_MDEXPORT_BUTTON'),
                'exported' => Text::_('PLG_SYSTEM_MERTOOLS_MDEXPORT_DONE'),
                'skipped'  => Text::_('PLG_SYSTEM_MERTOOLS_MDEXPORT_SKIPPED'),
                'names'   => [
                    'title'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_NAME_TITLE'),
                    'alias'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_NAME_ALIAS'),
                    'intro'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_NAME_INTRO'),
                    'keywords' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_NAME_KEYWORDS'),
                ],
                'empty'   => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_EMPTY'),
                'large'   => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_LARGE'),
                'fail'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_FAIL'),
            ],
        ]);
    }

    /**
     * The list of an app's items with Gridbox's CSV import (a store's products): many Markdown documents at once,
     * made into one CSV file of Gridbox's own kind in its dialog (media/js/mertools-mdcsv.js with the parser of
     * mertools-mdimport.js); Gridbox's import creates the products. The categories of the app for the choice of one.
     */
    public function csvScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $id    = $input->getInt('id', 0);
        if (!$this->enabled() || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'apps' || $id <= 0
            || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        $body = (string) $app->getBody();
        $user = $app->getIdentity();
        if (!$user || $user->guest || !str_contains($body, 'data-key="csv-import-options"')) {
            return;
        }
        $this->plugin->loadLanguage();
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $cats  = $db->setQuery($db->getQuery(true)->select($db->quoteName('title'))->from($db->quoteName('#__gridbox_categories'))
            ->where($db->quoteName('app_id') . ' = ' . $id)->order($db->quoteName('title')))->loadColumn();
        $texts = [];
        foreach (['CHOOSE', 'FILES', 'CHOSEN', 'NO_CATEGORY', 'REST_NONE', 'REST_INTO', 'PUBLISHED', 'FILLED', 'NOTHING', 'FAILED', 'LEFT', 'LARGE', 'FAIL'] as $k) {
            $texts[lcfirst(str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $k)))))] = Text::_('PLG_SYSTEM_MERTOOLS_MDCSV_' . $k);
        }
        $cfg  = ['texts' => $texts, 'categories' => array_values(array_unique(array_map('strval', $cats)))];
        $src  = fn (string $name) => '<script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-' . $name . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $tail = '<script type="application/json" id="mertools-mdcsv-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
            . $src('mdimport') . $src('mdcsv');
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /**
     * Gridbox's text of the empty first module of a new post ("Click Here And Start Typing" in English),
     * in the language of the editor, the site's default one and English: the post may have been made in any.
     */
    private function placeholders(): array
    {
        $current = $this->plugin->app()->getLanguage();
        $tags    = [$current->getTag(), (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB'), 'en-GB'];
        $texts   = [];
        foreach (array_unique($tags) as $tag) {
            try {
                $lang = $tag === $current->getTag() ? $current : Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
                $lang->load('com_gridbox', JPATH_SITE);
                $text = trim($lang->_('START_TYPING_HERE'));
            } catch (\Throwable $e) {
                continue;
            }
            if ($text !== '' && $text !== 'START_TYPING_HERE') {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }
}
