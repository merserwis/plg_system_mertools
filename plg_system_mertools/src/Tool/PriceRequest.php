<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Price on request: a product without a price (0 or empty in Gridbox) shows a text such as "On
 * request" where its price would be — on the product page and in the product lists and sliders.
 * A product without a price of its own whose versions/models (a Gridbox option list) do have
 * prices asks for a model instead ("Choose a model from the list"), until one is chosen.
 *
 * Gridbox prints no price for such a product (an empty price box, and its script empties the box
 * again when a version without a price is chosen), so the place stays blank. The text is put in by
 * media/js/mertools-price.js in the browser, because only there are the settings of each Gridbox
 * element known (window.gridboxItems): a list or slider whose prices are switched off in Gridbox
 * ("store": {"price": false}) has an empty box for every product, priced or not, and is left alone.
 * On the product page the script reads the versions and the chosen one from Gridbox's own data;
 * in the lists, which carry no versions, the server marks the products with priced versions
 * (data-mt-choose on the product). Lists loaded later and a change of version are followed too.
 *
 * The separate script that did this before (OLD_SCRIPT) is taken off the page, so nothing has to
 * be deleted from the server. Also hidden: a "lowest price of the last 30 days" line of an Omnibus plugin (.omnibus_info) that
 * says 0,00 — the price of a product on request, not a price anyone paid.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class PriceRequest
{
    /** the separate script that did this before (on merserwis.pl, put into the Omnibus plugin) */
    public const OLD_SCRIPT = '/plugins/system/omnibus/assets/scprice.js';

    /** at most this many products of one page are looked up */
    private const MAX_IDS = 500;

    /** the opening tag of a product in a list or slider (the whole class name: not ba-store-app-product-…) */
    private const TILE_TAG = '/<(?:li|div)\b[^>]*\sclass="(?:[^"]*\s)?ba-store-app-product(?=[\s"])[^>]*>/';

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('price_request', 0);
    }

    /** The text as set, else the default of the site language ("" when neither is there). */
    public static function text(Registry $params, string $default): string
    {
        $text = trim((string) $params->get('price_request_text', ''));

        return $text !== '' ? $text : $default;
    }

    /** The text of a product whose models have prices, as set, else the default of the site language. */
    public static function chooseText(Registry $params, string $default): string
    {
        $text = trim((string) $params->get('price_request_choose', ''));

        return $text !== '' ? $text : $default;
    }

    /**
     * The texts in a size of their own (0 = the size Gridbox gives the price), never broken in two,
     * and an Omnibus line of 0,00 hidden (!important: its script shows the lines with jQuery).
     */
    public static function css(Registry $params): string
    {
        $size  = (int) $params->get('price_request_size', 18);
        $value = '.mt-price-request .ba-blog-post-add-to-cart-price-value,.mt-price-request .ba-add-to-cart-price-value';
        $css   = $value . '{white-space:nowrap}.omnibus_info.mt-price-zero{display:none!important}';
        if ($size > 0) {
            // on the box too: its line keeps the height of Gridbox's (larger) price font otherwise
            $css .= '.mt-price-request,' . $value . '{font-size:' . min($size, 200) . 'px}';
        }

        return $css;
    }

    public static function jsConfig(Registry $params, string $default, string $chooseDefault): array
    {
        return ['text' => self::text($params, $default), 'choose' => self::chooseText($params, $chooseDefault)];
    }

    /**
     * The ids of the products in the lists and sliders of a finished page (Gridbox marks each with
     * the class ba-store-app-product and data-id).
     *
     * @return int[]
     */
    public static function tileIds(string $html): array
    {
        if (!preg_match_all(self::TILE_TAG, $html, $tags)) {
            return [];
        }
        $ids = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\sdata-id="(\d+)"/', $tag, $m)) {
                $ids[(int) $m[1]] = true;
            }
        }

        return \array_slice(array_keys($ids), 0, self::MAX_IDS);
    }

    /**
     * The products with no price of their own (0 or empty) and at least one version with a price,
     * from rows of #__gridbox_store_product_data (product_id, price, variations as Gridbox keeps it).
     *
     * @return int[]
     */
    public static function chooseIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ((float) str_replace(',', '.', (string) ($row['price'] ?? '')) > 0) {
                continue;
            }
            $versions = json_decode((string) ($row['variations'] ?? ''), true);
            if (!\is_array($versions)) {
                continue;
            }
            foreach ($versions as $version) {
                if (\is_array($version) && (float) str_replace(',', '.', (string) ($version['price'] ?? '')) > 0) {
                    $ids[] = (int) $row['product_id'];
                    break;
                }
            }
        }

        return $ids;
    }

    /** data-mt-choose on the list products of these ids (once; the rest of the page as it was). */
    public static function markTiles(string $html, array $ids): string
    {
        if (!$ids) {
            return $html;
        }
        $set = array_fill_keys(array_map('intval', $ids), true);

        return preg_replace_callback(self::TILE_TAG, static function (array $m) use ($set): string {
            $tag = $m[0];
            if (str_contains($tag, 'data-mt-choose') || !preg_match('/\sdata-id="(\d+)"/', $tag, $id) || !isset($set[(int) $id[1]])) {
                return $tag;
            }

            return substr($tag, 0, -1) . ' data-mt-choose>';
        }, $html) ?? $html;
    }
}
