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
 *  - Links to a product option: a link with a chosen product option ("?Zestawy+Metrel+MI+3155=…")
 *    opens the product with that option selected. Runs in onAfterRoute, on Gridbox pages only.
 */

namespace Merserwis\Plugin\System\MerTools\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Merserwis\Plugin\System\MerTools\Tool\DarkMode;
use Merserwis\Plugin\System\MerTools\Tool\Layout;
use Merserwis\Plugin\System\MerTools\Tool\Phones;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

final class MerTools extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '0.0.15';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            // before routing, so the clean address is served before Gridbox or the SEF router act
            'onAfterInitialise'   => ['onAfterInitialise', Priority::HIGH],
            'onAfterRoute'        => 'onAfterRoute',
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
