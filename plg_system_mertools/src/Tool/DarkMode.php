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

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

final class DarkMode
{
    public const PALETTES = ['slate', 'charcoal', 'midnight', 'warm', 'sepia', 'dim', 'custom'];

    /** Palettes offered to visitors (the dots by the toggle) when the setting was never saved. */
    public const OFFERED_DEFAULT = ['slate', 'midnight', 'warm', 'sepia', 'charcoal'];

    /** localStorage key of the palette a visitor chose. */
    public const PALETTE_STORAGE = 'mertools-palette';

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
        // sepia: dark brown paper with cream text, warm like old photographs
        'sepia' => [
            'bg' => '#2a2219', 'surface' => '#352b20', 'bg_dark' => '#1d1711',
            'title' => '#f2e6cf', 'text' => '#dccbad', 'muted' => 'rgba(242,230,207,.5)',
            'border' => '#4d3e2d', 'hover' => '#3c3124', 'shadow' => 'rgba(0,0,0,.5)',
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

    /** The palette colours the intensity slider deepens or softens. */
    public const SHADES = ['bg', 'surface', 'bg_dark', 'hover', 'border'];

    /** @return array<string, array<string,string>> the dark presets (for the settings preview). */
    public static function presets(): array
    {
        return self::PRESETS + ['custom' => self::CUSTOM_DEFAULT];
    }

    /** Intensity of the dark theme 0–100; 50 is the palette as designed. */
    public static function intensity(Registry $params): int
    {
        return max(0, min(100, (int) $params->get('dark_intensity', 50)));
    }

    /** @return int[]|null [r, g, b] of a #rgb / #rrggbb colour */
    private static function hex(string $c): ?array
    {
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($c), $m)) {
            return null;
        }
        $h = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /**
     * The intensity applied to one background shade: above 50 it is mixed towards a near-black
     * (never pure black), below 50 towards a soft slate grey. The settings preview uses the same
     * formula (media/js/mertools-preview.js).
     */
    public static function shade(string $colour, int $intensity): string
    {
        $rgb = self::hex($colour);
        if ($rgb === null || $intensity === 50) {
            return $colour;
        }
        $t      = ($intensity - 50) / 50;
        $target = $t > 0 ? [6, 8, 11] : [74, 82, 96];
        $amount = $t > 0 ? $t * 0.55 : -$t * 0.32;
        $out    = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= sprintf('%02x', (int) round($rgb[$i] + ($target[$i] - $rgb[$i]) * $amount));
        }

