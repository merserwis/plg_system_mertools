<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * MerTools for Gridbox — a set of fixes and optimisations for Balbooa Gridbox that cannot be done
 * from Gridbox's own settings, each a tool that can be turned on or off. The Gridbox core files are
 * never touched, so Gridbox updates install cleanly. Every tool is kept small and independent.
 *
 * This class listens to Joomla's events and hands the work to the tools, one class per setting tab
 * (src/Feature, each made when first needed), in the order the events ask for it; the pure parts
 * (parsing, HTML changes, database stores) are in src/Tool and tested on their own (tests/).
 *
 *  - onAfterInitialise: canonical URL (runs of slashes redirected to the clean address), carts in use noted.
 *  - onAfterRoute: apps against deletion, edit lock, the watchers of Gridbox saves (introductions, Omnibus
 *    prices, aliases of moved pages, edit history, kept pages) and of uploads (pictures to compress), the
 *    requests of the settings panels; on the site the blog feeds, kept pages and links to a product option.
 *  - onAfterRender: the Gridbox lists of the administrator (columns, history, apps); on the site the
 *    finished page — faster first view, introductions, post rows, price on request, Omnibus lines —
 *    and the page to keep.
 *  - onBeforeCompileHead: styles and scripts of the site (layout, tabs, phones, price on request,
 *    feed links, Omnibus, dark mode); in the administrator the Gridbox panel in Joomla's dark mode.
 *  - onAfterExtensionBoot: Gridbox's lists ordered by MerTools (pinned pages, an added column).
 *  - onAfterRespond: the work after the response (kept pages, pictures, compressed uploads, daily and hourly checks).
 *  - onError: missing pages (404). onAfterModuleList: the clear-cache button of the top bar.
 */

namespace Merserwis\Plugin\System\MerTools\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Feature\AppsProtection;
use Merserwis\Plugin\System\MerTools\Feature\Carts;
use Merserwis\Plugin\System\MerTools\Feature\Compress;
use Merserwis\Plugin\System\MerTools\Feature\Database;
use Merserwis\Plugin\System\MerTools\Feature\EditHistory;
use Merserwis\Plugin\System\MerTools\Feature\EditLocks;
use Merserwis\Plugin\System\MerTools\Feature\EditorFields;
use Merserwis\Plugin\System\MerTools\Feature\EditorImport;
use Merserwis\Plugin\System\MerTools\Feature\EditorSave;
use Merserwis\Plugin\System\MerTools\Feature\Feeds;
use Merserwis\Plugin\System\MerTools\Feature\GridboxLists;
use Merserwis\Plugin\System\MerTools\Feature\Hygiene;
use Merserwis\Plugin\System\MerTools\Feature\ListPages;
use Merserwis\Plugin\System\MerTools\Feature\MissingPages;
use Merserwis\Plugin\System\MerTools\Feature\PageSpeed;
use Merserwis\Plugin\System\MerTools\Feature\Shop;
use Merserwis\Plugin\System\MerTools\Feature\Sitemaps;
use Merserwis\Plugin\System\MerTools\Feature\SiteSchema;
use Merserwis\Plugin\System\MerTools\Tool\ArticleSchema;
use Merserwis\Plugin\System\MerTools\Tool\Badges;
use Merserwis\Plugin\System\MerTools\Tool\BlogGrid;
use Merserwis\Plugin\System\MerTools\Tool\DarkMode;
use Merserwis\Plugin\System\MerTools\Tool\Feed;
use Merserwis\Plugin\System\MerTools\Tool\IntroText;
use Merserwis\Plugin\System\MerTools\Tool\Layout;
use Merserwis\Plugin\System\MerTools\Tool\MainMenu;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\PageCache;
use Merserwis\Plugin\System\MerTools\Tool\Phones;
use Merserwis\Plugin\System\MerTools\Tool\PostRows;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\ProductSchema;
use Merserwis\Plugin\System\MerTools\Tool\SiteSchema as SiteSchemaTool;
use Merserwis\Plugin\System\MerTools\Tool\StaleCode;
use Merserwis\Plugin\System\MerTools\Tool\StoreCurrency;
use Merserwis\Plugin\System\MerTools\Tool\Tabs;
use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

