<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * MerTools for Gridbox — a set of fixes and optimisations for Balbooa Gridbox that cannot be done
 * from Gridbox's own settings, each a tool that can be turned on or off. The Gridbox core files are
 * never touched, so Gridbox updates install cleanly. Every tool is kept small and independent.
 *
 * Tools in this version:
 *  - Canonical URL (collapse duplicate slashes): "/oferta", "//oferta" and "//////oferta" all serve
 *    one page with status 200; this redirects every variant to the single clean address (SEO).
 *    Runs in onAfterInitialise, before routing, so it does not interfere with Gridbox's own routing.
 *  - Dark mode: an elegant dark palette (never pure black) built from Gridbox's own CSS variables,
 *    with a toggle button in the header, several palettes and the option to keep the Gridbox accent.
 *    Added in onBeforeCompileHead (CSS + a no-flash inline script + the toggle script).
 *  - Page not found (404): redirect to a chosen page (home by default) instead of the error page,
 *    without editing the template's error.php. Runs on onError, after System - Redirect.
 *  - Old shop carts: Gridbox never removes a cart; this removes the carts nobody can open any more
 *    (empty ones at once, abandoned ones after N days without use) — by hand, daily or above a limit.
 *    Watching in onAfterInitialise, the hourly job in onAfterRespond, the buttons in onAfterRoute.
 *  - Page speed panel: PageSpeed Insights measurements of chosen pages with a baseline, so every
 *    change can be compared with the state before it (Speed + MtspeedField + mertools-speed.js).
 *  - Faster first view (Speedup): real images at once with their size, the main product photo first,
 *    a YouTube background after the first interaction, marketing scripts at the first interaction.
 *    In onAfterRender, after Gridbox has finished the page (lowest priority).
 *  - Links to a product option: a link with a chosen product option ("?Zestawy+Metrel+MI+3155=…")
 *    opens the product with that option selected. Runs in onAfterRoute, on Gridbox pages only.
 *  - Page cache for guests (PageCache): the finished page of a guest is kept and the next guests get
 *    it at once, also Gridbox's two data requests before a page is shown. Served in onAfterRoute,
 *    kept in onAfterRespond, emptied after any change (a shutdown function, as Gridbox ends its
 *    save requests with exit).
 */

namespace Merserwis\Plugin\System\MerTools\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Merserwis\Plugin\System\MerTools\Tool\CartCleaner;
use Merserwis\Plugin\System\MerTools\Tool\DarkMode;
use Merserwis\Plugin\System\MerTools\Tool\ImageSize;
use Merserwis\Plugin\System\MerTools\Tool\Layout;
use Merserwis\Plugin\System\MerTools\Tool\NotFound;
use Merserwis\Plugin\System\MerTools\Tool\PageCache;
use Merserwis\Plugin\System\MerTools\Tool\Phones;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\Speed;
use Merserwis\Plugin\System\MerTools\Tool\Speedup;
use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

