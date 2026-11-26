<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The state of the site's tables that audits (mySites.guru, Joomla's own checks) report, and their repair:
 *  - the character set: tables not in utf8mb4_unicode_ci, Joomla's own (utf8/utf8mb3 cannot store emoji and some
 *    scripts, and two collations in one query stop it with "Illegal mix of collations"). Converted only from utf8,
 *    utf8mb3 or another utf8mb4 collation: utf8mb4 holds every character they do, so nothing is lost, and InnoDB's
 *    ALTER either converts the whole table or leaves it as it was. latin1 and the like are shown, not converted (the
 *    bytes in them may be UTF-8 stored as latin1, which a conversion would garble);
 *  - asset rows (permissions) of items that no longer exist: modules, articles, categories of Joomla, and Gridbox's
 *    pages, categories and apps (Gridbox deletes them without their assets). Deleted through Joomla's asset table,
 *    so the tree (lft/rgt) stays right; the rows under a deleted one move up to its parent;
 *  - the size of the tables and the free space InnoDB keeps after deletes, given back by OPTIMIZE TABLE.
 * Only the tables of the site's prefix. SHOW TABLE STATUS, no information_schema (often refused on hosting).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Asset;
use Joomla\Database\DatabaseInterface;

final class DbHealth
{
    public const TARGET = 'utf8mb4_unicode_ci';

    /** the character sets a table is converted from without a loss */
    public const SAFE = ['utf8', 'utf8mb3', 'utf8mb4'];

    /** the assets of items with a table of their own: kind => [the start of the asset's name, the items' table] */
    public const ASSETS = [
        'modules'            => ['com_modules.module.', '#__modules'],
        'articles'           => ['com_content.article.', '#__content'],
        'gridbox_pages'      => ['com_gridbox.page.', '#__gridbox_pages'],
        'gridbox_categories' => ['com_gridbox.category.', '#__gridbox_categories'],
        'gridbox_apps'       => ['com_gridbox.app.', '#__gridbox_app'],
    ];

    /** the components whose categories are Joomla's (#__categories) whether or not they have one now */
    public const CORE_CATEGORIES = ['com_content', 'com_contact', 'com_banners', 'com_newsfeeds', 'com_users'];

    /** a table with this much free space (bytes) is worth an OPTIMIZE */
    public const FREE_WORTH = 1048576;

    public function __construct(private DatabaseInterface $db)
    {
    }

    // ---------------------------------------------------------------- pure helpers (unit tested)

    /** the character set of a collation: "utf8mb3_general_ci" => "utf8mb3" */
    public static function charset(?string $collation): string
    {
        return strtolower(strstr((string) $collation, '_', true) ?: (string) $collation);
    }

    /** what a table of this collation needs: 'ok', 'convert' (without a loss), 'manual' (another character set), 'none' (a view) */
    public static function verdict(?string $collation): string
    {
        if ($collation === null || $collation === '') {
            return 'none';
        }
        if (strcasecmp($collation, self::TARGET) === 0) {
            return 'ok';
        }
        // a binary or case-sensitive one is chosen on purpose (tokens, hashes: "aB" is not "ab"); in utf8mb4 it holds
        // every character, in utf8mb3 it needs utf8mb4's of its kind, not Joomla's
        if (preg_match('/_(bin|cs)$/i', $collation)) {
            return self::charset($collation) === 'utf8mb4' ? 'ok' : 'manual';
        }

        return \in_array(self::charset($collation), self::SAFE, true) ? 'convert' : 'manual';
    }

    // ---------------------------------------------------------------- the tables

