<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Gridbox apps against deletion. Gridbox deletes an app (a shop, a blog) with one click on the X of its
 * card, past its trash and for good: the app, every product or post, its categories, fields, product
 * data, tags, comments and reviews, and the files that go with them. An app can be locked against it,
 * and a copy of the app is written before each deletion that is allowed and once a day or a week.
 *
 * A copy is a gzip file of JSON lines: a header, the rows of Gridbox's tables (as PLAN lists them) and
 * the files Gridbox removes with them, then an end line. It is written and read a line at a time, so a
 * large shop fits the memory. A restore brings back what is missing and changes nothing that exists.
 */
final class AppGuard
{
    public const TABLE = '#__mertools_app_backups';

    public const FORMAT = 'mertools-app-backup';

    /** Gridbox's tasks that delete an app (the X of a card, the trash icon in a folder, the toolbar of a single page app). */
    public const TASKS = ['pages.deleteapp', 'pages.deletegridboxappitem', 'apps.deleteapp'];

    /** a file larger than this is left out of a copy (noted in it) */
    public const FILE_MAX = 20971520;

    /** where Gridbox keeps the files of an app, relative to the site root */
    private const ASSETS = 'components/com_gridbox/assets/';

    private const CHUNK = 50;

    /**
     * What a copy holds, in this order: table => [the column that links a row, the set of ids it is
     * linked to, the set its rows make and from which column]. The first eighteen are what Gridbox
     * deletes with an app; the badges, the related products and the authors stay behind in Gridbox, but
     * belong to the copy of an app all the same.
     */
    public const PLAN = [
        '#__gridbox_app'                          => ['id', 'app', null],
        '#__gridbox_apps_order_map'               => ['item_id', 'app', null],
        '#__gridbox_categories'                   => ['app_id', 'app', null],
        '#__gridbox_fields'                       => ['app_id', 'app', ['fields', 'id']],
        '#__gridbox_fields_data'                  => ['field_id', 'fields', null],
        '#__gridbox_pages'                        => ['app_id', 'app', ['pages', 'id']],
        '#__gridbox_page_fields'                  => ['page_id', 'pages', null],
        '#__gridbox_fields_desktop_files'         => ['page_id', 'pages', null],
        '#__gridbox_category_page_map'            => ['page_id', 'pages', null],
        '#__gridbox_store_product_data'           => ['product_id', 'pages', null],
        '#__gridbox_store_product_variations_map' => ['product_id', 'pages', null],
        '#__gridbox_tags_map'                     => ['page_id', 'pages', ['tags', 'tag_id']],
        '#__gridbox_tags'                         => ['id', 'tags', null],
        '#__gridbox_comments'                     => ['page_id', 'pages', ['comments', 'id']],
        '#__gridbox_comments_attachments'         => ['comment_id', 'comments', null],
        '#__gridbox_comments_likes_map'           => ['comment_id', 'comments', null],
        '#__gridbox_reviews'                      => ['page_id', 'pages', ['reviews', 'id']],
        '#__gridbox_reviews_attachments'          => ['comment_id', 'reviews', null],
        '#__gridbox_reviews_likes_map'            => ['comment_id', 'reviews', null],
        '#__gridbox_store_badges_map'             => ['product_id', 'pages', null],
        '#__gridbox_store_related_products'       => ['product_id', 'pages', null],
        '#__gridbox_authors_map'                  => ['page_id', 'pages', null],
    ];

    /** Rows that come back by their own id, when it is free; the others come back with a page, field or comment that does. */
    private const CORE = ['#__gridbox_app', '#__gridbox_categories', '#__gridbox_fields', '#__gridbox_pages', '#__gridbox_tags'];

    /** Rows other rows point at by id: they come back with their own id or not at all. */
    private const KEEP_ID = ['#__gridbox_comments', '#__gridbox_reviews'];

    public function __construct(private DatabaseInterface $db, private string $root, private string $dir)
    {
        $this->root = rtrim($root, '/');
        $this->dir  = rtrim($dir, '/');
    }

    // ---------------------------------------------------------------- the lock

