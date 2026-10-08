<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Old shop carts: Gridbox keeps every cart ever started (a row in #__gridbox_store_cart, its products
 * in #__gridbox_store_cart_products) and never removes one, so the tables only grow — also carts of
 * orders long completed. This removes the carts nobody can open any more.
 *
 * When is a cart dead? A visitor reaches a cart only through the gridbox_store_cart cookie, which
 * Gridbox sets for 7 days and renews when the cart changes (StoreCartHelper::setCartLifetime);
 * there is no other way back to it, also not for a logged-in user. A cart whose cookie has not been
 * sent for more than 7 days can never be opened again. The carts table has no dates, so MerTools
 * notes the time a cart's cookie is seen (#__mertools_cart_seen, one row per cart, written at most
 * every 10 minutes) and, once a day, the highest cart id (#__mertools_cart_marks: carts up to that id
 * already existed then). A cart with products is removed when it is at least N days old (N >= 8) and
 * its cookie has not been seen for N days — which needs N days of watching, so this only starts N
 * days after MerTools began watching (and again after any break in watching).
 *
 * An empty cart (no products) holds nothing: it is removed at once, unless its cookie was seen in
 * the last hour or it is one of the newest carts. If its visitor comes back, Gridbox simply starts a
 * new cart, as it does when the cookie has expired.
 *
 * Never touched: orders and everything that belongs to them (they keep their own copies of the
 * products), carts with files attached to their products, carts used in the last hour. The deletes
 * run in small batches by primary key, so the shop is never locked for long.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class CartCleaner
{
    /** Gridbox keeps the cart cookie 7 days after the last change; one more day of margin */
    public const MIN_DAYS = 8;

    /** an empty cart used this recently is kept (a product may be on its way into it) */
    private const PROTECT_SECONDS = 3600;

    /** the newest carts are never touched (one being created right now has no product yet) */
    private const NEWEST_KEPT = 100;

    /** a break in watching longer than this restarts the count of days */
    private const GAP_SECONDS = 172800;

    private const CHUNK = 2000;

    private const SWEEP_STEP = 5000;

    public function __construct(private DatabaseInterface $db)
    {
    }

    // ---------------------------------------------------------------- pure helpers (unit tested)

    /** The number of days used: at least MIN_DAYS. */
    public static function effectiveDays(int $days): int
    {
        return max(self::MIN_DAYS, $days);
    }

    /**
     * From when carts with products can be removed: N days after watching began (null: not watching yet).
     */
    public static function abandonedFrom(?int $trackSince, int $days): ?int
    {
        return $trackSince === null ? null : $trackSince + self::effectiveDays($days) * 86400;
    }

    /**
     * Whether watching has to start counting again: never watched, or a break longer than two days.
     */
    public static function restartsWatch(?int $lastTick, int $now): bool
    {
        return $lastTick === null || $now - $lastTick > self::GAP_SECONDS;
    }

    /** The ids of the carts table started again (it was emptied): the highest id is below an earlier mark. */
    public static function idsRestarted(int $maxId, int $highestMark): bool
    {
        return $maxId < $highestMark;
    }

    // ---------------------------------------------------------------- set-up

    /** MySQL or MariaDB with the Gridbox store tables. */
    public function supported(): bool
    {
        try {
            if ($this->db->getServerType() !== 'mysql') {
                return false;
            }
            $tables = array_map('strtolower', $this->db->getTableList());
            $prefix = strtolower($this->db->getPrefix());

            return \in_array($prefix . 'gridbox_store_cart', $tables, true)
                && \in_array($prefix . 'gridbox_store_cart_products', $tables, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function ensureTables(): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS `#__mertools_cart_seen` (`cart_id` INT UNSIGNED NOT NULL, `seen` DATETIME NOT NULL,'
                . ' PRIMARY KEY (`cart_id`), KEY `idx_seen` (`seen`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `#__mertools_cart_marks` (`at` DATETIME NOT NULL, `cart_max` INT UNSIGNED NOT NULL,'
                . ' PRIMARY KEY (`at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `#__mertools_cart_queue` (`id` INT UNSIGNED NOT NULL, `items` TINYINT NOT NULL DEFAULT 0,'
                . ' `attach` TINYINT NOT NULL DEFAULT 0, `seen` DATETIME NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `#__mertools_state` (`name` VARCHAR(64) NOT NULL, `value` MEDIUMTEXT NOT NULL,'
                . ' PRIMARY KEY (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ] as $sql) {
            $this->db->setQuery($sql)->execute();
        }
    }

    /** The tables of this tool (removed when MerTools is uninstalled). */
    public static function tables(): array
    {
        return ['#__mertools_cart_seen', '#__mertools_cart_marks', '#__mertools_cart_queue'];
    }

    // ---------------------------------------------------------------- watching

    /**
     * A request carried a cart cookie: note that the cart is in use. Only carts that exist are noted
     * (a made-up cookie adds nothing), and a cart already noted in the last 10 minutes is not rewritten.
     */
    public function track(int $cartId, int $now): void
    {
        if ($cartId <= 0) {
            return;
        }
        $nowSql = $this->db->quote(gmdate('Y-m-d H:i:s', $now));
        $cutSql = $this->db->quote(gmdate('Y-m-d H:i:s', $now - 600));
        $this->db->setQuery('INSERT INTO `#__mertools_cart_seen` (`cart_id`, `seen`) SELECT `id`, ' . $nowSql
            . ' FROM `#__gridbox_store_cart` WHERE `id` = ' . $cartId
            . ' ON DUPLICATE KEY UPDATE `seen` = IF(`seen` < ' . $cutSql . ', ' . $nowSql . ', `seen`)')->execute();
    }

    /**
     * Once an hour on the site: the watching bookkeeping (start, break, daily mark) and, when the
     * settings ask for it, an automatic clean-up.
     *
     * @param string $mode  manual | daily | limit
     *
     * @return array|null what a clean-up did, or null when none ran
     */
    public function tick(int $now, string $mode, int $limit, int $days, bool $empty, float $budget): ?array
    {
        $this->ensureTables();
        if (!$this->claim('cart_tick', $now, 3300)) {
            return null;
        }

        $lastTick = $this->stateInt('cart_last_tick');
        // the cart ids started again from 1 (the table was emptied, e.g. in phpMyAdmin): the old marks
        // would make new carts look old, so they go and watching starts again
        $maxId   = (int) $this->db->setQuery('SELECT COALESCE(MAX(`id`), 0) FROM `#__gridbox_store_cart`')->loadResult();
        $markTop = (int) $this->db->setQuery('SELECT COALESCE(MAX(`cart_max`), 0) FROM `#__mertools_cart_marks`')->loadResult();
        $reset   = self::idsRestarted($maxId, $markTop);
        if ($reset) {
            $this->db->setQuery('DELETE FROM `#__mertools_cart_marks`')->execute();
            $this->db->setQuery('DELETE FROM `#__mertools_cart_seen`')->execute();
        }
        if ($reset || self::restartsWatch($lastTick, $now)) {
            $this->setState('cart_track_since', (string) $now);
        }
        $this->setState('cart_last_tick', (string) $now);

        // once a day: the highest cart id now (every cart up to it exists already)
        $lastMark = $this->db->setQuery('SELECT MAX(`at`) FROM `#__mertools_cart_marks`')->loadResult();
        if (!$lastMark || strtotime($lastMark . ' UTC') < $now - 82800) {
            $this->db->setQuery('INSERT IGNORE INTO `#__mertools_cart_marks` (`at`, `cart_max`) SELECT '
                . $this->db->quote(gmdate('Y-m-d H:i:s', $now)) . ', COALESCE(MAX(`id`), 0) FROM `#__gridbox_store_cart`')->execute();
            $this->db->setQuery('DELETE FROM `#__mertools_cart_marks` WHERE `at` < '
                . $this->db->quote(gmdate('Y-m-d H:i:s', $now - 400 * 86400)))->execute();
        }

        $pending = $this->stateInt('cart_pending') === 1;
        $due     = false;
        if ($mode === 'daily') {
            $last = $this->lastRun();
            $due  = $pending || !$last || ($last['at'] ?? 0) < $now - 82800;
        } elseif ($mode === 'limit') {
            $due = $pending || $this->count('#__gridbox_store_cart') > max(1, $limit);
        }
        if (!$due) {
            return null;
        }

        return $this->run($now, $days, $empty, true, false, $budget, 'auto');
    }

    // ---------------------------------------------------------------- figures

    /**
     * Figures for the settings page: carts, products, how long MerTools has been watching, the last
     * clean-up.
     */
    public function stats(int $now, int $days): array
    {
        $this->ensureTables();
        $since = $this->stateInt('cart_track_since');

        return [
            'carts'         => $this->count('#__gridbox_store_cart'),
            'withProducts'  => (int) $this->db->setQuery('SELECT COUNT(DISTINCT `cart_id`) FROM `#__gridbox_store_cart_products`')->loadResult(),
            'products'      => $this->count('#__gridbox_store_cart_products'),
            'size'          => $this->tableSizes()['size'],
            'trackSince'    => $since,
            'abandonedFrom' => self::abandonedFrom($since, $days),
            'last'          => $this->lastRun(),
            'pending'       => $this->stateInt('cart_pending') === 1,
        ];
    }

    // ---------------------------------------------------------------- clean-up

    /**
     * Find (and unless $dryRun, remove) the carts that can go.
     *
     * @return array{empty:int, abandoned:int, products:int, attached:int, done:bool, abandonedFrom:?int, busy?:bool}
     */
    public function run(int $now, int $days, bool $empty, bool $abandoned, bool $dryRun, float $budget, string $by = 'manual', bool $optimize = false): array
    {
        $started = microtime(true);
        $this->ensureTables();
        $days  = self::effectiveDays($days);
        $since = $this->stateInt('cart_track_since');
        $from  = self::abandonedFrom($since, $days);
        $out   = ['empty' => 0, 'abandoned' => 0, 'products' => 0, 'attached' => 0, 'done' => true, 'abandonedFrom' => $from];

        if (!$this->claim('cart_lock', $now, 600)) {
            return $out + ['busy' => true];
        }

        try {
            $maxId = (int) $this->db->setQuery('SELECT COALESCE(MAX(`id`), 0) FROM `#__gridbox_store_cart`')->loadResult();
            $top   = $maxId - self::NEWEST_KEPT;

            // carts with products: only when watching has covered the whole period, and only carts that
            // existed at the start of it (the newest daily mark at least N days old)
            $markMax = 0;
            if ($abandoned && $from !== null && $from <= $now) {
                $markMax = (int) $this->db->setQuery('SELECT `cart_max` FROM `#__mertools_cart_marks` WHERE `at` <= '
                    . $this->db->quote(gmdate('Y-m-d H:i:s', $now - $days * 86400)) . ' ORDER BY `at` DESC LIMIT 1')->loadResult();
            }
            // a mark above today's highest id means the table was emptied since: no mark can be trusted
            if (self::idsRestarted($maxId, (int) $this->db->setQuery('SELECT COALESCE(MAX(`cart_max`), 0) FROM `#__mertools_cart_marks`')->loadResult())) {
                $markMax = 0;
            }
            $markMax = min($markMax, $top);

            if ($top > 0 && ($empty || $markMax > 0)) {
                $this->buildQueue($top, $now, $days, $empty, $markMax);
                $counts = $this->db->setQuery('SELECT COALESCE(SUM(`items` = 0), 0) AS e, COALESCE(SUM(`items` = 1), 0) AS a'
                    . ' FROM `#__mertools_cart_queue`')->loadAssoc();
                $out['empty']     = (int) $counts['e'];
                $out['abandoned'] = (int) $counts['a'];
                $out['attached']  = (int) $this->stateInt('cart_attached_skipped');

                if ($dryRun) {
                    $out['products'] = (int) $this->db->setQuery('SELECT COUNT(*) FROM `#__gridbox_store_cart_products` AS `cp`'
                        . ' INNER JOIN `#__mertools_cart_queue` AS `q` ON `q`.`id` = `cp`.`cart_id`')->loadResult();
                } else {
                    $deleted = $this->deleteQueued($now, $started, $budget);
                    $out['empty']     = $deleted['empty'];
                    $out['abandoned'] = $deleted['abandoned'];
                    $out['done']      = $deleted['done'];
                }
            }

            if (!$dryRun) {
                // products whose cart is gone (also left over from before), in primary-key steps
                $sweep = $this->sweepProducts($top, $started, $budget);
                $out['products'] = $sweep['deleted'];
                $out['done']     = $out['done'] && $sweep['done'];
                if ($out['done']) {
                    $this->cleanCompanions($top);
                }
                // removed rows leave the table files as large as before: rebuild them (by hand only)
                if ($optimize && $out['done']) {
                    $out['sizeBefore'] = $this->tableSizes()['size'];
                    $this->optimize();
                    $out['sizeAfter']  = $this->tableSizes()['size'];
                }
                $this->setState('cart_pending', $out['done'] ? '0' : '1');
                $this->saveLastRun($now, $out, $by);
            }

            $this->db->setQuery('DELETE FROM `#__mertools_cart_queue`')->execute();
        } finally {
            $this->release('cart_lock');
        }

        return $out;
    }

    /** The queue of carts that can go, with the reason (items = 0: empty, 1: abandoned with products). */
    private function buildQueue(int $top, int $now, int $days, bool $empty, int $markMax): void
    {
        $db = $this->db;
        $db->setQuery('DELETE FROM `#__mertools_cart_queue`')->execute();
        $db->setQuery('INSERT INTO `#__mertools_cart_queue` (`id`) SELECT `id` FROM `#__gridbox_store_cart` WHERE `id` <= ' . $top)->execute();
        $db->setQuery('UPDATE `#__mertools_cart_queue` AS `q` INNER JOIN (SELECT DISTINCT `cart_id` FROM `#__gridbox_store_cart_products`) AS `p`'
            . ' ON `p`.`cart_id` = `q`.`id` SET `q`.`items` = 1')->execute();
        $db->setQuery('UPDATE `#__mertools_cart_queue` AS `q` INNER JOIN (SELECT DISTINCT `cart_id` FROM `#__gridbox_store_cart_attachments_map`'
            . ' WHERE `cart_id` > 0) AS `a` ON `a`.`cart_id` = `q`.`id` SET `q`.`attach` = 1')->execute();
        $db->setQuery('UPDATE `#__mertools_cart_queue` AS `q` INNER JOIN `#__mertools_cart_seen` AS `s` ON `s`.`cart_id` = `q`.`id`'
            . ' SET `q`.`seen` = `s`.`seen`')->execute();

        $this->setState('cart_attached_skipped', (string) (int) $db->setQuery(
            'SELECT COUNT(*) FROM `#__mertools_cart_queue` WHERE `attach` = 1')->loadResult());

        $recent = $db->quote(gmdate('Y-m-d H:i:s', $now - self::PROTECT_SECONDS));
        $cut    = $db->quote(gmdate('Y-m-d H:i:s', $now - $days * 86400));
        $keepEmpty     = $empty ? '`items` = 0 AND (`seen` IS NULL OR `seen` < ' . $recent . ')' : '0';
        $keepAbandoned = $markMax > 0 ? '`items` = 1 AND `id` <= ' . $markMax . ' AND (`seen` IS NULL OR `seen` < ' . $cut . ')' : '0';
        $db->setQuery('DELETE FROM `#__mertools_cart_queue` WHERE `attach` = 1 OR NOT ((' . $keepEmpty . ') OR (' . $keepAbandoned . '))')->execute();
    }

    /** Remove the queued carts in batches; a cart seen in the last hour is spared even now. */
    private function deleteQueued(int $now, float $started, float $budget): array
    {
        $db     = $this->db;
        $recent = $db->quote(gmdate('Y-m-d H:i:s', $now - self::PROTECT_SECONDS));
        $out    = ['empty' => 0, 'abandoned' => 0, 'done' => true];
        $last   = 0;
        while (true) {
            $rows = $db->setQuery('SELECT `id`, `items` FROM `#__mertools_cart_queue` WHERE `id` > ' . $last
                . ' ORDER BY `id` LIMIT ' . self::CHUNK)->loadAssocList();
            if (!$rows) {
                break;
            }
            $last = (int) end($rows)['id'];
            $ids  = implode(',', array_map(fn ($r) => (int) $r['id'], $rows));
            foreach ([0 => 'empty', 1 => 'abandoned'] as $items => $key) {
                $db->setQuery('DELETE `c` FROM `#__gridbox_store_cart` AS `c` INNER JOIN `#__mertools_cart_queue` AS `q` ON `q`.`id` = `c`.`id`'
                    . ' LEFT JOIN `#__mertools_cart_seen` AS `s` ON `s`.`cart_id` = `c`.`id`'
                    . ' WHERE `c`.`id` IN (' . $ids . ') AND `q`.`items` = ' . $items
                    . ' AND (`s`.`seen` IS NULL OR `s`.`seen` < ' . $recent . ')')->execute();
                $out[$key] += (int) $db->getAffectedRows();
            }
            // at least one batch per run, so short runs still get the job done
            if (microtime(true) - $started > $budget) {
                $more = $db->setQuery('SELECT 1 FROM `#__mertools_cart_queue` WHERE `id` > ' . $last . ' LIMIT 1')->loadResult();
                $out['done'] = !$more;
                break;
            }
        }

        return $out;
    }

    /**
     * Products whose cart no longer exists, walked through in primary-key steps (small locks); the
     * position is kept, so a walk cut short by the time limit goes on next time.
     */
    private function sweepProducts(int $top, float $started, float $budget): array
    {
        $db  = $this->db;
        $max = (int) $db->setQuery('SELECT COALESCE(MAX(`id`), 0) FROM `#__gridbox_store_cart_products`')->loadResult();
        $pos = (int) $this->stateInt('cart_sweep_pos');
        $out = ['deleted' => 0, 'done' => true];
        if ($top <= 0) {
            return $out;
        }
        while ($pos < $max) {
            $to = $pos + self::SWEEP_STEP;
            $db->setQuery('DELETE `cp` FROM `#__gridbox_store_cart_products` AS `cp` LEFT JOIN `#__gridbox_store_cart` AS `c` ON `c`.`id` = `cp`.`cart_id`'
                . ' WHERE `cp`.`id` > ' . $pos . ' AND `cp`.`id` <= ' . $to
                . ' AND `c`.`id` IS NULL AND `cp`.`cart_id` > 0 AND `cp`.`cart_id` <= ' . $top)->execute();
            $out['deleted'] += (int) $db->getAffectedRows();
            $pos = $to;
            // at least one step per run
            if ($pos < $max && microtime(true) - $started > $budget) {
                $out['done'] = false;
                break;
            }
        }
        $this->setState('cart_sweep_pos', $out['done'] ? '0' : (string) $pos);

        return $out;
    }

    /**
     * What a removed cart leaves: its watching row, and the shipping and payment choices of a
     * checkout that never became an order (order_id 0). Rows of orders are never touched.
     */
    private function cleanCompanions(int $top): void
    {
        $db = $this->db;
        $db->setQuery('DELETE `s` FROM `#__mertools_cart_seen` AS `s` LEFT JOIN `#__gridbox_store_cart` AS `c` ON `c`.`id` = `s`.`cart_id`'
            . ' WHERE `c`.`id` IS NULL AND `s`.`cart_id` <= ' . $top)->execute();
        foreach (['#__gridbox_store_orders_shipping', '#__gridbox_store_orders_payment'] as $table) {
            try {
                $db->setQuery('DELETE `t` FROM `' . $table . '` AS `t` LEFT JOIN `#__gridbox_store_cart` AS `c` ON `c`.`id` = `t`.`cart_id`'
                    . ' WHERE `t`.`order_id` = 0 AND `t`.`cart_id` > 0 AND `t`.`cart_id` <= ' . $top . ' AND `c`.`id` IS NULL')->execute();
            } catch (\Throwable $e) {
                // an older Gridbox without the table
            }
        }
    }

    // ---------------------------------------------------------------- table size

    /** The cart tables of Gridbox: their size on disk in bytes (data and indexes) and the unused part. */
    public function tableSizes(): array
    {
        // MySQL 8 keeps these figures cached for up to a day: fresh statistics first (a quick sample)
        try {
            $this->db->setQuery('ANALYZE TABLE `#__gridbox_store_cart`, `#__gridbox_store_cart_products`')->loadAssocList();
        } catch (\Throwable $e) {
        }
        $prefix = $this->db->getPrefix();
        $row    = $this->db->setQuery('SELECT COALESCE(SUM(`DATA_LENGTH` + `INDEX_LENGTH`), 0) AS `size`, COALESCE(SUM(`DATA_FREE`), 0) AS `free`'
            . ' FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ('
            . implode(',', array_map(fn ($t) => $this->db->quote($prefix . $t), ['gridbox_store_cart', 'gridbox_store_cart_products'])) . ')')->loadAssoc();

        return ['size' => (int) ($row['size'] ?? 0), 'free' => (int) ($row['free'] ?? 0)];
    }

    /**
     * Rebuild the cart tables so the space of removed rows is given back (InnoDB recreates the table
     * online; MySQL and MariaDB keep it usable meanwhile). The table statistics are refreshed too, so
     * phpMyAdmin shows the real number of rows.
     */
    public function optimize(): void
    {
        foreach (['#__gridbox_store_cart', '#__gridbox_store_cart_products', '#__mertools_cart_seen'] as $table) {
            try {
                // OPTIMIZE returns a result set: it has to be read, not just executed
                $this->db->setQuery('OPTIMIZE TABLE `' . $table . '`')->loadAssocList();
            } catch (\Throwable $e) {
                // no right to optimize: the rows are gone anyway
            }
        }
    }

    // ---------------------------------------------------------------- state

    private function count(string $table): int
    {
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM `' . $table . '`')->loadResult();
    }

    private function lastRun(): ?array
    {
        $json = $this->state('cart_last_run');
        $data = $json ? json_decode($json, true) : null;

        return \is_array($data) ? $data : null;
    }

    private function saveLastRun(int $now, array $out, string $by): void
    {
        $this->setState('cart_last_run', json_encode(['at' => $now, 'by' => $by, 'empty' => $out['empty'],
            'abandoned' => $out['abandoned'], 'products' => $out['products'], 'done' => $out['done']]));
    }

    private function state(string $name): ?string
    {
        $value = $this->db->setQuery('SELECT `value` FROM `#__mertools_state` WHERE `name` = ' . $this->db->quote($name))->loadResult();

        return $value === null ? null : (string) $value;
    }

    private function stateInt(string $name): ?int
    {
        $value = $this->state($name);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function setState(string $name, string $value): void
    {
        $this->db->setQuery('INSERT INTO `#__mertools_state` (`name`, `value`) VALUES (' . $this->db->quote($name) . ', '
            . $this->db->quote($value) . ') ON DUPLICATE KEY UPDATE `value` = ' . $this->db->quote($value))->execute();
    }

    /** Take a named lock until now + $seconds; false when someone else holds it. */
    private function claim(string $name, int $now, int $seconds): bool
    {
        $this->db->setQuery('INSERT IGNORE INTO `#__mertools_state` (`name`, `value`) VALUES (' . $this->db->quote($name) . ', ' . $this->db->quote('0') . ')')->execute();
        $this->db->setQuery('UPDATE `#__mertools_state` SET `value` = ' . $this->db->quote((string) ($now + $seconds))
            . ' WHERE `name` = ' . $this->db->quote($name) . ' AND CAST(`value` AS UNSIGNED) < ' . $now)->execute();

        return (int) $this->db->getAffectedRows() === 1;
    }

    private function release(string $name): void
    {
        $this->setState($name, '0');
    }
}
