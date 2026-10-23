<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Product data (JSON-LD): the Product block of a Gridbox product page built from the product itself,
 * in place of the one Gridbox fills from a schema template.
 *
 * Gridbox's templates put the values in as they are: a line break or a quotation mark in the
 * introduction breaks the whole block (Google drops it), the product without a price gets "price": "0"
 * with InStock, the default template says UsedCondition and has an empty description, and a brand from
 * a link field becomes "index.php?option=com_gridbox&…". Here the block is made with json_encode from
 * the product's data: no offer without a price (a price range when only its versions have prices),
 * NewCondition, the introduction (or the meta description) as the description, the brand from the
 * product's field, mpn from the SKU, all the pictures of its gallery, the VAT status of the store's
 * prices, and the availability from a field of the product (its delivery time) by the rules of the
 * settings. Keys this tool does not make (gtin, aggregateRating…) are kept from Gridbox's block when it
 * could be read.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class ProductSchema
{
    /** the keys made here; the others of Gridbox's block are kept */
    public const OWN = ['@context', '@type', 'name', 'image', 'description', 'brand', 'sku', 'mpn', 'offers', 'url'];

    /** labels of a field that holds the brand, when no field is chosen */
    private const BRAND_LABEL = '/producent|producer|manufacturer|marka|brand|hersteller/i';

    /** the availability values of schema.org that Google reads */
    public const AVAILABILITY = ['InStock', 'BackOrder', 'OutOfStock', 'PreOrder', 'MadeToOrder', 'LimitedAvailability'];

    /** at most this many characters of the description */
    private const MAX_DESCRIPTION = 5000;

    // ---------------------------------------------------------------- settings

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('shop_jsonld', 0);
    }

    /** mpn from the SKU: none, the whole SKU, or the part after its last "/" (SONE/WAPRZPN30 → WAPRZPN30) */
    public static function mpnMode(Registry $params): string
    {
        $mode = (string) $params->get('shop_jsonld_mpn', 'none');

        return \in_array($mode, ['none', 'sku', 'after_slash'], true) ? $mode : 'none';
    }

    /** The field the availability is read from (its id), 0 for none */
    public static function availabilityField(Registry $params): int
    {
        return max(0, (int) $params->get('shop_jsonld_avail_field', 0));
    }

    /**
     * The rules of availability: "text = Value" per line, the text found in the field (letter case
     * ignored) gives the value; the first rule that matches wins. Lines with an unknown value are left out.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function availabilityRules(Registry $params): array
    {
        $rules = [];
        foreach (preg_split('/\R/', (string) $params->get('shop_jsonld_avail_rules', '')) ?: [] as $line) {
            if (!preg_match('/^\s*(.+?)\s*=>?\s*([A-Za-z]+)\s*$/u', $line, $m)) {
                continue;
            }
            foreach (self::AVAILABILITY as $value) {
                if (strcasecmp($value, $m[2]) === 0) {
                    $rules[] = [$m[1], $value];
                }
            }
        }

        return $rules;
    }

    /** The availability of a product no rule matched: one of AVAILABILITY, or '' to keep Gridbox's */
    public static function availabilityDefault(Registry $params): string
    {
        $value = (string) $params->get('shop_jsonld_avail_default', '');

        return \in_array($value, self::AVAILABILITY, true) ? $value : '';
    }

    /**
     * The availability of a product: the value of the first rule whose text is in the field's text, else
     * the default, else Gridbox's.
     */
    public static function availability(string $text, array $rules, string $default, string $gridbox): string
    {
        foreach ($rules as [$needle, $value]) {
            if ($text !== '' && mb_stripos($text, $needle) !== false) {
                return 'https://schema.org/' . $value;
            }
        }
        if ($default !== '') {
            return 'https://schema.org/' . $default;
        }

        return $gridbox !== '' ? $gridbox : 'https://schema.org/InStock';
    }

    // ---------------------------------------------------------------- the values

    /**
     * The field of the app that holds the brand: the one chosen (its id), else the first url, text or
     * select field whose label names a producer or brand.
     *
     * @param array<int, array{id: int, type: string, label: string}> $fields the fields of the product's app
     */
    public static function brandField(array $fields, string $chosen): ?int
    {
        if ((int) $chosen > 0) {
            foreach ($fields as $f) {
                if ((int) $f['id'] === (int) $chosen) {
                    return (int) $f['id'];
                }
            }

            return null;
        }
        foreach ($fields as $f) {
            if (\in_array($f['type'], ['url', 'text', 'select', 'radio'], true) && preg_match(self::BRAND_LABEL, (string) $f['label'])) {
                return (int) $f['id'];
            }
        }

        return null;
    }

    /** The text of a field value: the label of a link field ({"label":"Sonel","link":…}), else the value */
    public static function fieldText(string $type, string $value): string
    {
        if ($type === 'url') {
            $json = json_decode($value, true);
            $value = \is_array($json) ? (string) ($json['label'] ?? '') : '';
        }

        return self::text($value, 200);
    }

    public static function mpn(string $sku, string $mode): ?string
    {
        $sku = trim($sku);
        if ($sku === '' || $mode === 'none') {
            return null;
        }
        if ($mode === 'after_slash' && str_contains($sku, '/')) {
            $sku = trim(substr($sku, strrpos($sku, '/') + 1));
        }

        return $sku === '' ? null : $sku;
    }

    /** Plain text: tags out, entities decoded, white space collapsed, cut at a word */
    public static function text(string $html, int $max = self::MAX_DESCRIPTION): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', ' $0', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
            $cut  = mb_strrpos($text, ' ');
            $text = rtrim($cut > $max * 0.8 ? mb_substr($text, 0, $cut) : $text, ' ,.;:') . '…';
        }

        return $text;
    }

    /** A price of Gridbox ("3999", "3999.50", "3 999,50") as a number, null for none or 0 */
    public static function amount(?string $price): ?float
    {
        $price = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $price));
        if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        return round((float) $price, 2);
    }

    /** What the product sells for: the sale price when it is set and lower, as Gridbox shows it */
    public static function price(?string $price, ?string $sale): ?float
    {
        $regular = self::amount($price);
        $reduced = self::amount($sale);

        return $reduced !== null && ($regular === null || $reduced < $regular) ? $reduced : $regular;
    }

    /**
     * The offer: one price, a range of the versions' prices (the product has none of its own), or none
     * (price on request: no offer rather than a price of 0).
     *
     * @param array $p ['price', 'sale', 'variations' (Gridbox's: id => {price, sale_price}), 'currency', 'url', 'availability', 'condition', 'vat' (true included, false excluded, null unknown)]
     */
    public static function offers(array $p): ?array
    {
        $own    = self::price($p['price'] ?? null, $p['sale'] ?? null);
        $prices = [];
        foreach ((array) ($p['variations'] ?? []) as $v) {
            if (\is_array($v) && ($amount = self::price($v['price'] ?? null, $v['sale_price'] ?? null)) !== null) {
                $prices[] = $amount;
            }
        }
        $base = ['priceCurrency' => (string) $p['currency']];
        if ($own === null && !$prices) {
            return null;
        }
        if ($own !== null) {
            $offer = ['@type' => 'Offer', 'url' => (string) $p['url']] + $base + ['price' => self::number($own)];
        } else {
            sort($prices);
            $offer = ['@type' => 'AggregateOffer', 'url' => (string) $p['url']] + $base
                + ['lowPrice' => self::number($prices[0]), 'highPrice' => self::number(end($prices)), 'offerCount' => \count($prices)];
        }
        $offer['availability']  = (string) ($p['availability'] ?? 'https://schema.org/InStock');
        $offer['itemCondition'] = (string) ($p['condition'] ?? 'https://schema.org/NewCondition');
        if (isset($p['vat']) && $own !== null) {
            $offer['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'price' => self::number($own), 'priceCurrency' => (string) $p['currency'],
                'valueAddedTaxIncluded' => (bool) $p['vat']];
        }

        return $offer;
    }

    private static function number(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * The Product: the values of the product over the keys of Gridbox's block (kept: those not made here).
     *
     * @param array      $d        ['name', 'url', 'images' (absolute), 'description', 'brand', 'sku', 'mpn', 'offers']
     * @param array|null $original Gridbox's Product, when it could be read
     */
    public static function build(array $d, ?array $original): array
    {
        $product = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => (string) $d['name'], 'url' => (string) $d['url']];
        $images  = array_values(array_unique(array_filter((array) ($d['images'] ?? []))));
        if ($images) {
            $product['image'] = \count($images) === 1 ? $images[0] : $images;
        }
        foreach (['description', 'sku', 'mpn'] as $key) {
            if ((string) ($d[$key] ?? '') !== '') {
                $product[$key] = (string) $d[$key];
            }
        }
        if (($d['brand'] ?? '') !== '') {
            $product['brand'] = ['@type' => 'Brand', 'name' => (string) $d['brand']];
        }
        if (!empty($d['offers'])) {
            $product['offers'] = $d['offers'];
        }
        foreach ((array) $original as $key => $value) {
            if (!\in_array($key, self::OWN, true)) {
                $product[$key] = $value;
            }
        }

        return $product;
    }

    // ---------------------------------------------------------------- the page

    /** The JSON-LD of a Product, as it goes into the page (no "</" can end the script early) */
    public static function script(array $product): string
    {
        $json = json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);

        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }

    /**
     * The Product blocks of a page: [whole <script> tag, its Product or null when it could not be read].
     * A block is a Product when its @type is Product, or, unreadable, when it says so in its text.
     *
     * @return array<int, array{0: string, 1: ?array}>
     */
    public static function find(string $html): array
    {
        preg_match_all('#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m, PREG_SET_ORDER);
        $found = [];
        foreach ($m as [$tag, $json]) {
            $data = json_decode(trim($json), true);
            if (\is_array($data)) {
                if (($data['@type'] ?? null) === 'Product') {
                    $found[] = [$tag, $data];
                }
            } elseif (preg_match('/"@type"\s*:\s*"Product"/', $json)) {
                $found[] = [$tag, null];
            }
        }

        return $found;
    }

    /** The page with its Product blocks replaced by the one of MerTools (added before </head> when it had none) */
    public static function replace(string $html, string $script): string
    {
        $blocks = self::find($html);
        if (!$blocks) {
            $pos = stripos($html, '</head>');

            return $pos === false ? $html : substr_replace($html, $script . "\n", $pos, 0);
        }
        $first = true;
        foreach ($blocks as [$tag]) {
            $pos = strpos($html, $tag);
            if ($pos !== false) {
                $html  = substr_replace($html, $first ? $script : '', $pos, \strlen($tag));
                $first = false;
            }
        }

        return $html;
    }
}
