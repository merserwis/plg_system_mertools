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

    /** the texts of the slider bar: config key => language key suffix */
    public const TEXTS = ['prev' => 'TABS_SLIDER_PREV', 'next' => 'TABS_SLIDER_NEXT', 'hint' => 'TABS_SLIDER_HINT'];

    /**
     * @param array<string, string> $texts the translated texts of the slider bar (keys of TEXTS)
     *
     * @return array{width: int, first: bool, sliders: string, texts: array}
     */
    public static function jsConfig(Registry $params, array $texts = []): array
    {
        $sliders = (string) $params->get('tabs_sliders', 'nav');

        return [
            'width'   => max(320, min(1600, (int) $params->get('tabs_accordion_width', 768))),
            'first'   => (bool) (int) $params->get('tabs_accordion_first', 0),
            'sliders' => \in_array($sliders, ['nav', 'stack', 'off'], true) ? $sliders : 'nav',
            'texts'   => array_intersect_key($texts, self::TEXTS),
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
            . 'padding:1.25rem .25rem;border-bottom:1px solid var(--border,rgba(0,0,0,.12));font-size:.75rem;font-weight:700;'
            . 'letter-spacing:.08rem;line-height:1.3;text-transform:uppercase;color:var(--title,#343434);cursor:pointer;scroll-margin-top:90px;'
            . '-webkit-tap-highlight-color:transparent}'
            . '.mt-tabacc .mt-tabacc-head:focus-visible{outline:2px solid var(--primary,#f2a705);outline-offset:2px}'
            . '.mt-tabacc .mt-tabacc-head i{flex:none;width:1.5rem;font-size:1.25rem;text-align:center;color:var(--primary,#f2a705)}'
            . '.mt-tabacc .mt-tabacc-head span{flex:1;min-width:0}'
            . '.mt-tabacc .mt-tabacc-head::after{content:"";flex:none;width:.5rem;height:.5rem;margin:0 .35rem .25rem;'
            . 'border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .2s}'
            . '.mt-tabacc .mt-tabacc-head[aria-expanded="true"]{color:var(--primary,#f2a705);border-bottom-color:var(--primary,#f2a705);margin-bottom:1.5rem}'
            . '.mt-tabacc .mt-tabacc-head.mt-after-open{margin-top:1.5rem}'
            . '.mt-tabacc .mt-tabacc-head[aria-expanded="true"]::after{transform:rotate(-135deg);margin-bottom:-.25rem}'
            . '@media (prefers-reduced-motion:reduce){.mt-tabacc .mt-tabacc-head::after{transition:none}}'
            // sliders in a row: the bar under them (previous, "2 / 12", next, a hint) …
            . '.mt-slnav{display:none}'
            . '.mt-tabacc .mt-slnav:not([hidden]){display:block;margin:1rem 0 .5rem;text-align:center;line-height:1.3}'
            . '.mt-tabacc .mt-slnav-row{display:flex;align-items:center;justify-content:center;gap:1.25rem}'
            . '.mt-tabacc .mt-slnav-btn{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;'
            . 'border:2px solid var(--primary,#f2a705);border-radius:50%;color:var(--primary,#f2a705);cursor:pointer;-webkit-tap-highlight-color:transparent}'
            . '.mt-tabacc .mt-slnav-btn::before{content:"";width:.6rem;height:.6rem;border-right:2px solid currentColor;border-bottom:2px solid currentColor}'
            . '.mt-tabacc .mt-slnav-prev::before{transform:translateX(.12rem) rotate(135deg)}'
            . '.mt-tabacc .mt-slnav-next::before{transform:translateX(-.12rem) rotate(-45deg)}'
            . '.mt-tabacc .mt-slnav-btn:focus-visible{outline:2px solid var(--primary,#f2a705);outline-offset:2px}'
            . '.mt-tabacc .mt-slnav-btn:active{background:var(--primary,#f2a705);color:var(--bg-primary,#fff)}'
            . '.mt-tabacc .mt-slnav-count{min-width:4.5rem;font-size:.9rem;font-weight:700;letter-spacing:.05rem;color:var(--title,#343434)}'
            . '.mt-tabacc .mt-slnav-hint{margin-top:.5rem;font-size:.75rem;color:var(--subtitle,#757575)}'
            // … or their posts one under another
            . '.mt-tabacc ul.mt-slstack .slideshow-content{height:auto!important;display:flex;flex-direction:column;gap:30px;overflow:visible}'
            . '.mt-tabacc ul.mt-slstack .slideshow-content>li{position:relative!important;left:auto!important;top:auto!important;width:100%!important;'
            . 'float:none!important;display:block!important;visibility:visible!important;transform:none!important;transition:none!important;animation:none!important}';
    }
}
