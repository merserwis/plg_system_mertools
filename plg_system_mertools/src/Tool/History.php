<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Edit history of Gridbox pages (articles, products, pages): who changed what and when. Gridbox keeps
 * neither (its page versions have no author or date), and it does not write to Joomla's User Actions
 * Log, so the plugin watches the requests that change pages: a save in the Gridbox editor and the
 * actions of the Gridbox lists (publish, trash, restore, delete, duplicate, move, featured).
 *
 * Before such a request the state of the pages it names is read; after it (at shutdown: Gridbox ends
 * its requests with exit) it is read again and only what really changed is kept, in
 * #__mertools_history: one row per page and request, the changed fields with the old and the new
 * value (content fields only as "changed"). Nothing is kept for a request that changed nothing,
 * except a save in the editor (somebody saved the page).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class History
{
    public const TABLE = '#__mertools_history';

    /** fields of #__gridbox_pages kept with their values */
    public const FIELDS = ['title', 'published', 'page_category', 'page_alias', 'app_id', 'featured', 'created', 'end_publishing', 'page_access',
        'language', 'meta_title', 'intro_image', 'theme', 'robots'];

    /** fields of #__gridbox_pages compared by a checksum only (long texts): name => key in the snapshot */
    public const CONTENT = ['params' => 'content', 'style' => 'content', 'intro_text' => 'intro_text', 'meta_description' => 'meta_description',
        'meta_keywords' => 'meta_keywords', 'schema_markup' => 'schema_markup'];

    /** fields of #__gridbox_store_product_data kept with their values (prefix product_) */
    public const PRODUCT = ['price', 'sale_price', 'sku', 'stock', 'min'];

    /** fields of the product compared by a checksum only */
    public const PRODUCT_CONTENT = ['variations', 'extra_options', 'dimensions'];

    /** Gridbox tasks (controller.task, lower case) that change pages, the editor's save and the list actions */
    public const TASKS = ['editor.gridboxajaxsave', 'editor.gridboxsave', 'editor.duplicate',
        'apps.publish', 'apps.unpublish', 'apps.addtrash', 'apps.contexttrash', 'apps.duplicate', 'apps.contextduplicate', 'apps.setfeatured',
        'apps.moveto', 'apps.pagemoveto',
        'pages.publish', 'pages.unpublish', 'pages.addtrash', 'pages.contexttrash', 'pages.duplicate', 'pages.contextduplicate', 'pages.setfeatured',
        'pages.moveto', 'pages.pagemoveto',
        'trashed.delete', 'trashed.contextdelete', 'trashed.restoresingle', 'trashed.restore',
        // the Settings window of a page in the Gridbox lists
        'gridbox.updateparams',
        // a new page: the window "Create" of the editor opened without a page (the button of a list)
        'gridbox.createpage'];

    public const ACTIONS = ['created', 'duplicated', 'edited', 'saved', 'published', 'unpublished', 'trashed', 'restored', 'deleted'];

    /** a request names at most this many pages (a list action on a whole page of the list) */
    private const MAX_IDS = 500;

    public function __construct(private DatabaseInterface $db)
    {
    }

    public static function tracked(string $task): bool
    {
        return \in_array(strtolower($task), self::TASKS, true);
    }

    /** The task creates pages (their numbers are not in the request): the new rows are looked for after it. */
    public static function creates(string $task): bool
    {
        return (bool) preg_match('/\.(?:(?:context)?duplicate|createpage)$/', strtolower($task));
    }

    /** The task copies pages (the new rows are "duplicated", else "created"). */
    public static function duplicates(string $task): bool
    {
        return (bool) preg_match('/\.(?:context)?duplicate$/', strtolower($task));
    }

    /**
     * The pages a request names: the page of the editor's save (obj.page.id, not for layouts, headers
     * or system pages - edit_type), "id", "context-item" and the ticked rows "cid[]".
     *
     * @param array<string, mixed> $request id, context-item, cid, obj (raw JSON)
     *
     * @return int[]
     */
    public static function requestIds(array $request): array
    {
        $ids = [];
        $obj = isset($request['obj']) && \is_string($request['obj']) && $request['obj'] !== '' ? json_decode($request['obj']) : null;
        if (\is_object($obj)) {
            if (isset($obj->edit_type) && $obj->edit_type !== '' && $obj->edit_type !== null) {
                return [];
            }
            if (isset($obj->page->id)) {
                $ids[] = (int) $obj->page->id;
            }
        }
        foreach (['id', 'context-item', 'ba_id'] as $key) {
            if (isset($request[$key]) && is_scalar($request[$key])) {
                $ids[] = (int) $request[$key];
            }
        }
        foreach ((array) ($request['cid'] ?? []) as $id) {
            if (is_scalar($id)) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));

        return \array_slice($ids, 0, self::MAX_IDS);
    }

    /**
     * What changed between two states of a page (null = the page does not exist).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return array{action: string, changes: array<int, array<string, mixed>>}|null  null = nothing changed
     */
    public static function diff(?array $before, ?array $after, bool $editorSave = false): ?array
    {
        if ($before === null && $after === null) {
            return null;
        }
        if ($before === null) {
            return ['action' => 'created', 'changes' => []];
        }
        if ($after === null) {
            return ['action' => 'deleted', 'changes' => []];
        }

        $changes = [];
        foreach ($after as $key => $value) {
            if ($key === 'saved_time' || $key === 'id' || !\array_key_exists($key, $before)) {
                continue;
            }
            $old = $before[$key];
            if ((string) $old === (string) $value) {
                continue;
            }
            if (str_starts_with($key, 'crc_')) {
                $changes[] = ['f' => substr($key, 4)];
            } else {
                $changes[] = ['f' => $key, 'from' => self::short($old), 'to' => self::short($value)];
            }
        }
        // one entry per content field (params and style are both "content")
        $changes = array_values(array_intersect_key($changes, array_unique(array_map(static fn ($c) => $c['f'], $changes))));

        if (!$changes) {
            return $editorSave ? ['action' => 'saved', 'changes' => []] : null;
        }
        $fields = array_column($changes, 'f');
        $action = 'edited';
        if (\in_array('page_category', $fields, true)) {
            if ((string) $after['page_category'] === 'trashed') {
                $action = 'trashed';
            } elseif ((string) $before['page_category'] === 'trashed') {
                $action = 'restored';
            }
        }
        if ($action === 'edited' && $fields === ['published']) {
            $action = (int) $after['published'] === 1 ? 'published' : 'unpublished';
        }

        return ['action' => $action, 'changes' => $changes];
    }

    private static function short($value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return mb_strlen($value) > 200 ? mb_substr($value, 0, 199) . '…' : $value;
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `page_id` INT UNSIGNED NOT NULL, `app_id` INT NOT NULL DEFAULT 0, `title` VARCHAR(255) NOT NULL DEFAULT \'\','
            . ' `user_id` INT NOT NULL DEFAULT 0, `user_name` VARCHAR(255) NOT NULL DEFAULT \'\', `action` VARCHAR(20) NOT NULL,'
            . ' `changes` TEXT NULL, `client` VARCHAR(16) NOT NULL DEFAULT \'\', `created` DATETIME NOT NULL, PRIMARY KEY (`id`),'
            . ' KEY `idx_page` (`page_id`, `created`), KEY `idx_created` (`created`), KEY `idx_user` (`user_id`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
    }

    /**
     * The state of pages: the kept fields, the checksums of the long texts and the product data.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $db     = $this->db;
        $select = ['p.id', 'p.saved_time'];
        foreach (self::FIELDS as $field) {
            $select[] = 'p.' . $db->quoteName($field);
        }
        foreach (array_unique(array_values(self::CONTENT)) as $key) {
            $columns  = array_keys(array_filter(self::CONTENT, static fn ($k) => $k === $key));
            $select[] = 'CRC32(CONCAT_WS(' . $db->quote("\x1F") . ', ' . implode(', ', array_map(fn ($c) => 'IFNULL(p.' . $db->quoteName($c) . ', \'\')', $columns)) . ')) AS '
                . $db->quoteName('crc_' . $key);
        }
        // the store's tables only where Gridbox has them (a site without the store): the page is kept all the same
        $have    = $this->tables();
        $product = isset($have['#__gridbox_store_product_data']);
        if ($product) {
            foreach (self::PRODUCT as $field) {
                $select[] = 'd.' . $db->quoteName($field) . ' AS ' . $db->quoteName('product_' . $field);
            }
            $select[] = 'CRC32(CONCAT_WS(' . $db->quote("\x1F") . ', ' . implode(', ', array_map(fn ($c) => 'IFNULL(d.' . $db->quoteName($c) . ', \'\')', self::PRODUCT_CONTENT))
                . ')) AS ' . $db->quoteName('crc_product_options');
        }
        // the fields of the app (a product's description, specifications, producer…), the extra
        // categories and the tags: Gridbox keeps them in their own tables
        if (isset($have['#__gridbox_page_fields'])) {
            $select[] = '(SELECT CRC32(GROUP_CONCAT(f.field_id, \':\', CRC32(IFNULL(f.value, \'\')) ORDER BY f.field_id, f.id)) FROM '
                . $db->quoteName('#__gridbox_page_fields') . ' AS f WHERE f.page_id = p.id) AS ' . $db->quoteName('crc_fields');
        }
        if (isset($have['#__gridbox_category_page_map'])) {
            $select[] = '(SELECT CRC32(GROUP_CONCAT(m.category_id ORDER BY m.category_id)) FROM ' . $db->quoteName('#__gridbox_category_page_map')
                . ' AS m WHERE m.page_id = p.id) AS ' . $db->quoteName('crc_categories');
        }
        if (isset($have['#__gridbox_tags_map'])) {
            $select[] = '(SELECT CRC32(GROUP_CONCAT(t.tag_id ORDER BY t.tag_id)) FROM ' . $db->quoteName('#__gridbox_tags_map')
                . ' AS t WHERE t.page_id = p.id) AS ' . $db->quoteName('crc_tags');
        }
        if (isset($have['#__gridbox_store_badges_map'])) {
            $select[] = '(SELECT CRC32(GROUP_CONCAT(b.badge_id ORDER BY b.order_list, b.id)) FROM ' . $db->quoteName('#__gridbox_store_badges_map')
                . ' AS b WHERE b.product_id = p.id) AS ' . $db->quoteName('crc_badges');
        }

        $rows = $db->setQuery('SELECT ' . implode(', ', $select) . ' FROM ' . $db->quoteName('#__gridbox_pages') . ' AS p'
            . ($product ? ' LEFT JOIN ' . $db->quoteName('#__gridbox_store_product_data') . ' AS d ON d.product_id = p.id' : '')
            . ' WHERE p.id IN (' . implode(',', $ids) . ')')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            // a page without product data: no product fields (a NULL would read as a change later)
            if (($row['product_price'] ?? null) === null && ($row['product_sku'] ?? null) === null) {
                foreach (self::PRODUCT as $field) {
                    unset($row['product_' . $field]);
                }
                unset($row['crc_product_options']);
            }
            // several product rows of one page: the first one
            $out[$id] ??= $row;
        }

        return $out;
    }

    /** @return array<string, true> the tables of the snapshot next to the pages that this Gridbox has (read once a request) */
    private function tables(): array
    {
        static $have = null;
        if ($have !== null) {
            return $have;
        }
        $prefix = $this->db->getPrefix();
        $list   = array_flip(array_map('strtolower', (array) $this->db->getTableList()));
        $have   = [];
        foreach (['#__gridbox_store_product_data', '#__gridbox_page_fields', '#__gridbox_category_page_map', '#__gridbox_tags_map', '#__gridbox_store_badges_map'] as $table) {
            if (isset($list[strtolower($prefix . substr($table, 3))])) {
                $have[$table] = true;
            }
        }

        return $have;
    }

    /**
     * Of the pages added while a request ran, the ones that request made: somebody else may add pages at
     * the same moment. A copy has the content and the app of the page it copies, a new page the title,
     * app and category the request asked for. Pages that cannot be told apart from somebody else's are
     * left out (no entry is better than one with the wrong person).
     *
     * @param array<int, array<string, mixed>> $added    snapshots of the pages added (id => row)
     * @param array<int, array<string, mixed>> $sources  snapshots of the pages the request copies (duplicate)
     * @param array{title: string, app_id: int, page_category: string}|null $asked  the page the request makes (create)
     *
     * @return array<int, int>  page id => the page it copies (0: a new page, or not clear which one)
     */
    public static function ownNew(array $added, array $sources, ?array $asked): array
    {
        $out = [];
        if ($asked !== null) {
            $match = array_keys(array_filter($added, static fn (array $row): bool => trim((string) ($row['title'] ?? '')) === trim($asked['title'])
                && (int) ($row['app_id'] ?? 0) === (int) $asked['app_id'] && (string) ($row['page_category'] ?? '') === (string) $asked['page_category']));

            return \count($match) === 1 ? [(int) $match[0] => 0] : [];
        }
        $sign = static fn (array $row): string => (string) ($row['crc_content'] ?? '') . '|' . (int) ($row['app_id'] ?? 0);
        $from = [];
        foreach ($sources as $id => $row) {
            $from[$sign($row)][] = (int) $id;
        }
        $made = [];
        foreach ($added as $id => $row) {
            $made[$sign($row)][] = (int) $id;
        }
        foreach ($made as $key => $ids) {
            // each page is copied once: more copies of it than pages copied = somebody else copied it too
            if (!isset($from[$key]) || \count($ids) > \count($from[$key])) {
                continue;
            }
            foreach ($ids as $id) {
                $out[$id] = \count($from[$key]) === 1 ? $from[$key][0] : 0;
            }
        }
        ksort($out);

        return $out;
    }

    public function maxPageId(): int
    {
        return (int) $this->db->setQuery('SELECT MAX(id) FROM ' . $this->db->quoteName('#__gridbox_pages'))->loadResult();
    }

    /** @return int[] pages added after $max */
    public function newPages(int $max): array
    {
        return array_map('intval', $this->db->setQuery('SELECT id FROM ' . $this->db->quoteName('#__gridbox_pages') . ' WHERE id > ' . (int) $max
            . ' ORDER BY id LIMIT ' . self::MAX_IDS)->loadColumn() ?: []);
    }

    /** @param array<int, array<string, mixed>> $changes */
    public function record(int $pageId, int $appId, string $title, int $userId, string $userName, string $action, array $changes, string $client, string $created): void
    {
        $db = $this->db;
        $db->setQuery('INSERT INTO ' . $db->quoteName(self::TABLE) . ' (page_id, app_id, title, user_id, user_name, action, changes, client, created) VALUES ('
            . $pageId . ', ' . $appId . ', ' . $db->quote(mb_substr($title, 0, 255)) . ', ' . $userId . ', ' . $db->quote(mb_substr($userName, 0, 255)) . ', '
            . $db->quote($action) . ', ' . $db->quote($changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : '') . ', ' . $db->quote($client) . ', '
            . $db->quote($created) . ')')->execute();
    }

    /**
     * The last entry of each page.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $db   = $this->db;
        $t    = $db->quoteName(self::TABLE);
        $rows = $db->setQuery('SELECT h.* FROM ' . $t . ' AS h INNER JOIN (SELECT page_id, MAX(id) AS id FROM ' . $t . ' WHERE page_id IN (' . implode(',', $ids)
            . ') GROUP BY page_id) AS l ON l.id = h.id')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['page_id']] = $row;
        }

        return $out;
    }

    /**
     * Who made each page: its entry "created" or "duplicated" (known), or else its first entry (a page
     * made before the history was kept: only the first person who changed it is known).
     *
     * @param int[] $ids
     *
     * @return array<int, array{user_id: int, user_name: string, created: string, known: bool}>
     */
    public function creators(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $db   = $this->db;
        $t    = $db->quoteName(self::TABLE);
        $rows = $db->setQuery('SELECT h.page_id, h.user_id, h.user_name, h.action, h.created FROM ' . $t . ' AS h INNER JOIN (SELECT page_id,'
            . ' COALESCE(MIN(CASE WHEN action IN (\'created\', \'duplicated\') THEN id END), MIN(id)) AS id FROM ' . $t . ' WHERE page_id IN ('
            . implode(',', $ids) . ') GROUP BY page_id) AS f ON f.id = h.id')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['page_id']] = ['user_id' => (int) $row['user_id'], 'user_name' => (string) $row['user_name'], 'created' => (string) $row['created'],
                'known' => \in_array($row['action'], ['created', 'duplicated'], true)];
        }

        return $out;
    }

    /**
     * Entries, newest first.
     *
     * @param array{page?: int, user?: int, q?: string, from?: string, to?: string, action?: string} $filter  from/to: Y-m-d (UTC, to included)
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function find(array $filter, int $limit, int $offset): array
    {
        $db    = $this->db;
        $where = [];
        if (!empty($filter['page'])) {
            $where[] = 'page_id = ' . (int) $filter['page'];
        }
        if (!empty($filter['user'])) {
            $where[] = 'user_id = ' . (int) $filter['user'];
        }
        if (!empty($filter['action']) && \in_array($filter['action'], self::ACTIONS, true)) {
            $where[] = 'action = ' . $db->quote($filter['action']);
        }
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $where[] = ctype_digit($q) ? '(page_id = ' . (int) $q . ' OR title LIKE ' . $db->quote('%' . $q . '%') . ')'
                : 'title LIKE ' . $db->quote('%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%');
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $day = (string) ($filter[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $where[] = 'created ' . $op . ' ' . $db->quote($key === 'to' ? date('Y-m-d', strtotime($day . ' +1 day')) : $day);
            }
        }
        $sql   = ' FROM ' . $db->quoteName(self::TABLE) . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $db->setQuery('SELECT COUNT(*)' . $sql)->loadResult();
        $rows  = $db->setQuery('SELECT *' . $sql . ' ORDER BY id DESC LIMIT ' . max(0, $offset) . ', ' . max(1, min(200, $limit)))->loadAssocList() ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int, string> users that have entries: id => name */
    public function users(): array
    {
        $out = [];
        foreach ($this->db->setQuery('SELECT user_id, MAX(user_name) FROM ' . $this->db->quoteName(self::TABLE) . ' GROUP BY user_id ORDER BY MAX(user_name)')->loadRowList() ?: [] as [$id, $name]) {
            $out[(int) $id] = (string) $name;
        }

        return $out;
    }

    /** Entries older than $days days go (0 = kept for ever). @return int removed */
    public function prune(int $days, string $now): int
    {
        if ($days <= 0) {
            return 0;
        }
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE created < ' . $this->db->quote(date('Y-m-d H:i:s', strtotime($now) - $days * 86400)))->execute();

        return (int) $this->db->getAffectedRows();
    }
}
