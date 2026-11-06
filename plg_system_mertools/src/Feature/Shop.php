<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Shop: the Discount % field of the product editor, price on request in the lists, the product data (JSON-LD) of a
 * product page and Omnibus — the price history (after each change sent to Gridbox by someone who may edit
 * it, hourly and when a Gridbox sale starts or ends) and the line of the lowest price before a reduction on
 * the finished page; the badges of a product on the picture of its page (Badges).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\Badges;
use Merserwis\Plugin\System\MerTools\Tool\Feed;
use Merserwis\Plugin\System\MerTools\Tool\History;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\OmnibusStore;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;
use Merserwis\Plugin\System\MerTools\Tool\ProductSchema;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\StoreCurrency;

final class Shop extends Feature
{
    /**
     * Product data (JSON-LD): on a product page of a Gridbox shop app, the Product block made from the
     * product (ProductSchema) in place of Gridbox's — no offer without a price, the price as a guest is shown
     * it (Gridbox's own sales included), NewCondition, the brand of
     * the product's producer field, the description, the pictures of its gallery, mpn, the VAT status, the
     * availability from a field of the product by the rules of the settings.
     */
    public function productSchema(string $html): string
    {
        $input = $this->plugin->app()->getInput();
        $id    = $input->getInt('id', 0);
        if ($id <= 0 || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'page') {
            return $html;
        }
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery('SELECT p.title, p.intro_text, p.meta_description, p.intro_image, p.app_id, p.page_category, a.type, d.price, d.sale_price, d.sku, d.variations FROM '
                . $db->quoteName('#__gridbox_pages', 'p') . ' JOIN ' . $db->quoteName('#__gridbox_app', 'a') . ' ON a.id = p.app_id LEFT JOIN '
                . $db->quoteName('#__gridbox_store_product_data', 'd') . ' ON d.product_id = p.id WHERE p.id = ' . $id, 0, 1)->loadAssoc();
            if (!$row || $row['type'] !== 'products') {
                return $html;
            }
            $fields = [];
            foreach ((array) $db->setQuery('SELECT id, field_type, label, options FROM ' . $db->quoteName('#__gridbox_fields') . ' WHERE app_id = ' . (int) $row['app_id'])->loadAssocList() as $f) {
                $options  = json_decode((string) $f['options'], true) ?: [];
                $fields[] = ['id' => (int) $f['id'], 'type' => (string) $f['field_type'], 'label' => trim($f['label'] . ' ' . ($options['label'] ?? ''))];
            }
            $values = (array) $db->setQuery('SELECT field_id, field_type, value FROM ' . $db->quoteName('#__gridbox_page_fields') . ' WHERE page_id = ' . $id)->loadAssocList('field_id');
            $brand  = ProductSchema::brandField($fields, 'auto');
            $field  = ProductSchema::availabilityField($this->plugin->params);
            $root   = rtrim(Uri::root(), '/');
            // the folder of a site in a subfolder only once, when the path already has it
            $abs    = static fn (string $src): string => Feed::absolute($src, $root);
            $images = [$abs((string) $row['intro_image'])];
            foreach ($fields as $f) {
                if ($f['type'] === 'product-slideshow' && isset($values[$f['id']])) {
                    foreach ((array) json_decode((string) $values[$f['id']]['value'], true) as $img) {
                        if (\is_array($img) && empty($img['unpublish']) && ($img['img'] ?? '') !== '') {
                            $images[] = $abs((string) $img['img']);
                        }
                    }
                    break;
                }
            }
            $store    = $this->store();
            $tax      = (string) ($store['tax']['mode'] ?? '');
            $blocks   = ProductSchema::find($html);
            $original = $blocks[0][1] ?? null;
            $url      = $this->pageUrl($id, (string) $row['type'], (int) $row['app_id'], (int) $row['page_category']);
            $sale     = $row['sale_price'];
            $versions = json_decode((string) $row['variations'], true) ?: [];
            // Gridbox's own sales (Gridbox → Discounts) lower the prices of what has no sale price of its
            // own, as the page shows them to a guest: worked out as for Omnibus
            $shown    = self::newOmnibusStore()->shownPrices([['product_id' => $id, 'price' => $row['price'], 'sale_price' => $sale, 'variations' => $row['variations']]]);
            foreach ($shown as $key => $item) {
                if (!isset($item['via'])) {
                    continue;
                }
                if ((string) $key === (string) $id) {
                    $sale = (string) $item['sale'];
                } elseif (\is_array($versions[$key] ?? null)) {
                    $versions[$key]['sale_price'] = (string) $item['sale'];
                }
            }
            $product  = ProductSchema::build([
                'name'        => (string) $row['title'],
                'url'         => $url,
                'images'      => $images,
                'description' => ProductSchema::text((string) $row['intro_text']) ?: ProductSchema::text((string) $row['meta_description']),
                'brand'       => $brand !== null && isset($values[$brand]) ? ProductSchema::fieldText((string) $values[$brand]['field_type'], (string) $values[$brand]['value']) : '',
                'sku'         => trim((string) $row['sku']),
                'mpn'         => ProductSchema::mpn((string) $row['sku'], ProductSchema::mpnMode($this->plugin->params)),
                'offers'      => ProductSchema::offers([
                    'price'        => $row['price'],
                    'sale'         => $sale,
                    'variations'   => $versions,
                    // the store's default currency; a store without one keeps what Gridbox's own block said
                    'currency'     => StoreCurrency::code($store) ?: (string) ($original['offers']['priceCurrency'] ?? ($original['offers'][0]['priceCurrency'] ?? '')),
                    'url'          => $url,
                    'availability' => ProductSchema::availability($field > 0 && isset($values[$field]) ? ProductSchema::fieldText((string) $values[$field]['field_type'], (string) $values[$field]['value']) : '',
                        ProductSchema::availabilityRules($this->plugin->params), ProductSchema::availabilityDefault($this->plugin->params), (string) ($original['offers']['availability'] ?? '')),
                    'vat'          => $tax === 'excl' ? false : ($tax === 'incl' ? true : null),
                ]),
            ], $original);
        } catch (\Throwable $e) {
            return $html;
        }

