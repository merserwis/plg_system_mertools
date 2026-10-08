<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The cart clean-up panel in the plugin settings: how many carts and cart products there are, since
 * when MerTools has been watching the carts, the last clean-up, and the buttons "Check" (what would
 * be removed now) and "Clean now". The work is done by media/js/mertools-carts.js through the
 * mertools_action requests of the plugin (MerTools::handleAdministratorAction).
 */

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

final class MtcartsField extends FormField
{
    protected $type = 'Mtcarts';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.carts', 'plg_system_mertools/mertools-carts.js', [], ['defer' => true]);

        $keys = ['CARTS', 'WITH_PRODUCTS', 'EMPTY', 'PRODUCTS', 'SIZE', 'OPTIMIZED', 'WATCH_SINCE', 'WATCH_NONE', 'ABANDONED_FROM', 'ABANDONED_NOW',
            'LAST', 'LAST_NONE', 'BY_AUTO', 'BY_MANUAL', 'NOT_FINISHED', 'CHECK', 'CLEAN', 'WORKING', 'CONFIRM',
            'WOULD', 'DID', 'NOTHING', 'ATTACHED', 'BUSY', 'ERROR', 'LOADING', 'LATER'];
        $texts = [];
        foreach ($keys as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_CART_UI_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts]), ENT_QUOTES, 'UTF-8');

        return '<div class="mt-carts" data-mt-carts="' . $data . '">'
            . '<dl class="mt-carts-figures row mb-2"></dl>'
            . '<div class="mt-carts-buttons d-flex flex-wrap gap-2">'
            . '<button type="button" class="btn btn-secondary" data-mt-cart="cart_check"><span class="icon-search" aria-hidden="true"></span> '
            . htmlspecialchars($texts['CHECK'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '<button type="button" class="btn btn-danger" data-mt-cart="cart_clean"><span class="icon-trash" aria-hidden="true"></span> '
            . htmlspecialchars($texts['CLEAN'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '</div>'
            . '<div class="mt-carts-result mt-2" role="status" aria-live="polite"></div>'
            . '</div>';
    }
}
