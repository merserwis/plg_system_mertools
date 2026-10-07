<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Dark mode for a Gridbox site. Gridbox builds the whole look from CSS custom properties set on
 * "html body" (--bg-primary, --bg-secondary, --text, --title, --subtitle, --icon, --border,
 * --hover, --shadow, --primary …) and its generated section styles read those variables. So a
 * clean, elegant dark mode is simply a second set of those variables for a dark palette, applied
 * at a higher specificity (html[data-mertools-theme="dark"] body) — no Gridbox core file is
 * touched and nothing is inverted, so brand colours and images stay intact.
 *
 * The palettes are deliberately soft dark greys/blues, never pure black, so they do not tire the
 * eyes. The brand accent (--primary) is kept from the site by default ("accent as in Gridbox"),
 * or replaced by a colour the administrator chooses.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class DarkMode
{
    public const PALETTES = ['slate', 'charcoal', 'midnight', 'warm', 'dim', 'custom'];

    /**
     * Dark palettes. Each is the dark value of the Gridbox theme variables. "bg_dark" is the
     * darkest shade, used for sections the site already paints dark. None is pure black.
     */
    private const PRESETS = [
        // elegant blue-grey (default)
        'slate' => [
            'bg' => '#1b2230', 'surface' => '#232c3d', 'bg_dark' => '#10151f',
            'title' => '#e8ecf3', 'text' => '#c3cbd9', 'muted' => 'rgba(232,236,243,.45)',
            'border' => '#333e52', 'hover' => '#2a3447', 'shadow' => 'rgba(0,0,0,.5)',
        ],
        // neutral charcoal grey
        'charcoal' => [
            'bg' => '#1d1f22', 'surface' => '#26282c', 'bg_dark' => '#141517',
            'title' => '#ececed', 'text' => '#c7c9cc', 'muted' => 'rgba(236,236,237,.45)',
            'border' => '#3a3d42', 'hover' => '#2d3034', 'shadow' => 'rgba(0,0,0,.5)',
        ],
        // deep blue night
        'midnight' => [
            'bg' => '#0f1826', 'surface' => '#172438', 'bg_dark' => '#0a111c',
            'title' => '#e3ecf7', 'text' => '#b9c6d8', 'muted' => 'rgba(227,236,247,.45)',
            'border' => '#2a3b54', 'hover' => '#1d2d44', 'shadow' => 'rgba(0,0,0,.55)',
        ],
        // warm dark (soft brown, very easy on the eyes)
        'warm' => [
            'bg' => '#211e1b', 'surface' => '#2b2723', 'bg_dark' => '#171511',
            'title' => '#efe9e1', 'text' => '#cfc7bb', 'muted' => 'rgba(239,233,225,.45)',
            'border' => '#423b32', 'hover' => '#322c26', 'shadow' => 'rgba(0,0,0,.5)',
        ],
        // dim, low contrast (the gentlest on the eyes)
        'dim' => [
            'bg' => '#262a31', 'surface' => '#2f343d', 'bg_dark' => '#1e2127',
            'title' => '#d4d9e0', 'text' => '#b6bcc6', 'muted' => 'rgba(212,217,224,.4)',
            'border' => '#3b414b', 'hover' => '#343a44', 'shadow' => 'rgba(0,0,0,.4)',
        ],
    ];

    private const CUSTOM_DEFAULT = [
        'bg' => '#1b2230', 'surface' => '#232c3d', 'bg_dark' => '#10151f',
        'title' => '#e8ecf3', 'text' => '#c3cbd9', 'muted' => 'rgba(232,236,243,.45)',
        'border' => '#333e52', 'hover' => '#2a3447', 'shadow' => 'rgba(0,0,0,.5)',
    ];

    public const KEYS = ['bg', 'surface', 'bg_dark', 'title', 'text', 'muted', 'border', 'hover', 'shadow'];

    /** localStorage key / data attribute base. */
    public const STORAGE = 'mertools-theme';

    private static function color(string $value, string $default): string
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%a-z]+\)|transparent)$/i', $value) ? $value : $default;
    }

    /** @return array<string,string> the resolved dark palette (preset or custom). */
    public static function palette(Registry $params): array
    {
        $key = (string) $params->get('dark_palette', 'slate');
        if ($key === 'custom') {
            $out = [];
            foreach (self::KEYS as $k) {
                $out[$k] = self::color((string) $params->get('dark_c_' . $k, ''), self::CUSTOM_DEFAULT[$k]);
            }

            return $out;
        }

        return self::PRESETS[$key] ?? self::PRESETS['slate'];
    }

    /** The accent colour for dark mode: the site's Gridbox accent (default) or a chosen colour. */
    public static function accent(Registry $params): ?string
    {
        if ((int) $params->get('dark_accent_site', 1)) {
            return null; // keep the site's --primary
        }
        $c = self::color((string) $params->get('dark_accent', ''), '');

        return $c !== '' ? $c : null;
    }

    /** The default mode for a first-time visitor: auto (follow the system), light or dark. */
    public static function defaultMode(Registry $params): string
    {
        $m = (string) $params->get('dark_default', 'auto');

        return in_array($m, ['auto', 'light', 'dark'], true) ? $m : 'auto';
    }

    /** The CSS: the dark palette as the Gridbox variables, plus the toggle button and a soft transition. */
    public static function css(Registry $params): string
    {
        $p   = self::palette($params);
        $sel = 'html[data-mertools-theme="dark"] body';

        $vars = '--bg-primary:' . $p['bg'] . ';--bg-secondary:' . $p['surface'] . ';--bg-dark:' . $p['bg_dark']
            . ';--bg-dark-accent:' . $p['bg_dark'] . ';--title:' . $p['title'] . ';--text:' . $p['text']
            . ';--icon:' . $p['text'] . ';--subtitle:' . $p['muted'] . ';--border:' . $p['border']
            . ';--hover:' . $p['hover'] . ';--shadow:' . $p['shadow'] . ';';

        $accent = self::accent($params);
        if ($accent !== null) {
            $vars .= '--primary:' . $accent . ';';
        }

        $css = $sel . '{' . $vars . '}';
        // native controls, scrollbars and form fields follow
        $css .= 'html[data-mertools-theme="dark"]{color-scheme:dark;}';
        // a gentle cross-fade when switching (only the colours, not layout)
        if ((int) $params->get('dark_transition', 1)) {
            $css .= 'html[data-mertools-theme] body,html[data-mertools-theme] body header,html[data-mertools-theme] body section,'
                . 'html[data-mertools-theme] body [class*="ba-item"]{transition:background-color .3s ease,border-color .3s ease,color .3s ease;}';
        }
        // colours Gridbox writes into its element styles, adapted by the script (only used in dark)
        if ((int) $params->get('dark_adaptive', 1)) {
            $css .= 'html[data-mertools-theme="dark"] [data-mt-bg]{background-color:var(--mt-bg)!important}'
                . 'html[data-mertools-theme="dark"] [data-mt-fg]{color:var(--mt-fg)!important}'
                . 'html[data-mertools-theme="dark"] a:hover[data-mt-fg],html[data-mertools-theme="dark"] a:hover [data-mt-fg]{color:var(--mt-fg-h,var(--mt-fg))!important}'
                . 'html.mertools-dt-calc *,html.mertools-dt-calc *::before,html.mertools-dt-calc *::after{transition:none!important}';
        }
        // slightly calm very bright images in dark mode (optional)
        if ((int) $params->get('dark_dim_media', 0)) {
            $css .= 'html[data-mertools-theme="dark"] img:not([src*=".svg"]),html[data-mertools-theme="dark"] video{filter:brightness(.9);}';
        }

        return $css . self::toggleCss($params);
    }

    /** Sizes of the list used up to 0.0.5, still read from saved settings. */
    private const OLD_SIZES = ['small' => 36, 'medium' => 44, 'large' => 52];

    /** @return array{int, int} the button and icon size in px (button 24–96 px, icon about 55 %). */
    public static function toggleSize(Registry $params): array
    {
        $raw = trim((string) $params->get('dark_toggle_size', '44'));
        $box = self::OLD_SIZES[$raw] ?? (ctype_digit($raw) ? (int) $raw : 44);
        $box = max(24, min(96, $box));

        return [$box, (int) round($box * 0.55)];
    }

    /** Styles of the toggle button (placed in the menu, beside the hamburger or floating by the script). */
    private static function toggleCss(Registry $params): string
    {
        $accent = self::accent($params) ?? 'var(--primary,#34dca2)';
        [$box, $icon] = self::toggleSize($params);

        return '.mertools-dt-li{display:inline-flex;align-items:center;align-self:center;vertical-align:middle;list-style:none;margin:0;padding:0}'
            . '.mertools-dt-li::before,.mertools-dt-li::after{display:none!important}'
            . '.mertools-dt{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:' . $box . 'px;height:' . $box . 'px;'
            . 'min-width:' . $box . 'px;flex:0 0 auto;padding:0;margin:0 6px;border:2px solid currentColor;border-radius:50%;'
            . 'background:transparent;color:var(--title,#1f2328);opacity:.9;cursor:pointer;line-height:0;font-size:0;'
            . 'transition:background-color .2s,border-color .2s,color .2s,opacity .2s,transform .2s;-webkit-appearance:none;appearance:none;'
            . '-webkit-tap-highlight-color:transparent;vertical-align:middle;position:relative;z-index:2}'
            . '.mertools-dt:hover{opacity:1;background:var(--hover,rgba(128,128,128,.14));color:' . $accent . ';transform:scale(1.06)}'
            . '.mertools-dt:focus-visible{outline:3px solid ' . $accent . ';outline-offset:2px;opacity:1}'
            . '.mertools-dt svg{width:' . $icon . 'px;height:' . $icon . 'px;display:block;flex:0 0 auto;pointer-events:none}'
            . '.mertools-dt .mertools-dt-sun{display:none}.mertools-dt .mertools-dt-moon{display:block}'
            . 'html[data-mertools-theme="dark"] .mertools-dt .mertools-dt-sun{display:block}'
            . 'html[data-mertools-theme="dark"] .mertools-dt .mertools-dt-moon{display:none}'
            . 'html[data-mertools-theme="dark"] .mertools-dt{color:var(--title,#e8ecf3)}'
            . '.mertools-dt-li .mertools-dt,html[data-mertools-theme="dark"] .mertools-dt-li .mertools-dt{color:var(--mt-dt-color,var(--title,currentColor))}'
            // beside the hamburger on phones
            . '.mertools-dt.mertools-dt-burger{margin:0}'
            // floating: always on top, in the visible part of the screen (the script adjusts the offsets)
            . '.mertools-dt.mertools-dt-float{position:fixed;right:18px;bottom:18px;z-index:2147483000;margin:0;opacity:1;'
            . 'border-width:1px;border-color:rgba(128,128,128,.35);background:var(--bg-primary,#fff);color:var(--title,#1f2328);'
            . 'box-shadow:0 6px 22px rgba(0,0,0,.28),0 2px 6px rgba(0,0,0,.18)}'
            . 'html[data-mertools-theme="dark"] .mertools-dt.mertools-dt-float{background:var(--bg-secondary,#232c3d);color:var(--title,#e8ecf3)}';
    }

    /** The early script (in <head>): sets the theme before the first paint so there is no flash. */
    public static function inlineScript(Registry $params): string
    {
        $def = self::defaultMode($params);

        return '(function(){try{var k=' . json_encode(self::STORAGE) . ',s=localStorage.getItem(k),d=' . json_encode($def) . ',t;'
            . 'if(s==="dark"||s==="light"){t=s}else if(d==="dark"){t="dark"}else if(d==="light"){t="light"}'
            . 'else{t=(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light"}'
            . 'document.documentElement.setAttribute("data-mertools-theme",t);}catch(e){}})();';
    }

    /** Config handed to the front-end script. */
    public static function jsConfig(Registry $params): array
    {
        return [
            'key'      => self::STORAGE,
            'def'      => self::defaultMode($params),
            'toggle'   => (bool) (int) $params->get('dark_toggle', 1),
            'selector' => trim((string) $params->get('dark_header_selector', 'header.header')) ?: 'header.header',
            'place'    => (string) $params->get('dark_toggle_place', 'auto'),
            'adaptive' => (bool) (int) $params->get('dark_adaptive', 1),
            'pal'      => self::palette($params),
            'accent'   => self::accent($params),
        ];
    }
}
