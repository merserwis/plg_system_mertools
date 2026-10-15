<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Omnibus: the lowest price of the 30 days before a price reduction, as the law asks for it (Polish
 * act on informing about prices, art. 4 sec. 2-3, from the EU Omnibus directive): "in every case of
 * informing about a reduced price, next to it the lowest price that applied in the 30 days before the
 * reduction"; a product offered for less than 30 days: the lowest price since it is offered.
 *
 * Gridbox keeps no price history, so MerTools keeps one (OmnibusStore): every change of the regular
 * or sale price of a product and of each of its versions, with its time. When a price goes down to a
 * sale price, the lowest price that applied in the 30 days before is worked out from that history and
 * kept for as long as that sale lasts; a further reduction takes the 30 days before itself (a sale
 * price of those days included), a sale price raised or a regular price changed during the sale keep
 * it, the end of the sale clears it.
 *
 * Shown: on the product page a line per product and version on sale (the line of the version chosen
 * is shown by media/js/mertools-omnibus.js, only while Gridbox shows a sale price), and in the lists
 * and sliders under a sale price. The lines and the script of a separate Omnibus plugin are taken off
 * the pages, so that plugin can be removed; the line looks as that plugin's stylesheet made it. What the
 * plugin did for category pages is in Categories.
 *
 * This class: the rules and the HTML (no database), OmnibusStore: the history and the state.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Omnibus
{
    /** the Omnibus plugin script that is replaced */
    public const SCRIPT = '/plugins/system/omnibus/assets/scr_omnibus.js';

    /** the length of the period before a reduction, in seconds */
    public const WINDOW = 30 * 86400;

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('shop_omnibus', 0);
    }

    public static function lists(Registry $params): bool
    {
        return (bool) (int) $params->get('omnibus_lists', 1);
    }

    /** The line as set (with {price}), else the default of the site language. */
    public static function template(Registry $params, string $default): string
    {
        $text = trim((string) $params->get('omnibus_text', ''));
        $text = $text !== '' ? $text : $default;

        return str_contains($text, '{price}') ? $text : rtrim($text, ': ') . ': {price}';
    }

    /**
     * The product page lines hidden unless MerTools shows them (!important: a copy of the old script
     * could still run); the list line under the price, small and allowed to wrap (Gridbox keeps the
     * price on one line).
     */
    public static function css(): string
    {
        // the look of the line as the Omnibus plugin's stylesheet gave it: centred, on a computer under the price on the left
        return '.omnibus_info{display:none!important}.omnibus_info.mt-omnibus-on{display:block!important}'
            . '.omnibus_info.mt-omnibus{margin:5px 0 25px;color:var(--text);font-size:14px;font-style:normal;font-weight:300;letter-spacing:0;line-height:22px;text-align:center}'
            . '@media screen and (min-width:1025px){.omnibus_info.mt-omnibus{margin:-20px 0 30px;text-align:left}}'
            . '.mt-omnibus-list{display:block;white-space:normal;font-size:12px;font-weight:400;line-height:1.35;color:var(--text,inherit);opacity:.75;margin-top:4px;text-decoration:none}.mt-omnibus-price{white-space:nowrap}';
    }

    /** The <script> tag of a file of the site taken off a finished page (with the line break after it). */
    public static function dropScript(string $html, string $path): string
    {
        $pattern = '#<script\b[^>]*\ssrc="[^"]*' . preg_quote($path, '#') . '(?:\?[^"]*)?"[^>]*>\s*</script>[ \t]*\R?#i';

        return preg_replace($pattern, '', $html) ?? $html;
    }

    /** The lines of a separate Omnibus plugin taken off a page (MerTools' own lines carry mt-omnibus). */
    public static function dropPluginLines(string $html): string
    {
        return preg_replace('#<div class="omnibus_info"(?![^>]*\bmt-omnibus\b)[^>]*>[^<]*</div>\s*#', '', $html) ?? $html;
    }

    /** A price as Gridbox keeps it ("6616", "12.5", "", "0") as a number; null when there is none. */
    public static function amount($value): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $value = round((float) $value, 4);

        return $value > 0 ? $value : null;
    }

    /** On sale: a sale price is set (Gridbox shows it, crossed regular price beside it). */
    public static function onSale(?float $regular, ?float $sale): bool
    {
        return $sale !== null;
    }

    /** The price a customer pays: the sale price when set, else the regular one (null: no price). */
    public static function effective(?float $regular, ?float $sale): ?float
    {
        return $sale ?? $regular;
    }

    /**
     * The priced items of Gridbox's product data: the product itself (key = its id) and each version
     * (key = Gridbox's version key), from rows of #__gridbox_store_product_data.
     *
     * @return array<string, array{product: int, regular: ?float, sale: ?float, default: bool}>
     */
    public static function items(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[(string) $id] = ['product' => $id, 'regular' => self::amount($row['price'] ?? ''), 'sale' => self::amount($row['sale_price'] ?? ''), 'default' => false];
            $versions = json_decode((string) ($row['variations'] ?? ''), true);
            if (!\is_array($versions)) {
                continue;
            }
            foreach ($versions as $key => $version) {
                $key = (string) $key;
                if (!\is_array($version) || $key === '' || \strlen($key) > 64 || isset($items[$key])) {
                    continue;
                }
                $items[$key] = ['product' => $id, 'regular' => self::amount($version['price'] ?? ''), 'sale' => self::amount($version['sale_price'] ?? ''),
                    'default' => !empty($version['default'])];
            }
        }

        return $items;
    }

    /**
     * The lowest price that applied in [from, to): the price in force at "from" (the last change before
     * or at it) and every price set after it, before "to". History rows: changed (UTC "Y-m-d H:i:s"),
     * regular, sale — in any order. Null: no price applied in the period.
     */
    public static function lowest(array $history, string $from, string $to): ?float
    {
        usort($history, static fn ($a, $b) => strcmp((string) $a['changed'], (string) $b['changed']));
        $lowest = null;
        $atFrom = null;
        foreach ($history as $row) {
            $changed = (string) $row['changed'];
            if ($changed >= $to) {
                break;
            }
            $price = self::effective(self::amount($row['regular'] ?? ''), self::amount($row['sale'] ?? ''));
            if ($changed <= $from) {
                $atFrom = $price;
                continue;
            }
            if ($price !== null) {
                $lowest = $lowest === null ? $price : min($lowest, $price);
            }
        }
        if ($atFrom !== null) {
            $lowest = $lowest === null ? $atFrom : min($lowest, $atFrom);
        }

        return $lowest;
    }

    /**
     * What a change of the prices of an item means for its reference (the lowest price before the
     * reduction): 'reduction' (worked out again from the 30 days before now), 'keep' (the sale goes
     * on: sale price raised, regular price changed) or 'clear' (not on sale).
     */
    public static function change(?float $oldRegular, ?float $oldSale, ?float $regular, ?float $sale): string
    {
        if (!self::onSale($regular, $sale)) {
            return 'clear';
        }
        if (!self::onSale($oldRegular, $oldSale)) {
            return 'reduction';
        }
        $before = self::effective($oldRegular, $oldSale);
        $now    = self::effective($regular, $sale);

        return $before !== null && $now !== null && $now < $before ? 'reduction' : 'keep';
    }

    /** A price in the store's format, e.g. 6616 → "6 616,00 zł netto" (Gridbox's currency settings). */
    public static function format(float $price, array $currency): string
    {
        $decimals = max(0, min(4, (int) ($currency['decimals'] ?? 2)));
        $number   = number_format($price, $decimals, (string) ($currency['separator'] ?? ','), (string) ($currency['thousand'] ?? ' '));
        $symbol   = trim((string) ($currency['symbol'] ?? ''));
        if ($symbol === '') {
            return $number;
        }

        return str_contains((string) ($currency['position'] ?? ''), 'left') ? $symbol . ' ' . $number : $number . ' ' . $symbol;
    }

    public static function line(string $template, float $price, array $currency): string
    {
        return str_replace('{price}', self::format($price, $currency), $template);
    }

    /** The line as HTML: the text escaped, the price never broken across two rows. */
    public static function lineHtml(string $template, float $price, array $currency): string
    {
        $parts = explode('{price}', $template);
        $price = '<span class="mt-omnibus-price">' . htmlspecialchars(self::format($price, $currency), ENT_QUOTES) . '</span>';

        return implode($price, array_map(static fn ($part) => htmlspecialchars($part, ENT_QUOTES), $parts));
    }

    /**
     * The product page: after the price box of the "Add to cart" element, one hidden line per item of
     * the product on sale (key => lowest price); the product's own line is marked, the script shows the
     * one of what is chosen.
     *
     * @param array<string, float> $lowest
     */
    public static function productLines(string $html, int $productId, array $lowest, string $template, array $currency): string
    {
        if (!$lowest || !preg_match('#<div class="ba-add-to-cart-price">.*?</div>#s', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $lines = '';
        foreach ($lowest as $key => $price) {
            $own    = (string) $key === (string) $productId ? ' data-mt-own' : '';
            $lines .= "\n" . '<div class="omnibus_info mt-omnibus" data-omnibus-id="' . htmlspecialchars((string) $key, ENT_QUOTES) . '"' . $own . '>'
                . self::lineHtml($template, $price, $currency) . '</div>';
        }
        $at = $m[0][1] + \strlen($m[0][0]);

        return substr($html, 0, $at) . $lines . substr($html, $at);
    }

    /**
     * The lists and sliders: under the price of each product shown on sale (Gridbox's sale price box
     * is there), the line of the item whose price the list shows (product id => lowest price).
     *
     * @param array<int, float> $lowest
     */
    public static function listLines(string $html, array $lowest, string $template, array $currency): string
    {
        if (!$lowest) {
            return $html;
        }
        $tile = '#(<(?:li|div)\b[^>]*\sclass="(?:[^"]*\s)?ba-store-app-product(?=[\s"])[^>]*>)(.*?)(?=<(?:li|div)\b[^>]*\sclass="(?:[^"]*\s)?ba-store-app-product(?=[\s"])|$)#s';

        return preg_replace_callback($tile, static function (array $m) use ($lowest, $template, $currency): string {
            if (!preg_match('/\sdata-id="(\d+)"/', $m[1], $id) || !isset($lowest[(int) $id[1]]) || str_contains($m[2], 'mt-omnibus-list')
                || !str_contains($m[2], 'ba-blog-post-add-to-cart-sale-price-wrapper')) {
                return $m[0];
            }
            $line = '<span class="mt-omnibus-list">' . self::lineHtml($template, $lowest[(int) $id[1]], $currency) . '</span>';
            // inside the price box, after its last price: the box ends before the wrapper's next element
            $body = preg_replace('#(<div class="ba-blog-post-add-to-cart-price">(?:(?!</div>).)*?)(</div>)#s', '$1' . $line . '$2', $m[2], 1, $done);

            return $done ? $m[1] . $body : $m[0];
        }, $html) ?? $html;
    }
}