        return $out;
    }

    /** @return string[] the themes the toggle switches between */
    public static function themes(Registry $params): array
    {
        return ['light', 'dark'];
    }

    /** The default dark palette (the one set in the settings). */
    public static function paletteKey(Registry $params): string
    {
        $key = (string) $params->get('dark_palette', 'slate');

        return in_array($key, self::PALETTES, true) ? $key : 'slate';
    }

    /** @return string[] the palettes visitors can choose with the dots (always with the default one first) */
    public static function offered(Registry $params): array
    {
        $raw  = $params->get('dark_palettes', null);
        $list = $raw === null ? self::OFFERED_DEFAULT : (is_array($raw) ? $raw : array_filter(explode(',', (string) $raw)));
        $list = array_values(array_intersect(self::PALETTES, array_map('strval', $list)));
        $def  = self::paletteKey($params);

        return array_values(array_unique(array_merge([$def], $list)));
    }

    /** Are the palette dots shown by the toggle? */
    public static function picker(Registry $params): bool
    {
        return (bool) (int) $params->get('dark_picker', 1) && count(self::offered($params)) > 1;
    }

    /** localStorage key / data attribute base. */
    public const STORAGE = 'mertools-theme';

    private static function color(string $value, string $default): string
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%a-z]+\)|transparent)$/i', $value) ? $value : $default;
    }

    /** @return array<string,string> the resolved default dark palette (preset or custom, with the intensity applied). */
    public static function palette(Registry $params): array
    {
        return self::paletteOf($params, self::paletteKey($params));
    }

    /** @return array<string,string> one dark palette by key, with the intensity applied */
    public static function paletteOf(Registry $params, string $key): array
    {
        if ($key === 'custom') {
            $out = [];
            foreach (self::KEYS as $k) {
                $out[$k] = self::color((string) $params->get('dark_c_' . $k, ''), self::CUSTOM_DEFAULT[$k]);
            }
        } else {
            $out = self::PRESETS[$key] ?? self::PRESETS['slate'];
        }
        $i = self::intensity($params);
        foreach (self::SHADES as $k) {
            $out[$k] = self::shade($out[$k], $i);
        }

        return $out;
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

        return in_array($m, array_merge(['auto'], self::themes($params)), true) ? $m : 'auto';
    }

    public const LOGO_SELECTOR = 'header .ba-item-logo img';

    /** The address of the logo for dark mode chosen in the settings, or null. */
    public static function logoUrl(Registry $params): ?string
    {
        $value = trim((string) $params->get('dark_logo', ''));
        if ($value === '') {
            return null;
        }
        $url = (string) HTMLHelper::cleanImageURL($value)->url;
        if ($url === '' || preg_match('#^(javascript|data|vbscript):#i', $url)) {
            return null;
        }
        if (!preg_match('#^(https?:)?//#i', $url)) {
            $url = rtrim(Uri::root(true), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /** The logo images to replace: a CSS selector list (characters that could leave the rule are removed). */
    public static function logoSelector(Registry $params): string
    {
        $sel = trim(str_replace(['{', '}', '<', '>', ';', '\\'], '', (string) $params->get('dark_logo_selector', self::LOGO_SELECTOR)));

        return $sel !== '' ? $sel : self::LOGO_SELECTOR;
    }

    /** The CSS: the dark palette as the Gridbox variables, plus the toggle button and a soft transition. */
    public static function css(Registry $params): string
    {
        $p    = self::palette($params);
        $vars = self::vars($p);

        $accent = self::accent($params);
        if ($accent !== null) {
            $vars .= '--primary:' . $accent . ';';
        }

        $css = 'html[data-mertools-theme="dark"] body{' . $vars . '}';
        // the other palettes a visitor can choose with the dots (the attribute is set before the first paint)
        foreach (self::offered($params) as $key) {
            if ($key !== self::paletteKey($params)) {
                $css .= 'html[data-mertools-theme="dark"][data-mertools-palette="' . $key . '"] body{' . self::vars(self::paletteOf($params, $key)) . '}';
            }
        }
        // native controls, scrollbars and form fields follow
        $css .= 'html[data-mertools-theme="dark"]{color-scheme:dark;}';
        // a gentle cross-fade when switching (only the colours, not layout)
        if ((int) $params->get('dark_transition', 1)) {
            $css .= 'html[data-mertools-theme] body,html[data-mertools-theme] body header,html[data-mertools-theme] body section,'
                . 'html[data-mertools-theme] body [class*="ba-item"]{transition:background-color .3s ease,border-color .3s ease,color .3s ease;}';
        }
        // colours Gridbox writes into its element styles, adapted by the script (only used in dark)
        if ((int) $params->get('dark_adaptive', 1)) {
            // html[data-mt-on] is set by the script while the fixes match the current dark or sepia theme
            $bd  = fn ($p) => 'border-top-color:var(--mt-' . $p . 't)!important;border-right-color:var(--mt-' . $p . 'r)!important;'
                . 'border-bottom-color:var(--mt-' . $p . 'b)!important;border-left-color:var(--mt-' . $p . 'l)!important';
            $css .= 'html[data-mt-on] [data-mt-bg]{background-color:var(--mt-bg)!important}'
                . 'html[data-mt-on] [data-mt-bgi]{background-image:var(--mt-bgi)!important}'
                . 'html[data-mt-on] [data-mt-fg]{color:var(--mt-fg)!important}'
                . 'html[data-mt-on] [data-mt-fgp]::before,html[data-mt-on] [data-mt-fgp]::after{color:var(--mt-fg)!important}'
                . 'html[data-mt-on] a:hover[data-mt-fg],html[data-mt-on] a:hover [data-mt-fg]{color:var(--mt-fg-h,var(--mt-fg))!important}'
                . 'html[data-mt-on] [data-mt-bd]{' . $bd('bd') . '}'
                . 'html[data-mt-on] [data-mt-bdb]::before{' . $bd('bb') . '}'
                . 'html[data-mt-on] [data-mt-bda]::after{' . $bd('ba') . '}'
                . 'html.mertools-dt-calc *,html.mertools-dt-calc *::before,html.mertools-dt-calc *::after{transition:none!important}';
        }
        // the logo for dark mode, shown from the first paint (the script also swaps the image address,
        // for browsers that do not draw "content" on images and for Gridbox's lazy loading)
        $logo = self::logoUrl($params);
        if ($logo !== null) {
            $rules = array_map(fn ($s) => 'html[data-mertools-theme="dark"] ' . trim($s), array_filter(explode(',', self::logoSelector($params)), 'trim'));
            $css  .= implode(',', $rules) . '{content:url("' . str_replace(['"', "\n", "\r"], ['%22', '', ''], $logo) . '")}';
        }
        // slightly calm very bright images in dark mode (optional)
        if ((int) $params->get('dark_dim_media', 0)) {
            $css .= 'html[data-mertools-theme="dark"] img:not([src*=".svg"]),html[data-mertools-theme="dark"] video{filter:brightness(.9);}';
        }

        return $css . self::toggleCss($params);
    }

    /** The Gridbox theme variables for a palette. */
    private static function vars(array $p): string
    {
        return '--bg-primary:' . $p['bg'] . ';--bg-secondary:' . $p['surface'] . ';--bg-dark:' . $p['bg_dark']
            . ';--bg-dark-accent:' . $p['bg_dark'] . ';--title:' . $p['title'] . ';--text:' . $p['text']
            . ';--icon:' . $p['text'] . ';--subtitle:' . $p['muted'] . ';--border:' . $p['border']
            . ';--hover:' . $p['hover'] . ';--shadow:' . $p['shadow'] . ';';
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

        $dot = max(14, (int) round($box * 0.45));

        return '.mertools-dt-li{display:inline-flex;align-items:center;align-self:center;vertical-align:middle;list-style:none;margin:0;padding:0}'
            . '.mertools-dt-li::before,.mertools-dt-li::after{display:none!important}'
            . '.mertools-dt-box{position:relative;display:inline-flex;align-items:center;vertical-align:middle;z-index:2}'
            . '.mertools-dt{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:' . $box . 'px;height:' . $box . 'px;'
            . 'min-width:' . $box . 'px;flex:0 0 auto;padding:0;margin:0 6px;border:2px solid currentColor;border-radius:50%;'
            . 'background:transparent;color:var(--title,#1f2328);opacity:.9;cursor:pointer;line-height:0;font-size:0;'
            . 'transition:background-color .2s,border-color .2s,color .2s,opacity .2s,transform .2s;-webkit-appearance:none;appearance:none;'
            . '-webkit-tap-highlight-color:transparent;vertical-align:middle;position:relative}'
            . '.mertools-dt:hover{opacity:1;background:var(--hover,rgba(128,128,128,.14));color:' . $accent . ';transform:scale(1.06)}'
            . '.mertools-dt:focus-visible{outline:3px solid ' . $accent . ';outline-offset:2px;opacity:1}'
            . '.mertools-dt svg{width:' . $icon . 'px;height:' . $icon . 'px;display:block;flex:0 0 auto;pointer-events:none}'
            . 'html[data-mertools-theme="dark"] .mertools-dt{color:var(--title,#e8ecf3)}'
            . '.mertools-dt-li .mertools-dt,html[data-mertools-theme="dark"] .mertools-dt-li .mertools-dt{color:var(--mt-dt-color,var(--title,currentColor))}'
            // beside the hamburger on phones
            . '.mertools-dt-box.mertools-dt-burger .mertools-dt{margin:0}'
            // floating: always on top, in the visible part of the screen (the script sets the offsets)
            . '.mertools-dt-box.mertools-dt-float{position:fixed;right:18px;bottom:18px;z-index:2147483000}'
            . '.mertools-dt-float .mertools-dt{margin:0;opacity:1;border-width:1px;border-color:rgba(128,128,128,.35);background:var(--bg-primary,#fff);'
            . 'color:var(--title,#1f2328);box-shadow:0 6px 22px rgba(0,0,0,.28),0 2px 6px rgba(0,0,0,.18)}'
            . 'html[data-mertools-theme="dark"] .mertools-dt-float .mertools-dt{background:var(--bg-secondary,#232c3d);color:var(--title,#e8ecf3)}'
            // the palette dots: slide out of the button on hover / keyboard focus / long press, into the page
            . '.mertools-dt-dots{position:absolute;display:flex;opacity:0;visibility:hidden;pointer-events:none;'
            . 'transition:opacity .22s ease,transform .22s ease,visibility 0s linear .22s}'
            . '.mertools-dt-dots-in{display:flex;gap:8px;padding:7px 9px;border-radius:999px;background:var(--bg-secondary,#fff);'
            . 'border:1px solid var(--border,rgba(128,128,128,.3));box-shadow:0 8px 24px rgba(0,0,0,.22)}'
            . '.mertools-dt-box[data-dir="left"] .mertools-dt-dots{right:100%;top:50%;padding-right:8px;transform:translate(12px,-50%)}'
            . '.mertools-dt-box[data-dir="right"] .mertools-dt-dots{left:100%;top:50%;padding-left:8px;transform:translate(-12px,-50%)}'
            . '.mertools-dt-box[data-dir="down"] .mertools-dt-dots{top:100%;right:0;padding-top:8px;transform:translateY(-10px)}'
            . '.mertools-dt-box:hover .mertools-dt-dots,.mertools-dt-box:has(:focus-visible) .mertools-dt-dots,.mertools-dt-box.is-open .mertools-dt-dots'
            . '{opacity:1;visibility:visible;pointer-events:auto;transition:opacity .22s ease,transform .22s ease,visibility 0s}'
            . '.mertools-dt-box[data-dir="left"]:is(:hover,:has(:focus-visible),.is-open) .mertools-dt-dots{transform:translate(0,-50%)}'
            . '.mertools-dt-box[data-dir="right"]:is(:hover,:has(:focus-visible),.is-open) .mertools-dt-dots{transform:translate(0,-50%)}'
            . '.mertools-dt-box[data-dir="down"]:is(:hover,:has(:focus-visible),.is-open) .mertools-dt-dots{transform:translateY(0)}'
            . '.mertools-dt-dot{box-sizing:border-box;width:' . $dot . 'px;height:' . $dot . 'px;padding:0;margin:0;border-radius:50%;cursor:pointer;'
            . 'border:2px solid var(--dot-fg);background:linear-gradient(135deg,var(--dot-bg) 0 55%,var(--dot-sf) 55% 100%);'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .15s;-webkit-appearance:none;appearance:none;flex:0 0 auto}'
            . '.mertools-dt-dot:hover,.mertools-dt-dot:focus-visible{transform:scale(1.18);outline:none}'
            . '.mertools-dt-dot[aria-pressed="true"]{box-shadow:0 0 0 2px var(--bg-secondary,#fff),0 0 0 4px ' . $accent . '}'
            . '@media (prefers-reduced-motion:reduce){.mertools-dt-dots,.mertools-dt-dot{transition:none}}';
    }

    /** The early script (in <head>): sets the theme before the first paint so there is no flash. */
    public static function inlineScript(Registry $params): string
    {
        $def = self::defaultMode($params);

        // a visitor's stored "sepia" theme (0.0.8) is dark mode with the sepia palette now
        return '(function(){try{var k=' . json_encode(self::STORAGE) . ',s=localStorage.getItem(k),d=' . json_encode($def) . ',a='
            . json_encode(self::themes($params)) . ',o=' . json_encode(self::offered($params)) . ',p=localStorage.getItem('
            . json_encode(self::PALETTE_STORAGE) . '),t;'
            . 'if(s==="sepia"){s="dark";if(!p&&o.indexOf("sepia")>-1)p="sepia"}'
            . 'if(o.indexOf(p)>-1){document.documentElement.setAttribute("data-mertools-palette",p)}'
            . 'if(a.indexOf(s)>-1){t=s}else if(a.indexOf(d)>-1){t=d}'
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
            'fx'       => max(0, min(400, (int) $params->get('dark_float_x', 18))),
            'fy'       => max(0, min(400, (int) $params->get('dark_float_y', 18))),
            'a11y'     => trim(str_replace(['{', '}', '<', '>', ';'], '', (string) $params->get('dark_a11y_selector', '._access-icon'))) ?: '._access-icon',
            'adaptive' => (bool) (int) $params->get('dark_adaptive', 1),
            'pal'      => self::palette($params),
            'themes'   => self::themes($params),
            'palette'  => self::paletteKey($params),
            'pals'     => array_combine(self::offered($params), array_map(fn ($k) => self::paletteOf($params, $k), self::offered($params))),
            'picker'   => self::picker($params),
            'pkey'     => self::PALETTE_STORAGE,
            'accent'   => self::accent($params),
            'logo'     => self::logoUrl($params),
            'logoSel'  => self::logoSelector($params),
        ];
    }
}