final class MerTools extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '1.13.3';

    /** the language file (60 KB) is loaded only where texts are needed, not on every page view */
    protected $autoloadLanguage = false;

    /** the tools of this request, made when first needed */
    private array $features = [];

    /** event => [the method that does its work, priority] — all of them called through guard() */
    private const LISTENERS = [
        // before routing, so the clean address is served before Gridbox or the SEF router act
        'onAfterInitialise'   => ['onAfterInitialise', Priority::HIGH],
        // early, so a kept page is sent before other plugins do their work for nothing
        'onAfterRoute'        => ['onAfterRoute', Priority::HIGH],
        // after Gridbox has finished the page (its lazy loading and deferred loading)
        'onAfterRender'       => ['onAfterRender', Priority::MIN],
        // after System - Redirect, so a redirect set for a single address in Joomla still wins
        'onError'             => ['onError', Priority::LOW],
        // after the response has gone to the visitor: the work that does not have to keep them waiting
        'onAfterRespond'      => ['onAfterRespond', Priority::MIN],
        'onBeforeCompileHead' => ['onBeforeCompileHead', Priority::NORMAL],
        // Gridbox's component booted: its lists get MerTools' order (pinned pages, sorting by an added column)
        'onAfterExtensionBoot' => ['onAfterExtensionBoot', Priority::NORMAL],
        // the administrator: the clear-cache button in the top bar, next to the Joomla version
        'onAfterModuleList'   => ['onAfterModuleList', Priority::NORMAL],
        'onExtensionAfterSave' => ['onExtensionAfterSave', Priority::NORMAL],
        // Gridbox installed or updated: its tables may have been created again, without our indexes
        'onExtensionAfterInstall' => ['onExtensionAfterInstallOrUpdate', Priority::NORMAL],
        'onExtensionAfterUpdate'  => ['onExtensionAfterInstallOrUpdate', Priority::NORMAL],
    ];

    /** set once a request met files of two versions (StaleCode): noted and told only once */
    private bool $staleSeen = false;

    public static function getSubscribedEvents(): array
    {
        return array_map(static fn (array $l) => ['guard', $l[1]], self::LISTENERS);
    }

    /**
     * Every event goes through here to its method (LISTENERS). After an update PHP may still run some
     * files of the previous version from OPcache next to new ones (see StaleCode); the error that mix
     * gives does not break the page then: MerTools leaves the rest of that event's work, drops its files
     * from OPcache once more, notes it in the log and tells it in the administrator, and the page goes on
     * without it until PHP has the new files. Any other error, and every error with Joomla's debug on,
     * goes on as before.
     *
     * onAfterRoute is the exception: its guards (deletion of apps, edit locks, the sorting Gridbox would
     * put into its query, the requests of the settings panels, emptying kept pages after a change) must
     * not be left out with the rest, so only its other steps are passed over one by one (soft()), and a
     * guard that meets such an error still breaks the request, as before.
     */
    public function guard(EventInterface $event): void
    {
        $method = self::LISTENERS[$event->getName()][0] ?? null;
        if ($method === null) {
            return;
        }
        if ($method === 'onAfterRoute') {
            $this->onAfterRoute();

            return;
        }
        try {
            $this->$method($event);
        } catch (\Error $e) {
            $this->stale($e, \in_array($method, ['onAfterRender', 'onAfterRespond'], true));
        }
    }

    /** One step whose leaving out opens nothing: a mix of versions in it (see guard()) does not stop the steps after it. */
    private function soft(callable $step, bool $rendered = false): void
    {
        try {
            $step();
        } catch (\Error $e) {
            $this->stale($e, $rendered);
        }
    }

    /** $e passed over when it is a mix of versions (see guard()), else thrown on. */
    private function stale(\Error $e, bool $rendered): void
    {
        if ((\defined('JDEBUG') && JDEBUG) || !StaleCode::matches($e)) {
            throw $e;
        }
        $this->staleCode($e, $rendered);
    }

    /** The first error of mixed versions in this request: see guard(); $rendered: the page is done, its message shown on the next one. */
    private function staleCode(\Error $e, bool $rendered): void
    {
        if ($this->staleSeen) {
            return;
        }
        $this->staleSeen = true;
        // a page made without part of MerTools' work is not kept for the next visitors (see onAfterRender())
        try {
            $this->speed()->pageToStore = null;
        } catch (\Throwable $ignored) {
        }
        $dropped = StaleCode::refreshOpcache([JPATH_PLUGINS . '/system/mertools', JPATH_ADMINISTRATOR . '/components/com_mertools']);
        try {
            Log::addLogger(['text_file' => 'plg_system_mertools.php'], Log::ALL, ['mertools']);
            Log::add('Files of two versions of MerTools in OPcache (' . ($dropped ? 'dropped again' : 'cannot be dropped here') . '), its work skipped: '
                . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), Log::WARNING, 'mertools');
        } catch (\Throwable $ignored) {
        }
        try {
            $app  = $this->getApplication();
            $user = $app->getIdentity();
            if ($app->isClient('administrator') && $user && !$user->guest) {
                $this->loadLanguage();
                $text = Text::_('PLG_SYSTEM_MERTOOLS_STALE_CODE');
                if (!$rendered) {
                    if (!\in_array($text, array_column($app->getMessageQueue(), 'message'), true)) {
                        $app->enqueueMessage($text, 'warning');
                    }
                } else {
                    // Joomla keeps its messages for the next page only on a redirect
                    $queue = (array) $app->getSession()->get('application.queue', []);
                    if (!\in_array($text, array_column($queue, 'message'), true)) {
                        $queue[] = ['message' => $text, 'type' => 'warning'];
                        $app->getSession()->set('application.queue', $queue);
                    }
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * The sitemaps (before the router), then the canonical URL: redirect a request whose path has runs of
     * slashes to the single clean address.
     */
    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();
        if ($app->isClient('site')) {
            // the sitemaps: answered before the router, from the kept files
            $this->sitemaps()->serveSitemap();
            $this->carts()->trackCart();
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
     * After routing: the guards and watchers of Gridbox's requests, the settings panels' requests, and on
     * the site what is answered before Gridbox (feeds, kept pages) and the links to a product option.
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        // the guards are called as they are, the rest through soft() (see guard())
        if ($app->isClient('administrator')) {
            // first: a refused deletion must not reach anything else
            $this->apps()->appDeleteGuard();
        }
        if ($app->isClient('site')) {
            $this->locks()->editLock();
            $this->soft(fn () => $this->shop()->foldEndpoint());
        }
        if ($app->isClient('administrator') || $app->isClient('site')) {
            // before the history: it then notes the introduction as it is kept
            $this->soft(fn () => $this->database()->introGuard());
            $this->soft(fn () => $this->shop()->omnibusWatch());
            $this->soft(fn () => $this->missing()->movedWatch());
            $this->soft(fn () => $this->history()->trackHistory());
            $this->speed()->purgeOnChange();
            $this->soft(fn () => $this->compress()->uploadWatch());
            $this->soft(fn () => $this->sitemaps()->watchChanges());
            $this->soft(fn () => $this->pages()->endedWatch());
        }
        if ($app->isClient('administrator')) {
            $this->locks()->adminLockGuard();
            $this->handleAdministratorAction();
            $this->soft(fn () => $this->settingsPage());

            return;
        }
        if ($app->isClient('site')) {
            // first: a sorting Gridbox would put into its query unchecked never reaches it
            $this->pages()->guardSort();
            $this->soft(fn () => $this->pages()->redirectPage());
            $this->soft(fn () => $this->feeds()->serveFeed());
            $this->soft(fn () => $this->speed()->servePageCache());
            $this->soft(fn () => $this->shop()->optionLinks());
        }
    }

    /**
     * Links to a product option, the second half: a radio-button option the server selected from the
     * link gets the data-value Gridbox's script reads, or the script would take it as nothing chosen.
     */
    public function onAfterRender(): void
    {
        $app = $this->getApplication();
        if ($app->isClient('administrator')) {
            $this->soft(fn () => $this->lists()->listColumns(), true);
            $this->soft(fn () => $this->lists()->mediaDropScript(), true);
            $this->soft(fn () => $this->lists()->dashboardScript(), true);
            $this->soft(fn () => $this->history()->historyInLists(), true);
            $this->soft(fn () => $this->apps()->appsScript(), true);

            return;
        }
        if (!$app->isClient('site')) {
            return;
        }
        $document = $app->getDocument();
        // these steps one by one (see guard()): the headers below are set also when one of them is passed over
        if ($this->locks()->editorLock && $document && $document->getType() === 'html') {
            $this->soft(fn () => $this->locks()->lockScript(), true);
        }
        if ($document && $document->getType() === 'html') {
            $this->soft(fn () => $this->shop()->discountScript(), true);
            $this->soft(fn () => $this->shop()->foldScript(), true);
            $this->soft(fn () => $this->editorSave()->saveScript(), true);
            $this->soft(fn () => $this->editorImport()->importScript(), true);
            $this->soft(fn () => $this->editorFields()->fillScript(), true);
            $this->soft(fn () => $this->lists()->mediaDropScript(), true);
        }
        $page = $document && $document->getType() === 'html' && !$this->inBuilder();
        // the last listener: the headers other plugins have set are all there
        $this->hygiene()->headers($page);
        if (!$page) {
            return;
        }
        $input = $app->getInput();
        $body  = (string) $app->getBody();
        $new   = $body;
        if ((int) $this->params->get('shop_option_links', 1) && $input->getCmd('option') === 'com_gridbox' && $input->getCmd('view') === 'page') {
            $new = ProductLinks::markChosenRadios($new);
        }
        if (ProductSchema::enabled($this->params)) {
            $new = $this->shop()->productSchema($new);
        }
        if (Badges::enabled($this->params) && Badges::options($this->params)['product']) {
            $new = $this->shop()->productBadges($new);
        }
        if (Badges::sales($this->params) && str_contains($new, 'ba-store-app-product')) {
            $this->soft(function () use (&$new): void {
                $new = $this->shop()->tileSaleBadges($new);
            }, true);
        }
        if (ArticleSchema::enabled($this->params)) {
            $new = $this->feeds()->articleSchema($new);
        }
        if (SiteSchemaTool::enabled($this->params)) {
            $new = $this->siteSchema()->html($new);
        }
        $new = $this->pages()->listPage($new);
        // after the page of the list is judged: a page with every card taken out would look empty
        $new = $this->pages()->hideEnded($new);
        $new = $this->speed()->speedup($new);
        $new = $this->hygiene()->html($new);
        if ($this->database()->introLtOn()) {
            $new = IntroText::fixMeta($new);
        }
        if ((int) $this->params->get('layout_post_rows', 1)) {
            $new = PostRows::apply($new, (string) $app->get('csp_nonce', ''));
        }
        if (PriceRequest::enabled($this->params)) {
            // the separate script that did this before goes, so nothing has to be deleted on the server
            $new = Omnibus::dropScript($new, PriceRequest::OLD_SCRIPT);
            if (str_contains($new, 'ba-store-app-product')) {
                $new = $this->shop()->priceRequestTiles($new);
            }
        }
        if (Omnibus::enabled($this->params)) {
            $new = $this->shop()->omnibusLines($new);
        }
        if ($new !== $body) {
            $app->setBody($new);
        }
        if ($this->speed()->pageToStore) {
            // a message that came up while rendering is this visitor's; the page is kept as it is here,
            // uncompressed (Joomla compresses the response afterwards; this listener is the last one);
            // a page made without part of MerTools' work (a mix of versions, see guard()) is not kept
            if (PageCache::storable($new) && !$app->getMessageQueue() && !$this->staleSeen) {
                $this->speed()->pageToStore['ok']   = true;
                $this->speed()->pageToStore['body'] = $new;
            } else {
                $this->speed()->pageToStore = null;
            }
        }
    }

    /**
     * Front-end pages (HTML documents only, not the Gridbox builder): the page layout fix for phones,
     * click-to-call phone numbers, and dark mode — the dark palette CSS, the no-flash inline script
     * and the toggle script.
     */
    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();
        if ($app->isClient('administrator')) {
            $this->lists()->darkPanel();

            return;
        }
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
        if (BlogGrid::enabled($this->params)) {
            $document->addStyleDeclaration(BlogGrid::css());
        }
        if (MainMenu::enabled($this->params)) {
            if (MainMenu::hold($this->params)) {
                $document->addStyleDeclaration(MainMenu::css());
            }
            $document->addScriptOptions('plg_system_mertools', ['menu' => MainMenu::jsConfig($this->params)]);
            $wa->registerAndUseScript('plg_system_mertools.menu', 'plg_system_mertools/mertools-menu.js', [], ['defer' => true]);
        }
        if (Tabs::enabled($this->params)) {
            $this->loadLanguage();
            $texts = [];
            foreach (Tabs::TEXTS as $key => $suffix) {
                $texts[$key] = $this->translate('PLG_SYSTEM_MERTOOLS_' . $suffix);
            }
            if (Tabs::accordion($this->params)) {
                $document->addStyleDeclaration(Tabs::css());
            }
            $document->addScriptOptions('plg_system_mertools', ['tabs' => Tabs::jsConfig($this->params, $texts)]);
            $wa->registerAndUseScript('plg_system_mertools.tabs', 'plg_system_mertools/mertools-tabs.js', [], ['defer' => true]);
        }
        if (Phones::enabled($this->params)) {
            $document->addStyleDeclaration(Phones::css($this->params));
            $document->addScriptOptions('plg_system_mertools', ['tel' => Phones::jsConfig($this->params)]);
            $wa->registerAndUseScript('plg_system_mertools.tel', 'plg_system_mertools/mertools-tel.js', [], ['defer' => true]);
        }
        if (PriceRequest::enabled($this->params)) {
            $this->loadLanguage();
            $default = $this->translate('PLG_SYSTEM_MERTOOLS_PRICE_REQUEST_DEFAULT');
            $choose  = $this->translate('PLG_SYSTEM_MERTOOLS_PRICE_REQUEST_CHOOSE_DEFAULT');
            if (PriceRequest::text($this->params, $default) !== '') {
                $document->addStyleDeclaration(PriceRequest::css($this->params));
                $document->addScriptOptions('plg_system_mertools', ['price' => PriceRequest::jsConfig($this->params, $default, $choose)]);
                $wa->registerAndUseScript('plg_system_mertools.price', 'plg_system_mertools/mertools-price.js', [], ['defer' => true]);
            }
        }
        if (Feed::enabled($this->params) && Feed::headMode($this->params) !== 'none') {
            $this->feeds()->feedHeadLinks($document);
        }
        if (Badges::enabled($this->params)) {
            $o = Badges::options($this->params);
            if ($o['product'] || $o['lists']) {
                $document->addStyleDeclaration(Badges::css($o));
            }
        }
        if (Omnibus::enabled($this->params)) {
            $document->addStyleDeclaration(Omnibus::css());
            $wa->registerAndUseScript('plg_system_mertools.omnibus', 'plg_system_mertools/mertools-omnibus.js', [], ['defer' => true]);
        }
        if (!(int) $this->params->get('dark_enabled', 0)) {
            return;
        }
        $this->loadLanguage();

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
     * After the page has been sent: the work of the tools that goes on after the response (keeping the
     * page, tidying up, pictures, daily and hourly checks), where PHP allows it without the visitor waiting.
     */
    public function onAfterRespond(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        // the visitor has the page: everything from here on (keeping the page, tidying up, pictures,
        // carts) goes on after the response has been closed, where PHP allows it
        $code    = (int) http_response_code();
        $headers = headers_list();
        if ($this->speed()->pageToStore || $this->speed()->pageCacheOn() || $this->speed()->dataCacheOn() || $this->database()->indexesOn() || $this->carts()->cartsOn()
            || (int) $this->params->get('speed_images', 1)) {
            $this->finishResponse();
        }
        if ($this->speed()->pageToStore) {
            $this->speed()->storePage($code, $headers);
        }
        $this->speed()->uncountWarmup();
        if ($this->speed()->pageCacheOn() || $this->speed()->dataCacheOn()) {
            $this->speed()->prunePageCache();
        }
        $this->database()->dailyIndexCheck();
        $this->database()->dailyIntroCheck();
        $this->shop()->hourlyOmnibus();
        $this->missing()->hourlyMoved();
        $this->pages()->hourlyEnded();
        $this->apps()->appBackupTick();
        $this->speed()->imageWork();
        $this->speed()->imageSweep();
        $this->compress()->afterRespond();
        $this->speed()->warmUp();
        $this->carts()->hourly();
    }

    /** An extension booted: see GridboxLists::bootGridbox(). */
    public function onAfterExtensionBoot(EventInterface $event): void
    {
        // every module and plugin boots too: only Gridbox's component is looked at
        if ($event->getArgument('extensionName') === 'gridbox' && $this->getApplication()->isClient('administrator')) {
            $this->lists()->bootGridbox($event);
        }
    }

    /** Page not found: see MissingPages::handle(). */
    public function onError(ErrorEvent $event): void
    {
        $this->missing()->handle($event);
    }

    /** The clear-cache button in the top bar of the administrator: see PageSpeed::headerButton(). */
    public function onAfterModuleList($event): void
    {
        $this->speed()->headerButton($event);
    }

    /**
     * Saving the MerTools settings: pages kept by Joomla's page cache still carry the old settings
     * (and the old script version), so that cache is emptied.
     */
    public function onExtensionAfterSave(EventInterface $event): void
    {
        $context = (string) (method_exists($event, 'getContext') ? $event->getContext() : $event->getArgument('context'));
        $table   = method_exists($event, 'getItem') ? $event->getItem() : $event->getArgument('subject');
        if ($context !== 'com_plugins.plugin' || !is_object($table) || ($table->element ?? '') !== 'mertools') {
            return;
        }
        self::cleanPageCache();
        // the site's address as the administrator reaches it: the warm-up of the page cache works on it
        $this->speed()->rememberSite();
        // the words of the finished items or where they are left out changed: the end dates follow at once
        $this->pages()->syncEnded(new Registry((string) ($table->params ?? '')));
        // Omnibus switched on (or on): the price history starts at once, with the Omnibus plugin's references
        if (Omnibus::enabled(new Registry((string) ($table->params ?? '')))) {
            try {
                Shop::omnibusNext(Shop::newOmnibusStore()->sync());
            } catch (\Throwable $e) {
                // the hourly check starts it then
            }
        }
    }

    /** Gridbox installed or updated: see Database::afterInstall(). */
    public function onExtensionAfterInstallOrUpdate(EventInterface $event): void
    {
        $this->database()->afterInstall($event);
    }

    /**
     * The requests of the settings panels and of the Gridbox lists (?mertools_action=…): POST, the form
     * token and the right for that action are required; the tool of the action answers with JSON.
     */
    private function handleAdministratorAction(): void
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $action = $input->getCmd('mertools_action', '');
        if (!\in_array($action, ['cart_stats', 'cart_check', 'cart_clean', 'speed_list', 'speed_save', 'speed_baseline', 'speed_delete',
            'pcache_stats', 'pcache_clear', 'dbidx_status', 'dbidx_fix', 'img_stats', 'img_run', 'img_clear', 'cache_clear_all', 'history_list',
            'cmp_stats', 'cmp_test', 'cmp_start', 'cmp_run', 'cmp_cancel', 'cmp_list', 'cmp_restore', 'cmp_restore_all',
            'list_columns', 'list_edit', 'list_badges', 'list_pin', 'list_sort', 'apps_list', 'apps_backup', 'apps_restore', 'apps_delete', 'apps_download',
            'nf_list', 'nf_target', 'nf_delete', 'nf_clear', 'nf_moved', 'nf_moved_delete',
            'sm_status', 'sm_rebuild', 'sm_takeover', 'sm_undo', 'sm_compare', 'sm_why'], true)) {
            return;
        }
        $this->loadLanguage();
        $user = $app->getIdentity();
        // the Gridbox lists: own columns (whoever may use the lists), quick edits (whoever may edit)
        if (\in_array($action, ['list_columns', 'list_edit', 'list_badges', 'list_pin', 'list_sort'], true)) {
            $right = \in_array($action, ['list_edit', 'list_badges'], true) ? 'core.edit' : 'core.manage';
            if ($input->getMethod() !== 'POST' || !$user || !$user->authorise($right, 'com_gridbox') || !Session::checkToken('request')) {
                $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
            }
            try {
                $this->sendJson(match ($action) {
                    'list_edit'   => $this->lists()->listEdit(),
                    'list_badges' => $this->lists()->listBadges(),
                    'list_pin'    => $this->lists()->listPin(),
                    'list_sort'   => $this->lists()->listSort(),
                    default       => $this->lists()->listColumnsSave(),
                });
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        // the edit history: whoever may use Gridbox's lists
        if ($action === 'history_list') {
            if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.manage', 'com_gridbox') || !Session::checkToken('request')) {
                $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
            }
            try {
                $this->sendJson(['success' => true] + $this->history()->historyList());
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        // the button of the top bar: whoever may clear the cache in Joomla (System → Clear Cache)
        if ($action === 'cache_clear_all') {
            if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.manage', 'com_cache') || !Session::checkToken('request')) {
                $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
            }
            try {
                $this->sendJson(['success' => true] + $this->speed()->clearAllCaches());
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.edit', 'com_plugins') || !Session::checkToken('request')) {
            $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
        }

        if (str_starts_with($action, 'speed_')) {
            $this->speed()->handleSpeedAction($action);
        }
        if (str_starts_with($action, 'apps_')) {
            $this->apps()->handleAppsAction($action);
        }
        if (str_starts_with($action, 'nf_')) {
            $this->missing()->handleNotFoundAction($action);
        }
        if (str_starts_with($action, 'sm_')) {
            $this->sitemaps()->handleAction($action);
        }
        if (str_starts_with($action, 'dbidx_')) {
            $this->database()->handleAction($action);
        }
        if (str_starts_with($action, 'img_') || str_starts_with($action, 'pcache_')) {
            $this->speed()->handleAction($action);
        }
        if (str_starts_with($action, 'cmp_')) {
            $this->compress()->handleAction($action);
        }
        $this->carts()->handleAction($action);
    }

    /**
     * The settings of MerTools are on its own pages (Components → MerTools): opening the plugin in
     * Joomla's plugin manager goes to its control panel. Not inside a modal window, and not when the
     * component is missing or off: then the plugin's own form, which holds every setting, opens as
     * before.
     */
    private function settingsPage(): void
    {
        $input = $this->getApplication()->getInput();
        if ($input->getCmd('option') !== 'com_plugins' || $input->getMethod() !== 'GET' || $input->getCmd('tmpl') === 'component'
            || !($input->getCmd('task') === 'plugin.edit' || ($input->getCmd('view') === 'plugin' && $input->getCmd('layout') === 'edit'))) {
            return;
        }
        $own  = PluginHelper::getPlugin('system', 'mertools');
        $user = $this->getApplication()->getIdentity();
        if (!\is_object($own) || (int) ($own->id ?? 0) !== $input->getInt('extension_id') || !ComponentHelper::isEnabled('com_mertools')) {
            return;
        }
        // an administrator who may edit plugins but not open the component keeps the plugin's form
        if (!$user || !$user->authorise('core.manage', 'com_mertools') || !$user->authorise('core.manage', 'com_plugins')) {
            return;
        }
        $this->getApplication()->redirect(Route::_('index.php?option=com_mertools', false));
    }

    // ---------------------------------------------------------------- shared by the tools

    public function sendJson(array $data, int $status = 200): void
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

    public function translate(string $key): string
    {
        $text = $this->getApplication()->getLanguage()->_($key);

        return $text === $key ? '' : $text;
    }

    /**
     * The Gridbox page builder (its editor frame shows the page on the site side): nothing is added
     * there, or links and colour marks could be saved into the page content.
     */
    public function inBuilder(): bool
    {
        $input = $this->getApplication()->getInput();

        return $input->getCmd('option') === 'com_gridbox' && \in_array($input->getCmd('view'), ['editor', 'gridbox'], true);
    }

    /** The visitor gets the page now; the work after it goes on without them waiting (where PHP allows it). */
    public function finishResponse(): bool
    {
        static $finished = null;
        if ($finished === null) {
            $finished = false;
            if (\function_exists('fastcgi_finish_request')) {
                $finished = (bool) @fastcgi_finish_request();
            } elseif (\function_exists('litespeed_finish_request')) {
                $finished = (bool) @litespeed_finish_request();
            }
        }

        return $finished;
    }

    /**
     * Where Joomla keeps its cache: the configured path, else administrator/cache (site and administrator
     * share it since Joomla 4). Always an absolute path: a relative one ("cache/" on merserwis.pl) would
     * point elsewhere in the administrator and at shutdown, where the working folder is another one.
     */
    public static function cacheBase(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        try {
            $path = (string) Factory::getApplication()->get('cache_path', '');
        } catch (\Throwable $e) {
            $path = '';
        }

        return $base = PageCache::absolutePath($path, JPATH_ROOT, \defined('JPATH_CACHE') ? JPATH_CACHE : JPATH_ADMINISTRATOR . '/cache');
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
     * A routed address of a Gridbox page or blog page, absolute: Gridbox's own link helper (it finds
     * the menu item the page belongs to), else the plain address.
     */
    public function gridboxLink(string $method, array $args, string $plain, string $extra = '', bool $absolute = true): string
    {
        $link   = $plain;
        $helper = 'Balbooa\\Component\\Gridbox\\Site\\Helper\\GridboxHelper';
        try {
            if (class_exists($helper) && method_exists($helper, $method)) {
                $link = (string) $helper::$method(...$args);
            }
        } catch (\Throwable $e) {
            $link = $plain;
        }

        return Route::_($link . $extra, false, Route::TLS_IGNORE, $absolute);
    }

    /** The store's currency (as Gridbox shows prices in its lists). */
    public function storeCurrency(): array
    {
        static $currency = null;
        if ($currency === null) {
            try {
                $db       = Factory::getContainer()->get(DatabaseInterface::class);
                $currency = StoreCurrency::from((string) $db->setQuery('SELECT ' . $db->quoteName('key') . ' FROM ' . $db->quoteName('#__gridbox_api')
                    . ' WHERE service = ' . $db->quote('store'))->loadResult());
            } catch (\Throwable $e) {
                $currency = StoreCurrency::DEFAULT;
            }
        }

        return $currency;
    }

    /** @return int[] the access levels of a guest (Public and Guest on a usual site) */
    public static function guestLevels(): array
    {
        try {
            $levels = array_values(array_map('intval', Access::getAuthorisedViewLevels(0)));
        } catch (\Throwable $e) {
            $levels = [];
        }

        return $levels ?: [1];
    }

    /** "Name (username)" of the user, as kept with a lock and in the history. */
    public static function userLabel($user): string
    {
        return trim((string) $user->name) . ' (' . (string) $user->username . ')';
    }

    /**
     * The tasks of the editor's save (lower case): Gridbox up to 2.20.4 editor.gridboxSave (the page as the
     * request body) and its fallback editor.gridboxAjaxSave, from 2.20.5 editor.saveGridbox (both: the form
     * field obj). Its saves of a version (…Version) change no page.
     */
    public const EDITOR_SAVES = ['editor.gridboxsave', 'editor.gridboxajaxsave', 'editor.savegridbox'];

    /** Is the task (any case) a save of the editor? */
    public static function isEditorSave(string $task): bool
    {
        return \in_array(strtolower($task), self::EDITOR_SAVES, true);
    }

    /** The JSON of the editor's save: the request body (editor.gridboxSave) or the form field obj (the others). */
    public function editorSaveObj(string $task): string
    {
        static $obj = null;

        return $obj ??= strtolower($task) === 'editor.gridboxsave' ? (string) @file_get_contents('php://input')
            : (string) $this->getApplication()->getInput()->get('obj', '', 'raw');
    }

    /** a script of the Gridbox editor (media/js/mertools-{name}.js) with its settings, put before </body> */
    public function editorScript(string $name, array $cfg): void
    {
        $app  = $this->getApplication();
        $tail = '<script type="application/json" id="mertools-' . $name . '-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-' . $name . (JDEBUG ? '' : '.min') . '.js?' . self::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    // ---------------------------------------------------------------- the tools, one per setting tab

    public function feeds(): Feeds
    {
        return $this->features[Feeds::class] ??= new Feeds($this);
    }

    public function lists(): GridboxLists
    {
        return $this->features[GridboxLists::class] ??= new GridboxLists($this);
    }

    public function apps(): AppsProtection
    {
        return $this->features[AppsProtection::class] ??= new AppsProtection($this);
    }

    public function locks(): EditLocks
    {
        return $this->features[EditLocks::class] ??= new EditLocks($this);
    }

    public function editorSave(): EditorSave
    {
        return $this->features[EditorSave::class] ??= new EditorSave($this);
    }

    public function editorFields(): EditorFields
    {
        return $this->features[EditorFields::class] ??= new EditorFields($this);
    }

    public function editorImport(): EditorImport
    {
        return $this->features[EditorImport::class] ??= new EditorImport($this);
    }

    public function history(): EditHistory
    {
        return $this->features[EditHistory::class] ??= new EditHistory($this);
    }

    public function speed(): PageSpeed
    {
        return $this->features[PageSpeed::class] ??= new PageSpeed($this);
    }

    public function compress(): Compress
    {
        return $this->features[Compress::class] ??= new Compress($this);
    }

    public function database(): Database
    {
        return $this->features[Database::class] ??= new Database($this);
    }

    public function carts(): Carts
    {
        return $this->features[Carts::class] ??= new Carts($this);
    }

    public function missing(): MissingPages
    {
        return $this->features[MissingPages::class] ??= new MissingPages($this);
    }

    public function pages(): ListPages
    {
        return $this->features[ListPages::class] ??= new ListPages($this);
    }

    public function sitemaps(): Sitemaps
    {
        return $this->features[Sitemaps::class] ??= new Sitemaps($this);
    }

    public function hygiene(): Hygiene
    {
        return $this->features[Hygiene::class] ??= new Hygiene($this);
    }

    public function siteSchema(): SiteSchema
    {
        return $this->features[SiteSchema::class] ??= new SiteSchema($this);
    }

    public function shop(): Shop
    {
        return $this->features[Shop::class] ??= new Shop($this);
    }

    /** The application, for the tools (CMSPlugin keeps getApplication() to itself). */
    public function app(): CMSApplicationInterface
    {
        return $this->getApplication();
    }
}
