<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page speed: the page cache for guests (a kept page sent in onAfterRoute, kept in onAfterRespond, emptied after
 * any change), lighter copies of pictures made after the response, the faster-first-view changes of the finished
 * page, the PageSpeed panel's requests and the clear-cache button in the administrator's top bar.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;
use Merserwis\Plugin\System\MerTools\Tool\ImageSize;
use Merserwis\Plugin\System\MerTools\Tool\PageCache;
use Merserwis\Plugin\System\MerTools\Tool\Speed;
use Merserwis\Plugin\System\MerTools\Tool\Speedup;

final class PageSpeed extends Feature
{
    /** a page of this request that may be kept: key, address, start time; 'ok' once the page qualified */
    public ?array $pageToStore = null;

    /** marketing scripts delayed by default (address or code contains) */
    public const SCRIPT_PATTERNS = "googletagmanager.com\nfbq(\nconnect.facebook.net\nclarity.ms\nhotjar.com\nelfsightcdn.com\ncloudflareinsights.com";

    public function pageCacheOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('speed_cache', 0);
    }

    public function dataCacheOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('speed_cache_data', 1);
    }

    public function imageOptimizer(): ImageOptimizer
    {
        return new ImageOptimizer(JPATH_ROOT, Uri::getInstance()->toString(['scheme', 'host', 'port']), Uri::root(true), MerTools::cacheBase());
    }

    /**
     * After the response: the copies of pictures that pages have queued, a few seconds of work, at
     * most every 15 s. Kept pages are emptied once new copies exist, so pages offer them (at most
     * every 10 minutes while the queue is long, and when it is done).
     */
    public function imageWork(): void
    {
        $p = $this->plugin->params;
        if (!(int) $p->get('speed_images', 1) || (!(int) $p->get('speed_webp', 1) && !(int) $p->get('speed_gif', 1)) || !ImageOptimizer::supported()) {
            return;
        }
        $base  = MerTools::cacheBase();
        $queue = $base . '/mertools-images-queue.json';
        if (!is_file($queue) || (int) @filesize($queue) < 5) {
            return;
        }
        $gate = $base . '/mertools-img-tick';
        if (is_file($gate) && (int) @filemtime($gate) > time() - 15) {
            return;
        }
        @touch($gate);
        $finished = $this->plugin->finishResponse();
        try {
            $images = $this->imageOptimizer();
            // while a visitor still waits (no fastcgi_finish_request): half a second and small pictures only
            if ($images->work($finished ? 8.0 : 0.5, $finished ? ImageOptimizer::MAX_PIXELS : 4000000) > 0) {
                $stamp = $base . '/mertools-img-purged';
                if ($images->pending() === 0 || !is_file($stamp) || (int) @filemtime($stamp) < time() - 600) {
                    @touch($stamp);
                    $this->purgeKeptPages();
                }
            }
        } catch (\Throwable $e) {
            // tried again with the next page
        }
    }

    /** Pages kept by MerTools and by Joomla's page cache are emptied (they would offer old pictures). */
    public function purgeKeptPages(): void
    {
        if ($this->pageCacheOn() || $this->dataCacheOn()) {
            $this->pageCache()->purge();
        }
        MerTools::cleanPageCache();
    }

    public function pageCache(): PageCache
    {
        return new PageCache(MerTools::cacheBase() . '/' . PageCache::DIR);
    }

    public function cacheTtl(): int
    {
        return max(5, min(1440, (int) $this->plugin->params->get('speed_cache_ttl', 240))) * 60;
    }

    /**
     * A request that changes what pages show (a save in Joomla or in the Gridbox editor, an order, a
     * comment): the kept pages go once it has finished — Gridbox ends its requests with exit, so after
     * the response, at shutdown, and only then (emptied earlier, a guest could keep the old page again).
     */
    public function purgeOnChange(): void
    {
        if (!$this->pageCacheOn() && !$this->dataCacheOn()) {
            return;
        }
        $app    = $this->plugin->app();
        $input  = $app->getInput();
        $user   = $app->getIdentity();
        $guest  = !$user || $user->guest;
        $method = strtoupper((string) $input->getMethod());
        if (!PageCache::changes($method, $guest, (string) $input->getCmd('option', ''), (string) $input->getString('task', ''))) {
            return;
        }
        $cache  = $this->pageCache();
        $finish = fn () => $this->plugin->finishResponse();
        register_shutdown_function(static function () use ($cache, $guest, $method, $finish): void {
            try {
                // anybody can send the address of an order or payment step: for a guest it counts only
                // when Gridbox answered as it does to a real one (a POST answered 2xx/3xx, or a 302/303
                // redirect on — a 301 is the SEF plugin's, a 404 nothing done), and not more often than
                // every 20 s: a loop of such requests must not keep the cache cold
                if ($guest) {
                    $code = (int) http_response_code();
                    $real = $method === 'POST' ? ($code >= 200 && $code < 400) : ($code === 302 || $code === 303);
                    if (!$real || $cache->purgedAt() > microtime(true) - 20) {
                        return;
                    }
                }
                // the time first (nothing older is served from now on); the files after the visitor has
                // the answer
                $cache->purge(false);
                $finish();
                $cache->removePurged();
            } catch (\Throwable $e) {
            }
        });
    }

    /** Guests: a kept page or Gridbox data answer is sent at once; otherwise noted to be kept. */
    public function servePageCache(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (($user && !$user->guest) || (!$this->pageCacheOn() && !$this->dataCacheOn())) {
            return;
        }
        // a visitor with a cart, a wishlist, an own currency…: their pages and Gridbox's answers (prices in
        // that currency) are their own, never kept and never served from what others got
        $lines = fn (string $s) => array_filter(array_map('trim', preg_split('/\R/', $s) ?: []));
        if (PageCache::hasCookie($_COOKIE, array_merge(PageCache::SKIP_COOKIES, $lines((string) $this->plugin->params->get('speed_cache_cookies', ''))))) {
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
            || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('tmpl', '') !== '' || $this->plugin->inBuilder()) {
            return;
        }
        // the Markdown version of a page (AI Markdown) and addresses with parameters (search, filters)
        $server = $input->server;
        if (stripos((string) $server->getString('HTTP_ACCEPT', ''), 'text/markdown') !== false || $input->get('output', '', 'cmd') !== ''
            || $input->get('markdown', '', 'cmd') !== '' || PageCache::cleanQuery((string) $server->getRaw('QUERY_STRING', '')) !== '') {
            return;
        }
        $uri  = Uri::getInstance();
        $path = (string) $uri->getPath();
        foreach ($lines((string) $this->plugin->params->get('speed_cache_exclude', '')) as $part) {
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
        $app  = $this->plugin->app();
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
        $app     = $this->plugin->app();
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
        $session = (string) $app->getSession()->getName();
        $ttl     = $this->cacheTtl();
        $max     = max(100, min(100000, (int) $this->plugin->params->get('speed_cache_max', 5000)));
        $buffer  = '';
        ob_start(static function (string $chunk, int $phase) use (&$buffer, $cache, $key, $kind, $started, $session, $ttl, $max): string {
            $buffer .= $chunk;
            if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                try {
                    // an answer that set a cookie (other than the session) may belong to this visitor
                    if (http_response_code() === 200 && PageCache::validData($kind, $buffer) && PageCache::cookieCheck(headers_list(), $session) === 0
                        && $cache->write($key, ['kind' => 'data', 'time' => microtime(true)], $buffer, $started) && random_int(1, 32) === 1) {
                        // the address of these answers carries a free part (the time of the last change): a
                        // flood of made-up addresses must not fill the disk before the hourly tidy-up
                        $cache->prune($ttl, $max, microtime(true));
                    }
                } catch (\Throwable $e) {
                }
            }

            return $chunk;
        });
    }

    /**
     * After the page has been sent: keep it, if it qualified and nothing in the answer speaks against it.
     *
     * @param int      $code    the response code, as sent
     * @param string[] $headers headers_list(), as sent
     */
    public function storePage(int $code, array $headers): void
    {
        $store             = $this->pageToStore;
        $this->pageToStore = null;
        if (!$store || empty($store['ok']) || $code !== 200) {
            return;
        }
        $app    = $this->plugin->app();
        $viewed = PageCache::cookieCheck($headers, (string) $app->getSession()->getName());
        $body   = (string) ($store['body'] ?? '');
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
    public function prunePageCache(): void
    {
        $gate = MerTools::cacheBase() . '/mertools-pcache-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        @touch($gate);
        try {
            $this->pageCache()->prune($this->cacheTtl(), max(100, min(100000, (int) $this->plugin->params->get('speed_cache_max', 5000))), microtime(true));
        } catch (\Throwable $e) {
        }
    }

    /**
     * The top-bar button: the pages kept by MerTools, then every group of Joomla's cache, as
     * System → Clear Cache does it (Joomla's own model, so plugins that listen to onAfterPurge
     * hear of it too). Returns the figures for the message.
     */
    public function clearAllCaches(): array
    {
        $pages = 0;
        try {
            $kept  = $this->pageCache();
            $stats = $kept->stats(86400 * 366, microtime(true));
            $pages = (int) $stats['pages'] + (int) $stats['data'];
            $kept->purge();
        } catch (\Throwable $e) {
            // nothing kept yet
        }
        $model = $this->plugin->app()->bootComponent('com_cache')->getMVCFactory()
            ->createModel('Cache', 'Administrator', ['ignore_request' => true]);
        $groups = [];
        $size   = 0;
        foreach ((array) $model->getCache()->getAll() as $item) {
            $groups[] = (string) $item->group;
            $size    += (int) ($item->size ?? 0);
        }
        $errors = $groups ? $model->cleanlist($groups) : [];
        $done   = \count($groups) - \count($errors);
        $text   = Text::sprintf('PLG_SYSTEM_MERTOOLS_HEADER_CLEARED', $done, HTMLHelper::_('number.bytes', $size));
        if ($pages > 0) {
            $text .= ' ' . Text::sprintf('PLG_SYSTEM_MERTOOLS_HEADER_CLEARED_PAGES', $pages);
        }
        if ($errors) {
            $text .= ' ' . Text::sprintf('PLG_SYSTEM_MERTOOLS_HEADER_NOT_CLEARED', implode(', ', $errors));
        }

        return ['groups' => $done, 'size' => $size, 'pages' => $pages, 'errors' => $errors, 'message' => $text];
    }

    /**
     * The clear-cache button in the top bar of the administrator: a module of the "status" position
     * added to the list (its content ready, so no module is booted), first in the bar. Being a
     * module, Atum also puts it in its "more" menu when the bar is too narrow.
     */
    public function headerButton($event): void
    {
        $app = $this->plugin->app();
        if (!$app->isClient('administrator') || !(int) $this->plugin->params->get('admin_cache_button', 1)
            || $app->getInput()->getCmd('tmpl', '') === 'component') {
            return;
        }
        $user = $app->getIdentity();
        $document = $app->getDocument();
        if (!$user || $user->guest || !$user->authorise('core.manage', 'com_cache') || !$document || $document->getType() !== 'html') {
            return;
        }
        $this->plugin->loadLanguage();
        try {
            $document->addScriptOptions('plg_system_mertools.header', [
                'url'   => Uri::base(true) . '/index.php?mertools_action=cache_clear_all',
                'token' => Session::getFormToken(),
                'error' => Text::_('PLG_SYSTEM_MERTOOLS_HEADER_ERROR'),
            ]);
            $document->getWebAssetManager()->registerAndUseScript('plg_system_mertools.header', 'plg_system_mertools/mertools-header.js', [], ['defer' => true]);
        } catch (\Throwable $e) {
            return;
        }
        $title  = htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_HEADER_TITLE'), ENT_QUOTES, 'UTF-8');
        $label  = htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_HEADER_LABEL'), ENT_QUOTES, 'UTF-8');
        $module = (object) [
            'id'              => -84101,
            'title'           => 'MerTools',
            'module'          => 'mod_mertools_header',
            'position'        => 'status',
            'content'         => '<button type="button" class="header-item-content" data-mertools-clear-cache title="' . $title . '">'
                . '<div class="header-item-icon"><span class="icon-trash" aria-hidden="true"></span></div>'
                . '<div class="header-item-text">' . $label . '</div></button>',
            'contentRendered' => true,
            'showtitle'       => 0,
            'params'          => '{}',
            'menuid'          => 0,
        ];
        $modules = $event->getArgument('modules', []);
        $modules = \is_array($modules) ? array_values($modules) : [];
        $at      = \count($modules);
        foreach ($modules as $i => $existing) {
            if (strtolower((string) ($existing->position ?? '')) === 'status') {
                $at = $i;
                break;
            }
        }
        array_splice($modules, $at, 0, [$module]);
        $event->setArgument('modules', $modules);
    }

    /** The page speed panel: list, keep a measurement (summary from the browser), baseline, delete. */
    public function handleSpeedAction(string $action): void
    {
        $input = $this->plugin->app()->getInput();
        $speed = new Speed(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            if ($action === 'speed_save') {
                $data = json_decode((string) $input->post->get('data', '', 'raw'), true);
                $m    = \is_array($data) ? Speed::clean($data) : null;
                if (!$m) {
                    $this->plugin->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_SPEED_BAD_RESULT')]);
                }
                $defaults = ['url_collapse_slashes' => 1, 'notfound_redirect' => 0, 'layout_clip_x' => 1, 'shop_option_links' => 1,
                    'cart_enabled' => 1, 'tel_enabled' => 1, 'dark_enabled' => 0, 'speed_show' => 1, 'speed_images' => 1, 'speed_mainphoto' => 1,
                    'speed_video' => 1, 'speed_video_phone' => 'none', 'speed_scripts' => 0, 'speed_cache' => 0, 'speed_cache_data' => 1,
                    'speed_icons' => 1, 'speed_webp' => 1, 'speed_gif' => 1, 'speed_popups' => 1, 'layout_post_rows' => 1, 'tabs_accordion' => 1, 'db_indexes' => 1];
                $speed->save($m, MerTools::VERSION, Speed::enabledTools($this->plugin->params->toArray(), $defaults), time());
            } elseif ($action === 'speed_baseline') {
                $speed->setBaseline($input->post->getInt('id', 0));
            } elseif ($action === 'speed_delete') {
                $speed->delete($input->post->getInt('id', 0));
            }
            $this->plugin->sendJson(['success' => true, 'rows' => $speed->all()]);
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** The faster-first-view tools that are switched on, applied to the finished page. */
    public function speedup(string $html): string
    {
        $p          = $this->plugin->params;
        $bgVariants = [];
        try {
            $site = Uri::getInstance()->toString(['scheme', 'host', 'port']);
            if ((int) $p->get('speed_show', 1)) {
                $html = Speedup::showAtOnce($html);
            }
            if ((int) $p->get('speed_icons', 1)) {
                $html = Speedup::iconStyles($html);
            }
            if ((int) $p->get('speed_images', 1)) {
                $sizes  = new ImageSize(JPATH_ROOT, $site, Uri::root(true), MerTools::cacheBase() . '/mertools-image-sizes.json');
                $webp   = (int) $p->get('speed_webp', 1) === 1;
                $gif    = (int) $p->get('speed_gif', 1) === 1;
                $copies = null;
                $images = ($webp || $gif) && ImageOptimizer::supported() ? $this->imageOptimizer() : null;
                if ($images) {
                    // only what is switched on is looked up (and queued)
                    $copies = function (string $url) use ($images, $webp, $gif): ?array {
                        $isGif = (bool) preg_match('#\.gif(?:[?\#]|$)#i', $url);
                        if ($isGif ? !$gif : !$webp) {
                            return null;
                        }

                        return $images->lookup($url);
                    };
                }
                $html = Speedup::images($html, $site, fn (string $url) => $sizes->get($url), $copies, true, $gif);
                $html = Speedup::headerBackgrounds($html);
                if ($copies && $webp) {
                    $html = Speedup::backgrounds($html, $site, $copies, $bgVariants);
                }
                $sizes->save();
                $images?->save();
            }
            if ((int) $p->get('speed_mainphoto', 1)) {
                [$html, $photo] = Speedup::mainPhoto($html);
                if ($photo) {
                    $html = Speedup::preloadImage($html, $photo, $bgVariants[$photo] ?? []);
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
            if ((int) $p->get('speed_popups', 1)) {
                $html = Speedup::delayPopups($html);
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

    /** The panels of lighter pictures (figures, make now, remove) and of kept pages (figures, empty). */
    public function handleAction(string $action): void
    {
        if (str_starts_with($action, 'img_')) {
            try {
                $images = $this->imageOptimizer();
                if ($action === 'img_run') {
                    if (!ImageOptimizer::supported()) {
                        $this->plugin->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_IMG_UI_NO_GD')]);
                    }
                    @set_time_limit(60);
                    if ($images->work(20.0) > 0) {
                        $this->purgeKeptPages();
                    }
                } elseif ($action === 'img_clear') {
                    $images->clear();
                    // kept pages point at the removed copies
                    $this->purgeKeptPages();
                    $images = $this->imageOptimizer();
                }
                $this->plugin->sendJson(['success' => true, 'stats' => $images->stats() + ['supported' => ImageOptimizer::supported()]]);
            } catch (\Throwable $e) {
                $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        if (str_starts_with($action, 'pcache_')) {
            try {
                $cache = $this->pageCache();
                if ($action === 'pcache_clear') {
                    $cache->purge();
                }
                $this->plugin->sendJson(['success' => true, 'stats' => $cache->stats($this->cacheTtl(), microtime(true))]);
            } catch (\Throwable $e) {
                $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
    }
}