        return ProductSchema::replace($html, ProductSchema::script($product));
    }

    /**
     * The badges of the product (Gridbox's, as its tiles show them) on the picture of its page: into its
     * slideshows and galleries, with the script that puts them back when a variation swaps the pictures.
     */
    public function productBadges(string $html): string
    {
        $input = $this->plugin->app()->getInput();
        $id    = $input->getInt('id', 0);
        if ($id <= 0 || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'page'
            || (!str_contains($html, 'ba-item-product-slideshow') && !str_contains($html, 'ba-item-product-gallery'))) {
            return $html;
        }
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $type = (string) $db->setQuery('SELECT a.type FROM ' . $db->quoteName('#__gridbox_pages', 'p') . ' JOIN ' . $db->quoteName('#__gridbox_app', 'a')
                . ' ON a.id = p.app_id WHERE p.id = ' . $id, 0, 1)->loadResult();
            if (!\in_array($type, ['products', 'booking'], true)) {
                return $html;
            }
            $data = $db->setQuery('SELECT price, sale_price, variations, stock, ' . $db->quoteName('min') . ' FROM ' . $db->quoteName('#__gridbox_store_product_data')
                . ' WHERE product_id = ' . $id, 0, 1)->loadAssoc();
            if (!$data) {
                return $html;
            }
            $rows = (array) $db->setQuery('SELECT b.title, b.color, b.type FROM ' . $db->quoteName('#__gridbox_store_badges_map', 'bm') . ' JOIN '
                . $db->quoteName('#__gridbox_store_badges', 'b') . ' ON b.id = bm.badge_id WHERE bm.product_id = ' . $id . ' ORDER BY bm.order_list, bm.id')->loadAssocList();
            $variations = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__gridbox_store_product_variations_map') . ' WHERE product_id = ' . $id)->loadResult() > 0;
        } catch (\Throwable $e) {
            return $html;
        }
        // Gridbox's own words for it (its language, loaded on its pages)
        $lang = Factory::getApplication()->getLanguage();
        if (!$lang->hasKey('OUT_OF_STOCK')) {
            $lang->load('com_gridbox', JPATH_SITE);
        }
        $percents = null;
        if (Badges::sales($this->plugin->params) && \in_array('sale', array_column($rows, 'type'), true)) {
            // the percentage of the price shown (the store's sales, the version chosen), which Gridbox does not work out
            $percents = $this->salePercents([$id => $data])[$id] ?? null;
        }
        $badges = Badges::forProduct($rows, $data, $variations, Text::_('OUT_OF_STOCK'), $percents);
        if (!$badges) {
            return $html;
        }
        $new = Badges::inject($html, Badges::html($badges));
        if ($new === $html) {
            return $html;
        }
        $tail = '<script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-badges' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $pos  = strripos($new, '</body>');

        return $pos === false ? $new . $tail : substr($new, 0, $pos) . $tail . substr($new, $pos);
    }

    /**
     * The sale badges of the tiles of Gridbox's lists with the percentage of the price beside them: that
     * of the default version when the product has one (the tile shows its price), with the store's sales
     * (Gridbox → Discounts). Gridbox works it out from the product's own prices only ("- 0%").
     */
    public function tileSaleBadges(string $html): string
    {
        if (!str_contains($html, 'ba-blog-post-badge') || !($ids = PriceRequest::tileIds($html))) {
            return $html;
        }
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $in   = implode(',', array_map('intval', $ids));
            $rows = (array) $db->setQuery('SELECT d.product_id, d.price, d.sale_price, d.variations FROM ' . $db->quoteName('#__gridbox_store_product_data', 'd')
                . ' WHERE d.product_id IN (' . $in . ') AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__gridbox_store_badges_map', 'bm') . ' JOIN '
                . $db->quoteName('#__gridbox_store_badges', 'b') . ' ON b.id = bm.badge_id WHERE bm.product_id = d.product_id AND b.type = ' . $db->quote('sale') . ')')
                ->loadAssocList('product_id');
            $texts = [];
            foreach ($this->salePercents($rows) as $id => $percents) {
                $from = Badges::salePercent($rows[$id]['price'], $rows[$id]['sale_price']);
                $to   = $percents['texts'][$percents['shown']] ?? $from;
                if ($from !== $to) {
                    $texts[$id] = [$from, $to];
                }
            }
        } catch (\Throwable $e) {
            return $html;
        }

        return Badges::tileSales($html, $texts);
    }

    /**
     * The sale badge's text of each item of products (id => row with price, sale_price, variations) at
     * the price this visitor is shown: the store's sales for its access levels applied as Gridbox's
     * calculateSalePrice does (see Badges::percents()).
     *
     * @return array<int, array{texts: array<string, string>, shown: string}>
     */
    private function salePercents(array $rows): array
    {
        $items = [];
        foreach ($rows as $id => $row) {
            $items[] = ['product_id' => (int) $id, 'price' => $row['price'], 'sale_price' => $row['sale_price'], 'variations' => $row['variations'] ?? ''];
        }
        try {
            $levels = array_values(array_map('intval', Factory::getApplication()->getIdentity()?->getAuthorisedViewLevels() ?: []));
        } catch (\Throwable $e) {
            $levels = [];
        }

        return Badges::percents($rows, self::newOmnibusStore($levels ?: null)->shownPrices($items));
    }

    /**
     * Links to a product option: give Gridbox the option parameters of the address under their real
     * names (PHP turns "Zestawy Metrel MI 3155" into "Zestawy_Metrel_MI_3155"), so its own code selects
     * the option named in the link. After routing, so only Gridbox pages are touched.
     */
    public function optionLinks(): void
    {
        $app = $this->plugin->app();
        if (strtoupper((string) $app->getInput()->getMethod()) !== 'GET' || !(int) $this->plugin->params->get('shop_option_links', 1)) {
            return;
        }
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'page') {
            return;
        }

        $restored = ProductLinks::restoredParams((string) $input->server->getRaw('QUERY_STRING', ''), $_GET);
        foreach ($restored as $name => $value) {
            // Gridbox reads $input->get; anything that builds its own input from $_GET later sees them too
            $input->get->set($name, $value);
            $_GET[$name] = $value;
        }
    }

    /**
     * "Discount %" next to the sale price in the Gridbox editor of a product (mertools-discount.js): a
     * percentage sets the sale prices of the product and of its versions; Gridbox saves the prices.
     */
    public function discountScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!(int) $this->plugin->params->get('shop_discount_field', 1) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $this->plugin->loadLanguage();
        $currency = $this->plugin->storeCurrency();
        $cfg      = ['decimals' => (int) ($currency['decimals'] ?? 2), 'label' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_LABEL'),
            'placeholder' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_PLACEHOLDER'), 'hint' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_HINT')];
        $this->plugin->editorScript('discount', $cfg);
    }

    /**
     * Folding the fields in the Gridbox editor of a page of an app (mertools-fold.js): an eye next to
     * the name of a field hides the field down to its name; which fields are folded, this browser keeps.
     */
    public function foldScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!(int) $this->plugin->params->get('shop_field_fold', 1) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $this->plugin->loadLanguage();
        try {
            $folded = $this->foldedFields((int) $user->id);
        } catch (\Throwable $e) {
            $folded = null;
        }
        $this->plugin->editorScript('fold', ['hide' => Text::_('PLG_SYSTEM_MERTOOLS_FOLD_HIDE'), 'show' => Text::_('PLG_SYSTEM_MERTOOLS_FOLD_SHOW'),
            // the folds of the user's account; null: none kept there yet (the browser's are taken over)
            'folded' => $folded, 'user' => (int) $user->id, 'url' => Uri::root(true) . '/index.php', 'token' => Session::getFormToken()]);
    }

    /** the most fields whose fold an account keeps */
    public const MAX_FOLDS = 500;

    private const FOLD_KEY = 'mertools.foldedfields';

    /** @return string[]|null the keys of the fields the user folded, null when the account has none kept */
    private function foldedFields(int $userId): ?array
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $raw = $db->setQuery('SELECT profile_value FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . $userId . ' AND profile_key = '
            . $db->quote(self::FOLD_KEY))->loadResult();
        if (!\is_string($raw)) {
            return null;
        }
        $list = json_decode($raw, true);

        return \is_array($list) ? self::foldKeys($list) : [];
    }

    /** @return string[] the keys of fields in a list (Gridbox's: "price", "item-1594892018"…), each once */
    public static function foldKeys(array $list): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', array_filter($list, 'is_scalar')),
            static fn (string $key) => (bool) preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key))));

        return \array_slice($keys, 0, self::MAX_FOLDS);
    }

    /**
     * The fold of a field kept on the account of the user (POST mertools_fold=toggle, key, fold 1/0), or
     * the folds of the browser taken over by an account that keeps none yet (mertools_fold=import, list):
     * answers with the user's folds as they are now.
     */
    public function foldEndpoint(): void
    {
        $app    = $this->plugin->app();
        $input  = $app->getInput();
        $action = $input->getCmd('mertools_fold', '');
        if ($action === '') {
            return;
        }
        $user = $app->getIdentity();
        if (!\in_array($action, ['toggle', 'import'], true) || strtoupper((string) $input->getMethod()) !== 'POST' || !$user || $user->guest
            || !Session::checkToken('request')) {
            $this->plugin->sendJson(['success' => false], 403);
        }
        $userId = (int) $user->id;
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        // read and written under a lock: two folds at the same moment (two tabs) would otherwise lose one
        $lock = $db->quote('mertools.folds.' . $userId);
        try {
            if (!(int) $db->setQuery('SELECT GET_LOCK(' . $lock . ', 5)')->loadResult()) {
                $this->plugin->sendJson(['success' => false]);
            }
            try {
                $folded = $this->foldedFields($userId);
                if ($action === 'import') {
                    // only into an account that keeps none yet: another browser's folds are not overwritten
                    $list = json_decode($input->post->getString('list', '[]'), true);
                    $next = $folded ?? self::foldKeys(\is_array($list) ? $list : []);
                } else {
                    $key  = self::foldKeys([$input->post->getString('key', '')])[0] ?? '';
                    $next = array_values(array_diff($folded ?? [], [$key]));
                    if ($key !== '' && $input->post->getInt('fold', 1) === 1) {
                        $next = self::foldKeys(array_merge([$key], $next));
                    }
                }
                if ($next !== $folded) {
                    $db->setQuery('DELETE FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . $userId . ' AND profile_key = '
                        . $db->quote(self::FOLD_KEY))->execute();
                    $db->setQuery('INSERT INTO ' . $db->quoteName('#__user_profiles') . ' (user_id, profile_key, profile_value, ordering) VALUES (' . $userId
                        . ', ' . $db->quote(self::FOLD_KEY) . ', ' . $db->quote(json_encode($next)) . ', 0)')->execute();
                }
            } finally {
                $db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->loadResult();
            }
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false]);
        }
        $this->plugin->sendJson(['success' => true, 'folded' => $next]);
    }

    /**
     * Price on request: the list products with no price of their own but priced versions get
     * data-mt-choose, so the script asks for a model there (the lists carry no versions).
     */
    public function priceRequestTiles(string $html): string
    {
        $ids = PriceRequest::tileIds($html);
        if (!$ids) {
            return $html;
        }
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = $db->setQuery('SELECT product_id, price, variations FROM ' . $db->quoteName('#__gridbox_store_product_data')
                . ' WHERE product_id IN (' . implode(',', array_map('intval', $ids)) . ')')->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return $html;
        }

        return PriceRequest::markTiles($html, PriceRequest::chooseIds($rows));
    }

    private function omnibusStore(): OmnibusStore
    {
        return self::newOmnibusStore();
    }

    /** The price history, with the guest's access levels and the site's time zone (Gridbox's sales follow both). */
    /** @param ?int[] $levels the access levels whose sales count (null: a guest's) */
    public static function newOmnibusStore(?array $levels = null): OmnibusStore
    {
        try {
            $zone = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }

        return new OmnibusStore(Factory::getContainer()->get(DatabaseInterface::class), $levels ?? MerTools::guestLevels(), $zone);
    }

    /**
     * The moment the next Gridbox sale starts or ends, kept as the time of a file in the cache folder:
     * the prices are compared again at the first visit after it (one stat per request).
     */
    public static function omnibusNext(array $result): void
    {
        if (!empty($result['busy'])) {
            return;
        }
        $file = MerTools::cacheBase() . '/mertools-omnibus-next';
        if (!empty($result['next'])) {
            @touch($file, (int) $result['next']);
        } elseif (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Omnibus on a finished page: the separate plugin's script and lines off, MerTools' lines in —
     * on the product page one per item of the product on sale, in the lists under a sale price.
     */
    public function omnibusLines(string $html): string
    {
        $html = Omnibus::dropPluginLines(Omnibus::dropScript($html, Omnibus::SCRIPT));
        $input = $this->plugin->app()->getInput();
        $page  = $input->getCmd('option') === 'com_gridbox' && $input->getCmd('view') === 'page' ? $input->getInt('id') : 0;
        $tiles = Omnibus::lists($this->plugin->params) && str_contains($html, 'ba-blog-post-add-to-cart-sale-price-wrapper') ? PriceRequest::tileIds($html) : [];
        if ((!$page || !str_contains($html, 'ba-add-to-cart-price')) && !$tiles) {
            return $html;
        }
        try {
            // the history is in the store's default currency: in another one the lowest price is converted
            // as Gridbox converts the prices shown; a currency without a rate gets no line
            $shown = $this->shownCurrency();
            if ($shown === null) {
                return $html;
            }
            $this->plugin->loadLanguage();
            $store    = $this->omnibusStore();
            $template = Omnibus::template($this->plugin->params, $this->plugin->translate('PLG_SYSTEM_MERTOOLS_OMNIBUS_TEXT_DEFAULT'));
            $currency = $shown['format'] ?? $this->plugin->storeCurrency();
            $convert  = static fn (array $prices): array => $shown['format'] === null ? $prices
                : array_map(static fn ($price) => StoreCurrency::convert((float) $price, $shown['rate'], $currency), $prices);
            if ($page && str_contains($html, 'ba-add-to-cart-price')) {
                $lowest = [];
                foreach ($store->references([$page]) as $key => $ref) {
                    $lowest[$key] = $ref['lowest'];
                }
                $html = Omnibus::productLines($html, $page, $convert($lowest), $template, $currency);
            }
            if ($tiles) {
                $html = Omnibus::listLines($html, $convert($store->listReferences($tiles)), $template, $currency);
            }
        } catch (\Throwable $e) {
            // no history yet (tables not made): the page as Gridbox made it
        }

        return $html;
    }

    /**
     * The currency the prices are shown in: ['format' => null] for the store's default one (that of the
     * history), else its format and rate (StoreCurrency::shown()), or null when it has no rate. Gridbox
     * shows another one after a choice in its currency switcher, or on a multilingual site for a language
     * that has one.
     *
     * @return array{rate: float, format: ?array}|null
     */
    private function shownCurrency(): ?array
    {
        $app    = $this->plugin->app();
        $chosen = (string) $app->getInput()->cookie->getString('gridbox-currency', '');
        $tag    = Associations::isEnabled() ? $app->getLanguage()->getTag() : null;
        if ($chosen === '' && $tag === null) {
            return ['rate' => 1.0, 'format' => null];
        }
        $store = $this->store();
        if (StoreCurrency::activeCode($store, $chosen, $tag) === StoreCurrency::code($store)) {
            return ['rate' => 1.0, 'format' => null];
        }
        // Gridbox's automatic rates: only with a key of the service (else it keeps the rates of the list)
        $rates = [];
        if (!empty($store['currencies']['auto']) && trim($this->gridboxApi('exchangerates')) !== '') {
            $data  = json_decode($this->gridboxApi('exchangerates_data'), true);
            $rates = \is_array($data['rates'] ?? null) ? $data['rates'] : [];
        }
        $shown = StoreCurrency::shown($store, $chosen, $tag, $rates);

        return $shown ? ['rate' => $shown['rate'], 'format' => $shown['format']] : null;
    }

    /** The "key" of a service of Gridbox's settings (#__gridbox_api), '' when there is none. */
    private function gridboxApi(string $service): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (string) $db->setQuery('SELECT ' . $db->quoteName('key') . ' FROM ' . $db->quoteName('#__gridbox_api') . ' WHERE service = '
            . $db->quote($service))->loadResult();
    }

    /** The settings of Gridbox's store (#__gridbox_api, service "store"), read once. */
    private function store(): array
    {
        static $store = null;
        if ($store === null) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $store = json_decode((string) $db->setQuery('SELECT ' . $db->quoteName('key') . ' FROM ' . $db->quoteName('#__gridbox_api')
                . ' WHERE service = ' . $db->quote('store'))->loadResult(), true);
            $store = \is_array($store) ? $store : [];
        }

        return $store;
    }

    /**
     * The address of a Gridbox page in its data: the address of the request; on a site without search
     * engine friendly URLs (only index.php, the page named by the parameters) the page's own link.
     */
    private function pageUrl(int $id, string $type, int $appId, int $category): string
    {
        if ((int) $this->plugin->app()->get('sef', 1)) {
            return Uri::current();
        }

        return $this->plugin->gridboxLink('getGridboxPageLinks', [$id, $type, $appId, $category],
            'index.php?option=com_gridbox&view=page&blog=' . $appId . '&category=' . $category . '&id=' . $id);
    }

    /**
     * After anything sent to Gridbox by someone who may change prices: the prices compared with the
     * history (at shutdown). A customer's requests on the site (the cart, an order, the currency, a
     * review) change none and are left to the hourly check.
     */
    public function omnibusWatch(): void
    {
        if (!Omnibus::enabled($this->plugin->params)) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !$user || $user->guest) {
            return;
        }
        // on the site: the editor's save (of a page the user may edit) or someone who may edit Gridbox's pages
        if ($app->isClient('site') && !\in_array(strtolower((string) $input->getCmd('task', '')), History::TASKS, true) && !self::editsGridbox($user)) {
            return;
        }
        register_shutdown_function(static function (): void {
            try {
                self::omnibusNext(self::newOmnibusStore()->sync());
            } catch (\Throwable $e) {
            }
        });
    }

    /** May the user edit Gridbox's pages (and so the prices of its products)? */
    private static function editsGridbox($user): bool
    {
        foreach (['core.admin', 'core.manage', 'core.create', 'core.edit', 'core.edit.own', 'core.edit.state'] as $action) {
            if ($user->authorise($action, 'com_gridbox')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Once an hour, and when a Gridbox sale starts or ends: prices changed past Gridbox (an import, the
     * database directly, a sale's dates); kept pages refreshed when they did.
     */
    public function hourlyOmnibus(): void
    {
        if (!Omnibus::enabled($this->plugin->params)) {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-omnibus-tick';
        $next = MerTools::cacheBase() . '/mertools-omnibus-next';
        $due  = is_file($next) && (int) @filemtime($next) <= time();
        if (!$due && ((is_file($gate) && (int) @filemtime($gate) > time() - 3600) || !@touch($gate))) {
            return;
        }
        if ($due) {
            // the next visitors do not start the same work
            @unlink($next);
            @touch($gate);
        }
        try {
            $result = $this->omnibusStore()->sync();
            self::omnibusNext($result);
            if (($result['changed'] ?? 0) > 0 && ($this->plugin->speed()->pageCacheOn() || $this->plugin->speed()->dataCacheOn())) {
                $this->plugin->speed()->pageCache()->purge(false);
            }
        } catch (\Throwable $e) {
        }
    }
}
