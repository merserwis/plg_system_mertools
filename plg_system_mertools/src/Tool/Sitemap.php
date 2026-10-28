<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Sitemap: the logic of MerTools' own sitemap, without Joomla — it replaces Gridbox's static
 * sitemap.xml with an index and one sitemap per Gridbox app, holding only addresses worth indexing,
 * with a lastmod that means a change of the content.
 *
 * What is here: the settings, the robots.txt parser (groups of agents, Allow/Disallow, the longest
 * match wins, Allow wins a tie, * and $), the effective robots of an item in Gridbox's order (the menu
 * item pointing to it, the item itself, the Global Configuration), the reasons an address is left out
 * (each counted in the panel), the lastmod decision from a hash of the content, and the XML of the maps
 * and of the index (escaped, split into parts).
 *
 * Gridbox's saved_time says nothing about the content: every save in its editor deletes the CSS of all
 * pages and the next visit of each page writes it again with a new saved_time. The date of a page is
 * therefore the moment its hash changed, not its saved_time.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Sitemap
{
    /** Why an address is not in the map, in the order the filters run */
    public const REASONS = ['status', 'flag', 'noindex', 'robots-txt', 'type', 'app', 'path', 'title', 'query', 'dup'];

    /** Item types and whether they are in the map by default */
    public const TYPES = ['menu' => 1, 'page' => 1, 'category' => 1, 'app' => 1, 'tag' => 0, 'author' => 0];

    /** The agents robots.txt is read for: an address blocked for either is left out */
    public const AGENTS = ['*', 'googlebot'];

    public const PART_LIMIT = 10000;

    /** The limit of sitemaps.org: addresses in one file */
    public const MAX_PART = 50000;

    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    // ---------------------------------------------------------------- settings

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('sitemap_enabled', 0);
    }

    /** One map per Gridbox app (the default) or all addresses in one file */
    public static function split(Registry $params): bool
    {
        return (string) $params->get('sitemap_split', 'app') !== 'single';
    }

    public static function partLimit(Registry $params): int
    {
        return max(1000, min(self::MAX_PART, (int) $params->get('sitemap_part_limit', self::PART_LIMIT)));
    }

    /** @return string[] the item types in the map */
    public static function types(Registry $params): array
    {
        $types = [];
        foreach (self::TYPES as $type => $default) {
            if ((int) $params->get('sitemap_type_' . $type, $default)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** Respect Gridbox's include flags, skip noindex pages, skip addresses blocked by robots.txt */
    public static function filters(Registry $params): array
    {
        return [
            'flag'       => (bool) (int) $params->get('sitemap_flags', 1),
            'noindex'    => (bool) (int) $params->get('sitemap_noindex', 1),
            'robots-txt' => (bool) (int) $params->get('sitemap_robots', 1),
        ];
    }

    /** @return int[] the apps left out */
    public static function excludedApps(Registry $params): array
    {
        $apps = $params->get('sitemap_apps', []);
        if (\is_string($apps)) {
            $apps = $apps === '' ? [] : explode(',', $apps);
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $apps), static fn (int $id): bool => $id > 0)));
    }

    /** @return string[] the non-empty lines of a text setting (excluded paths, excluded titles) */
    public static function lines(Registry $params, string $key): array
    {
        $lines = preg_split('/\R/', (string) $params->get($key, '')) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== '')));
    }

    /** Count price and stock changes as a change of a product */
    public static function countPrices(Registry $params): bool
    {
        return (bool) (int) $params->get('sitemap_prices', 0);
    }

    /** When the date is unknown: no <lastmod> ('omit', the default) or the date of publishing ('created') */
    public static function unknownDate(Registry $params): string
    {
        return (string) $params->get('sitemap_unknown_date', 'omit') === 'created' ? 'created' : 'omit';
    }

    /** Minutes after which the maps are built again */
    public static function ttl(Registry $params): int
    {
        return max(5, min(1440, (int) $params->get('sitemap_ttl', 360)));
    }

    // ---------------------------------------------------------------- robots.txt

    /**
     * The groups of robots.txt: for each agent (lower case) its rules, [allow, pattern] in file order.
     * Lines of one agent in several groups are joined, as Google does. An empty Disallow allows all
     * and is dropped; lines outside a group, comments and other fields (Sitemap, Crawl-delay) are ignored.
     *
     * @return array<string, array<int, array{0: bool, 1: string}>>
     */
    public static function parseRobots(string $txt): array
    {
        $groups = [];
        $agents = [];
        $rules  = false; // a rule seen since the last user-agent line: the next one starts a new group
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            $value = trim($m[2]);
            if ($field === 'user-agent') {
                if ($rules) {
                    $agents = [];
                    $rules  = false;
                }
                $agent          = strtolower($value);
                $agents[]       = $agent;
                $groups[$agent] = $groups[$agent] ?? [];
            } elseif ($field === 'allow' || $field === 'disallow') {
                $rules = true;
                if ($value === '') {
                    continue;
                }
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field === 'allow', $value];
                }
            }
        }

        return $groups;
    }

    /**
     * The rules an agent follows: its own group when there is one (the longest agent name that is a
     * part of it, "googlebot" for "googlebot-image"), otherwise the group of "*".
     */
    public static function rulesFor(array $groups, string $agent): array
    {
        $agent = strtolower($agent);
        $best  = null;
        foreach (array_keys($groups) as $name) {
            if ($name !== '*' && str_starts_with($agent, $name) && ($best === null || \strlen($name) > \strlen($best))) {
                $best = $name;
            }
        }

        return $groups[$best ?? '*'] ?? [];
    }

    /** Whether the rules allow a path (with its query): the longest matching rule wins, Allow wins a tie */
    public static function allowed(array $rules, string $path): bool
    {
        $path    = self::encode($path);
        $allow   = true;
        $longest = -1;
        foreach ($rules as [$isAllow, $pattern]) {
            $pattern = self::encode($pattern);
            if (!preg_match(self::robotsRegex($pattern), $path)) {
                continue;
            }
            $length = \strlen($pattern);
            if ($length > $longest || ($length === $longest && $isAllow)) {
                $allow   = $isAllow;
                $longest = $length;
            }
        }

        return $allow;
    }

    /** Whether robots.txt blocks a path for any of the agents the map is for */
    public static function blocked(array $groups, string $path): bool
    {
        foreach (self::AGENTS as $agent) {
            if (!self::allowed(self::rulesFor($groups, $agent), $path)) {
                return true;
            }
        }

        return false;
    }

    private static function robotsRegex(string $pattern): string
    {
        $end   = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote($end ? substr($pattern, 0, -1) : $pattern, '#'));

        return '#^' . $regex . ($end ? '$' : '') . '#';
    }

    /** Non-ASCII bytes percent-encoded and %xx in upper case, so "ą" and "%C4%85" compare equal */
    private static function encode(string $s): string
    {
        $s = preg_replace_callback('/[\x80-\xFF]/', static fn (array $m): string => '%' . strtoupper(bin2hex($m[0])), $s);

        return preg_replace_callback('/%[0-9a-f]{2}/i', static fn (array $m): string => strtoupper($m[0]), $s);
    }

    // ---------------------------------------------------------------- noindex

    /**
     * The robots of an item as Gridbox writes it into the page: the menu item pointing to the item
     * (when set), then the item's own field, then the Global Configuration.
     */
    public static function effectiveRobots(string $menu, string $own, string $global): string
    {
        foreach ([$menu, $own, $global] as $robots) {
            if (trim($robots) !== '') {
                return trim($robots);
            }
        }

        return '';
    }

    public static function isNoindex(string $robots): bool
    {
        $words = preg_split('/[\s,]+/', strtolower($robots), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return \in_array('noindex', $words, true) || \in_array('none', $words, true);
    }

    // ---------------------------------------------------------------- the filters

    /**
     * Gridbox's include flag: the item's own when it overrides the defaults, otherwise the default of
     * its type (#__gridbox_seo_defaults; no row means included).
     */
    public static function flagged(int $override, int $own, ?string $default): bool
    {
        return $override ? $own === 1 : ($default ?? '1') === '1';
    }

    /** The path of an address for the filters: "/" for the root, no trailing slash, no query */
    public static function path(string $url): string
    {
        return '/' . trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    /**
     * Whether a path matches one of the patterns: "*" for any characters, the whole path compared,
     * letter case ignored; a pattern may be a full address of the site.
     */
    public static function pathMatches(string $path, array $patterns): bool
    {
        $path = self::path($path);
        foreach ($patterns as $pattern) {
            $pattern = self::path(preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', trim($pattern)));
            $regex   = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a title contains one of the fragments, letter case ignored */
    public static function titleMatches(string $title, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && mb_stripos($title, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rules the items are checked against, from the settings and robots.txt (null: no file). $sef: Joomla's
     * Search Engine Friendly URLs; without them every address is index.php with a query that tells the pages apart.
     *
     * @return array{types: string[], filters: array<string, bool>, apps: int[], paths: string[], titles: string[], robots: ?array, sef: bool}
     */
    public static function rules(Registry $params, ?string $robotsTxt, bool $sef = true): array
    {
        return [
            'types'   => self::types($params),
            'filters' => self::filters($params),
            'apps'    => self::excludedApps($params),
            'paths'   => self::lines($params, 'sitemap_paths'),
            'titles'  => self::lines($params, 'sitemap_titles'),
            'robots'  => $robotsTxt === null ? null : self::parseRobots($robotsTxt),
            'sef'     => $sef,
        ];
    }

    /**
     * Why an item is not in the map, or null when it is. The item: type, app (id, 0 outside the apps),
     * live (published, public, started, not ended, not in the trash), flag (Gridbox's include flag),
     * robots (effective), loc, title. $seen collects the addresses already in the map. Without SEF an address
     * with a query is the page itself (index.php?option=…&id=42): kept, and robots.txt checked with its query.
     */
    public static function reason(array $item, array $rules, array &$seen): ?string
    {
        $loc    = (string) $item['loc'];
        $path   = self::path($loc);
        $query  = (string) parse_url($loc, PHP_URL_QUERY);
        $sef    = (bool) ($rules['sef'] ?? true);
        $checks = [
            'status'     => static fn (): bool => empty($item['live']),
            'flag'       => static fn (): bool => $rules['filters']['flag'] && empty($item['flag']),
            'noindex'    => static fn (): bool => $rules['filters']['noindex'] && self::isNoindex((string) ($item['robots'] ?? '')),
            'robots-txt' => static fn (): bool => $rules['filters']['robots-txt'] && $rules['robots'] !== null
                && self::blocked($rules['robots'], ((string) parse_url($loc, PHP_URL_PATH) ?: '/') . (!$sef && $query !== '' ? '?' . $query : '')),
            'type'       => static fn (): bool => !\in_array($item['type'], $rules['types'], true),
            'app'        => static fn (): bool => \in_array((int) ($item['app'] ?? 0), $rules['apps'], true),
            'path'       => static fn (): bool => self::pathMatches($path, $rules['paths']),
            'title'      => static fn (): bool => self::titleMatches((string) ($item['title'] ?? ''), $rules['titles']),
            'query'      => static fn (): bool => $sef && $query !== '',
            'dup'        => static fn (): bool => isset($seen[$loc]),
        ];
        foreach ($checks as $reason => $check) {
            if ($check()) {
                return $reason;
            }
        }
        $seen[$loc] = true;

        return null;
    }

    // ---------------------------------------------------------------- addresses

    /**
     * The address the routed paths are put after: Gridbox's sitemap domain, else the site's own address. The
     * paths Joomla's router builds begin with the folder of a site installed in one ("/shop/catalog/item"), so
     * the folder is taken off the end of the domain ("https://example.com/shop" → "https://example.com"): it is
     * never written twice. A site in the root of its domain keeps the domain as it is.
     *
     * @param string $domain Gridbox's sitemap domain ('' when not set)
     * @param string $root   the site's address (Uri::root(): "https://example.com/shop/")
     * @param string $base   the site's folder (Uri::root(true): "" or "/shop")
     */
    public static function domain(string $domain, string $root, string $base): string
    {
        $domain = rtrim($domain !== '' ? $domain : $root, '/');
        $base   = rtrim($base, '/');
        if ($base !== '' && str_ends_with(strtolower($domain), strtolower($base))) {
            $domain = substr($domain, 0, -\strlen($base));
        }

        return $domain;
    }

    /**
     * The address in the map, as Gridbox writes it: its sitemap domain before the routed path and its
     * rule of the trailing slash (added or removed), so the addresses stay the same as today. Not after
     * index.php followed by a query (a site without SEF): "index.php/?id=42" is not the page's address.
     */
    public static function loc(string $route, string $domain, bool $slash): string
    {
        [$path, $query] = array_pad(explode('?', $route, 2), 2, null);
        $script = $query !== null && str_ends_with(strtolower($path), '.php');
        if (!$script && $slash && !str_ends_with($path, '/')) {
            $path .= '/';
        } elseif (!$script && !$slash && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return rtrim($domain, '/') . $path . ($query !== null ? '?' . $query : '');
    }

    /**
     * The file a request asks for, from its path below the site's root ("/sitemap-blog-4.xml"), or null
     * when the path is not one of the maps.
     */
    public static function requested(string $path): ?string
    {
        return preg_match('#^/(sitemap(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?\.xml)$#', $path, $m) && \strlen($m[1]) <= 80 ? $m[1] : null;
    }

    /** The name of a map: "pages" for the menu items and single pages, "{type}-{id}" for an app */
    public static function group(string $appType, int $appId): string
    {
        if ($appId <= 0) {
            return 'pages';
        }
        $type = preg_replace('/[^a-z0-9]+/', '', strtolower($appType));

        return ($type === '' ? 'app' : $type) . '-' . $appId;
    }

    /**
     * The files of the maps. Split by app: the index sitemap.xml and sitemap-{group}.xml for each
     * non-empty group, -p2, -p3… for the parts over the limit. One file: sitemap.xml holds all the
     * addresses; above 50,000 it becomes the index of sitemap-all.xml, sitemap-all-p2.xml…
     *
     * @param array<string, array> $groups group => entries [loc, lastmod (unix time or null)]
     *
     * @return array{index: bool, files: array<string, array>}
     */
    public static function layout(array $groups, bool $split, int $limit): array
    {
        if (!$split) {
            $all = array_merge(...array_values($groups ?: [[]]));
            if (\count($all) <= self::MAX_PART) {
                return ['index' => false, 'files' => ['sitemap.xml' => $all]];
            }
            $groups = ['all' => $all];
            $limit  = self::MAX_PART;
        }
        $files = [];
        foreach ($groups as $group => $entries) {
            foreach (array_chunk($entries, max(1, $limit)) as $i => $part) {
                $files['sitemap-' . $group . ($i ? '-p' . ($i + 1) : '') . '.xml'] = $part;
            }
        }

        return ['index' => true, 'files' => $files];
    }

    // ---------------------------------------------------------------- lastmod

    /**
     * The next state of an item's date from the hash of its content now. A changed hash dates the
     * item at $now; the same hash keeps the date. An item seen for the first time takes the time of
     * the newest change in MerTools' edit history (null: unknown).
     *
     * @param array{hash: string, lastmod: ?int}|null $stored
     *
     * @return array{hash: string, lastmod: ?int}
     */
    public static function nextState(?array $stored, string $hash, int $now, ?int $history): array
    {
        if ($stored === null) {
            return ['hash' => $hash, 'lastmod' => $history];
        }
        if ($stored['hash'] === $hash) {
            return $stored;
        }

        return ['hash' => $hash, 'lastmod' => $now];
    }

    /** The date in the map: the known one, otherwise the date of publishing when chosen, otherwise none */
    public static function dateFor(?int $lastmod, ?int $created, string $unknown): ?int
    {
        return $lastmod ?? ($unknown === 'created' ? $created : null);
    }

    /**
     * The date of each category: the newest date of its pages and of the pages of its subcategories.
     *
     * @param array<int, int>      $parents category => parent
     * @param array<int, int|null> $own     category => the newest date of its own pages
     *
     * @return array<int, int|null>
     */
    public static function categoryDates(array $parents, array $own): array
    {
        $dates = [];
        foreach (array_keys($parents) as $id) {
            $dates[$id] = $own[$id] ?? null;
        }
        foreach ($own as $id => $date) {
            $seen = [];
            for ($parent = $parents[$id] ?? 0; $parent > 0 && !isset($seen[$parent]); $parent = $parents[$parent] ?? 0) {
                $seen[$parent]  = true;
                $dates[$parent] = self::newest([$dates[$parent] ?? null, $date]);
            }
        }

        return $dates;
    }

    /** The newest of the dates, null when none is known */
    public static function newest(array $dates): ?int
    {
        $dates = array_filter($dates, static fn ($d): bool => $d !== null);

        return $dates ? (int) max($dates) : null;
    }

    /** Whether the dates look changed in bulk: 100 addresses or more and over 90% of them in 7 days */
    public static function massDates(array $dates, int $now): bool
    {
        $dates = array_filter($dates, static fn ($d): bool => $d !== null);
        if (\count($dates) < 100) {
            return false;
        }
        $recent = \count(array_filter($dates, static fn (int $d): bool => $d >= $now - 7 * 86400));

        return $recent / \count($dates) > 0.9;
    }

    // ---------------------------------------------------------------- XML

    public static function time(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /** @param array<int, array{0: string, 1: ?int}> $entries [loc, lastmod] */
    public static function urlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="' . self::NS . '">' . "\n";
        foreach ($entries as [$loc, $lastmod]) {
            $xml .= '<url><loc>' . self::escape($loc) . '</loc>' . ($lastmod !== null ? '<lastmod>' . self::time($lastmod) . '</lastmod>' : '') . "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /** @param array<int, array{0: string, 1: ?int}> $maps [address of the map, its newest lastmod] */
    public static function index(array $maps): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
        foreach ($maps as [$loc, $lastmod]) {
            $xml .= '<sitemap><loc>' . self::escape($loc) . '</loc>' . ($lastmod !== null ? '<lastmod>' . self::time($lastmod) . '</lastmod>' : '') . "</sitemap>\n";
        }

        return $xml . "</sitemapindex>\n";
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---------------------------------------------------------------- the response

    /**
     * Whether a conditional request can be answered 304: If-None-Match decides when sent (an ETag
     * of the list, weak or not, or *), otherwise If-Modified-Since not older than the build.
     */
    public static function notModified(string $etag, int $built, string $ifNoneMatch, string $ifModifiedSince): bool
    {
        if (trim($ifNoneMatch) !== '') {
            foreach (explode(',', $ifNoneMatch) as $tag) {
                $tag = trim($tag);
                if ($tag === '*' || preg_replace('#^W/#', '', $tag) === $etag) {
                    return true;
                }
            }

            return false;
        }
        $since = $ifModifiedSince === '' ? false : strtotime($ifModifiedSince);

        return $since !== false && $since >= $built;
    }
}
