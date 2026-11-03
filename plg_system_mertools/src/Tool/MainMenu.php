<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The main menu of Gridbox on screens a little too narrow for it — a laptop at 1366 pixels, or a 1920
 * screen with Windows set to 150 % (the browser then has 1280). The items no longer fit on one line, so
 * the last ones go onto a second line under the first ones. Gridbox puts the drop-down and the mega menu
 * of an item under the whole menu, not under the item, so between the item and its panel there is a strip
 * as high as that second line; the mouse going down to the panel leaves the item there and the panel
 * disappears under the cursor.
 *
 * hold (on by default): while the pointer is in that strip the item keeps the class mt-menu-hold, which
 * shows the panel as hovering the item does; nothing changes where there is no strip.
 * fit (off by default): a menu that wraps gets narrower spaces between its top items, step by step, until
 * it fits on one line (left as it was when even the narrowest step does not fit). The work is done in
 * media/js/mertools-menu.js.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class MainMenu
{
    public static function hold(Registry $params): bool
    {
        return (bool) (int) $params->get('layout_menu_hold', 1);
    }

    public static function fit(Registry $params): bool
    {
        return (bool) (int) $params->get('layout_menu_fit', 0);
    }

    public static function enabled(Registry $params): bool
    {
        return self::hold($params) || self::fit($params);
    }

    /** @return array{hold: int, fit: int} */
    public static function jsConfig(Registry $params): array
    {
        return ['hold' => (int) self::hold($params), 'fit' => (int) self::fit($params)];
    }

    /** the panel of a held item shown as on :hover (the rules of Gridbox's menu for li:hover, with the class) */
    public static function css(): string
    {
        $h = '.ba-item-main-menu li.mt-menu-hold';

        return $h . '>.tabs-content-wrapper,' . $h . '>.nav-child,' . $h . '>.mod-menu__sub{display:block!important}'
            . $h . '>.tabs-content-wrapper .ba-section{display:block;animation-delay:0s;animation-fill-mode:none;box-sizing:border-box}';
    }
}
