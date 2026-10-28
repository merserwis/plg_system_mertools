<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Sitemap: the addresses Gridbox's sitemap would hold, read from the database — the menu items of the
 * site, Gridbox's single pages, and for each app its home page, categories, tags, authors and pages —
 * with what the filters of Sitemap need: whether the item is live, Gridbox's include flag, the
 * effective robots, the title and the map (group) it belongs to.
 *
 * The scope and the links are Gridbox's own (SitemapHelper::create): an item with a menu item of its
 * own comes in through the menu, not a second time, and the links are built by Gridbox's functions and
 * Joomla's router, so the addresses are the ones Gridbox writes. The data is loaded with one query per
 * type; unlike Gridbox, items that are not live are kept (marked) so the panel can count them.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

final class SitemapSource
{
    /** The dates of the pages: the hash of the content and the time it last changed (UTC) */
    public const TABLE = '#__mertools_sitemap';

    private const HELPER = 'Balbooa\\Component\\Gridbox\\Site\\Helper\\GridboxHelper';

    /** Actions of MerTools' edit history that change what a visitor sees */
    private const CONTENT_ACTIONS = ['created', 'duplicated', 'edited', 'published', 'restored'];

    /** #__gridbox_seo_defaults: item_type => item_id => sitemap_include */
    private array $defaults = [];

    /** The query string of the default menu item, added to links without a menu item of their own */
    private string $default = '';

    /** The categories by app and id, loaded once */
    private ?array $categories = null;

    /** The pages not in the trash, by id (from collect) */
    private array $pages = [];