final class MerTools extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '0.0.25';

    /** marketing scripts delayed by default (address or code contains) */
    public const SCRIPT_PATTERNS = "googletagmanager.com\nfbq(\nconnect.facebook.net\nclarity.ms\nhotjar.com\nelfsightcdn.com\ncloudflareinsights.com";

    protected $autoloadLanguage = true;

    /** a page of this request that may be kept: key, address, start time; 'ok' once the page qualified */
    private ?array $pageToStore = null;

    public static function getSubscribedEvents(): array
    {
        return [
            // before routing, so the clean address is served before Gridbox or the SEF router act
            'onAfterInitialise'   => ['onAfterInitialise', Priority::HIGH],
            // early, so a kept page is sent before other plugins do their work for nothing
            'onAfterRoute'        => ['onAfterRoute', Priority::HIGH],
            // after Gridbox has finished the page (its lazy loading and deferred loading)
            'onAfterRender'       => ['onAfterRender', Priority::MIN],
            // after System - Redirect, so a redirect set for a single address in Joomla still wins
            'onError'             => ['onError', Priority::LOW],
            // after the response has gone to the visitor: the hourly job of the cart clean-up
            'onAfterRespond'      => ['onAfterRespond', Priority::MIN],
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onExtensionAfterSave' => 'onExtensionAfterSave',
        ];
    }

    /**
     * Canonical URL: redirect a request whose path has runs of slashes to the single clean address.
     */
    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();
        if ($app->isClient('site')) {
            $this->trackCart();
        }

        // front-end only; a GET only (a 301 on a POST would drop the body); not inside the installer
        if (!$app->isClient('site') || strtoupper((string) $app->getInput()->getMethod()) !== 'GET') {
            return;
        }
        if (!(int) $this->params->get('url_collapse_slashes', 1)) {
            return;
        }

        $requestUri = (string) $app->getInput()->server->getString('REQUEST_URI', '');
        $target     = UrlNormalizer::canonicalTarget($requestUri);
        if ($target === null) {
            return;
        }

        // same scheme, host and port as the request: the canonical path can never leave this site
        $base = Uri::getInstance()->toString(['scheme', 'host', 'port']);
        $code = (int) $this->params->get('url_redirect_code', 301) === 302 ? 302 : 301;

        $app->redirect($base . $target, $code);
    }

    /**
     * Links to a product option: give Gridbox the option parameters of the address under their real
     * names (PHP turns "Zestawy Metrel MI 3155" into "Zestawy_Metrel_MI_3155"), so its own code selects
     * the option named in the link. After routing, so only Gridbox pages are touched.
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        if ($app->isClient('administrator') || $app->isClient('site')) {
            $this->purgeOnChange();
        }
        if ($app->isClient('administrator')) {
            $this->handleAdministratorAction();

            return;
        }
        if ($app->isClient('site')) {
            $this->servePageCache();
        }
        if (!$app->isClient('site') || strtoupper((string) $app->getInput()->getMethod()) !== 'GET'
            || !(int) $this->params->get('shop_option_links', 1)) {
            return;
        }
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'page') {
            return;
        }

        $restored = ProductLinks::restoredParams((string) $input->server->getRaw('QUERY_STRING', ''), $input->get->getArray());
        foreach ($restored as $name => $value) {
            // Gridbox reads $input->get; anything that builds its own input from $_GET later sees them too
            $input->get->set($name, $value);
            $_GET[$name] = $value;
        }
    }

    // ---------------------------------------------------------------- page cache for guests

    private function pageCacheOn(): bool
    {
        return (bool) (int) $this->params->get('speed_cache', 0);
    }

    private function dataCacheOn(): bool
    {
        return (bool) (int) $this->params->get('speed_cache_data', 1);
    }

    private function pageCache(): PageCache
    {
        return new PageCache(self::cacheBase() . '/' . PageCache::DIR);
    }

    private function cacheTtl(): int
    {
        return max(5, min(1440, (int) $this->params->get('speed_cache_ttl', 240))) * 60;
    }

    /**
     * A request that changes what pages show (a save in Joomla or in the Gridbox editor, an order, a
     * comment): the kept pages go once it has finished — Gridbox ends its requests with exit, so after
     * the response, at shutdown, and only then (emptied earlier, a guest could keep the old page again).
     */
    private function purgeOnChange(): void
    {
        if (!$this->pageCacheOn() && !$this->dataCacheOn()) {
            return;
        }
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (!PageCache::changes((string) $input->getMethod(), !$user || $user->guest, (string) $input->getCmd('option', ''), (string) $input->getString('task', ''))) {
            return;
        }
        $cache = $this->pageCache();
        register_shutdown_function(static function () use ($cache): void {
            try {
                $cache->purge();
            } catch (\Throwable $e) {
            }
        });
    }

    /** Guests: a kept page or Gridbox data answer is sent at once; otherwise noted to be kept. */
    private function servePageCache(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (($user && !$user->guest) || (!$this->pageCacheOn() && !$this->dataCacheOn())) {
            return;
        }
        $method = strtoupper((string) $input->getMethod());
        $kind   = PageCache::dataRequest((string) $input->getCmd('option', ''), (string) $input->getString('task', ''), (string) $input->getString('module', ''));
        if ($kind !== null) {
            // Gridbox asks for its texts by POST too; nothing is posted that changes the answer
            if ($this->dataCacheOn() && ($method === 'GET' || $method === 'HEAD' || ($method === 'POST' && $kind === 'language'))) {
                $this->gridboxData($kind);
            }

            return;
        }
        if (!$this->pageCacheOn() || !\in_array($method, ['GET', 'HEAD'], true) || $app->getMessageQueue()
            || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('tmpl', '') !== '' || $this->inBuilder()) {
            return;
        }
        // the Markdown version of a page (AI Markdown) and addresses with parameters (search, filters)
        $server = $input->server;
        if (stripos((string) $server->getString('HTTP_ACCEPT', ''), 'text/markdown') !== false || $input->get('output', '', 'cmd') !== ''
            || $input->get('markdown', '', 'cmd') !== '' || PageCache::cleanQuery((string) $server->getRaw('QUERY_STRING', '')) !== '') {
            return;
        }
        $lines = fn (string $s) => array_filter(array_map('trim', preg_split('/\R/', $s) ?: []));
        if (PageCache::hasCookie($_COOKIE, array_merge(PageCache::SKIP_COOKIES, $lines((string) $this->params->get('speed_cache_cookies', ''))))) {
            return;
        }
        $uri  = Uri::getInstance();
        $path = (string) $uri->getPath();
        foreach ($lines((string) $this->params->get('speed_cache_exclude', '')) as $part) {
            if (stripos($path, $part) !== false) {
                return;
            }
        }

        $url   = $uri->toString(['scheme', 'host', 'port', 'path']);
        $cache = $this->pageCache();
        $key   = $cache->key('page', $url);
        $entry = null;
        try {
            $entry = $cache->read($key, $this->cacheTtl(), microtime(true));
        } catch (\Throwable $e) {
        }
        $viewed = $input->cookie->get('gridbox_viewed_products', [], 'array');
        if ($entry) {
            // "Recently viewed products" of this visitor would differ from the kept one
            if (!empty($entry['meta']['viewed']) && PageCache::viewedOthers($viewed, (int) $entry['meta']['viewed'])) {
                return;
            }
            $this->sendPage($entry);
        }
        $this->pageToStore = ['key' => $key, 'url' => $url, 'started' => (float) $server->getFloat('REQUEST_TIME_FLOAT', microtime(true))];
    }

    /** Send a kept page with this visitor's token and nonce, as Joomla would have sent it, and stop. */
    private function sendPage(array $entry): void
    {
        $app  = $this->getApplication();
        $meta = $entry['meta'];
        $html = PageCache::forServe($entry['body'], (string) $app->getFormToken(), $app->get('csp_nonce'));

        // what Gridbox does on a page view: the "recently viewed" cookie and the hit counter
        if (!empty($meta['viewed'])) {
            $options = ['expires' => time() + 604800, 'path' => $app->get('cookie_path', '/') ?: '/', 'domain' => (string) $app->get('cookie_domain', ''),
                'secure' => $app->isSSLConnection(), 'httponly' => true, 'samesite' => 'Lax'];
            setcookie('gridbox_viewed_products[0]', (string) (int) $meta['viewed'], $options);
        }
        if (!empty($meta['page'])) {
            try {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                $db->setQuery('UPDATE ' . $db->quoteName('#__gridbox_pages') . ' SET ' . $db->quoteName('hits') . ' = ' . $db->quoteName('hits')
                    . ' + 1 WHERE ' . $db->quoteName('id') . ' = ' . (int) $meta['page'])->execute();
            } catch (\Throwable $e) {
            }
        }

        $app->setHeader('Content-Type', (string) ($meta['type'] ?? 'text/html; charset=utf-8'), true);
        foreach ((array) ($meta['headers'] ?? []) as $header) {
            if (\is_array($header) && \count($header) === 2) {
                $app->setHeader((string) $header[0], (string) $header[1]);
            }
        }
        $app->setHeader('Expires', 'Wed, 17 Aug 2005 00:00:00 GMT', true);
        $app->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', (int) $meta['time']) . ' GMT', true);
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('X-MerTools-Cache', 'HIT', true);
        // the no-store above stays (Joomla would add a bare no-cache to an uncachable answer)
        $app->allowCache(true);
        $app->setBody($html);
        echo $app->toString((bool) $app->get('gzip'));
        $app->close();
    }

    /**
     * Gridbox's page items or texts: the kept answer at once, else Gridbox answers (and ends with exit)
     * and its answer is kept on the way out, if complete.
     */
    private function gridboxData(string $kind): void
    {
        $app     = $this->getApplication();
        $server  = $app->getInput()->server;
        $address = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']) . '?' . (string) $server->getRaw('QUERY_STRING', '')
            . '|' . $app->getLanguage()->getTag();
        $cache   = $this->pageCache();
        $key     = $cache->key('data', $address);
        try {
            $entry = $cache->read($key, $this->cacheTtl(), microtime(true));
        } catch (\Throwable $e) {
            $entry = null;
        }
        if ($entry) {
            header('Content-Type: text/javascript; charset=UTF-8');
            header('X-MerTools-Cache: HIT');
            echo $entry['body'];
            $app->close();
        }

        $started = (float) $server->getFloat('REQUEST_TIME_FLOAT', microtime(true));
        $buffer  = '';
        ob_start(static function (string $chunk, int $phase) use (&$buffer, $cache, $key, $kind, $started): string {
            $buffer .= $chunk;
            if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                try {
                    if (http_response_code() === 200 && PageCache::validData($kind, $buffer)) {
                        $cache->write($key, ['kind' => 'data', 'time' => microtime(true)], $buffer, $started);
                    }
                } catch (\Throwable $e) {
                }
            }

            return $chunk;
        });
    }

    /** After the page has been sent: keep it, if it qualified and nothing in the answer speaks against it. */
    private function storePage(): void
    {
        $store             = $this->pageToStore;
        $this->pageToStore = null;
        if (!$store || empty($store['ok']) || http_response_code() !== 200) {
            return;
        }
        $app     = $this->getApplication();
        $headers = headers_list();
        $viewed  = PageCache::cookieCheck($headers, (string) $app->getSession()->getName());
        $body    = (string) $app->getBody();
        if ($viewed === null || !PageCache::storable($body)) {
            return;
        }
        $input = $app->getInput();
        $page  = $input->getCmd('option') === 'com_gridbox' && $input->getCmd('view') === 'page' ? $input->getInt('id', 0) : 0;
        $meta  = ['kind' => 'page', 'time' => microtime(true), 'url' => $store['url'], 'type' => PageCache::contentType($headers),
            'headers' => PageCache::replayHeaders($headers), 'viewed' => $viewed, 'page' => $page];
        try {
            $this->pageCache()->write($store['key'], $meta, PageCache::forStore($body, (string) $app->getFormToken(), $app->get('csp_nonce')), $store['started']);
        } catch (\Throwable $e) {
        }
    }

    /** Once an hour (after a page was sent): expired entries go, and the oldest above the limit. */
    private function prunePageCache(): void
    {
        $gate = self::cacheBase() . '/mertools-pcache-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        @touch($gate);
        try {
            $this->pageCache()->prune($this->cacheTtl(), max(100, min(100000, (int) $this->params->get('speed_cache_max', 5000))), microtime(true));
        } catch (\Throwable $e) {
        }
    }

    // ---------------------------------------------------------------- old shop carts

    private function cartsOn(): bool
    {
        return (bool) (int) $this->params->get('cart_enabled', 1);
    }

    private function cartCleaner(): CartCleaner
    {
        return new CartCleaner(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /** A request with Gridbox's cart cookie: note that the cart is in use (it must not be removed). */
    private function trackCart(): void
    {
        if (!$this->cartsOn()) {
            return;
        }
        $id = $this->getApplication()->getInput()->cookie->getInt('gridbox_store_cart', 0);
        if ($id <= 0) {
            return;
        }
        try {
            $this->cartCleaner()->track($id, time());
        } catch (\Throwable $e) {
            // no Gridbox shop or no tables yet: nothing to note
        }
    }

    /**
     * After the page has been sent: at most once an hour (a file in the cache folder keeps the time,
     * so other requests cost one stat), the watching bookkeeping and the automatic clean-up.
     */
    public function onAfterRespond(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        if ($this->pageToStore) {
            $this->storePage();
        }
        if ($this->pageCacheOn() || $this->dataCacheOn()) {
            $this->prunePageCache();
        }
        if (!$this->cartsOn()) {
            return;
        }
        $gate = self::cacheBase() . '/mertools-cart-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        if (!@touch($gate) && is_file($gate)) {
            return;
        }

        // the visitor has the page already; the work goes on without them waiting where PHP allows it
        $finished = false;
        if (\function_exists('fastcgi_finish_request')) {
            $finished = @fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            $finished = @litespeed_finish_request();
        }
        try {
            $cleaner = $this->cartCleaner();
            if (!$cleaner->supported()) {
                return;
            }
            $cleaner->tick($now, (string) $this->params->get('cart_mode', 'manual'), (int) $this->params->get('cart_limit', 50000),
                (int) $this->params->get('cart_days', 30), (bool) (int) $this->params->get('cart_empty', 1), $finished ? 8.0 : 2.0);
        } catch (\Throwable $e) {
            // tried again in an hour
        }
    }

    /**
     * The buttons of the cart clean-up in the plugin settings (figures, check, clean now): POST,
     * the form token and the right to edit plugins are required. The days and the empty-cart choice
     * come from the form as it is on screen, also unsaved.
     */
    private function handleAdministratorAction(): void
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $action = $input->getCmd('mertools_action', '');
        if (!\in_array($action, ['cart_stats', 'cart_check', 'cart_clean', 'speed_list', 'speed_save', 'speed_baseline', 'speed_delete',
            'pcache_stats', 'pcache_clear'], true)) {
            return;
        }
        $this->loadLanguage();
        $user = $app->getIdentity();
        if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.edit', 'com_plugins') || !Session::checkToken('request')) {
            $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
        }

        if (str_starts_with($action, 'speed_')) {
            $this->handleSpeedAction($action);
        }
        if (str_starts_with($action, 'pcache_')) {
            try {
                $cache = $this->pageCache();
                if ($action === 'pcache_clear') {
                    $cache->purge();
                }
                $this->sendJson(['success' => true, 'stats' => $cache->stats($this->cacheTtl(), microtime(true))]);
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        $cleaner = $this->cartCleaner();
        if (!$cleaner->supported()) {
            $this->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CART_UNSUPPORTED')]);
        }
        $days  = $input->post->getInt('days', (int) $this->params->get('cart_days', 30));
        $empty = (bool) $input->post->getInt('empty', (int) $this->params->get('cart_empty', 1));
        $now   = time();
        try {
            if ($action === 'cart_stats') {
                $this->sendJson(['success' => true, 'stats' => $cleaner->stats($now, $days)]);
            }
            @set_time_limit(90);
            $result = $cleaner->run($now, $days, $empty, true, $action === 'cart_check', 25.0, 'manual', $action === 'cart_clean');
            $this->sendJson(['success' => true, 'result' => $result, 'stats' => $cleaner->stats($now, $days)]);
        } catch (\Throwable $e) {
            $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** The page speed panel: list, keep a measurement (summary from the browser), baseline, delete. */
    private function handleSpeedAction(string $action): void
    {
        $input = $this->getApplication()->getInput();
        $speed = new Speed(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            if ($action === 'speed_save') {
                $data = json_decode((string) $input->post->get('data', '', 'raw'), true);
                $m    = \is_array($data) ? Speed::clean($data) : null;
                if (!$m) {
                    $this->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_SPEED_BAD_RESULT')]);
                }
                $defaults = ['url_collapse_slashes' => 1, 'notfound_redirect' => 1, 'layout_clip_x' => 1, 'shop_option_links' => 1,
                    'cart_enabled' => 1, 'tel_enabled' => 1, 'dark_enabled' => 0, 'speed_show' => 1, 'speed_images' => 1, 'speed_mainphoto' => 1,
                    'speed_video' => 1, 'speed_video_phone' => 'none', 'speed_scripts' => 0, 'speed_cache' => 0, 'speed_cache_data' => 1];
                $speed->save($m, self::VERSION, Speed::enabledTools($this->params->toArray(), $defaults), time());
            } elseif ($action === 'speed_baseline') {
                $speed->setBaseline($input->post->getInt('id', 0));
            } elseif ($action === 'speed_delete') {
                $speed->delete($input->post->getInt('id', 0));
            }
            $this->sendJson(['success' => true, 'rows' => $speed->all()]);
        } catch (\Throwable $e) {
            $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function sendJson(array $data, int $status = 200): void
    {
        $app = $this->getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        if ($status !== 200) {
            $app->setHeader('Status', (string) $status, true);
        }
        $app->sendHeaders();
        echo json_encode($data);
        $app->close();
    }

    /**
     * Page not found: redirect a missing page to the chosen target instead of showing the error page.
     */
    public function onError(ErrorEvent $event): void
    {
        $app = $event->getApplication();
        if (!$app instanceof \Joomla\CMS\Application\CMSApplicationInterface || !$app->isClient('site')
            || (int) $event->getError()->getCode() !== 404 || !(int) $this->params->get('notfound_redirect', 1)) {
            return;
        }
        // pages only: no form posts, AJAX or JSON (a redirect to the home page makes no sense there)
        $input = $app->getInput();
        if (!\in_array(strtoupper((string) $input->getMethod()), ['GET', 'HEAD'], true)
            || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('option') === 'com_ajax'
            || strtolower((string) $input->server->getString('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest') {
            return;
        }

        $target = NotFound::absoluteTarget($this->notFoundTarget(), Uri::root());
        $uri    = Uri::getInstance();
        if ($target === null || !NotFound::shouldRedirect($uri->toString(['path', 'query']), $target, $uri->getHost(),
            NotFound::excludes((string) $this->params->get('notfound_exclude', '')))) {
            return;
        }

        $app->redirect($target, (int) $this->params->get('notfound_code', 301) === 302 ? 302 : 301);
    }

    /** The redirect target of missing pages from the settings: the home page, a menu item or an address. */
    private function notFoundTarget(): string
    {
        switch ((string) $this->params->get('notfound_target', 'home')) {
            case 'menu':
                $id = (int) $this->params->get('notfound_menuitem', 0);
                try {
                    // the site menu holds published items only: a deleted or unpublished one gives the home page
                    $item = $id > 0 ? $this->getApplication()->getMenu()->getItem($id) : null;
                    if ($item) {
                        return Route::link('site', 'index.php?Itemid=' . $id, false, Route::TLS_IGNORE, true);
                    }
                } catch (\Throwable $e) {
                }

                return Uri::root();
            case 'url':
                return (string) $this->params->get('notfound_url', '');
            default:
                return Uri::root();
        }
    }

    /**
     * Links to a product option, the second half: a radio-button option the server selected from the
     * link gets the data-value Gridbox's script reads, or the script would take it as nothing chosen.
     */
    public function onAfterRender(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        $document = $app->getDocument();
        if (!$document || $document->getType() !== 'html' || $this->inBuilder()) {
            return;
        }
        $input = $app->getInput();
        $body  = (string) $app->getBody();
        $new   = $body;
        if ((int) $this->params->get('shop_option_links', 1) && $input->getCmd('option') === 'com_gridbox' && $input->getCmd('view') === 'page') {
            $new = ProductLinks::markChosenRadios($new);
        }
        $new = $this->speedup($new);
        if ($new !== $body) {
            $app->setBody($new);
        }
        if ($this->pageToStore) {
            if (PageCache::storable($new)) {
                // the page is kept as sent: Joomla would otherwise compress it before onAfterRespond
                $this->pageToStore['ok'] = true;
                $app->set('gzip', false);
            } else {
                $this->pageToStore = null;
            }
        }
    }

    /** The faster-first-view tools that are switched on, applied to the finished page. */
    private function speedup(string $html): string
    {
        $p = $this->params;
        try {
            $site = Uri::getInstance()->toString(['scheme', 'host', 'port']);
            if ((int) $p->get('speed_show', 1)) {
                $html = Speedup::showAtOnce($html);
            }
            if ((int) $p->get('speed_images', 1)) {
                $sizes = new ImageSize(JPATH_ROOT, $site, Uri::root(true), self::cacheBase() . '/mertools-image-sizes.json');
                $html  = Speedup::images($html, $site, fn (string $url) => $sizes->get($url));
                $html  = Speedup::headerBackgrounds($html);
                $sizes->save();
            }
            if ((int) $p->get('speed_mainphoto', 1)) {
                [$html, $photo] = Speedup::mainPhoto($html);
                if ($photo) {
                    $html = Speedup::preloadImage($html, $photo);
                }
            }
            if ((int) $p->get('speed_video', 1)) {
                $image = trim((string) $p->get('speed_video_phone_image', ''));
                if ($image !== '') {
                    $image = HTMLHelper::cleanImageURL($image)->url;
                    $image = preg_match('#^(https?:)?//#i', $image) ? $image : Uri::root(true) . '/' . ltrim($image, '/');
                }
                $html = Speedup::delayVideoBackground($html, (int) $p->get('speed_video_delay', 3), (string) $p->get('speed_video_phone', 'none'),
                    (int) $p->get('speed_video_phone_width', 768), $image, (string) $p->get('speed_video_phone_color', '#1a1a1a'));
            }
            if ((int) $p->get('speed_scripts', 0)) {
                $lines = fn (string $s) => preg_split('/\R/', $s) ?: [];
                $html  = Speedup::delayScripts($html, $lines((string) $p->get('speed_scripts_list', self::SCRIPT_PATTERNS)),
                    $lines((string) $p->get('speed_scripts_keep', 'cookieconsent' . "\n" . 'cookie-consent')), (int) $p->get('speed_scripts_timeout', 0));
            }
        } catch (\Throwable $e) {
            // a page is never broken by a speed-up: shown as Gridbox made it
        }

        return $html;
    }

    /**
     * Front-end pages (HTML documents only, not the Gridbox builder): the page layout fix for phones,
     * click-to-call phone numbers, and dark mode — the dark palette CSS, the no-flash inline script
     * and the toggle script.
     */
    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        $document = $app->getDocument();
        if (!$document || $document->getType() !== 'html' || $this->inBuilder()) {
            return;
        }
        $wa = $document->getWebAssetManager();
        if ((int) $this->params->get('layout_clip_x', 1)) {
            $document->addStyleDeclaration(Layout::css());
        }
        if (Phones::enabled($this->params)) {
            $document->addStyleDeclaration(Phones::css($this->params));
            $document->addScriptOptions('plg_system_mertools', ['tel' => Phones::jsConfig($this->params)]);
            $wa->registerAndUseScript('plg_system_mertools.tel', 'plg_system_mertools/mertools-tel.js', [], ['defer' => true]);
        }
        if (!(int) $this->params->get('dark_enabled', 0)) {
            return;
        }

        // the theme has to be set before the first paint, so this inline script goes in the head first
        // (exclude-deffer: Gridbox's deferred loading would otherwise move it to the end of the page)
        $wa->addInlineScript(DarkMode::inlineScript($this->params), ['position' => 'before'], ['type' => 'text/javascript', 'class' => 'exclude-deffer']);
        $document->addStyleDeclaration(DarkMode::css($this->params));

        $document->addScriptOptions('plg_system_mertools', [
            'dark' => DarkMode::jsConfig($this->params) + [
                'toDark'  => $this->translate('PLG_SYSTEM_MERTOOLS_DARK_TO_DARK'),
                'toLight' => $this->translate('PLG_SYSTEM_MERTOOLS_DARK_TO_LIGHT'),
                'choose'  => $this->translate('PLG_SYSTEM_MERTOOLS_DARK_CHOOSE'),
                'names'   => array_combine(DarkMode::PALETTES, array_map(
                    fn ($k) => $this->translate('PLG_SYSTEM_MERTOOLS_DARK_PALNAME_' . strtoupper($k)), DarkMode::PALETTES)),
            ],
        ]);
        $wa->registerAndUseScript('plg_system_mertools.dark', 'plg_system_mertools/mertools-dark.js', [], ['defer' => true]);
    }

    /**
     * Saving the MerTools settings: pages kept by Joomla's page cache still carry the old settings
     * (and the old script version), so that cache is emptied.
     */
    public function onExtensionAfterSave(\Joomla\Event\EventInterface $event): void
    {
        $context = (string) (method_exists($event, 'getContext') ? $event->getContext() : $event->getArgument('context'));
        $table   = method_exists($event, 'getItem') ? $event->getItem() : $event->getArgument('subject');
        if ($context !== 'com_plugins.plugin' || !is_object($table) || ($table->element ?? '') !== 'mertools') {
            return;
        }
        self::cleanPageCache();
    }

    /**
     * Where Joomla keeps its cache: the configured path, else administrator/cache (site and administrator
     * share it since Joomla 4). Always an absolute path: a relative one ("cache/" on merserwis.pl) would
     * point elsewhere in the administrator and at shutdown, where the working folder is another one.
     */
    public static function cacheBase(): string
    {
        try {
            $path = (string) Factory::getApplication()->get('cache_path', '');
        } catch (\Throwable $e) {
            $path = '';
        }

        return PageCache::absolutePath($path, JPATH_ROOT, \defined('JPATH_CACHE') ? JPATH_CACHE : JPATH_ADMINISTRATOR . '/cache');
    }

    /** Empties Joomla's page cache of the site (System - Page Cache), also when called from the administrator. */
    public static function cleanPageCache(): void
    {
        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'page', 'cachebase' => self::cacheBase()])
                ->clean('page');
        } catch (\Throwable $e) {
            // nothing cached, or a cache backend that cannot be emptied from here
        }
    }

    /**
     * The Gridbox page builder (its editor frame shows the page on the site side): nothing is added
     * there, or links and colour marks could be saved into the page content.
     */
    private function inBuilder(): bool
    {
        $input = $this->getApplication()->getInput();

        return $input->getCmd('option') === 'com_gridbox' && \in_array($input->getCmd('view'), ['editor', 'gridbox'], true);
    }

    private function translate(string $key): string
    {
        $text = $this->getApplication()->getLanguage()->_($key);

        return $text === $key ? '' : $text;
    }
}