    /**
     * The site's tables: name => [engine, collation, rows (estimate), data, index, free bytes].
     *
     * @return array<string, array{engine: string, collation: string, rows: int, data: int, index: int, free: int}>
     */
    public function tables(): array
    {
        $prefix = $this->db->getPrefix();
        $rows   = $this->db->setQuery('SHOW TABLE STATUS LIKE ' . $this->db->quote(str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%'))->loadAssocList();
        $out    = [];
        foreach ($rows as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $out[(string) $r['name']] = ['engine' => (string) ($r['engine'] ?? ''), 'collation' => (string) ($r['collation'] ?? ''), 'rows' => (int) ($r['rows'] ?? 0),
                'data' => (int) ($r['data_length'] ?? 0), 'index' => (int) ($r['index_length'] ?? 0), 'free' => (int) ($r['data_free'] ?? 0)];
        }
        ksort($out);

        return $out;
    }

    /**
     * The columns a conversion would change against their purpose: CONVERT TO gives every text column the new
     * collation, also one set to a binary or case-sensitive one (a token: "AbC" would equal "abc") or kept in another
     * character set. name => collation
     */
    public function protectedColumns(string $table): array
    {
        $out = [];
        foreach ($this->db->setQuery('SHOW FULL COLUMNS FROM ' . $this->db->quoteName($table))->loadAssocList() as $c) {
            $c         = array_change_key_case($c, CASE_LOWER);
            $collation = (string) ($c['collation'] ?? '');
            if ($collation !== '' && (preg_match('/_(bin|cs)$/i', $collation) || !\in_array(self::charset($collation), self::SAFE, true))) {
                $out[(string) $c['field']] = $collation;
            }
        }

        return $out;
    }

    /** the tables not in utf8mb4_unicode_ci (views left out): [table, collation, size, verdict, the protected columns] */
    public function collations(?array $tables = null): array
    {
        $out = [];
        foreach ($tables ?? $this->tables() as $name => $t) {
            $v = self::verdict($t['collation']);
            if ($v === 'convert' || $v === 'manual') {
                $keep = $v === 'convert' ? $this->protectedColumns($name) : [];
                $out[] = ['table' => $name, 'collation' => $t['collation'], 'size' => $t['data'] + $t['index'], 'verdict' => $keep ? 'manual' : $v,
                    'columns' => array_map(static fn ($c, $k) => $k . ' ' . $c, $keep, array_keys($keep))];
            }
        }

        return $out;
    }

    /** One table to utf8mb4_unicode_ci; null when done, else why not. Only a table of the site that may be converted. */
    public function convert(string $table): ?string
    {
        $tables = $this->tables();
        if (!isset($tables[$table])) {
            return 'unknown table';
        }
        if (self::verdict($tables[$table]['collation']) !== 'convert') {
            return 'not converted: ' . $tables[$table]['collation'];
        }
        if ($keep = $this->protectedColumns($table)) {
            return 'not converted, columns of their own collation: ' . implode(', ', array_map(static fn ($c, $k) => $k . ' ' . $c, $keep, array_keys($keep)));
        }
        try {
            $this->db->setQuery('ALTER TABLE ' . $this->db->quoteName($table) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . self::TARGET)->execute();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** MerTools' own tables (#__mertools_…) in utf8mb4_unicode_ci: those made before 1.20.0 took the server's default */
    public function ownTables(): array
    {
        $done = [];
        foreach ($this->tables() as $name => $t) {
            if (str_starts_with($name, $this->db->getPrefix() . 'mertools_') && self::verdict($t['collation']) === 'convert' && $this->convert($name) === null) {
                $done[] = $name;
            }
        }

        return $done;
    }

    /** OPTIMIZE TABLE of one table of the site: [ok, the database's message, free bytes before, after] */
    public function optimize(string $table): array
    {
        $tables = $this->tables();
        if (!isset($tables[$table])) {
            return ['ok' => false, 'message' => 'unknown table', 'before' => 0, 'after' => 0];
        }
        try {
            $rows = $this->db->setQuery('OPTIMIZE TABLE ' . $this->db->quoteName($table))->loadAssocList();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'before' => $tables[$table]['free'], 'after' => $tables[$table]['free']];
        }
        // InnoDB answers a note "doing recreate + analyze instead", then status OK
        $last  = array_change_key_case((array) end($rows), CASE_LOWER);
        $ok    = strtolower((string) ($last['msg_type'] ?? '')) === 'status' && strtoupper((string) ($last['msg_text'] ?? '')) === 'OK';
        $after = $this->tables()[$table]['free'] ?? 0;

        return ['ok' => $ok, 'message' => (string) ($last['msg_text'] ?? ''), 'before' => $tables[$table]['free'], 'after' => $after];
    }

    // ---------------------------------------------------------------- the assets

    /** @return array<string, string> kind => the WHERE of its leftover assets (alias a) */
    private function orphanWhere(): array
    {
        $db     = $this->db;
        $tables = array_map('strtolower', array_keys($this->tables()));
        $where  = [];
        foreach (self::ASSETS as $kind => [$start, $table]) {
            if (!\in_array(strtolower(str_replace('#__', $db->getPrefix(), $table)), $tables, true)) {
                continue; // no such component: its assets are not judged
            }
            // the id read from the name, so the item is found by its primary key; only names that end in a number
            $where[$kind] = 'a.name LIKE ' . $db->quote(str_replace('_', '\\_', $start) . '%') . ' AND a.name REGEXP ' . $db->quote('^' . preg_quote($start) . '[0-9]+$')
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName($table) . ' t WHERE t.id = CAST(SUBSTRING(a.name, ' . (\strlen($start) + 1) . ') AS UNSIGNED))';
        }
        // Joomla's categories: of the core components, and of any that keeps its categories there
        $users = array_unique(array_merge(self::CORE_CATEGORIES,
            $db->setQuery('SELECT DISTINCT extension FROM ' . $db->quoteName('#__categories'))->loadColumn()));
        $users = array_values(array_filter($users, static fn ($e) => \is_string($e) && preg_match('/^[a-z0-9_]+$/', $e) && $e !== 'com_gridbox' && $e !== 'system'));
        if ($users) {
            $ors = array_map(static fn (string $e): string => 'a.name LIKE ' . $db->quote(str_replace('_', '\\_', $e) . '.category.%'), $users);
            $where['categories'] = '(' . implode(' OR ', $ors) . ') AND a.name REGEXP ' . $db->quote('^[a-z0-9_]+\\.category\\.[0-9]+$')
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories') . ' t WHERE t.id = CAST(SUBSTRING_INDEX(a.name, ' . $db->quote('.') . ', -1) AS UNSIGNED)'
                . ' AND a.name = CONCAT(t.extension, ' . $db->quote('.category.') . ', t.id))';
        }

        return $where;
    }

    /** kind => [count, up to 5 titles of the rows] */
    public function orphans(): array
    {
        $out = [];
        foreach ($this->orphanWhere() as $kind => $where) {
            $count = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__assets') . ' a WHERE ' . $where)->loadResult();
            if ($count) {
                $titles = $this->db->setQuery('SELECT CONCAT(a.title, ' . $this->db->quote(' (') . ', a.name, ' . $this->db->quote(')') . ') FROM '
                    . $this->db->quoteName('#__assets') . ' a WHERE ' . $where . ' ORDER BY a.id', 0, 5)->loadColumn();
                $out[$kind] = ['count' => $count, 'titles' => $titles];
            }
        }

        return $out;
    }

    /**
     * Up to $limit leftover assets deleted, one by one through Joomla's asset table (the tree kept right, rows under
     * one moved up to its parent). [deleted, left, failed messages]
     */
    public function deleteOrphans(int $limit = 200): array
    {
        $ids = [];
        foreach ($this->orphanWhere() as $where) {
            $ids = array_merge($ids, array_map('intval', $this->db->setQuery('SELECT a.id FROM ' . $this->db->quoteName('#__assets') . ' a WHERE ' . $where
                . ' ORDER BY a.lft DESC', 0, $limit)->loadColumn()));
        }
        $ids     = \array_slice(array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 1))), 0, $limit);
        $deleted = 0;
        $failed  = [];
        foreach ($ids as $id) {
            try {
                $table = new Asset($this->db);
                if ($table->delete($id, false)) {
                    $deleted++;
                } else {
                    $failed[] = $id . (method_exists($table, 'getError') ? ': ' . $table->getError() : '');
                }
            } catch (\Throwable $e) {
                $failed[] = $id . ': ' . $e->getMessage();
            }
        }
        $left = array_sum(array_column($this->orphans(), 'count'));

        return ['deleted' => $deleted, 'left' => $left, 'failed' => $failed];
    }
}
