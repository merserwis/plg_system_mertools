<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Edit lock of Gridbox pages: one person at a time in the Gridbox editor of a page, article or
 * product (Gridbox has no check-out of its own, so two people could edit and save over each other).
 *
 * Opening the editor takes the lock; the open editor confirms it every 30 s (mertools-lock.js) and
 * gives it back when closed. A lock is free again when the editor stopped confirming it (TTL: a
 * closed laptop, a crashed browser) or when its holder did nothing in the editor for the idle time.
 * A save of the editor is refused when somebody else holds the lock (e.g. took it over).
 *
 * Kept in #__mertools_locks: one row per edited item (lock_key = "page:12", "post-layout:3", …).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class EditLock
{
    public const TABLE = '#__mertools_locks';

    /** seconds without a confirmation of the open editor after which the lock is free */
    public const TTL = 120;

    /** seconds between two confirmations of the editor */
    public const PING = 30;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** "page:12" for a page, article or product; "<edit type>:<id>" for layouts, headers and other templates. */
    public static function key(string $editType, int $id): string
    {
        $type = preg_replace('/[^a-z0-9_-]/', '', strtolower($editType));

        return ($type === '' ? 'page' : substr($type, 0, 40)) . ':' . max(0, $id);
    }

    /**
     * Is a lock row still held? Free when the editor stopped confirming it or its holder was idle.
     *
     * @param array<string, mixed>|null $row  seen, active (Y-m-d H:i:s, UTC)
     */
    public static function held(?array $row, int $now, int $idleSeconds): bool
    {
        if (!$row) {
            return false;
        }
        $time   = static fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $v) ? strtotime($v . ' UTC') : false;
        $seen   = $time($row['seen'] ?? '');
        $active = $time($row['active'] ?? ($row['seen'] ?? ''));
        if ($seen === false || $now - $seen > self::TTL) {
            return false;
        }

        return $idleSeconds <= 0 || $active === false || $now - $active <= $idleSeconds;
    }

    /** Somebody else holds it: the lock blocks $userId. */
    public static function blocks(?array $row, int $userId, int $now, int $idleSeconds): bool
    {
        return self::held($row, $now, $idleSeconds) && (int) $row['user_id'] !== $userId;
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' (`lock_key` VARCHAR(64) NOT NULL,'
            . ' `page_id` INT UNSIGNED NOT NULL DEFAULT 0, `user_id` INT NOT NULL, `user_name` VARCHAR(255) NOT NULL DEFAULT \'\','
            . ' `tab` VARCHAR(32) NOT NULL DEFAULT \'\', `since` DATETIME NOT NULL, `seen` DATETIME NOT NULL, `active` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`lock_key`), KEY `idx_page` (`page_id`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $row = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE lock_key = ' . $this->db->quote($key))->loadAssoc();

        return $row ?: null;
    }

    /**
     * Take the lock: free, already ours, or $force (taking it over). Keeps "since" while the same user
     * holds it. @return array<string, mixed>|null  the holder when somebody else holds it (not taken)
     */
    public function acquire(string $key, int $pageId, int $userId, string $userName, string $tab, int $now, int $idleSeconds, bool $force = false): ?array
    {
        $row = $this->get($key);
        if (!$force && self::blocks($row, $userId, $now, $idleSeconds)) {
            return $row;
        }
        $db    = $this->db;
        $stamp = $db->quote(gmdate('Y-m-d H:i:s', $now));
        $since = $row && (int) $row['user_id'] === $userId && self::held($row, $now, $idleSeconds) ? $db->quote((string) $row['since']) : $stamp;
        $db->setQuery('REPLACE INTO ' . $db->quoteName(self::TABLE) . ' (lock_key, page_id, user_id, user_name, tab, since, seen, active) VALUES ('
            . $db->quote($key) . ', ' . max(0, $pageId) . ', ' . $userId . ', ' . $db->quote(mb_substr($userName, 0, 255)) . ', ' . $db->quote(substr($tab, 0, 32)) . ', ' . $since . ', '
            . $stamp . ', ' . $stamp . ')')->execute();

        return null;
    }

    /**
     * The open editor confirms the lock; $active = the user did something since the last time.
     * A lock that went free in the meantime is taken again (nobody else took it).
     *
     * @return array<string, mixed>|null  the holder when somebody else has it now (lost)
     */
    public function ping(string $key, int $pageId, int $userId, string $userName, string $tab, bool $active, int $now, int $idleSeconds): ?array
    {
        $row = $this->get($key);
        if (self::blocks($row, $userId, $now, $idleSeconds)) {
            return $row;
        }
        if (!$row || (int) $row['user_id'] !== $userId || !self::held($row, $now, $idleSeconds)) {
            // free again (expired or idle): only a working editor takes it back
            return $active ? $this->acquire($key, $pageId, $userId, $userName, $tab, $now, $idleSeconds) : null;
        }
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName(self::TABLE) . ' SET seen = ' . $db->quote(gmdate('Y-m-d H:i:s', $now))
            . ($active ? ', active = ' . $db->quote(gmdate('Y-m-d H:i:s', $now)) : '') . ' WHERE lock_key = ' . $db->quote($key)
            . ' AND user_id = ' . $userId)->execute();

        return null;
    }

    /**
     * The editor was closed: the lock goes, if this very editor tab took it (a reload takes it again
     * at once, and the late signal of the closed page must not remove the new lock).
     */
    public function release(string $key, int $userId, string $tab): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE lock_key = ' . $this->db->quote($key) . ' AND user_id = ' . $userId
            . ' AND tab = ' . $this->db->quote(substr($tab, 0, 32)))->execute();
    }

    /**
     * Locks held now on pages (for the Gridbox lists).
     *
     * @param int[] $pageIds
     *
     * @return array<int, array<string, mixed>>  page id => lock
     */
    public function onPages(array $pageIds, int $now, int $idleSeconds): array
    {
        $ids = array_values(array_filter(array_map('intval', $pageIds), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->setQuery('SELECT * FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE page_id IN (' . implode(',', $ids) . ') AND lock_key LIKE '
            . $this->db->quote('page:%'))->loadAssocList() ?: [] as $row) {
            if (self::held($row, $now, $idleSeconds)) {
                $out[(int) $row['page_id']] = $row;
            }
        }

        return $out;
    }

    /** Old rows go (a lock not confirmed for a day). */
    public function prune(int $now): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE seen < ' . $this->db->quote(gmdate('Y-m-d H:i:s', $now - 86400)))->execute();
    }
}
