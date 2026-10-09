<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox tabs on phones: a row of tabs wider than the screen has to be scrolled sideways and
 * nobody sees that there are more tabs. On narrow screens (media/js/mertools-tabs.js) every tab
 * becomes a row with its icon, name and an arrow, one under the other, and opens its content below
 * it; computers keep the tabs. Gridbox's own tab is still selected on opening, so its script does
 * what it does when a tab is shown.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Tabs
{
    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('tabs_accordion', 1);
    }

    /** @return array{width: int, first: bool} */
    public static function jsConfig(Registry $params): array
    {
        return [
            'width' => max(320, min(1600, (int) $params->get('tabs_accordion_width', 768))),
            'first' => (bool) (int) $params->get('tabs_accordion_first', 1),
        ];
    }

    /**
     * The rows (shown only while the tabs are turned into rows: class mt-tabacc on the Gridbox item).
     * Colours from the theme variables, so dark mode and the site's own colours apply.
     */
    public static function css(): string
    {
        return '.mt-tabacc-head{display:none}'
            . '.mt-tabacc .mt-tabacc-head{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:.75rem;width:100%;'
            . 'padding:1rem .25rem;border-bottom:1px solid var(--border,rgba(0,0,0,.12));font-size:.75rem;font-weight:700;'
            . 'letter-spacing:.08rem;line-height:1.3;text-transform:uppercase;color:var(--title,#343434);cursor:pointer;scroll-margin-top:90px;'
            . '-webkit-tap-highlight-color:transparent}'
            . '.mt-tabacc .mt-tabacc-head:focus-visible{outline:2px solid var(--primary,#f2a705);outline-offset:2px}'
            . '.mt-tabacc .mt-tabacc-head i{flex:none;width:1.5rem;font-size:1.25rem;text-align:center;color:var(--primary,#f2a705)}'
            . '.mt-tabacc .mt-tabacc-head span{flex:1;min-width:0}'
            . '.mt-tabacc .mt-tabacc-head::after{content:"";flex:none;width:.5rem;height:.5rem;margin:0 .35rem .25rem;'
            . 'border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .2s}'
            . '.mt-tabacc .mt-tabacc-head[aria-expanded="true"]{color:var(--primary,#f2a705);border-bottom-color:var(--primary,#f2a705)}'
            . '.mt-tabacc .mt-tabacc-head[aria-expanded="true"]::after{transform:rotate(-135deg);margin-bottom:-.25rem}'
            . '@media (prefers-reduced-motion:reduce){.mt-tabacc .mt-tabacc-head::after{transition:none}}';
    }
}
