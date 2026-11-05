<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The badges of the products (Gridbox's Sale, New… — Shop → Badges): their look chosen in MerTools —
 * the corner of the picture, the colours (Gridbox's own, dark, light, one colour), a style, the size,
 * one under another or in a row — on the tiles of Gridbox's lists (categories, search, wishlist,
 * sliders of products) and, new, on the picture of the product page, where Gridbox shows none.
 *
 *  - The look is a stylesheet made of five parts (style, colours, corner, size, layout), each for the
 *    one value chosen on the site; the panel's preview gets every value of each, under its own class.
 *    The colours are custom properties (--mt-c the badge's, --mt-t the text on it, --mt-ink the text on
 *    a light ground), so a style never names a colour; the text on a badge of Gridbox's colours is dark
 *    or light as the colour needs it (CSS relative colours, white where the browser cannot) — in the
 *    style of Gridbox with Gridbox's colours its own text colour, as before.
 *  - The badges of the product page are worked out as Gridbox does for its tiles: its badges in their
 *    order, the sale badge as "- n%" from the price and the sale price, and only "Out of stock" for a
 *    product without variations whose stock is below its minimum. They go into the product's slideshow
 *    (over the picture, not the thumbnails) or the first picture of its gallery, made by the server
 *    (seen at once); mertools-badges.js puts them back when Gridbox swaps the pictures of a variation.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Badges
{
    /** the styles, in the order of the panel; gridbox: as Gridbox draws them */
    public const STYLES = ['gridbox', 'pill', 'soft', 'outline', 'glass', 'dot', 'caps', 'tag', 'gradient'];

    /** the colours: Gridbox's of each badge, all dark, all light, all one colour */
    public const COLOURS = ['gridbox', 'dark', 'light', 'one'];

    /** the corners of the picture: top left, top right, bottom left, bottom right */
    public const CORNERS = ['tl', 'tr', 'bl', 'br'];

    /** the font size of a badge: small (Gridbox's), medium, large */
    public const SIZES = ['s' => '.75rem', 'm' => '.875rem', 'l' => '1rem'];

    /** one under another (as Gridbox), or in a row */
    public const LAYOUTS = ['column', 'row'];

    /** the colour of "Out of stock" with Gridbox's colours (its own stylesheet's) */
    private const OUT_OF_STOCK = '#ff5c00';

    /** the dark and the light of the single-colour looks */
    private const DARK  = '#1f2328';
    private const LIGHT = '#ffffff';

    /** The badges in MerTools' look (the switch). */
    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('badge_on', 0);
    }

    /**
     * The settings, checked: on (product page, lists), style, colours, one (the colour of "one"), corner,
     * size, layout, offset (px from the edges).
     */
    public static function options(Registry $params): array
    {
        $pick = static fn (string $key, array $allowed, string $default): string
            => \in_array($v = (string) $params->get($key, $default), $allowed, true) ? $v : $default;

        return [
            'product' => (bool) (int) $params->get('badge_product', 1),
            'lists'   => (bool) (int) $params->get('badge_lists', 1),
            'style'   => $pick('badge_style', self::STYLES, 'gridbox'),
            'colours' => $pick('badge_colors', self::COLOURS, 'gridbox'),
            'one'     => self::colour((string) $params->get('badge_color', self::DARK)) ?? self::DARK,
            'corner'  => $pick('badge_corner', self::CORNERS, 'tl'),
            'size'    => $pick('badge_size', array_keys(self::SIZES), 's'),
            'layout'  => $pick('badge_layout', self::LAYOUTS, 'column'),
            'offset'  => max(0, min(80, (int) $params->get('badge_offset', 20))),
        ];
    }

    /** A colour safe to write into a stylesheet (#rgb, #rrggbb, #rrggbbaa, rgb()/rgba() of numbers), else null. */
    public static function colour(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value)
            || preg_match('/^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(?:,\s*[\d.]+%?\s*)?\)$/', $value)) {
            return $value;
        }

        return null;
    }

    // ---------------------------------------------------------------- the stylesheet

    /**
     * The stylesheet of the site: the wrappers of the badges ($o['lists']: also those of Gridbox's lists,
     * else only those of the product page) in the look chosen.
     */
    public static function css(array $o): string
    {
        $w = $o['lists'] ? 'body .ba-blog-post-badge-wrapper' : 'body .mt-badges-page';

        return self::base($w, 'body')
            . self::styleCss($o['style'], $w)
            . self::coloursCss($o['colours'], $w)
            . self::cornerCss($o['corner'], $w, $o['lists'])
            . self::sizeCss($o['size'], $w)
            . self::layoutCss($o['layout'], $w)
            . $w . '{--mt-off:' . $o['offset'] . 'px;--mt-one:' . $o['one'] . '}';
    }

    /**
     * The stylesheet of the panel's preview: every value of each part under the class of its value on
     * the box of the preview (mt-bp-s-<style>, -c-<colours>, -k-<corner>, -z-<size>, -l-<layout>); the
     * offset and the one colour are custom properties set on that box.
     */
    public static function previewCss(): string
    {
        $w   = static fn (string $cls): string => '.' . $cls . ' .ba-blog-post-badge-wrapper';
        $css = self::base('.mt-bp .ba-blog-post-badge-wrapper', '.mt-bp');
        foreach (self::STYLES as $v) {
            $css .= self::styleCss($v, $w('mt-bp-s-' . $v));
        }
        foreach (self::COLOURS as $v) {
            $css .= self::coloursCss($v, $w('mt-bp-c-' . $v));
        }
        foreach (self::CORNERS as $v) {
            $css .= self::cornerCss($v, $w('mt-bp-k-' . $v), false);
        }
        foreach (array_keys(self::SIZES) as $v) {
            $css .= self::sizeCss($v, $w('mt-bp-z-' . $v));
        }
        foreach (self::LAYOUTS as $v) {
            $css .= self::layoutCss($v, $w('mt-bp-l-' . $v));
        }

        return $css;
    }

    /** What every look has: the wrapper over the picture (clicks go through it to the picture), the badge's box. */
    private static function base(string $w, string $root): string
    {
        $b = $w . ' .ba-blog-post-badge';

        return $w . '{position:absolute;z-index:2;margin:0;gap:.5em;pointer-events:none;font-size:var(--mt-fs,.75rem);'
            . 'max-width:calc(100% - 2 * var(--mt-off,20px))}'
            // on the product page above the picture of the slide (Gridbox's: z-index 2, after the badges)
            . $root . ' .mt-badges-page{display:flex;z-index:5}' . $root . ' .mt-badge-host{position:relative}'
            . $b . '{margin:0;font-size:1em;line-height:1.2;cursor:default;max-width:100%;box-sizing:border-box;overflow-wrap:anywhere;'
            . 'border:0;box-shadow:none;text-shadow:none;text-transform:none;letter-spacing:normal;position:relative}';
    }

    /** The shape of a badge; its colours come from --mt-c (the badge), --mt-t (text on it), --mt-ink (text on a light ground). */
    private static function styleCss(string $style, string $w): string
    {
        $b = $w . ' .ba-blog-post-badge';

        return match ($style) {
            'pill'     => $b . '{background:var(--mt-c);color:var(--mt-t);border-radius:999px;padding:.5em 1.1em;font-weight:600;letter-spacing:.01em;'
                . 'box-shadow:0 2px 8px rgb(0 0 0/.18)}',
            'soft'     => $b . '{background:color-mix(in srgb,var(--mt-c) 16%,#fff);color:var(--mt-ink);border:1px solid color-mix(in srgb,var(--mt-c) 38%,#fff);'
                . 'border-radius:8px;padding:.45em .9em;font-weight:600}',
            'outline'  => $b . '{background:rgb(255 255 255/.92);color:var(--mt-ink);border:2px solid var(--mt-c);border-radius:6px;padding:.4em .85em;font-weight:700}',
            'glass'    => $b . '{background:color-mix(in srgb,var(--mt-c) 62%,transparent);color:var(--mt-t);-webkit-backdrop-filter:blur(10px) saturate(1.5);'
                . 'backdrop-filter:blur(10px) saturate(1.5);border:1px solid rgb(255 255 255/.4);border-radius:10px;padding:.5em 1em;font-weight:600;'
                . 'box-shadow:0 4px 14px rgb(0 0 0/.12)}',
            'dot'      => $b . '{background:rgb(255 255 255/.95);color:' . self::DARK . ';border-radius:999px;padding:.45em .95em .45em .8em;font-weight:600;'
                . 'box-shadow:0 1px 6px rgb(0 0 0/.14),inset 0 0 0 1px rgb(0 0 0/.06);display:inline-flex;align-items:center;gap:.5em}'
                . $b . '::before{content:"";flex:none;width:.6em;height:.6em;border-radius:50%;background:var(--mt-c);box-shadow:inset 0 0 0 1px rgb(0 0 0/.18)}',
            'caps'     => $b . '{background:var(--mt-c);color:var(--mt-t);border-radius:0;padding:.6em .95em;font-size:.85em;font-weight:800;'
                . 'text-transform:uppercase;letter-spacing:.09em}',
            // the arrow points away from the edge it sits at (--mt-flip -1 at the right corners)
            'tag'      => $b . '{background:none;color:var(--mt-t);isolation:isolate;font-weight:700;padding:.45em calc(.8em + (1 + var(--mt-flip,1)) / 2 * .75em) .45em '
                . 'calc(.8em + (1 - var(--mt-flip,1)) / 2 * .75em)}'
                . $b . '::before{content:"";position:absolute;inset:0;z-index:-1;background:var(--mt-c);border-radius:3px;'
                . 'clip-path:polygon(0 0,calc(100% - .75em) 0,100% 50%,calc(100% - .75em) 100%,0 100%);transform:scaleX(var(--mt-flip,1))}',
            'gradient' => $b . '{background:linear-gradient(135deg,var(--mt-c),color-mix(in oklab,var(--mt-c) 78%,#000));color:var(--mt-t);border-radius:8px;'
                . 'padding:.5em 1em;font-weight:700;box-shadow:0 4px 14px color-mix(in srgb,var(--mt-c) 45%,transparent)}',
            // Gridbox's own (store-app-product.css): with Gridbox's colours its text colour too (--mt-tg)
            default    => $b . '{background:var(--mt-c);color:var(--mt-tg,var(--mt-t));border-radius:3px;padding:.83em 1.25em;font-weight:700}',
        };
    }

    /** The colours: Gridbox's of each badge (the text dark or light as it needs), dark, light, one colour. */
    private static function coloursCss(string $mode, string $w): string
    {
        $b = $w . ' .ba-blog-post-badge';
        // the text on a colour: white, or dark on a light colour where the browser can tell
        $auto = '@supports (color:oklch(from red l c h)){' . $b . '{--mt-t:oklch(from var(--mt-c) clamp(0,(.78 - l) * 1000,1) 0 0 / 1)}}';
        // the colour darkened enough to read on a light ground (also a yellow)
        $ink  = '--mt-ink:color-mix(in srgb,var(--mt-c) 55%,#000)';

        return match ($mode) {
            'dark'  => $b . '{--mt-c:' . self::DARK . ';--mt-t:#fff;--mt-ink:' . self::DARK . '}',
            'light' => $b . '{--mt-c:' . self::LIGHT . ';--mt-t:' . self::DARK . ';--mt-ink:' . self::DARK . '}',
            'one'   => $b . '{--mt-c:var(--mt-one,' . self::DARK . ');--mt-t:#fff;' . $ink . '}' . $auto,
            default => $b . '{--mt-c:var(--badge-color,' . self::DARK . ');--mt-t:#fff;--mt-tg:var(--title-inverse,#fff);' . $ink . '}'
                . $w . ' .ba-blog-post-badge.out-of-stock-badge{--mt-c:' . self::OUT_OF_STOCK . '}' . $auto,
        };
    }

    /**
     * The corner: the wrapper at it, the badges lined up to its edge; in Gridbox's lists the top right
     * one goes below the wishlist heart there (Gridbox's: 20px from the corner).
     */
    private static function cornerCss(string $corner, string $w, bool $lists): string
    {
        $top   = $corner[0] === 't';
        $left  = $corner[1] === 'l';
        $css   = $w . '{' . ($top ? 'top:var(--mt-off,20px);bottom:auto' : 'bottom:var(--mt-off,20px);top:auto') . ';'
            . ($left ? 'left:var(--mt-off,20px);right:auto' : 'right:var(--mt-off,20px);left:auto') . ';--mt-align:flex-' . ($left ? 'start' : 'end')
            . ($left ? '' : ';--mt-flip:-1') . '}';
        if ($lists && $corner === 'tr') {
            $css .= $w . ':has(~ .ba-blog-post-wishlist-wrapper){top:calc(var(--mt-off,20px) + 3.25rem)}';
        }

        return $css;
    }

    private static function sizeCss(string $size, string $w): string
    {
        return $w . '{--mt-fs:' . (self::SIZES[$size] ?? self::SIZES['s']) . '}';
    }

    /** One under another (lined up to the corner's edge), or in a row that wraps (from the corner's edge). */
    private static function layoutCss(string $layout, string $w): string
    {
        return $layout === 'row'
            ? $w . '{flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:var(--mt-align,flex-start)}'
            : $w . '{flex-direction:column;flex-wrap:nowrap;align-items:var(--mt-align,flex-start);justify-content:flex-start}';
    }

    // ---------------------------------------------------------------- the product page

    /**
     * The badges of a product as Gridbox shows them on its tiles (blog-post-badge-wishlist.php): its
     * badges in their order, the sale one as "- n%" (from price and sale price), or only "Out of stock"
     * for a product without variations and with less stock than its minimum.
     *
     * @param list<array{title:string, color:string, type:string}> $badges the badges of the product, in order
     * @param array{price:mixed, sale_price:mixed, stock:mixed, min:mixed} $data its row of product data
     *
     * @return list<array{title:string, color:string, out:bool}>
     */
    public static function forProduct(array $badges, array $data, bool $variations, string $outOfStock): array
    {
        $min   = !empty($data['min']) ? (float) $data['min'] : 1;
        $stock = $data['stock'] ?? null;
        // as Gridbox: a product without variations is out of stock unless its stock is empty (not counted) or enough
        if (!$variations && !($stock === '' || (is_numeric($stock) && (float) $stock >= $min))) {
            return [['title' => $outOfStock, 'color' => '', 'out' => true]];
        }
        $out = [];
        foreach ($badges as $badge) {
            $title = (string) ($badge['title'] ?? '');
            if (($badge['type'] ?? '') === 'sale') {
                $price = (float) ($data['price'] ?? 0);
                $sale  = (string) ($data['sale_price'] ?? '');
                $title = '- ' . ($price == 0 ? 0 : round(100 - (($sale === '' ? $price : (float) $sale) * 100 / $price))) . '%';
            }
            if ($title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'color' => (string) ($badge['color'] ?? ''), 'out' => false];
        }

        return $out;
    }

    /** The wrapper of the badges of the product page (Gridbox's classes, so its own look applies to it too). */
    public static function html(array $badges): string
    {
        $e   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false);
        $out = '<div class="ba-blog-post-badge-wrapper mt-badges-page" data-mt-badges>';
        foreach ($badges as $b) {
            $colour = $b['out'] ? null : self::colour($b['color']);
            $out   .= '<span class="ba-blog-post-badge' . ($b['out'] ? ' out-of-stock-badge' : '') . '"'
                . ($colour !== null ? ' style="--badge-color:' . $colour . ';"' : '') . '>' . $e($b['title']) . '</span>';
        }

        return $out . '</div>';
    }

    /**
     * The badges put into each picture of the product on a page: its slideshows (over the picture, as the
     * first child of the slides' box — Gridbox's script takes only the slides from it) and the first
     * picture of its galleries. Pictures that have them already are left as they are.
     */
    public static function inject(string $html, string $wrapper): string
    {
        if ($wrapper === '' || str_contains($html, 'data-mt-badges')) {
            return $html;
        }
        // the box of the slides of a product slideshow
        $html = (string) preg_replace_callback(
            '#(<div\b[^>]*\bclass="ba-item-product-slideshow\b[^"]*"[^>]*>(?:(?!\bclass="ba-item-)[^<]|<)*?<div\b[^>]*\bclass="slideshow-content\b[^"]*"[^>]*>)#',
            static fn (array $m): string => $m[1] . $wrapper,
            $html
        );
        // the first picture of a product gallery
        $html = (string) preg_replace_callback(
            '#(<div\b[^>]*\bclass="ba-item-product-gallery\b[^"]*"[^>]*>(?:(?!\bclass="ba-item-)[^<]|<)*?<div\b[^>]*\bclass=")(ba-gridbox-gallery-image\b[^"]*)("[^>]*>)#',
            static fn (array $m): string => $m[1] . $m[2] . ' mt-badge-host' . $m[3] . $wrapper,
            $html
        );

        return $html;
    }
}
