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
 *  - Price on request (PriceRequest): a product without a price shows a text ("On request") where
 *    its price would be, one whose models have prices "Choose a model from the list", only in the
 *    lists and sliders that show prices (mertools-price.js; the list products with priced models
 *    are marked in onAfterRender).
 *  - Omnibus (Omnibus): the "lowest price of the last 30 days" line of an Omnibus plugin only while
 *    the product or its chosen version is on sale; the plugin's own script (which polled every half
 *    second) is taken off the page in onAfterRender and replaced by mertools-omnibus.js.
 *  - Gridbox lists (ListColumns): each user chooses, orders and adds the columns of the product and
 *    post lists, and edits prices, sale prices, stock and SKU right in the list.
 *  - Edit lock (EditLock): one person at a time in the Gridbox editor of a page; the others see who
 *    edits it (and may take it over, by the setting), a save of an editor that lost the lock is refused.
 *  - Edit history (History): who changed which Gridbox page or product and when, from the editor's
 *    saves and the actions of the Gridbox lists; shown under the titles of the Gridbox lists, in a
 *    window per page, on the tab "Edit history" and (optionally) in Joomla's User Actions Log.
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
use Merserwis\Plugin\System\MerTools\Tool\DbIndexes;
use Merserwis\Plugin\System\MerTools\Tool\EditLock;
use Merserwis\Plugin\System\MerTools\Tool\History;
use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;
use Merserwis\Plugin\System\MerTools\Tool\ImageSize;
use Merserwis\Plugin\System\MerTools\Tool\Layout;
use Merserwis\Plugin\System\MerTools\Tool\IntroText;
use Merserwis\Plugin\System\MerTools\Tool\ListColumns;
use Merserwis\Plugin\System\MerTools\Tool\NotFound;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\PageCache;
use Merserwis\Plugin\System\MerTools\Tool\PostRows;
use Merserwis\Plugin\System\MerTools\Tool\Phones;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;
use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;
use Merserwis\Plugin\System\MerTools\Tool\Speed;
use Merserwis\Plugin\System\MerTools\Tool\Speedup;
use Merserwis\Plugin\System\MerTools\Tool\Tabs;
use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

final class MerTools extends CMSPlugin implements SubscriberInterface
{
    public const VERSION = '1.0.11';

    /** marketing scripts delayed by default (address or code contains) */
    public const SCRIPT_PATTERNS = "googletagmanager.com\nfbq(\nconnect.facebook.net\nclarity.ms\nhotjar.com\nelfsightcdn.com\ncloudflareinsights.com";

    /** the language file (60 KB) is loaded only where texts are needed, not on every page view */
    protected $autoloadLanguage = false;

    /** the edit lock this editor page holds (key, page, tab), for its script */
    private ?array $editorLock = null;

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
            // the administrator: the clear-cache button in the top bar, next to the Joomla version
            'onAfterModuleList'   => 'onAfterModuleList',
            'onExtensionAfterSave' => 'onExtensionAfterSave',
            // Gridbox installed or updated: its tables may have been created again, without our indexes
            'onExtensionAfterInstall' => 'onExtensionAfterInstallOrUpdate',
            'onExtensionAfterUpdate'  => 'onExtensionAfterInstallOrUpdate',
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
        if ($app->isClient('site')) {
            $this->editLock();
        }
        if ($app->isClient('administrator') || $app->isClient('site')) {
            // before the history: it then notes the introduction as it is kept
            $this->introGuard();
            $this->trackHistory();
            $this->purgeOnChange();
        }
        if ($app->isClient('administrator')) {
            $this->adminLockGuard();
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

        $restored = ProductLinks::restoredParams((string) $input->server->getRaw('QUERY_STRING', ''), $_GET);
        foreach ($restored as $name => $value) {
            // Gridbox reads $input->get; anything that builds its own input from $_GET later sees them too
            $input->get->set($name, $value);
            $_GET[$name] = $value;
        }
    }

    // ---------------------------------------------------------------- Gridbox lists: columns and quick edits

    /** The list of this request: "apps.<id>" (an app's products or posts) or "pages" (single pages); null elsewhere. */
    private function gridboxList(): ?string
    {
        $input = $this->getApplication()->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('format', 'html') !== 'html') {
            return null;
        }
        $view = $input->getCmd('view', '');
        if ($view === 'apps' && $input->getInt('id', 0) > 0) {
            return 'apps.' . $input->getInt('id', 0);
        }

