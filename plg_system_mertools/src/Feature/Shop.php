<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Shop: the Discount % field of the product editor, price on request in the lists, the category description of
 * category pages and Omnibus — the price history (after each change sent to Gridbox, hourly and when a Gridbox
 * sale starts or ends) and the line of the lowest price before a reduction on the finished page.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\Categories;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\OmnibusStore;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;

final class Shop extends Feature
{
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

    /** A category page: the description of the category in the description block left as a placeholder. */
    public function categoryDescription(string $html): string
    {
        $input = $this->plugin->app()->getInput();
        $id    = $input->getInt('id');
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'blog' || $id <= 0) {
            return $html;
        }
        try {
            $db          = Factory::getContainer()->get(DatabaseInterface::class);
            $description = (string) $db->setQuery('SELECT ' . $db->quoteName('description') . ' FROM ' . $db->quoteName('#__gridbox_categories')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->loadResult();
        } catch (\Throwable $e) {
            return $html;
        }

        return Categories::description($html, $description);
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

    /** After anything sent to Gridbox by a logged-in user: the prices compared with the history (at shutdown). */
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
        register_shutdown_function(static function (): void {
            try {
                self::omnibusNext(self::newOmnibusStore()->sync());
            } catch (\Throwable $e) {
            }
        });
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
