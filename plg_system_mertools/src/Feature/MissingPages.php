<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page not found (404): a redirect typed for the address in the log of missing addresses, else the page or
 * category the address asks for when it was renamed or moved (Redirects), else 410 Gone (or the page's
 * category) for a page that was withdrawn, else the chosen target or the error page; every missing address logged. The aliases are compared after a Gridbox save that may change one and hourly.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\NotFound;
use Merserwis\Plugin\System\MerTools\Tool\Redirects;
use Merserwis\Plugin\System\MerTools\Tool\Warmup;

final class MissingPages extends Feature
{
    /**
     * Page not found: a redirect typed for the address in the log, else the page or category the address
     * asks for when it was renamed or moved, else 410 Gone (or its category) for a withdrawn page, else the
     * chosen target (or the error page); the address is logged with what was done.
     */
    public function handle(ErrorEvent $event): void
    {
        $app = $event->getApplication();
        if (!$app instanceof \Joomla\CMS\Application\CMSApplicationInterface || !$app->isClient('site') || (int) $event->getError()->getCode() !== 404) {
            return;
        }
        $fallback = (bool) (int) $this->plugin->params->get('notfound_redirect', 0);
        $gone     = (string) $this->plugin->params->get('notfound_gone', 'gone');
        $moved    = (bool) (int) $this->plugin->params->get('notfound_moved', 1);
        // the warm-up of the page cache asking for an address of an old sitemap is not a visitor's miss
        $log      = (bool) (int) $this->plugin->params->get('notfound_log', 1)
            && !str_starts_with((string) $event->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', ''), Warmup::AGENT);
        // pages only: no form posts, AJAX or JSON (a redirect to the home page makes no sense there)
        $input = $app->getInput();
        if (!\in_array(strtoupper((string) $input->getMethod()), ['GET', 'HEAD'], true)
            || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('option') === 'com_ajax'
            || strtolower((string) $input->server->getString('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest') {
            return;
        }
        $uri     = Uri::getInstance();
        $request = $uri->toString(['path', 'query']);
        if (NotFound::excluded($request, NotFound::excludes((string) $this->plugin->params->get('notfound_exclude', '')))) {
            return;
        }

        $path   = Redirects::logPath((string) $uri->getPath(), (string) $uri->getQuery());
        $store  = new Redirects(Factory::getContainer()->get(DatabaseInterface::class));
        $host   = $uri->getHost();
        // never to the missing address itself (a missing target page shows the error page)
        $usable = static fn (?string $url): ?string => $url !== null && NotFound::shouldRedirect($request, $url, $host, []) ? $url : null;
        $result = 'none';
        $target = null;
        $code   = 301;
        try {
            $typed = $store->manualTarget($path);
        } catch (\Throwable $e) {
            $typed = null;
        }
        if ($typed !== null && ($target = $usable(NotFound::absoluteTarget($typed, Uri::root()))) !== null) {
            $result = 'manual';
        }
        if ($target === null && $moved) {
            try {
                $target = $usable($this->movedTarget($store, (string) $uri->getPath()));
            } catch (\Throwable $e) {
                $target = null;
            }
            $result = $target !== null ? 'moved' : $result;
        }
        if ($target === null && $gone !== 'off') {
            try {
                [$result, $target] = $this->goneTarget($store, (string) $uri->getPath(), $gone === 'category') ?? [$result, null];
                $target            = $usable($target);
                // the category is the missing address itself: 410 all the same
                $result = $result === 'category' && $target === null ? 'gone' : $result;
            } catch (\Throwable $e) {
                $target = null;
            }
        }
        if ($target === null && $result !== 'gone' && $fallback && ($target = $usable(NotFound::absoluteTarget($this->notFoundTarget(), Uri::root()))) !== null) {
            $result = 'fallback';
            $code   = (int) $this->plugin->params->get('notfound_code', 301) === 302 ? 302 : 301;
        }
        if ($log) {
            $now = gmdate('Y-m-d H:i:s');
            try {
                $store->record($path, (string) $input->server->getString('HTTP_REFERER', ''), $result, (string) $target, $now);
                // once a day: addresses not visited for the set days go
                $gate = MerTools::cacheBase() . '/mertools-notfound-tick';
                if ((!is_file($gate) || (int) @filemtime($gate) < time() - 86400) && @touch($gate)) {
                    $store->prune(max(0, (int) $this->plugin->params->get('notfound_log_days', 90)), $now);
                }
            } catch (\Throwable $e) {
                // the visitor gets the page anyway
            }
        }
        if ($target !== null) {
            $app->redirect($target, $code);
        }
        if ($result === 'gone') {
            // the same error page, with 410: the page was there and is not coming back
            $this->plugin->loadLanguage();
            $event->setError(new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_NOTFOUND_GONE_MESSAGE'), 410, $event->getError()));
        }
    }

    /**
     * A withdrawn page: everything with the alias of the missing address is unpublished, in the trash, past
     * its end of publishing or deleted (Redirects::goneItem). With $toCategory, a page's category when a
     * guest may see it (a 301), else 410.
     *
     * @return array{0: string, 1: ?string}|null [result, target]: ['gone', null] or ['category', url]; null when not gone
     */
    private function goneTarget(Redirects $store, string $path, bool $toCategory): ?array
    {
        $slug = Redirects::slug($path, Uri::root(true), (bool) $this->plugin->app()->get('sef_suffix', 0));
        if ($slug === null) {
            return null;
        }
        $zone = new \DateTimeZone((string) $this->plugin->app()->get('offset', 'UTC') ?: 'UTC');
        $item = $store->goneItem($slug, (new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s'));
        if ($item === null) {
            return null;
        }
        if ($toCategory && $item['category'] > 0) {
            $cat = $store->liveCategories([$item['category']], MerTools::guestLevels())[$item['category']] ?? null;
            if ($cat) {
                return ['category', $this->plugin->gridboxLink('getGridboxCategoryLinks', [$item['category'], $cat['app']],
                    'index.php?option=com_gridbox&view=blog&app=' . $cat['app'] . '&id=' . $item['category'])];
            }
        }

        return ['gone', null];
    }

    /**
     * Moved pages: the page or category whose alias — of today, or one it had before — is the last part
     * of the missing address, at the address it has now; of several, the one sharing the longest
     * beginning with the missing address (the same app and category). Only what a guest may see.
     */
    private function movedTarget(Redirects $store, string $path): ?string
    {
        $slug = Redirects::slug($path, Uri::root(true), (bool) $this->plugin->app()->get('sef_suffix', 0));
        if ($slug === null) {
            return null;
        }
        // the aliases are noted for the first time (an update installed by hand, without the installer)
        $gate = MerTools::cacheBase() . '/mertools-moved-tick';
        if (!is_file($gate) && @touch($gate)) {
            $store->sync(gmdate('Y-m-d H:i:s'));
        }
        $candidates = $store->candidates($slug);
        if (!$candidates) {
            return null;
        }
        $levels = MerTools::guestLevels();
        $ids    = ['p' => [], 'c' => []];
        foreach ($candidates as $c) {
            $ids[$c['kind']][] = $c['id'];
        }
        $pages = $store->livePages($ids['p'], $levels);
        $cats  = $store->liveCategories($ids['c'], $levels);
        $urls  = [];
        $rows  = [];
        foreach ($candidates as $c) {
            $id = $c['id'];
            if ($c['kind'] === 'p' && isset($pages[$id])) {
                $url = $this->plugin->gridboxLink('getGridboxPageLinks', [$id, $pages[$id]['type'], $pages[$id]['app'], $pages[$id]['category']],
                    self::pageLink($id, $pages[$id]));
            } elseif ($c['kind'] === 'c' && isset($cats[$id])) {
                $url = $this->plugin->gridboxLink('getGridboxCategoryLinks', [$id, $cats[$id]['app']], 'index.php?option=com_gridbox&view=blog&app=' . $cats[$id]['app'] . '&id=' . $id);
            } else {
                continue;
            }
            if (!isset($rows[$url])) {
                $urls[]     = $url;
                $rows[$url] = $c['moved'];
            }
        }
        $best = Redirects::best($urls, $path);
        if ($best !== null && $rows[$best] > 0) {
            $store->hitMoved($rows[$best], gmdate('Y-m-d H:i:s'));
        }

        return $best;
    }

    /** The plain address of a Gridbox page (without its menu item). */
    private static function pageLink(int $id, array $page): string
    {
        return $page['type'] === 'single' ? 'index.php?option=com_gridbox&view=page&id=' . $id
            : 'index.php?option=com_gridbox&view=page&blog=' . $page['app'] . '&category=' . $page['category'] . '&id=' . $id;
    }

    /** Tasks of Gridbox that may give a page or category another alias, or delete one: the aliases are compared after them. */
    private const ALIAS_TASKS = ['editor.gridboxajaxsave', 'editor.gridboxsave', 'gridbox.updateparams', 'apps.updatecategory', 'apps.importcsv',
        'apps.applysettings', 'pages.applysettings', 'pages.importcsv', 'trashed.restore', 'trashed.restoresingle',
        // deleted for good: the alias is kept as gone (410)
        'trashed.delete', 'trashed.contextdelete'];

    /** After a Gridbox save that may change an alias (by a logged-in user): the aliases compared at shutdown. */
    public function movedWatch(): void
    {
        if (!(int) $this->plugin->params->get('notfound_moved', 1)) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !$user || $user->guest
            || !\in_array(strtolower((string) $input->getString('task', '')), self::ALIAS_TASKS, true)) {
            return;
        }
        register_shutdown_function(static function (): void {
            try {
                (new Redirects(Factory::getContainer()->get(DatabaseInterface::class)))->sync(gmdate('Y-m-d H:i:s'));
            } catch (\Throwable $e) {
            }
        });
    }

    /** Once an hour: aliases changed past the watched tasks (an import, the database directly). */
    public function hourlyMoved(): void
    {
        if (!(int) $this->plugin->params->get('notfound_moved', 1)) {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-moved-tick';
        if ((is_file($gate) && (int) @filemtime($gate) > time() - 3600) || !@touch($gate)) {
            return;
        }
        try {
            (new Redirects(Factory::getContainer()->get(DatabaseInterface::class)))->sync(gmdate('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
        }
    }

    /**
     * The panel of the tab "Page not found": the log (list, a redirect typed or removed, an entry or
     * all without a redirect deleted) and the old aliases of renamed pages (list, delete).
     */
    public function handleNotFoundAction(string $action): void
    {
        $input = $this->plugin->app()->getInput();
        $store = new Redirects(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            if ($action === 'nf_target') {
                $id     = $input->post->getInt('id', 0);
                $target = trim((string) $input->post->getString('target', ''));
                $row    = $store->logRow($id);
                if (!$row) {
                    $this->plugin->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_NF_UI_GONE')]);
                }
                if ($target !== '') {
                    $absolute = NotFound::absoluteTarget($target, Uri::root());
                    $host     = (string) parse_url(Uri::root(), PHP_URL_HOST);
                    if ($absolute === null || !NotFound::shouldRedirect($row['path'], $absolute, $host, [])) {
                        $this->plugin->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_NF_UI_BAD_TARGET')]);
                    }
                }
                $store->setTarget($id, $target);
                $this->plugin->sendJson(['success' => true]);
            }
            if ($action === 'nf_delete') {
                $store->deleteLog($input->post->getInt('id', 0));
                $this->plugin->sendJson(['success' => true]);
            }
            if ($action === 'nf_clear') {
                $this->plugin->sendJson(['success' => true, 'removed' => $store->clearLog()]);
            }
            if ($action === 'nf_moved_delete') {
                $store->deleteMoved($input->post->getInt('id', 0));
                $this->plugin->sendJson(['success' => true]);
            }
            $limit  = max(1, min(200, $input->post->getInt('limit', 50)));
            $offset = max(0, $input->post->getInt('offset', 0));
            if ($action === 'nf_moved') {
                // the panel shows what is true now: changes not compared yet are noted first
                $store->sync(gmdate('Y-m-d H:i:s'));
                $this->plugin->sendJson(['success' => true] + $this->movedRows($store, $limit, $offset));
            }
            $this->plugin->sendJson(['success' => true] + $this->notFoundRows($store, $limit, $offset));
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Rows of the log for the panel, with a suggestion for each address that is not redirected. */
    private function notFoundRows(Redirects $store, int $limit, int $offset): array
    {
        $input = $this->plugin->app()->getInput();
        $show  = $input->post->getCmd('show', 'all');
        $found = $store->logList(\in_array($show, ['open', 'redirected'], true) ? $show : 'all', mb_substr(trim($input->post->getString('q', '')), 0, 100),
            $input->post->getCmd('order', 'last') === 'hits' ? 'hits' : 'last', $limit, $offset);
        $aliases = null;
        $levels  = MerTools::guestLevels();
        $rows    = [];
        foreach ($found['rows'] as $row) {
            $suggest = null;
            $typed   = trim((string) $row['target']);
            if ($typed === '' && $row['result'] !== 'moved') {
                $slug = Redirects::slug((string) parse_url((string) $row['path'], PHP_URL_PATH), Uri::root(true), (bool) $this->plugin->app()->get('sef_suffix', 0));
                if ($slug !== null) {
                    $aliases ??= $store->aliases();
                    $best = Redirects::suggest($slug, $aliases);
                    if ($best) {
                        $suggest = $this->siteAddress($store, $best['kind'], $best['id'], $levels);
                    }
                }
            }
            $rows[] = [
                'id'      => (int) $row['id'],
                'path'    => (string) $row['path'],
                'url'     => rtrim(Uri::root(), '/') . (string) $row['path'],
                'hits'    => (int) $row['hits'],
                'first'   => HTMLHelper::_('date', (string) $row['first_seen'], 'd.m.Y H:i'),
                'last'    => HTMLHelper::_('date', (string) $row['last_seen'], 'd.m.Y H:i'),
                'referer' => (string) $row['referer'],
                'result'  => (string) $row['result'],
                'sent'    => (string) $row['result_url'],
                'target'  => $typed,
                'suggest' => $suggest,
            ];
        }

        return ['rows' => $rows, 'total' => $found['total']];
    }

    /** The old aliases for the panel, each with the address of its page today. */
    private function movedRows(Redirects $store, int $limit, int $offset): array
    {
        $found  = $store->movedList($limit, $offset);
        $levels = MerTools::guestLevels();
        $rows   = [];
        foreach ($found['rows'] as $row) {
            $rows[] = [
                'id'      => (int) $row['id'],
                'kind'    => (string) $row['kind'],
                'item'    => (int) $row['item_id'],
                'alias'   => (string) $row['alias'],
                'title'   => (string) ($row['title'] ?? ''),
                'now'     => (string) ($row['now_alias'] ?? ''),
                'url'     => $this->siteAddress($store, (string) $row['kind'], (int) $row['item_id'], $levels),
                'changed' => HTMLHelper::_('date', (string) $row['changed'], 'd.m.Y H:i'),
                'hits'    => (int) $row['hits'],
                'edit'    => $row['kind'] === 'p' ? 'index.php?option=com_gridbox&task=gridbox.edit&id=' . (int) $row['item_id'] : '',
            ];
        }

        return ['rows' => $rows, 'total' => $found['total']];
    }

    /** The address of a Gridbox page or category on the site, from the administrator (null when a guest cannot see it). */
    private function siteAddress(Redirects $store, string $kind, int $id, array $levels): ?string
    {
        if ($kind === 'p') {
            $page = $store->livePages([$id], $levels)[$id] ?? null;
            if (!$page) {
                return null;
            }
            [$method, $args, $plain] = ['getGridboxPageLinks', [$id, $page['type'], $page['app'], $page['category']], self::pageLink($id, $page)];
        } else {
            $cat = $store->liveCategories([$id], $levels)[$id] ?? null;
            if (!$cat) {
                return null;
            }
            [$method, $args, $plain] = ['getGridboxCategoryLinks', [$id, $cat['app']], 'index.php?option=com_gridbox&view=blog&app=' . $cat['app'] . '&id=' . $id];
        }
        $link   = $plain;
        $helper = 'Balbooa\\Component\\Gridbox\\Site\\Helper\\GridboxHelper';
        try {
            if (class_exists($helper) && method_exists($helper, $method)) {
                $link = (string) $helper::$method(...$args);
            }
            $url = (string) Route::link('site', $link, false, Route::TLS_IGNORE, true);
            if ($url !== '' && !str_contains($url, '/administrator/')) {
                return $url;
            }
        } catch (\Throwable $e) {
        }

        return rtrim(Uri::root(), '/') . '/' . $plain;
    }

    /** The redirect target of missing pages from the settings: the home page, a menu item or an address. */
    private function notFoundTarget(): string
    {
        switch ((string) $this->plugin->params->get('notfound_target', 'home')) {
            case 'menu':
                $id = (int) $this->plugin->params->get('notfound_menuitem', 0);
                try {
                    // the site menu holds published items only: a deleted or unpublished one gives the home page
                    $item = $id > 0 ? $this->plugin->app()->getMenu()->getItem($id) : null;
                    if ($item) {
                        return Route::link('site', 'index.php?Itemid=' . $id, false, Route::TLS_IGNORE, true);
                    }
                } catch (\Throwable $e) {
                }

                return Uri::root();
            case 'url':
                return (string) $this->plugin->params->get('notfound_url', '');
            default:
                return Uri::root();
        }
    }
}
