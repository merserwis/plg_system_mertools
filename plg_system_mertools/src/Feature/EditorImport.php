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

use Joomla\CMS\Language\Text;

final class EditorImport extends Feature
{
    public function enabled(): bool
    {
        return (bool) (int) $this->plugin->params->get('blog_md_import', 1);
    }

    /** the first heading of the first level is the title of the post, not a module of it */
    public function firstTitle(): bool
    {
        return (bool) (int) $this->plugin->params->get('blog_md_title', 1);
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
            'firstTitle' => $this->firstTitle(),
            'texts'      => [
                'button'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_BUTTON'),
                'working' => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_WORKING'),
                'done'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_DONE'),
                'titled'  => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_TITLED'),
                'kept'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_KEPT'),
                'empty'   => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_EMPTY'),
                'large'   => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_LARGE'),
                'fail'    => Text::_('PLG_SYSTEM_MERTOOLS_MDIMPORT_FAIL'),
            ],
        ]);
    }
}