    /** The tags and the authors of the live pages by app, loaded once (filters) */
    private array $filters = [];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** Gridbox's sitemap settings: domain, trailing slash, and whether its own sitemap.xml is on */
    public function website(): array
    {
        $db  = $this->db;
        $row = $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['sitemap_domain', 'sitemap_slash', 'enable_sitemap']))
            ->from($db->quoteName('#__gridbox_website')))->loadAssoc() ?: [];

        return [
            'domain'  => rtrim((string) ($row['sitemap_domain'] ?? ''), '/'),
            'slash'   => (bool) (int) ($row['sitemap_slash'] ?? 0),
            'enabled' => (bool) (int) ($row['enable_sitemap'] ?? 0),
        ];
    }

    /**
     * The items in Gridbox's order: menu items, single pages, then for each app its home page (when no
     * menu item has it), categories, tags, authors and pages. Each: type, id, app, group, live, flag,
     * robots (effective), title, route (the path from the router), created (the site's local time),
     * target ([type, id] of the Gridbox item the address shows; null for a menu item of another component).
     *
     * @param string $now    the site's local time, as Gridbox keeps dates
     * @param string $robots the robots of the Global Configuration
     */
    public function collect(string $now, string $robots): array
    {
        $helper = self::HELPER;
        if (!class_exists($helper)) {
            return [];
        }
        $helper::getGridboxMenuItems();
        $itemId         = (int) $helper::getDefaultMenuItem();
        $this->default  = '&Itemid=' . $itemId;
        $this->defaults = $this->seoDefaults();
        $apps           = $this->apps();
        $pages          = $this->pages = $this->pages($now, $apps);

        $items = $this->menuItems($pages, $apps, $robots);
        foreach ($this->appPages($pages, 0) as $page) {
            $items[] = $this->pageItem($page, $apps, $robots);
        }
        $categories = $this->categories();
        foreach ($apps as $app) {
            if ($app['type'] !== 'single') {
                if (!$helper::getGridboxMenuItemidByApp($app['id'])) {
                    $items[] = $this->item('app', $app['id'], $app, $app['live'], $app['flag'],
                        Sitemap::effectiveRobots('', $app['robots'], $robots), $app['title'],
                        'index.php?option=com_gridbox&view=blog&app=' . $app['id'] . '&id=0' . $this->default, '');
                }
                foreach ($categories[$app['id']] ?? [] as $cat) {
                    if (!$helper::getGridboxMenuItemidByCategory($app['id'], $cat['id'])) {
                        $items[] = $this->item('category', $cat['id'], $app, $cat['live'] && $app['live'],
                            Sitemap::flagged($cat['sitemap_override'], $cat['sitemap_include'], $this->defaults['category'][$app['id']] ?? null),
                            Sitemap::effectiveRobots('', $cat['robots'], $robots), $cat['title'],
                            $this->withDefault((string) $helper::getGridboxCategoryLinks($cat['id'], $app['id'])), '');
                    }
                }
                foreach ($this->filters('tag', $now)[$app['id']] ?? [] as $tag) {
                    if (!$helper::getGridboxMenuItemidByTag($tag['id'], $app['id'])) {
                        $items[] = $this->item('tag', $tag['id'], $app, $app['live'],
                            Sitemap::flagged($tag['sitemap_override'], $tag['sitemap_include'], $this->defaults['tag'][0] ?? null),
                            Sitemap::effectiveRobots('', $tag['robots'], $robots), $tag['title'],
                            $this->withDefault((string) $helper::getGridboxTagLinks($tag['id'], $app['id'])), '');
                    }
                }
                foreach ($this->filters('author', $now)[$app['id']] ?? [] as $author) {
                    $items[] = $this->item('author', $author['id'], $app, $app['live'],
                        Sitemap::flagged($author['sitemap_override'], $author['sitemap_include'], $this->defaults['author'][0] ?? null),
                        Sitemap::effectiveRobots('', $author['robots'], $robots), $author['title'],
                        $this->withDefault((string) $helper::getGridboxAuthorLinks($author['id'], $app['id'])), '');
                }
            }
            foreach ($this->appPages($pages, $app['id']) as $page) {
                $items[] = $this->pageItem($page, $apps, $robots);
            }
        }

        return array_values(array_filter($items));
    }

    // ---------------------------------------------------------------- the items

    /** The menu items of the site's menus (published, public, of a component), Gridbox's targets resolved */
    private function menuItems(array $pages, array $apps, string $robots): array
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['m.id', 'm.title', 'm.link', 'm.params']))
            ->from($db->quoteName('#__menu_types', 'mt'))
            ->join('INNER', $db->quoteName('#__menu', 'm') . ' ON ' . $db->quoteName('m.menutype') . ' = ' . $db->quoteName('mt.menutype'))
            ->where($db->quoteName('mt.client_id') . ' = 0')
            ->where($db->quoteName('m.published') . ' = 1')
            ->where($db->quoteName('m.access') . ' = 1')
            ->where($db->quoteName('m.type') . ' = ' . $db->quote('component'))
            ->order($db->quoteName('m.lft')))->loadAssocList();
        $categories = [];
        foreach ($this->categories() as $list) {
            $categories += $list;
        }
        $items = [];
        foreach ($rows as $row) {
            parse_str(str_replace('index.php?', '', (string) $row['link']), $q);
            $params     = json_decode((string) $row['params'], true) ?: [];
            $menuRobots = (string) ($params['robots'] ?? '');
            $link       = 'index.php?Itemid=' . (int) $row['id'];
            $app        = ['id' => 0, 'type' => 'single'];
            $target     = null;
            [$live, $flag, $own, $title] = [true, true, '', (string) $row['title']];
            if (($q['option'] ?? '') === 'com_gridbox' && isset($q['id'])) {
                $view = (string) ($q['view'] ?? '');
                if ($view === 'page' && isset($pages[(int) $q['id']])) {
                    $page  = $pages[(int) $q['id']];
                    $app   = $apps[$page['app_id']] ?? $app;
                    // as without a menu item: the page and its app (unpublished or not public: out)
                    $live  = $page['live'] && ($app['live'] ?? true);
                    $flag  = Sitemap::flagged($page['sitemap_override'], $page['sitemap_include'], $this->defaults['page'][$page['app_id']] ?? null);
                    [$own, $title] = [$page['robots'], $page['title']];
                    $target = ['page', $page['id']];
                } elseif ($view === 'blog' && (int) $q['id'] > 0 && isset($categories[(int) $q['id']])) {
                    $cat   = $categories[(int) $q['id']];
                    $app   = $apps[$cat['app_id']] ?? $app;
                    $live  = $cat['live'] && ($app['live'] ?? true);
                    $flag  = Sitemap::flagged($cat['sitemap_override'], $cat['sitemap_include'], $this->defaults['category'][$cat['app_id']] ?? null);
                    [$own, $title] = [$cat['robots'], $cat['title']];
                    $target = ['category', $cat['id']];
                } elseif ($view === 'blog' && isset($apps[(int) ($q['app'] ?? 0)])) {
                    $app  = $apps[(int) $q['app']];
                    $live = $app['live'];
                    $flag = $app['flag'];
                    [$own, $title] = [$app['robots'], $app['title']];
                    $target = ['app', $app['id']];
                } elseif ($view === 'page' || $view === 'blog') {
                    $live = false; // a menu item of a page or category that is gone or in the trash
                }
            }
            $items[] = $this->item('menu', (int) $row['id'], $app, $live, $flag, Sitemap::effectiveRobots($menuRobots, $own, $robots), $title, $link, '', $target);
        }

        return $items;
    }

    /** A page without a menu item of its own (those come in through the menu) */
    private function pageItem(array $page, array $apps, string $robots): ?array
    {
        $helper = self::HELPER;
        if ($helper::getGridboxMenuItemidByPage($page['id'])) {
            return null;
        }
        $app  = $apps[$page['app_id']] ?? ['id' => 0, 'type' => 'single', 'live' => true];
        $link = (string) $helper::getGridboxPageLinks($page['id'], $app['type'], $app['id'], $page['page_category']);

        return $this->item('page', $page['id'], $app, $page['live'] && $app['live'],
            Sitemap::flagged($page['sitemap_override'], $page['sitemap_include'], $this->defaults['page'][$page['app_id']] ?? null),
            Sitemap::effectiveRobots('', $page['robots'], $robots), $page['title'], $this->withDefault($link), $page['created']);
    }

    private function item(string $type, int $id, array $app, bool $live, bool $flag, string $robots, string $title, string $link, string $created, ?array $target = null): array
    {
        return [
            'type'    => $type,
            'id'      => $id,
            'app'     => (int) $app['id'],
            'group'   => Sitemap::group($app['type'] === 'single' ? '' : $app['type'], $app['type'] === 'single' ? 0 : (int) $app['id']),
            'live'    => $live,
            'flag'    => $flag,
            'robots'  => $robots,
            'title'   => $title,
            'route'   => (string) Route::link('site', $link, false),
            'created' => $created,
            'target'  => $type === 'menu' ? $target : [$type, $id],
        ];
    }

    private function withDefault(string $link): string
    {
        return str_contains($link, '&Itemid=') ? $link : $link . $this->default;
    }

    // ---------------------------------------------------------------- lastmod

    /**
     * The items of collect() with their lastmod (unix time or null). A page is dated by the hash of its
     * content (Sitemap::nextState), the state kept in #__mertools_sitemap; a category, an app's home page,
     * a tag and an author take the newest date of their live pages (a category with its subcategories),
     * a menu item the date of the item it shows.
     *
     * @param bool          $prices  count price and stock changes of the products
     * @param string        $unknown 'omit' or 'created' (Sitemap::unknownDate)
     * @param \DateTimeZone $zone    the site's time zone (Gridbox's dates are local)
     */
    public function dates(array $items, int $now, bool $prices, string $unknown, \DateTimeZone $zone): array
    {
        $this->ensureTable();
        $stored  = $this->state();
        $history = null;
        $changed = [];
        $pages   = [];
        foreach ($this->hashes($prices) as $id => $hash) {
            $old = $stored[$id] ?? null;
            if ($old === null) {
                $history ??= $this->history();
            }
            $next = Sitemap::nextState($old, $hash, $now, $old === null ? ($history[$id] ?? null) : null);
            if ($next !== $old) {
                $changed[$id] = $next;
            }
            $pages[$id] = $next['lastmod'];
        }
        $this->saveState($changed, array_keys(array_diff_key($stored, $pages)));

        // the newest date of the live pages of each app, category (own pages), tag and author
        $byApp = $byCategory = [];
        $by    = ['tag' => [], 'author' => []];
        $maps  = $this->pageMaps();
        foreach ($this->pages as $id => $page) {
            if (!$page['live']) {
                continue;
            }
            $date                    = $pages[$id] ?? null;
            $byApp[$page['app_id']]  = Sitemap::newest([$byApp[$page['app_id']] ?? null, $date]);
            foreach (array_unique(array_merge([(int) $page['page_category']], $maps['category'][$id] ?? [])) as $cat) {
                $byCategory[$cat] = Sitemap::newest([$byCategory[$cat] ?? null, $date]);
            }
            foreach (['tag', 'author'] as $kind) {
                foreach ($maps[$kind][$id] ?? [] as $other) {
                    $by[$kind][$other] = Sitemap::newest([$by[$kind][$other] ?? null, $date]);
                }
            }
        }
        $parents = [];
        foreach ($this->categories() as $list) {
            foreach ($list as $cat) {
                $parents[$cat['id']] = $cat['parent'];
            }
        }
        $categories = Sitemap::categoryDates($parents, $byCategory);

        foreach ($items as &$item) {
            [$type, $id] = $item['target'] ?? [null, 0];
            $lastmod = match ($type) {
                'page'          => $pages[$id] ?? null,
                'category'      => $categories[$id] ?? null,
                'app'           => $byApp[$id] ?? null,
                'tag', 'author' => $by[$type][$id] ?? null,
                default         => null,
            };
            $created         = $type === 'page' ? Feed::time($this->pages[$id]['created'] ?? '', $zone) : null;
            $item['lastmod'] = Sitemap::dateFor($lastmod, $created, $unknown);
        }

        return $items;
    }

    public function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' (`item_type` VARCHAR(16) NOT NULL,'
            . ' `item_id` INT UNSIGNED NOT NULL, `hash` CHAR(32) NOT NULL, `lastmod` DATETIME NULL, PRIMARY KEY (`item_type`, `item_id`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
    }

    /**
     * The hash of the content of each page not in the trash: the texts a visitor and a robot see,
     * the page's fields (a product's parameters), and with $prices the price, sale price, stock,
     * variations and options of a product. Not saved_time, not the CSS (Gridbox rewrites both).
     *
     * @return array<int, string>
     */
    private function hashes(bool $prices): array
    {
        $db = $this->db;
        try {
            $db->setQuery('SET SESSION group_concat_max_len = 1048576')->execute();
        } catch (\Throwable $e) {
        }
        $columns = ['p.title', 'p.params', 'p.intro_text', 'p.meta_title', 'p.meta_description', 'p.page_alias', 'p.page_category', 'p.robots', 'p.intro_image', 'f.h'];
        if ($prices) {
            array_push($columns, 'd.price', 'd.sale_price', 'd.stock', 'd.variations', 'd.extra_options');
        }
        $fields = $db->getQuery(true)
            ->select($db->quoteName('page_id') . ', MD5(GROUP_CONCAT(CONCAT(' . $db->quoteName('field_id') . ", ':', MD5(COALESCE(" . $db->quoteName('value')
                . ", ''))) ORDER BY " . $db->quoteName('field_id') . ', ' . $db->quoteName('id') . " SEPARATOR ',')) AS " . $db->quoteName('h'))
            ->from($db->quoteName('#__gridbox_page_fields'))
            ->group($db->quoteName('page_id'));
        $query = $db->getQuery(true)
            ->select($db->quoteName('p.id') . ', MD5(CONCAT_WS(CHAR(31), '
                . implode(', ', array_map(static fn (string $c): string => 'COALESCE(' . $db->quoteName($c) . ", '')", $columns)) . ')) AS ' . $db->quoteName('hash'))
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->join('LEFT', '(' . $fields . ') AS ' . $db->quoteName('f') . ' ON ' . $db->quoteName('f.page_id') . ' = ' . $db->quoteName('p.id'))
            ->where($db->quoteName('p.page_category') . ' <> ' . $db->quote('trashed'));
        if ($prices) {
            $query->join('LEFT', $db->quoteName('#__gridbox_store_product_data', 'd') . ' ON ' . $db->quoteName('d.product_id') . ' = ' . $db->quoteName('p.id'));
        }
        $hashes = [];
        foreach ((array) $db->setQuery($query)->loadRowList() as [$id, $hash]) {
            $hashes[(int) $id] = (string) $hash;
        }

        return $hashes;
    }

    /** @return array<int, array{hash: string, lastmod: ?int}> the kept state of the pages */
    private function state(): array
    {
        $db    = $this->db;
        $state = [];
        $rows  = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['item_id', 'hash', 'lastmod']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('item_type') . ' = ' . $db->quote('page')))->loadRowList();
        foreach ($rows as [$id, $hash, $lastmod]) {
            $state[(int) $id] = ['hash' => (string) $hash, 'lastmod' => $lastmod === null ? null : (int) strtotime($lastmod . ' UTC')];
        }

        return $state;
    }

    /** The changed states written, the states of pages that are gone (deleted, in the trash) removed */
    private function saveState(array $changed, array $gone): void
    {
        $db = $this->db;
        foreach (array_chunk($changed, 500, true) as $chunk) {
            $values = [];
            foreach ($chunk as $id => $s) {
                $values[] = '(' . $db->quote('page') . ', ' . (int) $id . ', ' . $db->quote($s['hash']) . ', '
                    . ($s['lastmod'] === null ? 'NULL' : $db->quote(gmdate('Y-m-d H:i:s', $s['lastmod']))) . ')';
            }
            $db->setQuery('INSERT INTO ' . $db->quoteName(self::TABLE) . ' (`item_type`, `item_id`, `hash`, `lastmod`) VALUES ' . implode(', ', $values)
                . ' ON DUPLICATE KEY UPDATE `hash` = VALUES(`hash`), `lastmod` = VALUES(`lastmod`)')->execute();
        }
        foreach (array_chunk($gone, 500) as $chunk) {
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE `item_type` = ' . $db->quote('page')
                . ' AND `item_id` IN (' . implode(', ', array_map('intval', $chunk)) . ')')->execute();
        }
    }

    /**
     * The newest change of the content of each page in MerTools' edit history (UTC), for pages seen
     * for the first time; empty when the history is off or empty.
     *
     * @return array<int, int>
     */
    private function history(): array
    {
        $db = $this->db;
        try {
            $rows = (array) $db->setQuery($db->getQuery(true)
                ->select($db->quoteName('page_id') . ', MAX(' . $db->quoteName('created') . ')')
                ->from($db->quoteName(History::TABLE))
                ->where($db->quoteName('action') . ' IN (' . implode(', ', array_map([$db, 'quote'], self::CONTENT_ACTIONS)) . ')')
                ->group($db->quoteName('page_id')))->loadRowList();
        } catch (\Throwable $e) {
            return [];
        }
        $dates = [];
        foreach ($rows as [$id, $created]) {
            $time = strtotime($created . ' UTC');
            if ($time) {
                $dates[(int) $id] = $time;
            }
        }

        return $dates;
    }

    /** The other categories, the tags and the authors of each page: kind => page => ids */
    private function pageMaps(): array
    {
        $db   = $this->db;
        $maps = ['category' => [], 'tag' => [], 'author' => []];
        foreach (['category' => ['#__gridbox_category_page_map', 'category_id'], 'tag' => ['#__gridbox_tags_map', 'tag_id'],
            'author' => ['#__gridbox_authors_map', 'author_id']] as $kind => [$table, $column]) {
            $rows = (array) $db->setQuery($db->getQuery(true)
                ->select($db->quoteName(['page_id', $column]))
                ->from($db->quoteName($table)))->loadRowList();
            foreach ($rows as [$page, $other]) {
                $maps[$kind][(int) $page][] = (int) $other;
            }
        }

        return $maps;
    }

    // ---------------------------------------------------------------- Gridbox's data

    /** The apps (not the system apps), by id; live = published and public */
    private function apps(): array
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'type', 'published', 'access', 'robots', 'sitemap_include']))
            ->from($db->quoteName('#__gridbox_app'))
            ->where($db->quoteName('type') . ' <> ' . $db->quote('system_apps'))
            ->order($db->quoteName('id')))->loadAssocList('id');
        $apps = [];
        foreach ($rows as $id => $row) {
            $apps[(int) $id] = [
                'id'     => (int) $id,
                'title'  => (string) $row['title'],
                'type'   => (string) $row['type'],
                'live'   => (int) $row['published'] === 1 && (int) $row['access'] === 1,
                'flag'   => (int) $row['sitemap_include'] === 1,
                'robots' => (string) $row['robots'],
            ];
        }

        return $apps;
    }

    /**
     * The pages not in the trash, by id. Live as Gridbox's sitemap has it: published, public, started,
     * not ended, not a product hidden by a subscription, and of a known app (or none).
     */
    private function pages(string $now, array $apps): array
    {
        $db     = $this->db;
        $null   = $db->getNullDate();
        $hidden = array_flip(array_map('intval', (array) (self::HELPER)::getSubscriptionProducts()));
        // a finished item MerTools gave an end of publishing (EndedPages) is out of the lists only: its page is still there
        $kept   = array_flip((new EndedPages($db))->keptIds());
        $rows   = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'app_id', 'page_category', 'published', 'page_access', 'created', 'end_publishing',
                'robots', 'sitemap_override', 'sitemap_include']))
            ->from($db->quoteName('#__gridbox_pages'))
            ->where($db->quoteName('page_category') . ' <> ' . $db->quote('trashed'))
            ->order($db->quoteName('id')))->loadAssocList();
        $pages = [];
        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $appId = (int) $row['app_id'];
            $end   = (string) $row['end_publishing'];
            $pages[$id] = [
                'id'               => $id,
                'title'            => (string) $row['title'],
                'app_id'           => $appId,
                'page_category'    => (string) $row['page_category'],
                'created'          => (string) $row['created'],
                'robots'           => (string) $row['robots'],
                'sitemap_override' => (int) $row['sitemap_override'],
                'sitemap_include'  => (int) $row['sitemap_include'],
                'live'             => (int) $row['published'] === 1 && (int) $row['page_access'] === 1
                    && (string) $row['created'] <= $now && ($end === '' || $end === $null || $end >= $now || isset($kept[$id]))
                    && !isset($hidden[$id]) && ($appId === 0 || isset($apps[$appId])),
            ];
        }

        return $pages;
    }

    /** The pages of one app (0: the single pages outside the apps) */
    private function appPages(array $pages, int $appId): array
    {
        return array_filter($pages, static fn (array $p): bool => $p['app_id'] === $appId);
    }

    /** The categories by app and id; live = published and public */
    private function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'app_id', 'parent', 'published', 'access', 'robots', 'sitemap_override', 'sitemap_include']))
            ->from($db->quoteName('#__gridbox_categories'))
            ->order($db->quoteName('id')))->loadAssocList();
        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['app_id']][(int) $row['id']] = [
                'id'               => (int) $row['id'],
                'title'            => (string) $row['title'],
                'app_id'           => (int) $row['app_id'],
                'parent'           => (int) $row['parent'],
                'live'             => (int) $row['published'] === 1 && (int) $row['access'] === 1,
                'robots'           => (string) $row['robots'],
                'sitemap_override' => (int) $row['sitemap_override'],
                'sitemap_include'  => (int) $row['sitemap_include'],
            ];
        }

        return $this->categories = $list;
    }

    /**
     * The tags (published, public) or authors (published) of the live pages of each app, as Gridbox
     * lists them: a tag or author without such a page is not in its sitemap. One query for all the apps,
     * kept for the next app.
     *
     * @return array<int, array> app => its tags or authors, in the order of their ids
     */
    private function filters(string $kind, string $now): array
    {
        if (isset($this->filters[$kind . $now])) {
            return $this->filters[$kind . $now];
        }
        $db    = $this->db;
        $nowQ  = $db->quote($now);
        $table = $kind === 'tag' ? '#__gridbox_tags' : '#__gridbox_authors';
        $map   = $kind === 'tag' ? ['#__gridbox_tags_map', 'tag_id'] : ['#__gridbox_authors_map', 'author_id'];
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . implode(', ', $db->quoteName(['p.app_id', 't.id', 't.title', 't.robots', 't.sitemap_override', 't.sitemap_include'])))
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->join('INNER', $db->quoteName($map[0], 'm') . ' ON ' . $db->quoteName('m.page_id') . ' = ' . $db->quoteName('p.id'))
            ->join('INNER', $db->quoteName($table, 't') . ' ON ' . $db->quoteName('t.id') . ' = ' . $db->quoteName('m.' . $map[1]))
            ->where($db->quoteName('p.page_category') . ' <> ' . $db->quote('trashed'))
            ->where($db->quoteName('p.published') . ' = 1')
            ->where($db->quoteName('p.page_access') . ' = 1')
            ->where($db->quoteName('p.created') . ' <= ' . $nowQ)
            ->where('(' . $db->quoteName('p.end_publishing') . ' = ' . $db->quote($db->getNullDate()) . ' OR ' . $db->quoteName('p.end_publishing') . ' >= ' . $nowQ . ')')
            ->where($db->quoteName('t.published') . ' = 1')
            ->order($db->quoteName('t.id'));
        if ($kind === 'tag') {
            $query->where($db->quoteName('t.access') . ' = 1');
        }
        $list = [];
        foreach ((array) $db->setQuery($query)->loadAssocList() as $row) {
            $list[(int) $row['app_id']][] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'robots' => (string) $row['robots'],
                'sitemap_override' => (int) $row['sitemap_override'], 'sitemap_include' => (int) $row['sitemap_include']];
        }

        return $this->filters[$kind . $now] = $list;
    }

    /** Gridbox's defaults of the include flag: item_type => item_id (the app, 0 for tags and authors) => value */
    private function seoDefaults(): array
    {
        $db       = $this->db;
        $defaults = [];
        $rows     = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['item_id', 'item_type', 'sitemap_include']))
            ->from($db->quoteName('#__gridbox_seo_defaults')))->loadAssocList();
        foreach ($rows as $row) {
            $defaults[(string) $row['item_type']][(int) $row['item_id']] = (string) $row['sitemap_include'];
        }

        return $defaults;
    }
}
