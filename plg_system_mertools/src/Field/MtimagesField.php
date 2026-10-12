<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/** The lighter copies of the site's pictures in the plugin settings: figures, make them now, remove them. */
final class MtimagesField extends FormField
{
    protected $type = 'Mtimages';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.images', 'plg_system_mertools/mertools-images.js', [], ['defer' => true]);

        $texts = [];
        foreach (['READY', 'PENDING', 'SKIPPED', 'FAILED', 'FILES', 'RUN', 'RUN_DONE', 'RUN_MORE', 'CLEAR', 'CLEAR_CONFIRM', 'CLEARED', 'NO_GD', 'LOADING', 'ERROR'] as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_IMG_UI_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts]), ENT_QUOTES, 'UTF-8');
        $esc  = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<div class="mt-images" data-mt-images="' . $data . '">'
            . '<dl class="mt-images-figures row mb-2"></dl>'
            . '<button type="button" class="btn btn-secondary me-2" data-mt-images-run><span class="icon-images" aria-hidden="true"></span> ' . $esc($texts['RUN']) . '</button>'
            . '<button type="button" class="btn btn-outline-danger" data-mt-images-clear><span class="icon-trash" aria-hidden="true"></span> ' . $esc($texts['CLEAR']) . '</button>'
            . '<div class="mt-images-result mt-2" role="status" aria-live="polite"></div>'
            . '</div>';
    }
}
