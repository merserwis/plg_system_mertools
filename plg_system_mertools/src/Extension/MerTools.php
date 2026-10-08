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
 *  - Links to a product option: a link with a chosen product option ("?Zestawy+Metrel+MI+3155=…")
 *    opens the product with that option selected. Runs in onAfterRoute, on Gridbox pages only.
 */

namespace Merserwis\Plugin\System\MerTools\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Factory;
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
use Merserwis\Plugin\System\MerTools\Tool\Layout;
use Merserwis\Plugin\System\MerTools\Tool\NotFound;
use Merserwis\Plugin\System\MerTools\Tool\Phones;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

final class MerTools extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '0.0.19';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            // before routing, so the clean address is served before Gridbox or the SEF router act
            'onAfterInitialise'   => ['onAfterInitialise', Priority::HIGH],
            'onAfterRoute'        => 'onAfterRoute',
            'onAfterRender'       => 'onAfterRender',
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
        if ($app->isClient('administrator')) {
            $this->handleAdministratorAction();

            return;
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
        if (!$app->isClient('site') || !$this->cartsOn()) {
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
        if (!\in_array($action, ['cart_stats', 'cart_check', 'cart_clean'], true)) {
            return;
        }
        $this->loadLanguage();
        $user = $app->getIdentity();
        if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.edit', 'com_plugins') || !Session::checkToken('request')) {
            $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
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
        if (!$app->isClient('site') || !(int) $this->params->get('shop_option_links', 1)) {
            return;
        }
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'page') {
            return;
        }
        $document = $app->getDocument();
        if (!$document || $document->getType() !== 'html') {
            return;
        }
        $body   = (string) $app->getBody();
        $marked = ProductLinks::markChosenRadios($body);
        if ($marked !== $body) {
            $app->setBody($marked);
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
        $wa->addInlineScript(DarkMode::inlineScript($this->params), ['position' => 'before'], ['type' => 'text/javascript']);
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

    /** Where Joomla keeps its cache: the configured path, else administrator/cache (site and administrator share it since Joomla 4). */
    public static function cacheBase(): string
    {
        try {
            $path = (string) Factory::getApplication()->get('cache_path', '');
        } catch (\Throwable $e) {
            $path = '';
        }

        return $path !== '' ? $path : (\defined('JPATH_CACHE') ? JPATH_CACHE : JPATH_ADMINISTRATOR . '/cache');
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