    public static function isDeleteTask(string $task): bool
    {
        return \in_array(strtolower(trim($task)), self::TASKS, true);
    }

    /** May the app be deleted: always with the protection off, else only an app chosen as one that may be. */
    public static function deletable(int $appId, bool $protect, array $unlocked): bool
    {
        return !$protect || \in_array($appId, array_map('intval', $unlocked), true);
    }

    /** The folder of the copies: the setting (absolute, or relative to the site root), else administrator/backups/mertools. */
    public static function folder(string $setting, string $root, string $admin): string
    {
        $setting = trim(str_replace('\\', '/', $setting));
        if ($setting === '') {
            return rtrim($admin, '/') . '/backups/mertools';
        }
        if (!preg_match('#^(/|[A-Za-z]:/)#', $setting)) {
            $setting = rtrim($root, '/') . '/' . ltrim($setting, './');
        }

        return rtrim($setting, '/');
    }

    // ---------------------------------------------------------------- what is kept

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `app_id` INT NOT NULL, `app_title` VARCHAR(255) NOT NULL DEFAULT \'\', `app_type` VARCHAR(64) NOT NULL DEFAULT \'\','
            . ' `reason` VARCHAR(16) NOT NULL DEFAULT \'\', `file` VARCHAR(190) NOT NULL, `size` BIGINT NOT NULL DEFAULT 0,'
            . ' `pages` INT NOT NULL DEFAULT 0, `items` INT NOT NULL DEFAULT 0, `files` INT NOT NULL DEFAULT 0, `skipped` INT NOT NULL DEFAULT 0,'
            . ' `hash` CHAR(40) NOT NULL DEFAULT \'\', `user_id` INT NOT NULL DEFAULT 0, `user_name` VARCHAR(255) NOT NULL DEFAULT \'\','
            . ' `created` DATETIME NOT NULL, `checked` DATETIME NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_file` (`file`),'
            . ' KEY `idx_app` (`app_id`, `created`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
    }

    /** @return array<int, array{id:int, title:string, type:string, pages:int}> the apps of the Gridbox dashboard */
    public function apps(): array
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select('a.' . $db->quoteName('id') . ', a.' . $db->quoteName('title') . ', a.' . $db->quoteName('type')
                . ', (SELECT COUNT(*) FROM ' . $db->quoteName('#__gridbox_pages') . ' p WHERE p.' . $db->quoteName('app_id') . ' = a.' . $db->quoteName('id') . ') AS ' . $db->quoteName('pages'))
            ->from($db->quoteName('#__gridbox_app', 'a'))
            ->order('a.' . $db->quoteName('title')))->loadAssocList('id');
        foreach ($rows as $id => $row) {
            $rows[$id] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'type' => (string) $row['type'], 'pages' => (int) $row['pages']];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> the copies, newest first, with the files of the folder no row knows of */
    public function copies(): array
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName(self::TABLE))
            ->order($db->quoteName('created') . ' DESC, ' . $db->quoteName('id') . ' DESC'))->loadAssocList();
        $out  = [];
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['file']] = true;
            $path   = $this->dir . '/' . $row['file'];
            $out[]  = [
                'file' => (string) $row['file'], 'app_id' => (int) $row['app_id'], 'app_title' => (string) $row['app_title'],
                'app_type' => (string) $row['app_type'], 'reason' => (string) $row['reason'], 'size' => (int) $row['size'],
                'pages' => (int) $row['pages'], 'rows' => (int) $row['items'], 'files' => (int) $row['files'], 'skipped' => (int) $row['skipped'],
                'user_name' => (string) $row['user_name'], 'created' => (string) $row['created'], 'missing' => !is_file($path),
            ];
        }
        // a copy put into the folder by hand (or kept from another site): known by its header
        foreach (is_dir($this->dir) ? (array) glob($this->dir . '/*.jsonl.gz') : [] as $path) {
            $file = basename((string) $path);
            if (isset($seen[$file]) || !self::validName($file)) {
                continue;
            }
            $head = $this->header($path);
            if (!$head) {
                continue;
            }
            $out[] = [
                'file' => $file, 'app_id' => (int) ($head['app']['id'] ?? 0), 'app_title' => (string) ($head['app']['title'] ?? ''),
                'app_type' => (string) ($head['app']['type'] ?? ''), 'reason' => (string) ($head['reason'] ?? ''), 'size' => (int) @filesize($path),
                'pages' => -1, 'rows' => -1, 'files' => -1, 'skipped' => 0, 'user_name' => (string) ($head['user'] ?? ''),
                'created' => (string) ($head['created'] ?? ''), 'missing' => false,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['created'], $a['created']));

        return $out;
    }

    /** A name of a copy as this class makes it: nothing that could leave the folder. */
    public static function validName(string $file): bool
    {
        return (bool) preg_match('/^app-\d{1,10}-[a-z0-9-]{0,40}-?\d{8}-\d{6}-[a-f0-9]{8}\.jsonl\.gz$/', $file);
    }

    /**
     * A file a copy may hold or put back: under Gridbox's css storage or uploads, nothing that climbs out.
     * The path is relative to the site root, with forward slashes.
     */
    public static function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        if (!str_starts_with($path, self::ASSETS . 'css/storage/') && !str_starts_with($path, self::ASSETS . 'uploads/')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /** The files Gridbox deletes with an app, as paths relative to the site root. */
    public static function appFiles(int $appId, array $pageIds, array $desktop, array $comments, array $reviews): array
    {
        $files = [self::ASSETS . 'css/storage/app-' . $appId . '.css', self::ASSETS . 'css/storage/post-' . $appId . '.css'];
        foreach ($pageIds as $id) {
            $files[] = self::ASSETS . 'css/storage/style-' . (int) $id . '.css';
        }
        foreach ($desktop as $row) {
            $files[] = self::ASSETS . 'uploads/app-' . (int) $row['app_id'] . '/' . $row['filename'];
        }
        foreach ($comments as $name) {
            $files[] = self::ASSETS . 'uploads/comments/' . $name;
        }
        foreach ($reviews as $name) {
            $files[] = self::ASSETS . 'uploads/reviews/' . $name;
        }

        return array_values(array_unique(array_filter($files, [self::class, 'safePath'])));
    }

    /** One row as a line; a value that is not UTF-8 (binary) goes as {"b64": …}, so nothing is lost or replaced. */
    public static function encodeRow(string $table, array $row): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $line  = json_encode(['t' => $table, 'r' => $row], $flags);
        if ($line !== false) {
            return $line;
        }
        foreach ($row as $key => $value) {
            if (\is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $row[$key] = ['b64' => base64_encode($value)];
            }
        }

        return (string) json_encode(['t' => $table, 'r' => $row], $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array<string, ?string> the row of a line, its {"b64": …} values decoded */
    public static function decodeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (\is_array($value)) {
                $row[$key] = isset($value['b64']) && \is_string($value['b64']) ? (string) base64_decode($value['b64'], true) : null;
            } elseif ($value !== null && !\is_string($value)) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    // ---------------------------------------------------------------- writing a copy

    /**
     * Writes a copy of the app. An automatic copy with the same content as the last copy of the app is not
     * kept: that copy is marked as checked instead.
     *
     * @return array{file:string, size:int, pages:int, rows:int, files:int, skipped:int, unchanged:bool}
     */
    public function backup(int $appId, string $reason, int $userId, string $userName, string $now): array
    {
        $db  = $this->db;
        $app = $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', 'title', 'type']))->from($db->quoteName('#__gridbox_app'))
            ->where($db->quoteName('id') . ' = ' . $appId))->loadAssoc();
        if (!$app) {
            throw new \RuntimeException('No Gridbox app ' . $appId);
        }
        $this->ensureFolder();
        $slug = trim(substr((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::ascii((string) $app['title']))), 0, 40), '-');
        $file = 'app-' . $appId . '-' . ($slug !== '' ? $slug . '-' : '') . gmdate('Ymd-His', (int) strtotime($now . ' UTC')) . '-' . bin2hex(random_bytes(4)) . '.jsonl.gz';
        $path = $this->dir . '/' . $file;
        $part = $path . '.part';
        $gz   = @gzopen($part, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('Cannot write ' . $part);
        }
        $hash    = hash_init('sha1');
        $counts  = ['rows' => 0, 'files' => 0, 'skipped' => 0, 'pages' => 0];
        $written = 0;
        $put     = function (string $line, bool $hashed = true) use ($gz, $hash, &$written): void {
            if (@gzwrite($gz, $line . "\n") === false) {
                throw new \RuntimeException('Write failed');
            }
            $written += \strlen($line) + 1;
            if ($hashed) {
                hash_update($hash, $line . "\n");
            }
        };
        try {
            $put((string) json_encode(['format' => self::FORMAT, 'v' => 1, 'app' => ['id' => (int) $app['id'], 'title' => (string) $app['title'],
                'type' => (string) $app['type']], 'reason' => $reason, 'created' => $now, 'user' => $userName, 'prefix' => $db->getPrefix()],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
            $sets    = ['app' => [$appId]];
            $desktop = [];
            $attach  = ['comments' => [], 'reviews' => []];
            $tables  = $this->existingTables();
            foreach (self::PLAN as $table => [$column, $set, $makes]) {
                if ($makes) {
                    $sets[$makes[0]] ??= [];
                }
                if (!isset($tables[$table]) || empty($sets[$set])) {
                    continue;
                }
                foreach (array_chunk(array_values(array_unique($sets[$set])), self::CHUNK) as $chunk) {
                    $rows = (array) $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName($table))
                        ->where($db->quoteName($column) . ' IN (' . implode(', ', array_map('intval', $chunk)) . ')')
                        ->order($db->quoteName('id')))->loadAssocList();
                    foreach ($rows as $row) {
                        $put(self::encodeRow($table, $row));
                        $counts['rows']++;
                        if ($makes) {
                            $sets[$makes[0]][] = (int) $row[$makes[1]];
                        }
                        if ($table === '#__gridbox_fields_desktop_files') {
                            $desktop[] = ['app_id' => $row['app_id'], 'filename' => (string) $row['filename']];
                        } elseif ($table === '#__gridbox_comments_attachments' || $table === '#__gridbox_reviews_attachments') {
                            $attach[$table === '#__gridbox_comments_attachments' ? 'comments' : 'reviews'][] = (string) $row['filename'];
                        }
                    }
                    unset($rows);
                }
            }
            $counts['pages'] = \count($sets['pages'] ?? []);
            foreach (self::appFiles($appId, $sets['pages'] ?? [], $desktop, $attach['comments'], $attach['reviews']) as $rel) {
                $full = $this->root . '/' . $rel;
                if (!is_file($full)) {
                    continue;
                }
                $size = (int) @filesize($full);
                if ($size > self::FILE_MAX) {
                    $put((string) json_encode(['f' => $rel, 'skip' => $size], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $counts['skipped']++;
                    continue;
                }
                $data = @file_get_contents($full);
                if ($data === false) {
                    continue;
                }
                $put((string) json_encode(['f' => $rel, 'd' => base64_encode($data)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $counts['files']++;
                unset($data);
            }
            $digest = hash_final($hash);
            $put((string) json_encode(['end' => true] + $counts + ['hash' => $digest]), false);
            if (!@gzclose($gz)) {
                throw new \RuntimeException('Write failed');
            }
            $gz = null;
        } catch (\Throwable $e) {
            if ($gz) {
                @gzclose($gz);
            }
            @unlink($part);
            throw $e;
        }
        // an automatic copy of an app that has not changed: the last copy stands for it
        if ($reason === 'auto') {
            $last = $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', 'file', 'hash']))->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('app_id') . ' = ' . $appId)->order($db->quoteName('created') . ' DESC, ' . $db->quoteName('id') . ' DESC'), 0, 1)->loadAssoc();
            if ($last && hash_equals((string) $last['hash'], $digest) && is_file($this->dir . '/' . $last['file'])) {
                @unlink($part);
                $db->setQuery($db->getQuery(true)->update($db->quoteName(self::TABLE))->set($db->quoteName('checked') . ' = ' . $db->quote($now))
                    ->where($db->quoteName('id') . ' = ' . (int) $last['id']))->execute();

                return ['file' => (string) $last['file'], 'size' => 0, 'unchanged' => true] + $counts;
            }
        }
        if (!@rename($part, $path)) {
            @unlink($part);
            throw new \RuntimeException('Cannot write ' . $path);
        }
        $size = (int) @filesize($path);
        $db->setQuery($db->getQuery(true)->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName(['app_id', 'app_title', 'app_type', 'reason', 'file', 'size', 'pages', 'items', 'files', 'skipped', 'hash', 'user_id', 'user_name', 'created', 'checked']))
            ->values(implode(', ', [$appId, $db->quote(mb_substr((string) $app['title'], 0, 255)), $db->quote(mb_substr((string) $app['type'], 0, 64)), $db->quote($reason),
                $db->quote($file), $size, $counts['pages'], $counts['rows'], $counts['files'], $counts['skipped'], $db->quote($digest), $userId,
                $db->quote(mb_substr($userName, 0, 255)), $db->quote($now), $db->quote($now)])))->execute();

        return ['file' => $file, 'size' => $size, 'unchanged' => false] + $counts;
    }

    /**
     * The apps whose regular copy is due: no copy (of any kind) written or checked within the period.
     *
     * @return int[]
     */
    public function due(int $periodSeconds, string $now): array
    {
        $db    = $this->db;
        $since = gmdate('Y-m-d H:i:s', (int) strtotime($now . ' UTC') - $periodSeconds + 600);
        $fresh = (array) $db->setQuery($db->getQuery(true)->select('DISTINCT ' . $db->quoteName('app_id'))->from($db->quoteName(self::TABLE))
            ->where('GREATEST(' . $db->quoteName('created') . ', IFNULL(' . $db->quoteName('checked') . ', ' . $db->quoteName('created') . ')) > ' . $db->quote($since)))->loadColumn();
        $fresh = array_flip(array_map('intval', $fresh));

        return array_values(array_filter(array_keys($this->apps()), static fn (int $id): bool => !isset($fresh[$id])));
    }

    /** Only the newest $keep regular copies of each app stay; copies made before a deletion or by hand stay until deleted. */
    public function prune(int $keep): int
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', 'app_id', 'file']))->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('reason') . ' = ' . $db->quote('auto'))
            ->order($db->quoteName('app_id') . ', ' . $db->quoteName('created') . ' DESC, ' . $db->quoteName('id') . ' DESC'))->loadAssocList();
        $count = [];
        $gone  = 0;
        foreach ($rows as $row) {
            $app = (int) $row['app_id'];
            $count[$app] = ($count[$app] ?? 0) + 1;
            if ($count[$app] > max(1, $keep)) {
                $this->delete((string) $row['file']);
                $gone++;
            }
        }

        return $gone;
    }

    public function delete(string $file): bool
    {
        if (!self::validName($file)) {
            return false;
        }
        $path = $this->dir . '/' . $file;
        $ok   = !is_file($path) || @unlink($path);
        $db   = $this->db;
        $db->setQuery($db->getQuery(true)->delete($db->quoteName(self::TABLE))->where($db->quoteName('file') . ' = ' . $db->quote($file)))->execute();

        return $ok;
    }

    /** The full path of a copy, or null when there is no such copy. */
    public function path(string $file): ?string
    {
        return self::validName($file) && is_file($this->dir . '/' . $file) ? $this->dir . '/' . $file : null;
    }

    // ---------------------------------------------------------------- restoring

    /**
     * Brings back what of the copy is missing: the app, when it is gone, then the categories, fields,
     * tags and pages (products, posts) whose ids are free, each page with its product data, fields,
     * tags, categories, comments and reviews, and the missing files. Nothing that exists is changed.
     * An id the copy needs that another app uses now stops it before anything is written.
     *
     * @return array<string, int> what came back
     */
    public function restore(string $file): array
    {
        $path = $this->path($file);
        if (!$path) {
            throw new \RuntimeException('No such copy');
        }
        $head = $this->header($path);
        if (!$head) {
            throw new \RuntimeException('Not a MerTools copy of a Gridbox app');
        }
        $appId = (int) $head['app']['id'];
        $db    = $this->db;

        // first reading: the ids the copy holds, and that it is whole
        $ids  = [];
        $end  = false;
        $this->lines($path, function (array $line) use (&$ids, &$end): void {
            if (isset($line['t'], $line['r']) && \in_array($line['t'], self::CORE, true)) {
                $ids[$line['t']][(int) $line['r']['id']] = $line['t'] === '#__gridbox_app' ? (string) ($line['r']['type'] ?? '')
                    : ($line['t'] === '#__gridbox_tags' ? '' : (int) ($line['r']['app_id'] ?? 0));
            } elseif (!empty($line['end'])) {
                $end = true;
            }
        });
        if (!$end) {
            throw new \RuntimeException('The copy is not complete');
        }
        $tables = $this->existingTables();
        $taken  = [];
        $exists = [];
        foreach ($ids as $table => $rows) {
            if (!isset($tables[$table])) {
                throw new \RuntimeException('Gridbox has no table ' . $table);
            }
            $owner = $table === '#__gridbox_app' ? 'type' : ($table === '#__gridbox_tags' ? 'id' : 'app_id');
            foreach (array_chunk(array_keys($rows), 500) as $chunk) {
                $now = (array) $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', $owner], ['id', 'owner']))->from($db->quoteName($table))
                    ->where($db->quoteName('id') . ' IN (' . implode(', ', array_map('intval', $chunk)) . ')'))->loadAssocList('id');
                foreach ($now as $id => $row) {
                    $exists[$table][(int) $id] = true;
                    $mine = $table === '#__gridbox_app' ? (string) $row['owner'] === (string) $rows[(int) $id]
                        : ($table === '#__gridbox_tags' || (int) $row['owner'] === $appId);
                    if (!$mine) {
                        $taken[] = $table . ' #' . (int) $id;
                    }
                }
            }
        }
        if ($taken) {
            throw new \RuntimeException('Ids used by other items now: ' . implode(', ', \array_slice($taken, 0, 10)) . (\count($taken) > 10 ? ', …' : ''));
        }

        // second reading: the rows, in one transaction, then the files
        $back    = ['app' => isset($exists['#__gridbox_app'][$appId]) ? [] : [$appId], 'pages' => [], 'fields' => [], 'comments' => [], 'reviews' => []];
        $result  = ['app' => 0, 'pages' => 0, 'categories' => 0, 'rows' => 0, 'files' => 0, 'kept' => 0];
        $columns = [];
        $inTx    = true;
        $db->transactionStart();
        try {
            $this->lines($path, function (array $line) use ($db, $tables, $exists, $appId, &$back, &$result, &$columns, &$inTx): void {
                if (isset($line['f'])) {
                    if ($inTx) {
                        $db->transactionCommit();
                        $inTx = false;
                    }
                    $this->putFile((string) $line['f'], $line['d'] ?? null) && $result['files']++;

                    return;
                }
                if (!isset($line['t'], $line['r']) || !\is_array($line['r']) || !isset(self::PLAN[$line['t']], $tables[$line['t']])) {
                    return;
                }
                $table = (string) $line['t'];
                $row   = self::decodeRow($line['r']);
                $id    = (int) ($row['id'] ?? 0);
                [$column, $set, $makes] = self::PLAN[$table];
                if (\in_array($table, self::CORE, true)) {
                    if (isset($exists[$table][$id])) {
                        $table === '#__gridbox_pages' && $result['kept']++;

                        return;
                    }
                } elseif (!\in_array((int) ($row[$column] ?? 0), $back[$set] ?? [], true)) {
                    // a row of a page (field, comment) that is there now, or that does not come back
                    return;
                }
                $columns[$table] ??= array_change_key_case((array) $db->getTableColumns($table, true), CASE_LOWER);
                $row = array_intersect_key($row, $columns[$table]);
                if ($id > 0 && !\in_array($table, self::CORE, true) && $this->idTaken($table, $id)) {
                    if (\in_array($table, self::KEEP_ID, true)) {
                        return;
                    }
                    unset($row['id']);
                }
                $this->insert($table, $row);
                $result['rows']++;
                if ($makes && $makes[1] === 'id') {
                    $back[$makes[0]][] = $id;
                }
                if ($table === '#__gridbox_app') {
                    $result['app'] = 1;
                } elseif ($table === '#__gridbox_pages') {
                    $result['pages']++;
                } elseif ($table === '#__gridbox_categories') {
                    $result['categories']++;
                }
            });
            if ($inTx) {
                $db->transactionCommit();
                $inTx = false;
            }
        } catch (\Throwable $e) {
            if ($inTx) {
                $db->transactionRollback();
            }
            throw $e;
        }

        return $result;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, true> the tables of PLAN that exist (an older Gridbox may not have them all) */
    private function existingTables(): array
    {
        $prefix = $this->db->getPrefix();
        $have   = array_flip(array_map('strtolower', (array) $this->db->getTableList()));
        $out    = [];
        foreach (array_keys(self::PLAN) as $table) {
            if (isset($have[strtolower($prefix . substr($table, 3))])) {
                $out[$table] = true;
            }
        }

        return $out;
    }

    private function ensureFolder(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create ' . $this->dir);
        }
        // the folder may be inside the web root: nothing in it is served
        $guards = [
            '.htaccess'  => "# MerTools: copies of Gridbox apps, never served\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.html' => "<!DOCTYPE html><title></title>\n",
        ];
        foreach ($guards as $name => $content) {
            if (!is_file($this->dir . '/' . $name)) {
                @file_put_contents($this->dir . '/' . $name, $content);
            }
        }
    }

    /** @return ?array the header of a copy, null when the file is not one */
    private function header(string $path): ?array
    {
        $gz = @gzopen($path, 'rb');
        if (!$gz) {
            return null;
        }
        $first = gzgets($gz, 65536);
        gzclose($gz);
        $head = \is_string($first) ? json_decode($first, true) : null;

        return \is_array($head) && ($head['format'] ?? '') === self::FORMAT && (int) ($head['v'] ?? 0) === 1 && (int) ($head['app']['id'] ?? 0) > 0 ? $head : null;
    }

    /** Every line after the header, decoded, to the callback. */
    private function lines(string $path, callable $each): void
    {
        $gz = @gzopen($path, 'rb');
        if (!$gz) {
            throw new \RuntimeException('Cannot read the copy');
        }
        try {
            gzgets($gz);
            while (($line = gzgets($gz)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (!\is_array($data)) {
                    throw new \RuntimeException('The copy is damaged');
                }
                $each($data);
            }
        } finally {
            gzclose($gz);
        }
    }

    private function idTaken(string $table, int $id): bool
    {
        $db = $this->db;

        return (bool) $db->setQuery($db->getQuery(true)->select('1')->from($db->quoteName($table))->where($db->quoteName('id') . ' = ' . $id), 0, 1)->loadResult();
    }

    private function insert(string $table, array $row): void
    {
        $db = $this->db;
        $db->setQuery($db->getQuery(true)->insert($db->quoteName($table))->columns($db->quoteName(array_keys($row)))
            ->values(implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $db->quote((string) $v), array_values($row)))))->execute();
    }

    /** A file of the copy goes back only where none is now. */
    private function putFile(string $rel, ?string $data): bool
    {
        if ($data === null || !self::safePath($rel)) {
            return false;
        }
        $full = $this->root . '/' . $rel;
        if (file_exists($full)) {
            return false;
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || (!is_dir(\dirname($full)) && !@mkdir(\dirname($full), 0755, true))) {
            return false;
        }

        return @file_put_contents($full, $bytes) !== false;
    }

    private static function ascii(string $text): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'];
        $text = strtr($text, $map);
        if (\function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (\is_string($out)) {
                return $out;
            }
        }

        return $text;
    }
}
