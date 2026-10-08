<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/** The page cache panel in the plugin settings: what is kept now and the button that empties it. */
final class MtpagecacheField extends FormField
{
    protected $type = 'Mtpagecache';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.pcache', 'plg_system_mertools/mertools-pcache.js', [], ['defer' => true]);

        $texts = [];
        foreach (['PAGES', 'DATA', 'SIZE', 'OLDEST', 'PURGED', 'NEVER', 'CLEAR', 'CLEARED', 'LOADING', 'ERROR'] as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_PCACHE_UI_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts]), ENT_QUOTES, 'UTF-8');

        return '<div class="mt-pcache" data-mt-pcache="' . $data . '">'
            . '<dl class="mt-pcache-figures row mb-2"></dl>'
            . '<button type="button" class="btn btn-secondary" data-mt-pcache-clear><span class="icon-trash" aria-hidden="true"></span> '
            . htmlspecialchars($texts['CLEAR'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '<div class="mt-pcache-result mt-2" role="status" aria-live="polite"></div>'
            . '</div>';
    }
}
