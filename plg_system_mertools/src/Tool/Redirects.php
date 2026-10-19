<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Moved pages and the log of missing addresses (404).
 *
 * Moved pages: a Gridbox address ends with the alias of its page or category
 * (/oferta/mierniki/…/sonel-mpi-540), so a renamed alias or a page moved to another category leaves
 * the old address answering 404 — Gridbox redirects nothing. The aliases of every Gridbox page and
 * category are kept (#__mertools_aliases) and compared after each change sent to Gridbox and once an
 * hour; an alias that changed is noted with its page (#__mertools_moved). A missing address whose last
 * part is the alias of a page or category today, or an old alias of one, is sent to where that page is
 * now with a 301. The alias changes of the edit history are taken over at the first run.
 *
 * Log: every missing page address with the number of visits, the first and the last one, where the
 * last visitor came from and what MerTools did (#__mertools_notfound). A redirect typed for an address
 * of the log is followed from then on (a 301), before the moved pages and the general target.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class Redirects
{
    public const ALIASES = '#__mertools_aliases';
    public const MOVED   = '#__mertools_moved';
    public const LOG     = '#__mertools_notfound';

    /** what MerTools did with a missing address: sent to the page that moved, to a typed target, to the general target, nothing */
    public const RESULTS = ['moved', 'manual', 'fallback', 'none'];

    /** the log never grows past this many addresses (those with a typed redirect are always kept) */
    public const MAX_LOG = 10000;

    public function __construct(private DatabaseInterface $db)
    {
    }

    // ---------------------------------------------------------------- helpers (no database)

    /**
     * The alias a missing address asks for: its last part (decoded, without the .html of Joomla's
     * "Add suffix to URL"), or null for the home page, index.php and parts no alias can be.
     *
     * @param string $path   the request path ("/oferta/mierniki/sonel-mpi-540")
     * @param string $base   the site's path (Uri::root(true): "" or "/joomla")
     * @param bool   $suffix Joomla's sef_suffix
     */
    public static function slug(string $path, string $base = '', bool $suffix = false): ?string
    {
        $path = '/' . ltrim($path, '/');
        $base = rtrim($base, '/');
        if ($base !== '' && str_starts_with(strtolower($path), strtolower($base) . '/')) {
            $path = substr($path, \strlen($base));
        }
        $parts = array_values(array_filter(explode('/', $path), static fn ($p) => $p !== ''));
        if (!$parts) {
            return null;
        }
        $slug = rawurldecode((string) end($parts));
        if ($suffix || str_ends_with(strtolower($slug), '.html')) {
            $slug = preg_replace('/\.html?$/i', '', $slug) ?? $slug;
        }
        if (preg_match('/[\x00-\x1F\x7F\/\\\\]/', $slug)) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '' || \strlen($slug) > 255 || strtolower($slug) === 'index.php') {
            return null;
        }

        return $slug;
    }

    /**
     * The address kept in the log: the path, and with index.php (or the bare root) also the query,
     * which then is what tells pages apart. At most 1024 characters.
     */
    public static function logPath(string $path, string $query = ''): string
    {
        $path = '/' . ltrim($path, '/');
        if ($query !== '' && preg_match('#(?:^/|/index\.php)$#i', $path)) {
            $path .= '?' . $query;
        }

        return mb_substr($path, 0, 1024);
    }

    /** The key of an address in the log. */
    public static function key(string $path): string
    {
        return sha1($path);
    }

    /**
     * Of the addresses a missing one may go to, the one sharing the longest beginning with it (the
     * same app, the same category), the first one on a tie; never the missing address itself.
     *
     * @param string[] $urls        absolute or root-relative addresses, best kind first
     * @param string   $requestPath the missing path
     */
    public static function best(array $urls, string $requestPath): ?string
    {
        $want  = self::segments($requestPath);
        $best  = null;
        $score = -1;
        foreach ($urls as $url) {
            $path = (string) parse_url((string) $url, PHP_URL_PATH);
            $have = self::segments($path);
            if ($have === $want) {
                continue;
            }
            $same = 0;
            while (isset($want[$same], $have[$same]) && $want[$same] === $have[$same]) {
                $same++;
            }
            if ($same > $score) {
                [$best, $score] = [(string) $url, $same];
            }
        }

        return $best;
    }

    /** @return string[] the decoded, lower-case parts of a path */
    private static function segments(string $path): array
    {
        return array_values(array_map(static fn ($p) => mb_strtolower(rawurldecode($p)), array_filter(explode('/', $path), static fn ($p) => $p !== '')));
    }

    /**
     * The alias most like the missing one, as a suggestion for a redirect: of the aliases sharing a word
     * of at least three letters with it, the most alike (the share of letters in common, then the longer
     * common beginning), when at least 60 % of them are the same.
     *
     * @param array<int, array{kind: string, id: int, alias: string}> $aliases
     *
     * @return array{kind: string, id: int, alias: string, score: float}|null
     */
    public static function suggest(string $slug, array $aliases): ?array
    {
        $slug  = mb_strtolower(trim($slug));
        $words = array_filter(preg_split('/[-_.\s]+/u', $slug) ?: [], static fn ($w) => mb_strlen($w) >= 3);
        if ($slug === '' || !$words) {
            return null;
        }
        $best = null;
        foreach ($aliases as $row) {
            $alias = mb_strtolower((string) $row['alias']);
            if ($alias === '' || $alias === $slug) {
                continue;
            }
            $shared = false;
            foreach ($words as $word) {
                if (str_contains($alias, $word)) {
                    $shared = true;
                    break;
                }
            }
            if (!$shared) {
                continue;
            }
            similar_text($slug, $alias, $percent);
            $score  = round($percent / 100, 3);
            $prefix = 0;
            for ($max = min(\strlen($slug), \strlen($alias)); $prefix < $max && $slug[$prefix] === $alias[$prefix]; $prefix++) {
            }
            if ($score >= 0.6 && ($best === null || $score > $best['score'] || ($score === $best['score'] && $prefix > $best['prefix']))) {
                $best = ['kind' => (string) $row['kind'], 'id' => (int) $row['id'], 'alias' => (string) $row['alias'], 'score' => $score, 'prefix' => $prefix];
            }
        }
        if ($best !== null) {
            unset($best['prefix']);
        }

        return $best;
    }

    /**
     * Alias changes in rows of the edit history (#__mertools_history): page, old alias, when.
     *
     * @param array<int, array{page_id: mixed, changes: mixed, created: mixed}> $rows
     *
     * @return array<int, array{id: int, alias: string, changed: string}>
     */
    public static function historyAliases(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) ($row['changes'] ?? ''), true) as $change) {
                if (\is_array($change) && ($change['f'] ?? '') === 'page_alias' && trim((string) ($change['from'] ?? '')) !== '') {
                    $out[] = ['id' => (int) $row['page_id'], 'alias' => mb_substr(trim((string) $change['from']), 0, 255), 'changed' => (string) $row['created']];
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- tables

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db   = $this->db;
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::ALIASES) . ' (`kind` CHAR(1) NOT NULL, `item_id` INT UNSIGNED NOT NULL,'
            . ' `alias` VARCHAR(255) NOT NULL, PRIMARY KEY (`kind`, `item_id`), KEY `idx_alias` (`alias`))' . $tail)->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::MOVED) . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `kind` CHAR(1) NOT NULL,'
            . ' `item_id` INT UNSIGNED NOT NULL, `alias` VARCHAR(255) NOT NULL, `changed` DATETIME NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `last_hit` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_alias` (`alias`), KEY `idx_item` (`kind`, `item_id`))' . $tail)->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::LOG) . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `hash` CHAR(40) NOT NULL,'
            . ' `path` VARCHAR(1024) NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 1, `first_seen` DATETIME NOT NULL, `last_seen` DATETIME NOT NULL,'
            . ' `referer` VARCHAR(512) NOT NULL DEFAULT \'\', `result` VARCHAR(8) NOT NULL DEFAULT \'\', `result_url` VARCHAR(1024) NOT NULL DEFAULT \'\','
            . ' `target` VARCHAR(1024) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_hash` (`hash`), KEY `idx_last` (`last_seen`))' . $tail)->execute();
    }

    // ---------------------------------------------------------------- moved pages

    /**
     * The aliases of today compared with the kept ones: every one that changed is noted as moved. The
     * first run keeps them and takes over the alias changes of the edit history.
     *
     * @return array{items: int, moved: int, first: bool, busy?: bool}
     */
    public function sync(string $now): array
    {
        $this->ensureTables();
        $db   = $this->db;
        $lock = $db->quote($db->replacePrefix(self::ALIASES));
        try {
            if (!(int) $db->setQuery('SELECT GET_LOCK(' . $lock . ', 0)')->loadResult()) {
                return ['items' => 0, 'moved' => 0, 'first' => false, 'busy' => true];
            }
        } catch (\Throwable $e) {
            $lock = null;
        }
        try {
            return $this->syncLocked($now);
        } finally {
            if ($lock !== null) {
                $db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->execute();
            }
        }
    }

    private function syncLocked(string $now): array
    {
        $db    = $this->db;
        $known = [];
        foreach ($db->setQuery('SELECT kind, item_id, alias FROM ' . $db->quoteName(self::ALIASES))->loadRowList() ?: [] as [$kind, $id, $alias]) {
            $known[$kind . $id] = (string) $alias;
        }
        $first   = !$known;
        $current = [];
        foreach ($db->setQuery('SELECT id, page_alias FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE page_alias <> \'\'')->loadRowList() ?: [] as [$id, $alias]) {
            $current['p' . $id] = ['p', (int) $id, mb_substr((string) $alias, 0, 255)];
        }
        foreach ($db->setQuery('SELECT id, alias FROM ' . $db->quoteName('#__gridbox_categories') . ' WHERE alias <> \'\'')->loadRowList() ?: [] as [$id, $alias]) {
            $current['c' . $id] = ['c', (int) $id, mb_substr((string) $alias, 0, 255)];
        }
        $write = [];
        $moved = [];
        foreach ($current as $key => [$kind, $id, $alias]) {
            if (!isset($known[$key])) {
                $write[] = [$kind, $id, $alias];
            } elseif ($known[$key] !== $alias) {
                $write[] = [$kind, $id, $alias];
                $moved[] = [$kind, $id, $known[$key], $now];
            }
        }
        foreach (array_chunk($write, 500) as $chunk) {
            $db->setQuery('INSERT INTO ' . $db->quoteName(self::ALIASES) . ' (kind, item_id, alias) VALUES '
                . implode(',', array_map(static fn ($r) => '(' . $db->quote($r[0]) . ',' . (int) $r[1] . ',' . $db->quote($r[2]) . ')', $chunk))
                . ' ON DUPLICATE KEY UPDATE alias = VALUES(alias)')->execute();
        }
        $gone = array_keys(array_diff_key($known, $current));
        foreach (array_chunk($gone, 500) as $chunk) {
            $where = implode(' OR ', array_map(static fn ($k) => '(kind = ' . $db->quote($k[0]) . ' AND item_id = ' . (int) substr($k, 1) . ')', $chunk));
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::ALIASES) . ' WHERE ' . $where)->execute();
        }
        if ($first) {
            $moved = array_merge($moved, $this->historyMoves());
        }
        $this->addMoved($moved);

        return ['items' => \count($current), 'moved' => \count($moved), 'first' => $first];
    }

    /** The alias changes the edit history holds (only pages: it does not follow categories). */
    private function historyMoves(): array
    {
        $db = $this->db;
        try {
            if (!\in_array($db->replacePrefix(History::TABLE), $db->getTableList(), true)) {
                return [];
            }
            $rows = $db->setQuery('SELECT page_id, changes, created FROM ' . $db->quoteName(History::TABLE) . ' WHERE changes LIKE '
                . $db->quote('%"page_alias"%') . ' ORDER BY id')->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn ($r) => ['p', $r['id'], $r['alias'], $r['changed']], self::historyAliases($rows));
    }

    /** Old aliases noted (once each per page); one that is the page's alias again is not kept. */
    private function addMoved(array $moved): void
    {
        if (!$moved) {
            return;
        }
        $db   = $this->db;
        $have = [];
        $ids  = array_values(array_unique(array_map(static fn ($m) => (int) $m[1], $moved)));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->setQuery('SELECT kind, item_id, alias FROM ' . $db->quoteName(self::MOVED) . ' WHERE item_id IN (' . implode(',', $chunk) . ')')->loadRowList() ?: [] as [$k, $i, $a]) {
                $have[$k . $i . '|' . mb_strtolower((string) $a)] = true;
            }
        }
        $rows = [];
        foreach ($moved as [$kind, $id, $alias, $changed]) {
            $key = $kind . $id . '|' . mb_strtolower((string) $alias);
            if ($alias === '' || isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $rows[]     = '(' . $db->quote($kind) . ',' . (int) $id . ',' . $db->quote($alias) . ',' . $db->quote($changed) . ')';
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->setQuery('INSERT INTO ' . $db->quoteName(self::MOVED) . ' (kind, item_id, alias, changed) VALUES ' . implode(',', $chunk))->execute();
        }
        // an alias given back to its page: the page answers it itself
        $db->setQuery('DELETE m FROM ' . $db->quoteName(self::MOVED) . ' m JOIN ' . $db->quoteName(self::ALIASES)
            . ' a ON a.kind = m.kind AND a.item_id = m.item_id AND a.alias = m.alias')->execute();
    }

    /**
     * Pages and categories an alias belongs to: today's first, then those that had it before (the
     * latest change first).
     *
     * @return array<int, array{kind: string, id: int, moved: int}>  moved = the row of #__mertools_moved, 0 for today's alias
     */
    public function candidates(string $slug): array
    {
        $this->ensureTables();
        $db  = $this->db;
        $out = [];
        foreach ($db->setQuery('SELECT kind, item_id FROM ' . $db->quoteName(self::ALIASES) . ' WHERE alias = ' . $db->quote($slug), 0, 20)->loadRowList() ?: [] as [$kind, $id]) {
            $out[] = ['kind' => (string) $kind, 'id' => (int) $id, 'moved' => 0];
        }
        foreach ($db->setQuery('SELECT id, kind, item_id FROM ' . $db->quoteName(self::MOVED) . ' WHERE alias = ' . $db->quote($slug)
            . ' ORDER BY changed DESC, id DESC', 0, 20)->loadRowList() ?: [] as [$row, $kind, $id]) {
            $out[] = ['kind' => (string) $kind, 'id' => (int) $id, 'moved' => (int) $row];
        }

        return $out;
    }

    /**
     * Of the pages named, those a guest may see now, with what their address is made of: the app,
     * its type ("single" for pages outside apps) and the category.
     *
     * @param int[] $ids
     * @param int[] $levels the guest's access levels
     *
     * @return array<int, array{app: int, type: string, category: int}>
     */
    public function livePages(array $ids, array $levels): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $db     = $this->db;
        $levels = implode(',', array_map('intval', $levels ?: [1]));
        $rows   = $db->setQuery('SELECT p.id, p.app_id, p.page_category, a.type FROM ' . $db->quoteName('#__gridbox_pages') . ' p LEFT JOIN '
            . $db->quoteName('#__gridbox_app') . ' a ON a.id = p.app_id WHERE p.id IN (' . implode(',', $ids) . ') AND p.published = 1'
            . ' AND p.page_category <> ' . $db->quote('trashed') . ' AND p.page_access IN (' . $levels . ')'
            . ' AND (p.app_id = 0 OR (a.published = 1 AND a.access IN (' . $levels . ') AND a.type NOT IN (' . $db->quote('system_apps') . ')))')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $type = (int) $row['app_id'] === 0 ? 'single' : (string) $row['type'];
            $out[(int) $row['id']] = ['app' => (int) $row['app_id'], 'type' => $type, 'category' => (int) $row['page_category']];
        }

        return $out;
    }

    /**
     * Of the categories named, those a guest may see now, with their app.
     *
     * @param int[] $ids
     * @param int[] $levels
     *
     * @return array<int, array{app: int}>
     */
    public function liveCategories(array $ids, array $levels): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $db     = $this->db;
        $levels = implode(',', array_map('intval', $levels ?: [1]));
        $rows   = $db->setQuery('SELECT c.id, c.app_id FROM ' . $db->quoteName('#__gridbox_categories') . ' c JOIN ' . $db->quoteName('#__gridbox_app')
            . ' a ON a.id = c.app_id WHERE c.id IN (' . implode(',', $ids) . ') AND c.published = 1 AND c.access IN (' . $levels . ')'
            . ' AND a.published = 1 AND a.access IN (' . $levels . ')')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = ['app' => (int) $row['app_id']];
        }

        return $out;
    }

    public function hitMoved(int $id, string $now): void
    {
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName(self::MOVED) . ' SET hits = hits + 1, last_hit = ' . $db->quote($now) . ' WHERE id = ' . $id)->execute();
    }

    /**
     * The old aliases for the panel, newest first, with the title and the alias of the page or category today.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function movedList(int $limit, int $offset): array
    {
        $this->ensureTables();
        $db    = $this->db;
        $total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::MOVED))->loadResult();
        $rows  = $db->setQuery('SELECT m.id, m.kind, m.item_id, m.alias, m.changed, m.hits, m.last_hit, COALESCE(p.title, c.title) AS title,'
            . ' COALESCE(p.page_alias, c.alias) AS now_alias FROM ' . $db->quoteName(self::MOVED) . ' m'
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_pages') . ' p ON m.kind = ' . $db->quote('p') . ' AND p.id = m.item_id'
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_categories') . ' c ON m.kind = ' . $db->quote('c') . ' AND c.id = m.item_id'
            . ' ORDER BY m.changed DESC, m.id DESC', $offset, $limit)->loadAssocList() ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    public function deleteMoved(int $id): void
    {
        $this->ensureTables();
        $db = $this->db;
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::MOVED) . ' WHERE id = ' . $id)->execute();
    }

    /** @return array<int, array{kind: string, id: int, alias: string}> every alias of today, for the suggestions */
    public function aliases(): array
    {
        $this->ensureTables();
        $db  = $this->db;
        $out = [];
        foreach ($db->setQuery('SELECT kind, item_id, alias FROM ' . $db->quoteName(self::ALIASES))->loadRowList() ?: [] as [$kind, $id, $alias]) {
            $out[] = ['kind' => (string) $kind, 'id' => (int) $id, 'alias' => (string) $alias];
        }

        return $out;
    }

    // ---------------------------------------------------------------- log of missing addresses

    /** The redirect typed for a logged address, or null. */
    public function manualTarget(string $path): ?string
    {
        $this->ensureTables();
        $db     = $this->db;
        $target = $db->setQuery('SELECT target FROM ' . $db->quoteName(self::LOG) . ' WHERE hash = ' . $db->quote(self::key($path)))->loadResult();

        return $target === null || trim((string) $target) === '' ? null : trim((string) $target);
    }

    /** A visit of a missing address: counted, with where it came from and what was done with it. */
    public function record(string $path, string $referer, string $result, string $resultUrl, string $now): void
    {
        $this->ensureTables();
        $db      = $this->db;
        $referer = mb_substr(trim($referer), 0, 512);
        $db->setQuery('INSERT INTO ' . $db->quoteName(self::LOG) . ' (hash, path, hits, first_seen, last_seen, referer, result, result_url) VALUES ('
            . $db->quote(self::key($path)) . ',' . $db->quote($path) . ',1,' . $db->quote($now) . ',' . $db->quote($now) . ',' . $db->quote($referer) . ','
            . $db->quote($result) . ',' . $db->quote(mb_substr($resultUrl, 0, 1024)) . ') ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen),'
            . ' referer = IF(VALUES(referer) <> \'\', VALUES(referer), referer), result = VALUES(result), result_url = VALUES(result_url)')->execute();
    }

    /**
     * The log for the panel.
     *
     * @param string $show all | open (no redirect: the error page or the general target) | redirected (typed or moved)
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function logList(string $show, string $q, string $order, int $limit, int $offset): array
    {
        $this->ensureTables();
        $db    = $this->db;
        $where = [];
        if ($show === 'open') {
            $where[] = '(target IS NULL OR target = \'\') AND result IN (' . $db->quote('fallback') . ',' . $db->quote('none') . ')';
        } elseif ($show === 'redirected') {
            $where[] = '((target IS NOT NULL AND target <> \'\') OR result = ' . $db->quote('moved') . ')';
        }
        if ($q !== '') {
            $like    = $db->quote('%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%');
            $where[] = '(path LIKE ' . $like . ' OR referer LIKE ' . $like . ' OR target LIKE ' . $like . ')';
        }
        $sql   = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::LOG) . $sql)->loadResult();
        $by    = $order === 'hits' ? 'hits DESC, last_seen DESC' : 'last_seen DESC, id DESC';
        $rows  = $db->setQuery('SELECT id, path, hits, first_seen, last_seen, referer, result, result_url, target FROM ' . $db->quoteName(self::LOG) . $sql
            . ' ORDER BY ' . $by, $offset, $limit)->loadAssocList() ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{path: string, target: ?string}|null */
    public function logRow(int $id): ?array
    {
        $this->ensureTables();
        $db  = $this->db;
        $row = $db->setQuery('SELECT path, target FROM ' . $db->quoteName(self::LOG) . ' WHERE id = ' . $id)->loadAssoc();

        return $row ? ['path' => (string) $row['path'], 'target' => $row['target'] === null ? null : (string) $row['target']] : null;
    }

    /** A redirect typed for an address of the log ('' or null: none). */
    public function setTarget(int $id, ?string $target): void
    {
        $this->ensureTables();
        $db     = $this->db;
        $target = $target === null || trim($target) === '' ? null : mb_substr(trim($target), 0, 1024);
        $db->setQuery('UPDATE ' . $db->quoteName(self::LOG) . ' SET target = ' . ($target === null ? 'NULL' : $db->quote($target)) . ' WHERE id = ' . $id)->execute();
    }

    public function deleteLog(int $id): void
    {
        $this->ensureTables();
        $db = $this->db;
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::LOG) . ' WHERE id = ' . $id)->execute();
    }

    /** Every address without a typed redirect goes from the log; returns how many. */
    public function clearLog(): int
    {
        $this->ensureTables();
        $db = $this->db;
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::LOG) . ' WHERE target IS NULL OR target = \'\'')->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * Addresses not visited for the given days go (0 = kept), and above MAX_LOG the least recently
     * visited; those with a typed redirect stay.
     */
    public function prune(int $days, string $now): void
    {
        $this->ensureTables();
        $db   = $this->db;
        $open = '(target IS NULL OR target = \'\')';
        if ($days > 0) {
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::LOG) . ' WHERE ' . $open . ' AND last_seen < '
                . $db->quote(gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - $days * 86400)))->execute();
        }
        $count = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::LOG) . ' WHERE ' . $open)->loadResult();
        if ($count > self::MAX_LOG) {
            $edge = $db->setQuery('SELECT last_seen FROM ' . $db->quoteName(self::LOG) . ' WHERE ' . $open . ' ORDER BY last_seen DESC', self::MAX_LOG, 1)->loadResult();
            if ($edge) {
                $db->setQuery('DELETE FROM ' . $db->quoteName(self::LOG) . ' WHERE ' . $open . ' AND last_seen <= ' . $db->quote((string) $edge))->execute();
            }
        }
    }
}
