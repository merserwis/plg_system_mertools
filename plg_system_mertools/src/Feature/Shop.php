<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Shop: the Discount % field of the product editor, price on request in the lists, the product data (JSON-LD) of a
 * product page and Omnibus — the price history (after each change sent to Gridbox by someone who may edit
 * it, hourly and when a Gridbox sale starts or ends) and the line of the lowest price before a reduction on
 * the finished page.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
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
        $tail = '<script type="application/json" id="mertools-discount-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-discount' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
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
    public static function newOmnibusStore(): OmnibusStore
    {
        try {
            $zone = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }

        return new OmnibusStore(Factory::getContainer()->get(DatabaseInterface::class), MerTools::guestLevels(), $zone);
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
            if (!$this->defaultCurrencyShown()) {
                // the history is in the store's default currency: no line rather than one in another currency
                return $html;
            }
            $this->plugin->loadLanguage();
            $store    = $this->omnibusStore();
            $template = Omnibus::template($this->plugin->params, $this->plugin->translate('PLG_SYSTEM_MERTOOLS_OMNIBUS_TEXT_DEFAULT'));
            $currency = $this->plugin->storeCurrency();
            if ($page && str_contains($html, 'ba-add-to-cart-price')) {
                $lowest = [];
                foreach ($store->references([$page]) as $key => $ref) {
                    $lowest[$key] = $ref['lowest'];
                }
                $html = Omnibus::productLines($html, $page, $lowest, $template, $currency);
            }
            if ($tiles) {
                $html = Omnibus::listLines($html, $store->listReferences($tiles), $template, $currency);
            }
        } catch (\Throwable $e) {
            // no history yet (tables not made): the page as Gridbox made it
        }

        return $html;
    }

    /**
     * Are the prices shown in the store's default currency (that of the history)? Gridbox shows another
     * one after a choice in its currency switcher, or on a multilingual site for a language that has one.
     */
    private function defaultCurrencyShown(): bool
    {
        $app    = $this->plugin->app();
        $chosen = (string) $app->getInput()->cookie->getString('gridbox-currency', '');
        $tag    = Associations::isEnabled() ? $app->getLanguage()->getTag() : null;
        if ($chosen === '' && $tag === null) {
            return true;
        }
        $store = $this->store();

        return StoreCurrency::activeCode($store, $chosen, $tag) === StoreCurrency::code($store);
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