        return $view === 'pages' ? 'pages' : null;
    }

    private function listProfileKey(string $list): string
    {
        return 'mertools.listcols.' . $list;
    }

    /** @return array{order: string[], known: string[]}|null the user's saved columns of a list */
    private function listPrefs(int $userId, string $list): ?array
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $raw = $db->setQuery('SELECT profile_value FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . $userId . ' AND profile_key = '
            . $db->quote($this->listProfileKey($list)))->loadResult();
        $prefs = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($prefs) && isset($prefs['order']) && \is_array($prefs['order'])
            ? ['order' => array_map('strval', $prefs['order']), 'known' => array_map('strval', (array) ($prefs['known'] ?? []))] : null;
    }

    /** The store's currency (as Gridbox shows prices in its lists). */
    private function storeCurrency(): array
    {
        static $currency = null;
        if ($currency === null) {
            $currency = ['symbol' => '', 'position' => '', 'thousand' => ' ', 'separator' => ',', 'decimals' => 2];
            try {
                $db    = Factory::getContainer()->get(DatabaseInterface::class);
                $store = json_decode((string) $db->setQuery('SELECT ' . $db->quoteName('key') . ' FROM ' . $db->quoteName('#__gridbox_api') . ' WHERE service = '
                    . $db->quote('store'))->loadResult());
                if (\is_object($store->currency ?? null)) {
                    $currency = array_merge($currency, array_intersect_key((array) $store->currency, $currency));
                }
            } catch (\Throwable $e) {
            }
        }

        return $currency;
    }

    /**
     * The Gridbox list as the user arranged it: Gridbox's columns in the chosen order (hidden ones out),
     * the added columns, and the cells of price, sale price, stock and SKU editable in place. In a
     * folder, the products (posts) added to it whose main folder is another one are listed after its
     * own, greyed out: Gridbox lists an item only in its main folder.
     */
    private function listColumns(): void
    {
        $list = $this->gridboxList();
        if ($list === null) {
            return;
        }
        $app       = $this->getApplication();
        $input     = $app->getInput();
        $columnsOn = (int) $this->params->get('list_columns', 1) === 1;
        $category  = str_starts_with($list, 'apps.') && $input->getCmd('layout', '') !== 'modal' ? $input->getInt('category', 0) : 0;
        $ghostsOn  = $category > 0 && (int) $this->params->get('list_ghosts', 1) === 1;
        if (!$columnsOn && !$ghostsOn) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage', 'com_gridbox')) {
            return;
        }
        $body   = (string) $app->getBody();
        $bounds = ListColumns::tableBounds($body);
        if (!$bounds) {
            return;
        }
        [$start, $end] = $bounds;
        $table = substr($body, $start, $end - $start);
        $head  = ListColumns::headCells($table);
        if (!$head) {
            return;
        }
        $ids     = preg_match_all('/name="cid\[\]" value="(\d+)"/', $table, $m) ? array_map('intval', $m[1]) : [];
        $builtin = array_column($head, 'key');
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $this->loadLanguage();

        try {
            $appId   = str_starts_with($list, 'apps.') ? (int) substr($list, 5) : 0;
            $appType = $appId ? (string) $db->setQuery('SELECT type FROM ' . $db->quoteName('#__gridbox_app') . ' WHERE id = ' . $appId)->loadResult() : 'single';
            $product = $appType === 'products';

            // the items of other folders added to this one: listed on the last page of the list
            $ghosts   = $ghostsOn ? $this->ghostItems($appId, $appType, $category) : [];
            $lastPage = ListColumns::lastPage(substr($body, $end, 6000));
            if ((!$ids && !$ghosts) || (!$columnsOn && !$ghosts)) {
                return;
            }

            // the columns MerTools can add to this list
            $extra = [];
            if (!$columnsOn) {
                // only the ghosts: Gridbox's columns as they are
            } elseif ($product) {
                $extra['sale_price'] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_SALE_PRICE');
                $extra['min']        = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_MIN');
                $extra['badges']     = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_BADGES');
                $extra['variants']   = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_VARIANTS');
            }
            foreach ($columnsOn ? ['created' => 'CREATED', 'end_publishing' => 'END_PUBLISHING'] : [] as $key => $label) {
                if (!\in_array($key, $builtin, true)) {
                    $extra[$key] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_' . $label);
                }
            }
            $fields       = [];
            $hiddenFields = self::hiddenFields((string) $this->params->get('list_fields_hidden', self::LIST_FIELDS_HIDDEN));
            if ($appId && $columnsOn) {
                foreach ($db->setQuery('SELECT id, label, field_type, options FROM ' . $db->quoteName('#__gridbox_fields') . ' WHERE app_id = ' . $appId
                    . ' AND field_type IN (\'text\', \'textarea\', \'select\', \'radio\', \'checkbox\', \'url\', \'email\', \'number\', \'date\', \'price\', \'time\')'
                    . ' ORDER BY order_list, id')->loadAssocList() ?: [] as $field) {
                    // a field without a real name (empty, "." …) or hidden in the settings is not offered
                    if (!self::fieldOffered($field, $hiddenFields)) {
                        continue;
                    }
                    $fields[(int) $field['id']] = $field;
                    $extra['field_' . (int) $field['id']] = trim((string) $field['label']) ?: '#' . (int) $field['id'];
                }
            }

            $prefs   = $columnsOn ? $this->listPrefs((int) $user->id, $list) : null;
            $visible = ListColumns::visible($builtin, array_keys($extra), $prefs['order'] ?? null, $prefs['known'] ?? []);
            $shown   = array_flip($visible);

            // the data of the shown rows, only for the columns shown (and the editable cells)
            $ghostIds = $lastPage ? array_keys($ghosts) : [];
            $in       = implode(',', array_merge($ids, $ghostIds) ?: [0]);
            $pages = [];
            foreach ($db->setQuery('SELECT id, created, end_publishing, featured, language FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE id IN (' . $in . ')')->loadAssocList() ?: [] as $row) {
                $pages[(int) $row['id']] = $row;
            }
            $products = [];
            if ($product) {
                foreach ($db->setQuery('SELECT product_id, price, sale_price, stock, sku, min, variations FROM ' . $db->quoteName('#__gridbox_store_product_data')
                    . ' WHERE product_id IN (' . $in . ') ORDER BY id')->loadAssocList() ?: [] as $row) {
                    $products[(int) $row['product_id']] ??= $row;
                }
            }
            $badges    = [];
            $allBadges = [];
            if ($product) {
                foreach ($db->setQuery('SELECT id, title, color, type FROM ' . $db->quoteName('#__gridbox_store_badges') . ' ORDER BY id')->loadAssocList() ?: [] as $badge) {
                    $allBadges[(int) $badge['id']] = $badge;
                }
            }
            if (isset($shown['badges'])) {
                foreach ($db->setQuery('SELECT product_id, badge_id FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id IN (' . $in
                    . ') ORDER BY order_list, id')->loadRowList() ?: [] as [$pid, $bid]) {
                    if (isset($allBadges[(int) $bid])) {
                        $badges[(int) $pid][] = (int) $bid;
                    }
                }
            }
            $values = [];
            $wanted = array_filter(array_keys($fields), static fn ($id) => isset($shown['field_' . $id]));
            if ($wanted) {
                foreach ($db->setQuery('SELECT page_id, field_id, value FROM ' . $db->quoteName('#__gridbox_page_fields') . ' WHERE page_id IN (' . $in . ') AND field_id IN ('
                    . implode(',', $wanted) . ')')->loadRowList() ?: [] as [$pid, $fid, $value]) {
                    $values[(int) $pid][(int) $fid] = (string) $value;
                }
            }
        } catch (\Throwable $e) {
            return;
        }

        $currency = $this->storeCurrency();
        $edit     = (int) $this->params->get('list_quickedit', 1) === 1 && $user->authorise('core.edit', 'com_gridbox');
        $esc      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $date     = static fn ($v) => ($v === '' || $v === null || str_starts_with((string) $v, '0000')) ? '' : HTMLHelper::_('date', (string) $v, 'd.m.Y H:i', false);

        $extraCell = function (string $key, int $id) use ($pages, $products, $badges, $allBadges, $values, $fields, $currency, $esc, $date): string {
            $page = $pages[$id] ?? [];
            switch ($key) {
                case 'sale_price':
                    return $esc(ListColumns::money((string) ($products[$id]['sale_price'] ?? ''), $currency));
                case 'min':
                    return $esc((string) ($products[$id]['min'] ?? ''));
                case 'created':
                case 'end_publishing':
                    return $esc($date($page[$key] ?? ''));
                case 'badges':
                    return self::badgesHtml($badges[$id] ?? [], $allBadges, $products[$id] ?? []);
                case 'variants':
                    if (!isset($products[$id])) {
                        return '';
                    }

                    return $esc(Text::_(self::hasVariants((string) $products[$id]['variations']) ? 'JYES' : 'JNO'));
            }
            if (str_starts_with($key, 'field_')) {
                $fid = (int) substr($key, 6);

                return $esc(self::fieldText($fields[$fid] ?? [], $values[$id][$fid] ?? ''));
            }

            return '';
        };
        $ghostSet = array_flip($ghostIds);
        $decorate = function (string $key, string $cell, int $id) use ($products, $badges, $edit, $ghostSet): string {
            if (isset($ghostSet[$id])) {
                return $cell;
            }
            if ($edit && $key === 'badges' && isset($products[$id])) {
                return ListColumns::withAttributes($cell, ['data-mt-badges' => $id, 'data-mt-raw' => implode(',', $badges[$id] ?? []), 'tabindex' => '0']);
            }
            if (!$edit || !isset(ListColumns::EDITABLE[$key]) || !isset($products[$id])) {
                return $cell;
            }
            $row  = $products[$id];
            $attr = ['data-mt-edit' => $key, 'data-mt-id' => $id, 'data-mt-raw' => (string) ($row[$key] ?? ''), 'tabindex' => '0'];
            if ($key === 'price' && self::hasVariants((string) $row['variations'])) {
                $attr['data-mt-variants'] = '1';
            }

            return ListColumns::withAttributes($cell, $attr);
        };
        if ($ghosts) {
            $rows = '';
            foreach ($ghostIds as $gid) {
                $rows .= '<tr class="mt-ghost">' . implode('', array_map(fn ($key) => $this->ghostCell($key, $ghosts[$gid], $products[$gid] ?? [], $appId, $currency),
                    $builtin)) . '</tr>';
            }
            $note  = Text::sprintf($lastPage ? 'PLG_SYSTEM_MERTOOLS_LIST_GHOSTS_HEAD' : 'PLG_SYSTEM_MERTOOLS_LIST_GHOSTS_LATER', \count($ghosts));
            $rows  = '<tr class="mt-ghost-head"><td colspan="' . \count($visible) . '">' . $esc($note) . '</td></tr>' . $rows;
            $close = strrpos($table, '</table>');
            $table = substr($table, 0, $close) . '<tbody class="mt-ghosts">' . $rows . '</tbody>' . substr($table, $close);
        }
        if ($columnsOn) {
            $table = ListColumns::apply($table, $visible, $extra, $extraCell, $decorate);
        }

        // the columns window: Gridbox's columns (labels from its head), then the added ones
        $columns = [];
        $labels  = array_column($head, 'label', 'key');
        foreach (array_merge($builtin, array_keys($extra)) as $key) {
            if ($key === 'select') {
                continue;
            }
            $columns[] = ['key' => $key, 'label' => $labels[$key] ?? $extra[$key] ?? $key, 'visible' => isset($shown[$key]), 'locked' => $key === 'title',
                'added' => isset($extra[$key])];
        }
        usort($columns, static function ($a, $b) use ($visible) {
            $ia = array_search($a['key'], $visible, true);
            $ib = array_search($b['key'], $visible, true);

            return ($ia === false ? 1000 : $ia) <=> ($ib === false ? 1000 : $ib);
        });
        $texts = [];
        foreach (['BUTTON', 'TITLE', 'HINT', 'SAVE', 'RESET', 'CANCEL', 'UP', 'DOWN', 'ADDED', 'SAVING', 'ERROR', 'EDIT_HINT', 'VARIANTS', 'SAVED', 'EMPTY_SALE',
            'BADGES_TITLE', 'BADGES_NONE'] as $k) {
            $texts[$k] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_' . $k);
        }
        $edit   = $edit && $columnsOn;
        $config = ['url' => Uri::base(true) . '/index.php', 'token' => Session::getFormToken(), 'list' => $list, 'builtin' => array_values(array_diff($builtin, ['select'])),
            'columns' => $columns, 'edit' => $edit, 'texts' => $texts,
            'badges' => array_values(array_map(static fn ($b) => ['id' => (int) $b['id'], 'label' => self::badgeLabel($b), 'color' => (string) $b['color']], $allBadges))];
        $tools  = !$columnsOn ? '' : '<div class="mt-list-tools"><button type="button" class="mt-cols-btn" data-mt-columns><span aria-hidden="true">☰</span> ' . $esc($texts['BUTTON']) . '</button>'
            . ($edit && $product ? '<span class="mt-list-hint">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_EDIT_HINT_BADGES')) . '</span>' : '') . '</div>';
        $body = substr($body, 0, $start) . $tools . $table . substr($body, $end);
        $tail = '<style>' . self::LIST_CSS . ($ghosts ? self::GHOST_CSS : '') . '</style><script type="application/json" id="mertools-lists-config">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . Uri::root(true)
            . '/media/plg_system_mertools/js/mertools-lists' . (JDEBUG ? '' : '.min') . '.js?' . self::VERSION . '" defer></script>';
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    public const LIST_CSS = '.mt-list-tools{display:flex;align-items:center;gap:12px;margin:0 0 8px}.mt-cols-btn{border:1px solid #d1d5db;background:#fff;border-radius:6px;'
        . 'padding:6px 12px;cursor:pointer;font-size:13px}.mt-cols-btn:hover{border-color:#1d4ed8;color:#1d4ed8}.mt-list-hint{font-size:12px;color:#6b7280}'
        . '[data-mt-edit]{cursor:pointer;position:relative}[data-mt-edit]:hover{outline:1px dashed #93c5fd;outline-offset:-3px}[data-mt-edit].mt-saving{opacity:.5}'
        . '[data-mt-edit].mt-saved{background:#dcfce7!important;transition:background 1.5s}[data-mt-edit] input{width:100%;min-width:80px;box-sizing:border-box;'
        . 'padding:3px 6px;font:inherit;border:1px solid #1d4ed8;border-radius:4px}[data-mt-variants]::after{content:"ⓘ";font-size:11px;color:#b45309;margin-left:4px}'
        . '[data-mt-badges]{cursor:pointer;min-width:60px}[data-mt-badges]:hover{outline:1px dashed #93c5fd;outline-offset:-3px}'
        . '.mt-badge{display:inline-block;color:#fff;font-size:11px;font-weight:600;padding:1px 7px;border-radius:10px;margin:1px 2px 1px 0;white-space:nowrap}'
        . '.mt-col{font-size:13px}.mt-col small{color:#6b7280}';

    public const GHOST_CSS = 'tbody.mt-ghosts tr.mt-ghost td{color:#9ca3af!important;background:#fafafa}tbody.mt-ghosts tr.mt-ghost .post-intro-image{filter:grayscale(1);opacity:.45}'
        . 'tbody.mt-ghosts tr.mt-ghost .mt-badge{opacity:.5}.mt-ghost-title{display:flex;align-items:center;line-height:18px;font-style:italic}'
        . '.mt-ghost-title .post-intro-image{display:block;flex:0 0 50px;width:50px;height:50px;margin-right:15px;background-size:cover;background-position:center;border-radius:3px}'
        . '.mt-ghost-note{margin:4px 0 0 65px;font-size:12px;line-height:16px;color:#6b7280}'
        . '.mt-ghost-note a{display:inline!important;color:#1d4ed8!important;text-decoration:underline}.mt-ghost-mark{font-size:15px;color:#9ca3af}'
        . 'tbody.mt-ghosts tr.mt-ghost-head td{background:#f3f4f6;color:#4b5563;font-size:12px;font-weight:600;padding:8px 14px;text-align:left!important;border-top:2px dashed #d1d5db}';

    /**
     * The items of other folders added to this one (Gridbox keeps the extra folders of an item in
     * #__gridbox_category_page_map, the main one in page_category), with the list's filters applied.
     *
     * @return array<int, array<string, mixed>>  id => item
     */
    private function ghostItems(int $appId, string $appType, int $category): array
    {
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $app     = $this->getApplication();
        $context = 'com_gridbox.apps.' . strtolower($appType);
        $query   = 'SELECT DISTINCT p.id, p.title, p.published, p.intro_image, p.page_category, p.hits, p.created, p.language, c.title AS main_title'
            . ' FROM ' . $db->quoteName('#__gridbox_category_page_map') . ' AS m JOIN ' . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = m.page_id'
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_categories') . ' AS c ON c.id = p.page_category'
            . ' WHERE m.category_id = ' . $category . ' AND p.app_id = ' . $appId . ' AND p.page_category <> ' . $db->quote('trashed')
            . ' AND p.page_category <> ' . $db->quote((string) $category);
        // the filters of the list (as Gridbox applies them to its own rows)
        $search = trim((string) $app->getUserState($context . '.filter.search', ''));
        if ($search !== '') {
            $query .= ' AND p.title LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false);
        }
        $state = (string) $app->getUserState($context . '.filter.state', '');
        $query .= is_numeric($state) ? ' AND p.published = ' . (int) $state : ' AND p.published IN (0, 1)';
        if ((int) $app->getUserState($context . '.filter.theme', 0)) {
            $query .= ' AND p.theme = ' . (int) $app->getUserState($context . '.filter.theme', 0);
        }
        if ((int) $app->getUserState($context . '.filter.author', 0)) {
            $query .= ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__gridbox_authors_map') . ' AS a WHERE a.page_id = p.id AND a.author_id = '
                . (int) $app->getUserState($context . '.filter.author', 0) . ')';
        }
        foreach (['access' => 'page_access', 'language' => 'language'] as $filter => $column) {
            $value = (string) $app->getUserState($context . '.filter.' . $filter, '');
            if ($value !== '') {
                $query .= ' AND p.' . $column . ' = ' . $db->quote($value);
            }
        }
        $items = [];
        foreach ($db->setQuery($query . ' ORDER BY c.title, p.title LIMIT 500')->loadAssocList() ?: [] as $row) {
            $items[(int) $row['id']] = $row;
        }

        return $items;
    }

    /** A cell of a ghost row, for a column of Gridbox's list: shown only, nothing to click but the main folder. */
    private function ghostCell(string $key, array $item, array $product, int $appId, array $currency): string
    {
        $esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        switch ($key) {
            case 'select':
                // no tick box: Gridbox's bulk actions and its row menu are for the items of this folder
                return '<td class="select-td" data-mt-ghost="' . (int) $item['id'] . '"><span class="mt-ghost-mark" aria-hidden="true">↪</span></td>';
            case 'published':
                $on = (int) $item['published'] === 1;

                return '<td class="status-td"><i class="zmdi zmdi-' . ($on ? 'eye' : 'eye-off') . ' ba-icon-md" title="' . $esc(Text::_($on ? 'JPUBLISHED' : 'JUNPUBLISHED')) . '"></i></td>';
            case 'title':
                $image = trim((string) $item['intro_image']);
                if ($image !== '' && !preg_match('#^(https?:)?//#i', $image)) {
                    $image = Uri::root() . ltrim($image, '/');
                }
                $folder = '<a href="' . $esc('index.php?option=com_gridbox&view=apps&id=' . $appId . '&category=' . (int) $item['page_category']) . '">'
                    . $esc((string) ($item['main_title'] ?? '') !== '' ? $item['main_title'] : '#' . $item['page_category']) . '</a>';

                return '<td class="title-cell" title="' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LIST_GHOST_TIP')) . '"><span class="mt-ghost-title">'
                    . ($image !== '' ? '<span class="post-intro-image" style="background-image: url(' . $esc($image) . ');"></span>' : '')
                    . $esc($item['title']) . '</span><div class="mt-ghost-note"' . ($image === '' ? ' style="margin-left:0"' : '') . '>' . Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_GHOST_NOTE', $folder) . '</div></td>';
            case 'price':
                return '<td class="price-cell">' . $esc(isset($product['price']) ? ListColumns::money((string) $product['price'], $currency) : '') . '</td>';
            case 'stock':
            case 'sku':
                return '<td class="' . $key . '-cell">' . $esc($product[$key] ?? '') . '</td>';
            case 'page_category':
                return '<td class="category-cell">' . $esc($item['main_title'] ?? '') . '</td>';
            case 'hits':
                return '<td class="hits-cell">' . (int) $item['hits'] . '</td>';
            case 'created':
                return '<td>' . $esc(str_starts_with((string) $item['created'], '0000') ? '' : HTMLHelper::_('date', (string) $item['created'], Text::_('DATE_FORMAT_LC4'), false)) . '</td>';
            case 'language':
                return '<td>' . $esc($item['language'] === '*' ? Text::_('JALL') : $item['language']) . '</td>';
            case 'id':
                return '<td>' . (int) $item['id'] . '</td>';
        }

        return '<td></td>';
    }

    /** The value of a Gridbox field as text: the title of a chosen option, the label of a link, a short text. */
    private static function fieldText(array $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $type    = (string) ($field['field_type'] ?? '');
        $options = [];
        $json    = json_decode((string) ($field['options'] ?? ''), true);
        foreach ((array) ($json['items'] ?? []) as $item) {
            if (\is_array($item) && isset($item['key'])) {
                $options[(string) $item['key']] = (string) ($item['title'] ?? $item['key']);
            }
        }
        if (\in_array($type, ['select', 'radio'], true)) {
            return $options[$value] ?? $value;
        }
        if ($type === 'checkbox') {
            $keys = json_decode($value, true);

            return implode(', ', array_map(static fn ($k) => $options[(string) $k] ?? (string) $k, \is_array($keys) ? $keys : [$value]));
        }
        if ($type === 'url') {
            $link = json_decode($value, true);

            return \is_array($link) ? (string) ($link['label'] ?? $link['link'] ?? '') : $value;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }

    /** Fields hidden from the columns window by default (a link to a manual is no column for a list). */
    public const LIST_FIELDS_HIDDEN = 'Instrukcja obsługi Metrel';

    /** @return array<string, true> the hidden fields of the setting: names (lower case, without a final ":") and ids */
    private static function hiddenFields(string $setting): array
    {
        $out = [];
        foreach (preg_split('/\R|,/', $setting) ?: [] as $line) {
            $line = mb_strtolower(rtrim(trim($line), ':'));
            if ($line !== '') {
                $out[$line] = true;
            }
        }

        return $out;
    }

    /** A field offered as a column: it has a real name (letters or digits) and is not hidden by name or id. */
    private static function fieldOffered(array $field, array $hidden): bool
    {
        $label = trim((string) ($field['label'] ?? ''));
        if (!preg_match('/[\p{L}\p{N}]/u', $label)) {
            return false;
        }

        return !isset($hidden[mb_strtolower(rtrim($label, ':'))]) && !isset($hidden[(string) (int) ($field['id'] ?? 0)]);
    }

    /** The product has versions or sets (Gridbox options with their own prices). */
    private static function hasVariants(string $variations): bool
    {
        $data = json_decode($variations, true);

        return \is_array($data) && $data !== [];
    }

    /** The name of a badge: its title; the discount badge of Gridbox ("sale", no title) as "Discount −%". */
    private static function badgeLabel(array $badge): string
    {
        $title = trim((string) ($badge['title'] ?? ''));

        return ($badge['type'] ?? '') === 'sale' && $title === '' ? Text::_('PLG_SYSTEM_MERTOOLS_LIST_BADGE_SALE') : ($title !== '' ? $title : '#' . (int) $badge['id']);
    }

    /** The badges of a product as Gridbox shows them (the discount with its percentage). */
    private static function badgesHtml(array $ids, array $all, array $product): string
    {
        $out = [];
        foreach ($ids as $id) {
            $badge = $all[$id] ?? null;
            if (!$badge) {
                continue;
            }
            $label = self::badgeLabel($badge);
            if (($badge['type'] ?? '') === 'sale' && trim((string) $badge['title']) === '' && isset($product['price'])) {
                $price = (float) $product['price'];
                $sale  = (string) $product['sale_price'] === '' ? $price : (float) $product['sale_price'];
                $label = '- ' . ($price == 0.0 ? 0 : (int) round(100 - $sale * 100 / $price)) . '%';
            }
            $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $badge['color']) ? (string) $badge['color'] : '#6b7280';
            $out[] = '<span class="mt-badge" style="background:' . $color . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return implode(' ', $out);
    }

    /**
     * The badges of a product chosen in the list: the map of Gridbox replaced (in the chosen order), noted
     * in the edit history. Refused while the product is open in the Gridbox editor (it saves its badges).
     */
    private function listBadges(): array
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $id    = $input->getInt('id', 0);
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $row   = $id > 0 ? $db->setQuery('SELECT d.price, d.sale_price, p.title, p.app_id FROM ' . $db->quoteName('#__gridbox_store_product_data') . ' AS d INNER JOIN '
            . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = d.product_id WHERE d.product_id = ' . $id . ' ORDER BY d.id LIMIT 1')->loadAssoc() : null;
        if (!$row) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_NOT_PRODUCT')];
        }
        if ($this->lockOn()) {
            $lock = $this->editLocks()->get(EditLock::key('', $id));
            if (EditLock::held($lock, time(), $this->lockIdle())) {
                return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_UI_LOCKED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']))];
            }
        }
        $all = [];
        foreach ($db->setQuery('SELECT id, title, color, type FROM ' . $db->quoteName('#__gridbox_store_badges'))->loadAssocList() ?: [] as $badge) {
            $all[(int) $badge['id']] = $badge;
        }
        $chosen = json_decode($input->getString('badges', '[]'), true);
        $chosen = array_values(array_unique(array_filter(array_map('intval', \is_array($chosen) ? $chosen : []), static fn ($b) => isset($all[$b]))));
        $old    = array_map('intval', $db->setQuery('SELECT badge_id FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id = ' . $id
            . ' ORDER BY order_list, id')->loadColumn() ?: []);
        if ($old !== $chosen) {
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id = ' . $id)->execute();
            foreach ($chosen as $i => $badgeId) {
                $db->setQuery('INSERT INTO ' . $db->quoteName('#__gridbox_store_badges_map') . ' (product_id, badge_id, order_list) VALUES (' . $id . ', ' . $badgeId . ', ' . $i . ')')->execute();
            }
            $names = fn (array $ids) => implode(', ', array_map(fn ($b) => isset($all[$b]) ? self::badgeLabel($all[$b]) : '#' . $b, $ids));
            if ($this->historyOn()) {
                try {
                    $history = new History($db);
                    $history->ensureTables();
                    $history->record($id, (int) $row['app_id'], (string) $row['title'], (int) $user->id, self::userLabel($user), 'edited',
                        [['f' => 'badges', 'from' => $names($old), 'to' => $names($chosen)]], 'admin', gmdate('Y-m-d H:i:s'));
                    if ((int) $this->params->get('history_actionlog', 1) === 1) {
                        $this->historyActionLog('edited', $id, (string) $row['title'], (int) $user->id, (string) $user->username);
                    }
                } catch (\Throwable $e) {
                }
            }
            $this->purgeKeptPages();
        }

        return ['success' => true, 'raw' => implode(',', $chosen), 'html' => self::badgesHtml($chosen, $all, $row)];
    }

    /** The columns window saved: the order of the shown columns (empty = Gridbox's own again). */
    private function listColumnsSave(): array
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $list  = $input->getString('list', '');
        if (!preg_match('/^(apps\.\d{1,9}|pages)$/', $list)) {
            return ['success' => false, 'message' => 'list'];
        }
        $clean = static fn ($v) => array_values(array_filter(array_map('strval', \is_array($v) ? $v : []), static fn ($k) => preg_match('/^[a-z_0-9]{1,40}$/', $k)));
        $order = $clean(json_decode($input->getString('order', '[]'), true));
        $known = $clean(json_decode($input->getString('known', '[]'), true));
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $key   = $db->quote($this->listProfileKey($list));
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . (int) $user->id . ' AND profile_key = ' . $key)->execute();
        if ($order) {
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__user_profiles') . ' (user_id, profile_key, profile_value, ordering) VALUES (' . (int) $user->id . ', ' . $key
                . ', ' . $db->quote(json_encode(['order' => \array_slice($order, 0, 60), 'known' => \array_slice($known, 0, 60)])) . ', 0)')->execute();
        }

        return ['success' => true];
    }

    /**
     * A price, sale price, stock or SKU changed in the list: checked, kept as Gridbox keeps it, noted in
     * the edit history, kept pages emptied. Refused while the product is open in the Gridbox editor
     * (its next save would put the old value back).
     */
    private function listEdit(): array
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $id    = $input->getInt('id', 0);
        $field = $input->getCmd('field', '');
        if ($id <= 0 || !isset(ListColumns::EDITABLE[$field])) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_ERROR')];
        }
        $typed   = (string) $input->post->get('value', '', 'raw');
        $percent = $field === 'sale_price' ? ListColumns::percent($typed) : null;
        $value   = $percent === null ? ListColumns::clean(ListColumns::EDITABLE[$field], $typed) : '';
        if ($value === null || ($field === 'price' && $value === '')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_BAD_' . strtoupper(ListColumns::EDITABLE[$field]))];
        }
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery('SELECT d.id, d.' . $db->quoteName($field) . ' AS value, d.price AS base_price, p.title, p.app_id FROM ' . $db->quoteName('#__gridbox_store_product_data')
            . ' AS d INNER JOIN ' . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = d.product_id WHERE d.product_id = ' . $id . ' ORDER BY d.id LIMIT 1')->loadAssoc();
        if (!$row) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_NOT_PRODUCT')];
        }
        if ($this->lockOn()) {
            $lock = $this->editLocks()->get(EditLock::key('', $id));
            if (EditLock::held($lock, time(), $this->lockIdle())) {
                return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_UI_LOCKED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']))];
            }
        }
        // "10%" in the sale price: the regular price minus 10 % (0 % = no sale price)
        if ($percent !== null) {
            $value = $percent > 0 && (float) $row['base_price'] > 0
                ? (string) ListColumns::clean('money', number_format((float) $row['base_price'] * (1 - $percent / 100), max(0, min(4, (int) ($this->storeCurrency()['decimals'] ?? 2))), '.', ''))
                : '';
        }
        $old = (string) $row['value'];
        if ($old !== $value) {
            $db->setQuery('UPDATE ' . $db->quoteName('#__gridbox_store_product_data') . ' SET ' . $db->quoteName($field) . ' = ' . $db->quote($value)
                . ' WHERE id = ' . (int) $row['id'])->execute();
            if ($this->historyOn()) {
                try {
                    $history = new History($db);
                    $history->ensureTables();
                    $history->record($id, (int) $row['app_id'], (string) $row['title'], (int) $user->id, self::userLabel($user), 'edited',
                        [['f' => 'product_' . $field, 'from' => $old, 'to' => $value]], 'admin', gmdate('Y-m-d H:i:s'));
                    if ((int) $this->params->get('history_actionlog', 1) === 1) {
                        $this->historyActionLog('edited', $id, (string) $row['title'], (int) $user->id, (string) $user->username);
                    }
                } catch (\Throwable $e) {
                }
            }
            $this->purgeKeptPages();
        }
        $kind = ListColumns::EDITABLE[$field];

        return ['success' => true, 'raw' => $value, 'display' => $kind === 'money' ? ListColumns::money($value, $this->storeCurrency()) : $value];
    }

    /**
     * The Settings window of a page in the Gridbox lists (gridbox.updateParams) while somebody else has
     * the page open in the editor: refused, as their next save would undo it.
     */
    private function adminLockGuard(): void
    {
        if (!$this->lockOn()) {
            return;
        }
        $app   = $this->getApplication();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || strtolower((string) $input->getString('task', '')) !== 'gridbox.updateparams'
            || strtoupper((string) $input->getMethod()) !== 'POST') {
            return;
        }
        $user = $app->getIdentity();
        $id   = $input->getInt('ba_id', 0);
        if (!$user || $user->guest || $id <= 0) {
            return;
        }
        try {
            $lock = $this->editLocks()->get(EditLock::key('', $id));
            if (!EditLock::blocks($lock, (int) $user->id, time(), $this->lockIdle())) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        $this->loadLanguage();
        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SETTINGS_REFUSED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name'])), 'error');
        $back = (string) $input->server->getString('HTTP_REFERER', '');
        $app->redirect($back !== '' && Uri::isInternal($back) ? $back : 'index.php?option=com_gridbox');
    }

    // ---------------------------------------------------------------- edit lock

    private function lockOn(): bool
    {
        return (bool) (int) $this->params->get('lock_enabled', 1);
    }

    /** Minutes without anything done in the editor after which its lock is free (0 = never). */
    private function lockIdle(): int
    {
        return max(0, (int) $this->params->get('lock_idle', 30)) * 60;
    }

    private function editLocks(): EditLock
    {
        $locks = new EditLock(Factory::getContainer()->get(DatabaseInterface::class));
        $locks->ensureTables();

        return $locks;
    }

    /** "Name (username)" of the user, as kept with a lock and in the history. */
    private static function userLabel($user): string
    {
        return trim((string) $user->name) . ' (' . (string) $user->username . ')';
    }

    /** The JSON of the editor's save: the request body (editor.gridboxSave) or the form field obj (editor.gridboxAjaxSave). */
    private function editorSaveObj(string $task): string
    {
        static $obj = null;

        return $obj ??= $task === 'editor.gridboxsave' ? (string) @file_get_contents('php://input')
            : (string) $this->getApplication()->getInput()->get('obj', '', 'raw');
    }

    /**
     * Site requests of a logged-in user: the confirmations of an open editor, the Gridbox editor
     * itself (the lock is taken, or the page of who edits it is shown) and the editor's saves.
     */
    private function editLock(): void
    {
        if (!$this->lockOn()) {
            return;
        }
        $app  = $this->getApplication();
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $input  = $app->getInput();
        $action = $input->getCmd('mertools_lock', '');
        if ($action !== '') {
            $this->lockEndpoint($action);
        }
        if ($input->getCmd('option') !== 'com_gridbox') {
            return;
        }
        $task = strtolower((string) $input->getString('task', ''));
        try {
            if (\in_array($task, ['editor.gridboxsave', 'editor.gridboxajaxsave'], true) && strtoupper((string) $input->getMethod()) === 'POST') {
                $this->lockSaveGuard($task);
            } elseif ($task === '' && $input->getCmd('view') === 'editor' && $input->getInt('id', 0) > 0 && strtoupper((string) $input->getMethod()) === 'GET') {
                $this->lockEditorGate();
            }
        } catch (\Throwable $e) {
            // without its table (or database trouble) the editor works as without the lock
        }
    }

    private function mayTakeOver($user): bool
    {
        switch ((string) $this->params->get('lock_takeover', 'all')) {
            case 'none':
                return false;
            case 'admins':
                return $user->authorise('core.admin', 'com_gridbox') || $user->authorise('core.admin');
            default:
                return true;
        }
    }

    /** Opening the Gridbox editor: the lock is ours (taken, or taken over), else the page of who edits it. */
    private function lockEditorGate(): void
    {
        $app      = $this->getApplication();
        $input    = $app->getInput();
        $user     = $app->getIdentity();
        $id       = $input->getInt('id', 0);
        $editType = $input->getCmd('edit_type', '');
        $key      = EditLock::key($editType, $id);
        $locks    = $this->editLocks();
        $now      = time();
        $tab      = bin2hex(random_bytes(8));
        $force    = $input->getInt('mertools_takeover', 0) === 1 && Session::checkToken('get') && $this->mayTakeOver($user);
        $holder   = $locks->acquire($key, $editType === '' ? $id : 0, (int) $user->id, self::userLabel($user), $tab, $now, $this->lockIdle(), $force);
        if ($holder) {
            $this->lockedPage($holder, $user);
        }
        $this->editorLock = ['key' => $key, 'page' => $editType === '' ? $id : 0, 'tab' => $tab];
        if (random_int(1, 50) === 1) {
            $locks->prune($now);
        }
    }

    /** The page instead of the editor: who edits the page since when, and what can be done. */
    private function lockedPage(array $holder, $user): void
    {
        $app = $this->getApplication();
        $this->loadLanguage();
        $esc     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $name    = preg_replace('/\s*\([^()]*\)$/', '', (string) $holder['user_name']);
        $since   = HTMLHelper::_('date', (string) $holder['since'], 'd.m.Y H:i');
        $active  = HTMLHelper::_('date', (string) $holder['active'], 'H:i');
        $idle    = (int) round($this->lockIdle() / 60);
        $uri     = clone Uri::getInstance();
        $uri->setVar('mertools_takeover', '1');
        $uri->setVar(Session::getFormToken(), '1');
        $take    = $this->mayTakeOver($user)
            ? '<a class="btn warn" href="' . $esc($uri->toString()) . '" onclick="return confirm(' . $esc(json_encode(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_TAKE_CONFIRM', $name), JSON_UNESCAPED_UNICODE)) . ')">'
                . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TAKE')) . '</a>'
            : '';
        $html = '<!DOCTYPE html><html lang="' . $esc($app->getLanguage()->getTag()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TITLE')) . '</title><style>'
            . 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f3f4f6;color:#1f2328;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . '.box{background:#fff;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.12);padding:28px 32px;max-width:560px;margin:16px}'
            . 'h1{font-size:20px;margin:0 0 12px}p{margin:0 0 10px}.who{font-size:17px;font-weight:600}.muted{color:#6b7280;font-size:13px}'
            . '.btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-block;padding:9px 16px;border-radius:6px;text-decoration:none;border:1px solid #d1d5db;color:#1f2328;background:#fff;cursor:pointer;font:inherit}'
            . '.btn.primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}.btn.warn{border-color:#b91c1c;color:#b91c1c}</style></head><body><div class="box">'
            . '<h1>🔒 ' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TITLE')) . '</h1>'
            . '<p class="who">' . $esc(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_WHO', $name)) . '</p>'
            . '<p>' . $esc(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SINCE', $since, $active)) . '</p>'
            . '<p class="muted">' . $esc($idle > 0 ? Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_EXPLAIN', $idle) : Text::_('PLG_SYSTEM_MERTOOLS_LOCK_EXPLAIN_NOIDLE')) . '</p>'
            . '<div class="btns"><a class="btn primary" href="' . $esc(Uri::getInstance()->toString()) . '">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_RETRY')) . '</a>'
            . '<button type="button" class="btn" onclick="window.close();history.length>1&&history.back()">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_BACK')) . '</button>'
            . $take . '</div></div></body></html>';
        $app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex', true);
        $app->sendHeaders();
        echo $html;
        $app->close();
    }

    /** A save of the editor while somebody else holds the lock is refused (Gridbox shows the answer as its notice). */
    private function lockSaveGuard(string $task): void
    {
        $obj = json_decode($this->editorSaveObj($task));
        if (!\is_object($obj) || empty($obj->page->id)) {
            return;
        }
        $app      = $this->getApplication();
        $user     = $app->getIdentity();
        $editType = isset($obj->edit_type) && \is_string($obj->edit_type) ? $obj->edit_type : '';
        $key      = EditLock::key($editType, (int) $obj->page->id);
        $locks    = $this->editLocks();
        $row      = $locks->get($key);
        if (!EditLock::blocks($row, (int) $user->id, time(), $this->lockIdle())) {
            return;
        }
        $this->loadLanguage();
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        echo Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SAVE_REFUSED', preg_replace('/\s*\([^()]*\)$/', '', (string) $row['user_name']));
        $app->close();
    }

    /** The open editor: ping (confirm the lock, every 30 s) and release (closed). */
    private function lockEndpoint(string $action): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (!\in_array($action, ['ping', 'release'], true) || strtoupper((string) $input->getMethod()) !== 'POST' || !Session::checkToken('request')) {
            $this->sendJson(['ok' => false], 403);
        }
        $key = $input->getString('key', '');
        if (!preg_match('/^[a-z0-9_-]{1,40}:\d{1,10}$/', $key)) {
            $this->sendJson(['ok' => false], 400);
        }
        $tab = substr(preg_replace('/[^a-f0-9]/', '', $input->getString('tab', '')), 0, 32);
        try {
            $locks = $this->editLocks();
            if ($action === 'release') {
                $locks->release($key, (int) $user->id, $tab);
                $this->sendJson(['ok' => true]);
            }
            $holder = $locks->ping($key, max(0, $input->getInt('page', 0)), (int) $user->id, self::userLabel($user), $tab,
                $input->getInt('active', 0) === 1, time(), $this->lockIdle());
            if ($holder) {
                $this->loadLanguage();
                $this->sendJson(['ok' => false, 'lost' => true, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_LOST',
                    preg_replace('/\s*\([^()]*\)$/', '', (string) $holder['user_name']), HTMLHelper::_('date', (string) $holder['since'], 'H:i'))]);
            }
            $this->sendJson(['ok' => true]);
        } catch (\Throwable $e) {
            $this->sendJson(['ok' => false, 'error' => true]);
        }
    }

    /**
     * "Discount %" next to the sale price in the Gridbox editor of a product (mertools-discount.js): a
     * percentage sets the sale prices of the product and of its versions; Gridbox saves the prices.
     */
    private function discountScript(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        if (!(int) $this->params->get('shop_discount_field', 1) || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $this->loadLanguage();
        $currency = $this->storeCurrency();
        $cfg      = ['decimals' => (int) ($currency['decimals'] ?? 2), 'label' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_LABEL'),
            'placeholder' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_PLACEHOLDER'), 'hint' => Text::_('PLG_SYSTEM_MERTOOLS_DISCOUNT_HINT')];
        $tail = '<script type="application/json" id="mertools-discount-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-discount' . (JDEBUG ? '' : '.min') . '.js?' . self::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /** The editor page that took the lock: its script confirms it and gives it back when closed. */
    private function lockScript(): void
    {
        if (!$this->editorLock) {
            return;
        }
        $app  = $this->getApplication();
        $this->loadLanguage();
        $idle = $this->lockIdle();
        $cfg  = $this->editorLock + [
            'url'   => Uri::root(true) . '/index.php',
            'token' => Session::getFormToken(),
            'ping'  => EditLock::PING,
            'idle'  => $idle,
            'texts' => ['idle' => $idle > 0 ? Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_IDLE', (int) round($idle / 60)) : '', 'resume' => Text::_('PLG_SYSTEM_MERTOOLS_LOCK_RESUMED')],
        ];
        $tail = '<script type="application/json" id="mertools-lock-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-lock' . (JDEBUG ? '' : '.min') . '.js?' . self::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    // ---------------------------------------------------------------- edit history

    private function historyOn(): bool
    {
        return (bool) (int) $this->params->get('history_enabled', 1);
    }

    /**
     * A Gridbox save or list action of a logged-in user: the pages it names are read now and again
     * once the request has finished (Gridbox ends with exit, so at shutdown), and what changed is kept.
     */
    private function trackHistory(): void
    {
        if (!$this->historyOn()) {
            return;
        }
        $app   = $this->getApplication();
        $input = $app->getInput();
        $task  = strtolower((string) $input->getString('task', ''));
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !History::tracked($task)) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        // the editor's save sends its page as JSON: the whole request body (editor.gridboxSave, what
        // Gridbox uses) or the form field obj (editor.gridboxAjaxSave, its fallback)
        $obj = str_starts_with($task, 'editor.gridbox') ? $this->editorSaveObj($task) : '';
        $ids = History::requestIds([
            'obj'          => $obj,
            'id'           => $input->getInt('id', 0),
            'context-item' => $input->getInt('context-item', 0),
            'ba_id'        => $input->getInt('ba_id', 0),
            'cid'          => $input->get('cid', [], 'array'),
        ]);
        $creates = History::creates($task);
        if (!$ids && !$creates) {
            return;
        }
        try {
            $history = new History(Factory::getContainer()->get(DatabaseInterface::class));
            $history->ensureTables();
            $before = $history->snapshot($ids);
            $max    = $creates ? $history->maxPageId() : 0;
        } catch (\Throwable $e) {
            return;
        }
        $editor   = str_starts_with($task, 'editor.gridbox');
        $client   = $app->isClient('administrator') ? 'admin' : 'site';
        $userId   = (int) $user->id;
        $userName = trim((string) $user->name) . ' (' . (string) $user->username . ')';
        $username = (string) $user->username;
        $toLog    = (int) $this->params->get('history_actionlog', 1) === 1;
        $days     = max(0, (int) $this->params->get('history_days', 365));
        register_shutdown_function(function () use ($history, $ids, $before, $creates, $max, $editor, $client, $userId, $userName, $username, $toLog, $days): void {
            try {
                $new   = $creates ? $history->newPages($max) : [];
                $after = $history->snapshot(array_merge($ids, $new));
                $now   = gmdate('Y-m-d H:i:s');
                foreach (array_values(array_unique(array_merge($ids, $new))) as $id) {
                    $diff = History::diff($before[$id] ?? null, $after[$id] ?? null, $editor);
                    if (!$diff) {
                        continue;
                    }
                    if (\in_array($id, $new, true)) {
                        $diff = ['action' => 'duplicated', 'changes' => \count($ids) === 1 ? [['f' => 'source', 'to' => (string) $ids[0]]] : []];
                    }
                    $row   = $after[$id] ?? $before[$id];
                    $title = (string) ($row['title'] ?? '');
                    $history->record($id, (int) ($row['app_id'] ?? 0), $title, $userId, $userName, $diff['action'], $diff['changes'], $client, $now);
                    if ($toLog) {
                        $this->historyActionLog($diff['action'], $id, $title, $userId, $username);
                    }
                }
                // once a day: entries older than the setting go
                $gate = self::cacheBase() . '/mertools-history-tick';
                if ($days > 0 && (!is_file($gate) || (int) @filemtime($gate) < time() - 86400) && @touch($gate)) {
                    $history->prune($days, $now);
                }
            } catch (\Throwable $e) {
                // the edit itself is never affected
            }
        });
    }

    /** The same entry in Joomla's User Actions Log (Users → User Actions Log), with links to the page and the user. */
    private function historyActionLog(string $action, int $id, string $title, int $userId, string $username): void
    {
        try {
            $model = $this->getApplication()->bootComponent('com_actionlogs')->getMVCFactory()
                ->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
            $model->addLog([[
                'action'      => $action,
                'id'          => $id,
                'title'       => $title,
                'itemlink'    => 'index.php?option=com_gridbox&task=gridbox.edit&id=' . $id,
                'userid'      => $userId,
                'username'    => $username,
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
            ]], 'PLG_SYSTEM_MERTOOLS_ACTIONLOG_' . strtoupper($action), 'plg_system_mertools.history', $userId);
        } catch (\Throwable $e) {
        }
    }

    /**
     * The Gridbox lists (apps, pages, trash): under the title of each page its last change - when and
     * who - as a link to the whole history of the page (a window, mertools-history.js).
     */
    private function historyInLists(): void
    {
        $showHistory = $this->historyOn() && (int) $this->params->get('history_lists', 1);
        if (!$showHistory && !$this->lockOn()) {
            return;
        }
        $app   = $this->getApplication();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || !\in_array($input->getCmd('view', ''), ['apps', 'pages', 'trashed'], true)
            || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage', 'com_gridbox')) {
            return;
        }
        $body = (string) $app->getBody();
        if (!preg_match_all('/name="cid\[\]" value="(\d+)"/', $body, $m)) {
            return;
        }
        $latest = [];
        $locked = [];
        try {
            if ($showHistory) {
                $history = new History(Factory::getContainer()->get(DatabaseInterface::class));
                $history->ensureTables();
                $latest = $history->latest(array_map('intval', $m[1]));
            }
            if ($this->lockOn()) {
                $locked = $this->editLocks()->onPages(array_map('intval', $m[1]), time(), $this->lockIdle());
            }
        } catch (\Throwable $e) {
            return;
        }
        if (!$latest && !$locked) {
            return;
        }
        $this->loadLanguage();
        $body = preg_replace_callback('#<tr\b[^>]*>.*?</tr>#s', function (array $row) use ($latest, $locked): string {
            if (!preg_match('/name="cid\[\]" value="(\d+)"/', $row[0], $id) || (!isset($latest[(int) $id[1]]) && !isset($locked[(int) $id[1]]))) {
                return $row[0];
            }
            $lockHtml = '';
            if (isset($locked[(int) $id[1]])) {
                $lock     = $locked[(int) $id[1]];
                $lockHtml = '<div class="mt-lock-mark">' . htmlspecialchars('🔒 ' . Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_LIST',
                    preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']), HTMLHelper::_('date', (string) $lock['since'], 'H:i')), ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if (!isset($latest[(int) $id[1]])) {
                $cell = strpos($row[0], 'class="title-cell"');
                $end  = $cell === false ? false : strpos($row[0], '</td>', $cell);

                return $end === false ? $row[0] : substr($row[0], 0, $end) . $lockHtml . substr($row[0], $end);
            }
            $cell = strpos($row[0], 'class="title-cell"');
            $end  = $cell === false ? false : strpos($row[0], '</td>', $cell);
            if ($end === false) {
                return $row[0];
            }
            $last  = $latest[(int) $id[1]];
            $label = '✎ ' . HTMLHelper::_('date', (string) $last['created'], 'd.m.Y H:i') . ' · ' . preg_replace('/\s*\([^()]*\)$/', '', (string) $last['user_name']);
            $html  = '<div class="mt-hist-last"><a href="#" data-mt-history="' . (int) $id[1] . '" title="'
                . htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_SHOW') . ': ' . Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_A_' . strtoupper((string) $last['action'])), ENT_QUOTES, 'UTF-8')
                . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></div>';

            return substr($row[0], 0, $end) . $lockHtml . $html . substr($row[0], $end);
        }, $body) ?? $body;
        $config = ['url' => Uri::base(true) . '/index.php?mertools_action=history_list', 'token' => Session::getFormToken(), 'texts' => self::historyTexts()];
        $tail   = '<style>' . self::HISTORY_CSS . '</style><script type="application/json" id="mertools-history-config">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . Uri::root(true)
            . '/media/plg_system_mertools/js/mertools-history' . (JDEBUG ? '' : '.min') . '.js?' . self::VERSION . '" defer></script>';
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /** The look of the history in the Gridbox lists and its window (Gridbox's admin is not Atum). */
    public const HISTORY_CSS = '.mt-lock-mark{margin-top:4px;font-size:12px;line-height:1.3;color:#b45309;font-weight:600}.mt-hist-last{margin-top:4px;font-size:12px;line-height:1.3}.mt-hist-last a{color:#6b7280;text-decoration:none}'
        . '.mt-hist-last a:hover{color:#1d4ed8;text-decoration:underline}dialog.mt-hist-dlg{border:0;border-radius:8px;padding:0;width:min(920px,94vw);'
        . 'max-height:86vh;box-shadow:0 20px 60px rgba(0,0,0,.35);font:14px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2328}'
        . 'dialog.mt-hist-dlg::backdrop{background:rgba(0,0,0,.4)}.mt-hist-head{display:flex;align-items:center;gap:12px;padding:14px 18px;'
        . 'border-bottom:1px solid #e5e7eb}.mt-hist-head h3{margin:0;font-size:17px;flex:1}.mt-hist-head button{border:0;background:none;font-size:22px;'
        . 'cursor:pointer;line-height:1}.mt-hist-body{padding:6px 18px 16px;overflow:auto;max-height:calc(86vh - 60px)}';

    /** @return array<string, string> texts of the history window and panel */
    public static function historyTexts(): array
    {
        $texts = [];
        foreach (['TITLE', 'WHEN', 'WHO', 'WHAT', 'CHANGES', 'NONE', 'LOADING', 'ERROR', 'CLOSE', 'MORE', 'SEARCH', 'ALL_USERS', 'ALL_ACTIONS', 'FROM', 'TO',
            'FILTER', 'PAGE', 'TOTAL', 'OPEN', 'CLIENT_SITE', 'CLIENT_ADMIN'] as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_UI_' . $key);
        }
        foreach (History::ACTIONS as $action) {
            $texts['A_' . strtoupper($action)] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_A_' . strtoupper($action));
        }

        return $texts;
    }

    /**
     * Entries of the history for the window (one page) or the panel (filters), with the changes put
     * in words: "Title: “A” → “B”", "Content changed"; category numbers as their names.
     */
    private function historyList(): array
    {
        $input   = $this->getApplication()->getInput();
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $history = new History($db);
        $history->ensureTables();
        $limit  = max(1, min(200, $input->getInt('limit', 50)));
        $found  = $history->find([
            'page'   => $input->getInt('page', 0),
            'user'   => $input->getInt('user', 0),
            'q'      => mb_substr(trim($input->getString('q', '')), 0, 100),
            'from'   => $input->getString('from', ''),
            'to'     => $input->getString('to', ''),
            'action' => $input->getCmd('filter_action', ''),
        ], $limit, max(0, $input->getInt('offset', 0)));

        // category numbers -> names (the main category of a page is a number, or "trashed")
        $cats = [];
        foreach ($found['rows'] as $row) {
            foreach ((array) json_decode((string) $row['changes'], true) as $change) {
                if (($change['f'] ?? '') === 'page_category') {
                    foreach (['from', 'to'] as $k) {
                        if (ctype_digit((string) ($change[$k] ?? ''))) {
                            $cats[(int) $change[$k]] = true;
                        }
                    }
                }
            }
        }
        $names = [];
        if ($cats) {
            try {
                foreach ($db->setQuery('SELECT id, title FROM ' . $db->quoteName('#__gridbox_categories') . ' WHERE id IN (' . implode(',', array_keys($cats)) . ')')->loadRowList() ?: [] as [$cid, $ctitle]) {
                    $names[(int) $cid] = (string) $ctitle;
                }
            } catch (\Throwable $e) {
            }
        }

        $rows = [];
        foreach ($found['rows'] as $row) {
            $lines = [];
            foreach ((array) json_decode((string) $row['changes'], true) as $change) {
                $lines[] = $this->historyChange((array) $change, $names);
            }
            $rows[] = [
                'id'      => (int) $row['id'],
                'page'    => (int) $row['page_id'],
                'title'   => (string) $row['title'],
                'when'    => HTMLHelper::_('date', (string) $row['created'], 'd.m.Y H:i:s'),
                'who'     => (string) $row['user_name'],
                'action'  => (string) $row['action'],
                'client'  => (string) $row['client'],
                'changes' => $lines,
                'edit'    => 'index.php?option=com_gridbox&task=gridbox.edit&id=' . (int) $row['page_id'],
            ];
        }

        return ['rows' => $rows, 'total' => $found['total'], 'users' => $input->getInt('with_users', 0) ? $history->users() : null];
    }

    /** One change in words, in the administrator's language. */
    private function historyChange(array $change, array $categories): string
    {
        $field = (string) ($change['f'] ?? '');
        $key   = 'PLG_SYSTEM_MERTOOLS_HISTORY_F_' . strtoupper($field);
        $label = Text::_($key);
        $label = $label === $key ? $field : $label;
        if (!\array_key_exists('to', $change)) {
            return Text::sprintf('PLG_SYSTEM_MERTOOLS_HISTORY_CHANGED', $label);
        }
        $show = function ($value) use ($field, $categories): string {
            $value = (string) $value;
            if ($field === 'published' || $field === 'featured') {
                return Text::_($value === '1' ? 'JYES' : 'JNO');
            }
            if ($field === 'page_category') {
                return $value === 'trashed' ? Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_TRASH') : ($categories[(int) $value] ?? $value);
            }

            return $value === '' ? '—' : '„' . $value . '”';
        };
        if ($field === 'source') {
            return Text::sprintf('PLG_SYSTEM_MERTOOLS_HISTORY_SOURCE', (string) $change['to']);
        }
        if (!\array_key_exists('from', $change)) {
            return $label . ': ' . $show($change['to']);
        }

        return $label . ': ' . $show($change['from']) . ' → ' . $show($change['to']);
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

    private function imageOptimizer(): ImageOptimizer
    {
        return new ImageOptimizer(JPATH_ROOT, Uri::getInstance()->toString(['scheme', 'host', 'port']), Uri::root(true), self::cacheBase());
    }

    /**
     * After the response: the copies of pictures that pages have queued, a few seconds of work, at
     * most every 15 s. Kept pages are emptied once new copies exist, so pages offer them (at most
     * every 10 minutes while the queue is long, and when it is done).
     */
    private function imageWork(): void
    {
        $p = $this->params;
        if (!(int) $p->get('speed_images', 1) || (!(int) $p->get('speed_webp', 1) && !(int) $p->get('speed_gif', 1)) || !ImageOptimizer::supported()) {
            return;
        }
        $base  = self::cacheBase();
        $queue = $base . '/mertools-images-queue.json';
        if (!is_file($queue) || (int) @filesize($queue) < 5) {
            return;
        }
        $gate = $base . '/mertools-img-tick';
        if (is_file($gate) && (int) @filemtime($gate) > time() - 15) {
            return;
        }
        @touch($gate);
        $finished = $this->finishResponse();
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

    /** The visitor gets the page now; the work after it goes on without them waiting (where PHP allows it). */
    private function finishResponse(): bool
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

    /** Pages kept by MerTools and by Joomla's page cache are emptied (they would offer old pictures). */
    private function purgeKeptPages(): void
    {
        if ($this->pageCacheOn() || $this->dataCacheOn()) {
            $this->pageCache()->purge();
        }
        self::cleanPageCache();
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
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $user   = $app->getIdentity();
        $guest  = !$user || $user->guest;
        $method = strtoupper((string) $input->getMethod());
        if (!PageCache::changes($method, $guest, (string) $input->getCmd('option', ''), (string) $input->getString('task', ''))) {
            return;
        }
        $cache  = $this->pageCache();
        $finish = fn () => $this->finishResponse();
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
    private function servePageCache(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (($user && !$user->guest) || (!$this->pageCacheOn() && !$this->dataCacheOn())) {
            return;
        }
        // a visitor with a cart, a wishlist, an own currency…: their pages and Gridbox's answers (prices in
        // that currency) are their own, never kept and never served from what others got
        $lines = fn (string $s) => array_filter(array_map('trim', preg_split('/\R/', $s) ?: []));
        if (PageCache::hasCookie($_COOKIE, array_merge(PageCache::SKIP_COOKIES, $lines((string) $this->params->get('speed_cache_cookies', ''))))) {
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
        $session = (string) $app->getSession()->getName();
        $ttl     = $this->cacheTtl();
        $max     = max(100, min(100000, (int) $this->params->get('speed_cache_max', 5000)));
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
    private function storePage(int $code, array $headers): void
    {
        $store             = $this->pageToStore;
        $this->pageToStore = null;
        if (!$store || empty($store['ok']) || $code !== 200) {
            return;
        }
        $app    = $this->getApplication();
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

    // ---------------------------------------------------------------- indexes of Gridbox's tables

    private function indexesOn(): bool
    {
        return (bool) (int) $this->params->get('db_indexes', 1);
    }

    private function dbIndexes(): DbIndexes
    {
        return new DbIndexes(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /**
     * Once a day: the indexes still there? (Gridbox updated, the database restored from a backup…)
     * The check is a few SHOW INDEX; a missing index is created after the response has gone out.
     */
    private function dailyIndexCheck(): void
    {
        if (!$this->indexesOn()) {
            return;
        }
        $gate = self::cacheBase() . '/mertools-dbindex-tick';
        if (is_file($gate) && (int) @filemtime($gate) > time() - 86400) {
            return;
        }
        if (!@touch($gate)) {
            return;
        }
        try {
            $indexes = $this->dbIndexes();
            if (!\in_array('missing', array_column($indexes->status(), 'state'), true)) {
                return;
            }
            $this->finishResponse();
            @ignore_user_abort(true);
            @set_time_limit(300);
            $indexes->ensure();
        } catch (\Throwable $e) {
            // tried again the next day
        }
    }

    // ---------------------------------------------------------------- "<" in introductions

    private function introLtOn(): bool
    {
        return (int) $this->params->get('intro_lt', 1) === 1;
    }

    /**
     * After anything sent to Gridbox by a logged-in user (the editor's save, the settings of a page,
     * a CSV import…): a "<" Gridbox's lists would lose with an old libxml is kept as &lt; (IntroText).
     * Looks only for the rows that have one, so it costs a few ms.
     */
    private function introGuard(): void
    {
        if (!$this->introLtOn()) {
            return;
        }
        $app   = $this->getApplication();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !$user || $user->guest) {
            return;
        }
        register_shutdown_function(static function (): void {
            try {
                IntroText::repair(Factory::getContainer()->get(DatabaseInterface::class));
            } catch (\Throwable $e) {
            }
        });
    }

    /** Once a day: introductions changed past Gridbox (another import, the database directly). */
    private function dailyIntroCheck(): void
    {
        if (!$this->introLtOn()) {
            return;
        }
        $gate = self::cacheBase() . '/mertools-intro-tick';
        if ((is_file($gate) && (int) @filemtime($gate) > time() - 86400) || !@touch($gate)) {
            return;
        }
        try {
            if (IntroText::repair(Factory::getContainer()->get(DatabaseInterface::class)) && ($this->pageCacheOn() || $this->dataCacheOn())) {
                $this->pageCache()->purge(false);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Gridbox (its component or package) installed or updated: the missing indexes are created again at once. */
    public function onExtensionAfterInstallOrUpdate(\Joomla\Event\EventInterface $event): void
    {
        if (!$this->indexesOn()) {
            return;
        }
        try {
            $eid = (int) (method_exists($event, 'getEid') ? $event->getEid() : $event->getArgument('eid'));
            if ($eid <= 0) {
                return;
            }
            $db      = Factory::getContainer()->get(DatabaseInterface::class);
            $element = (string) $db->setQuery($db->getQuery(true)->select($db->quoteName('element'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = ' . $eid))->loadResult();
            if (DbIndexes::isGridbox($element)) {
                $this->dbIndexes()->ensure();
            }
        } catch (\Throwable $e) {
            // the daily check creates them
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
        // the visitor has the page: everything from here on (keeping the page, tidying up, pictures,
        // carts) goes on after the response has been closed, where PHP allows it
        $code    = (int) http_response_code();
        $headers = headers_list();
        if ($this->pageToStore || $this->pageCacheOn() || $this->dataCacheOn() || $this->indexesOn() || $this->cartsOn()
            || (int) $this->params->get('speed_images', 1)) {
            $this->finishResponse();
        }
        if ($this->pageToStore) {
            $this->storePage($code, $headers);
        }
        if ($this->pageCacheOn() || $this->dataCacheOn()) {
            $this->prunePageCache();
        }
        $this->dailyIndexCheck();
        $this->dailyIntroCheck();
        $this->imageWork();
        if (!$this->cartsOn()) {
            return;
        }
        $gate = self::cacheBase() . '/mertools-cart-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        if (!@touch($gate)) {
            // the cache folder cannot be written: no hourly work at all rather than on every request
            return;
        }

        // the visitor has the page already; the work goes on without them waiting where PHP allows it
        $finished = $this->finishResponse();
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
            'pcache_stats', 'pcache_clear', 'dbidx_status', 'dbidx_fix', 'img_stats', 'img_run', 'img_clear', 'cache_clear_all', 'history_list',
            'list_columns', 'list_edit', 'list_badges'], true)) {
            return;
        }
        $this->loadLanguage();
        $user = $app->getIdentity();
        // the Gridbox lists: own columns (whoever may use the lists), quick edits (whoever may edit)
        if ($action === 'list_columns' || $action === 'list_edit' || $action === 'list_badges') {
            $right = $action === 'list_columns' ? 'core.manage' : 'core.edit';
            if ($input->getMethod() !== 'POST' || !$user || !$user->authorise($right, 'com_gridbox') || !Session::checkToken('request')) {
                $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
            }
            try {
                $this->sendJson($action === 'list_edit' ? $this->listEdit() : ($action === 'list_badges' ? $this->listBadges() : $this->listColumnsSave()));
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
                $this->sendJson(['success' => true] + $this->historyList());
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
                $this->sendJson(['success' => true] + $this->clearAllCaches());
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        if ($input->getMethod() !== 'POST' || !$user || !$user->authorise('core.edit', 'com_plugins') || !Session::checkToken('request')) {
            $this->sendJson(['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
        }

        if (str_starts_with($action, 'speed_')) {
            $this->handleSpeedAction($action);
        }
        if (str_starts_with($action, 'dbidx_')) {
            try {
                $indexes = $this->dbIndexes();
                if ($action === 'dbidx_fix') {
                    @set_time_limit(120);
                    $this->sendJson(['success' => true] + $indexes->ensure());
                }
                $this->sendJson(['success' => true, 'status' => $indexes->status()]);
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        if (str_starts_with($action, 'img_')) {
            try {
                $images = $this->imageOptimizer();
                if ($action === 'img_run') {
                    if (!ImageOptimizer::supported()) {
                        $this->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_IMG_UI_NO_GD')]);
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
                $this->sendJson(['success' => true, 'stats' => $images->stats() + ['supported' => ImageOptimizer::supported()]]);
            } catch (\Throwable $e) {
                $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
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

    /**
     * The top-bar button: the pages kept by MerTools, then every group of Joomla's cache, as
     * System → Clear Cache does it (Joomla's own model, so plugins that listen to onAfterPurge
     * hear of it too). Returns the figures for the message.
     */
    private function clearAllCaches(): array
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
        $model = $this->getApplication()->bootComponent('com_cache')->getMVCFactory()
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
    public function onAfterModuleList($event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('administrator') || !(int) $this->params->get('admin_cache_button', 1)
            || $app->getInput()->getCmd('tmpl', '') === 'component') {
            return;
        }
        $user = $app->getIdentity();
        $document = $app->getDocument();
        if (!$user || $user->guest || !$user->authorise('core.manage', 'com_cache') || !$document || $document->getType() !== 'html') {
            return;
        }
        $this->loadLanguage();
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
                    'speed_video' => 1, 'speed_video_phone' => 'none', 'speed_scripts' => 0, 'speed_cache' => 0, 'speed_cache_data' => 1,
                    'speed_icons' => 1, 'speed_webp' => 1, 'speed_gif' => 1, 'speed_popups' => 1, 'layout_post_rows' => 1, 'tabs_accordion' => 1, 'db_indexes' => 1];
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
        if ($app->isClient('administrator')) {
            $this->listColumns();
            $this->historyInLists();

            return;
        }
        if (!$app->isClient('site')) {
            return;
        }
        $document = $app->getDocument();
        if ($this->editorLock && $document && $document->getType() === 'html') {
            $this->lockScript();
        }
        if ($document && $document->getType() === 'html') {
            $this->discountScript();
        }
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
        if ($this->introLtOn()) {
            $new = IntroText::fixMeta($new);
        }
        if ((int) $this->params->get('layout_post_rows', 1)) {
            $new = PostRows::apply($new);
        }
        if (PriceRequest::enabled($this->params)) {
            // the separate script that did this before goes, so nothing has to be deleted on the server
            $new = Omnibus::dropScript($new, PriceRequest::OLD_SCRIPT);
            if (str_contains($new, 'ba-store-app-product')) {
                $new = $this->priceRequestTiles($new);
            }
        }
        if (Omnibus::enabled($this->params)) {
            $new = Omnibus::dropScript($new, Omnibus::SCRIPT);
        }
        if ($new !== $body) {
            $app->setBody($new);
        }
        if ($this->pageToStore) {
            // a message that came up while rendering is this visitor's; the page is kept as it is here,
            // uncompressed (Joomla compresses the response afterwards; this listener is the last one)
            if (PageCache::storable($new) && !$app->getMessageQueue()) {
                $this->pageToStore['ok']   = true;
                $this->pageToStore['body'] = $new;
            } else {
                $this->pageToStore = null;
            }
        }
    }

    /**
     * Price on request: the list products with no price of their own but priced versions get
     * data-mt-choose, so the script asks for a model there (the lists carry no versions).
     */
    private function priceRequestTiles(string $html): string
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

    /** The faster-first-view tools that are switched on, applied to the finished page. */
    private function speedup(string $html): string
    {
        $p          = $this->params;
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
                $sizes  = new ImageSize(JPATH_ROOT, $site, Uri::root(true), self::cacheBase() . '/mertools-image-sizes.json');
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
