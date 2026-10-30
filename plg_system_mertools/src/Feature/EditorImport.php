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
                'working' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_WORKING'),
                'done'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_DONE'),
                'filled'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_FILLED'),
                'kept'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_KEPT'),
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
