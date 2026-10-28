<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The price history behind Omnibus (see there for the rules).
 *
 * #__mertools_omnibus_log: one row per change of the prices of an item (a product or a version):
 * regular and sale price from that moment on. Kept two years, and of the older rows the last of each item.
 * #__mertools_omnibus: the state of each item — its prices, since when, and while it is on sale the
 * moment of the reduction, the lowest price of the 30 days before it and where that came from:
 * 'history' (MerTools' own history), 'import' (the separate Omnibus plugin, at the start, for the same
 * sale: same regular and sale price), 'regular'
 * (on sale at the start with nothing better known: the regular price), 'new' (a new product offered
 * on sale from its first day: its own sale price, the lowest price since it is offered).
 *
 * sync() compares Gridbox's product data with the state and writes what changed. It runs after a
 * change sent to Gridbox, once an hour and when the settings are saved; the first run (empty state)
 * takes the sale references of the Omnibus plugin, where they belong to the same sale.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class OmnibusStore
{
    public const STATE = '#__mertools_omnibus';
    public const LOG   = '#__mertools_omnibus_log';

    /** the separate Omnibus plugin's state, read once at the start */
    public const PLUGIN_TABLE = '#__omnibus_products';

    /** history older than this goes */
    private const KEEP_DAYS = 730;

    /**
     * @param int[]              $levels the access levels of a guest: Gridbox's sales for other levels change no public price
     * @param \DateTimeZone|null $zone   the site's time zone, in which Gridbox keeps the dates of its sales
     */
    public function __construct(private DatabaseInterface $db, private array $levels = [1], private ?\DateTimeZone $zone = null)
    {
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db   = $this->db;
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::STATE) . ' (`item` VARCHAR(64) NOT NULL, `product_id` INT UNSIGNED NOT NULL,'
            . ' `regular` DECIMAL(15,4) NULL, `sale` DECIMAL(15,4) NULL, `since` DATETIME NOT NULL, `promo_since` DATETIME NULL,'
            . ' `lowest` DECIMAL(15,4) NULL, `source` VARCHAR(8) NULL, `dflt` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`item`), KEY `idx_product` (`product_id`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::LOG) . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `item` VARCHAR(64) NOT NULL,'
            . ' `product_id` INT UNSIGNED NOT NULL, `regular` DECIMAL(15,4) NULL, `sale` DECIMAL(15,4) NULL, `changed` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`id`), KEY `idx_item` (`item`, `changed`), KEY `idx_changed` (`changed`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
    }

    /**
     * Gridbox's product data compared with the state; the changes written.
     *
     * @return array{items: int, changed: int, reductions: int, started: bool, busy?: bool}
     */
    public function sync(?int $now = null): array
    {
        $this->ensureTables();
        $db   = $this->db;
        $lock = $db->quote($db->replacePrefix(self::STATE));
        try {
            // one sync at a time (a save and the hourly check at once would write a change twice)
            if (!(int) $db->setQuery('SELECT GET_LOCK(' . $lock . ', 0)')->loadResult()) {
                return ['items' => 0, 'changed' => 0, 'reductions' => 0, 'started' => false, 'busy' => true];
            }
        } catch (\Throwable $e) {
            $lock = null;
        }
        try {
            return $this->syncLocked($now ?? time());
        } finally {
            if ($lock !== null) {
                $db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->execute();
            }
        }
    }

    private function syncLocked(int $now): array
    {
        $db    = $this->db;
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $items = Omnibus::items($db->setQuery('SELECT product_id, price, sale_price, variations FROM ' . $db->quoteName('#__gridbox_store_product_data'))->loadAssocList() ?: []);
        // Gridbox's own sales (Gridbox → Discounts) lower the prices of products without a sale price of their own
        $local   = $this->localTime($now);
        $sales   = $this->sales();
        $running = array_values(array_filter($sales, static fn ($s) => Omnibus::saleRuns($s, $local)));
        if ($running) {
            $items = Omnibus::withSales($items, $running, $this->productCategories($items, $running), $this->levels);
        }
        $next  = Omnibus::nextSaleChange($sales, $local, $this->zone ?? new \DateTimeZone('UTC'));
        $state = [];
        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName(self::STATE))->loadAssocList() ?: [] as $row) {
            $state[(string) $row['item']] = $row;
        }
        $start   = !$state;
        $plugin  = $start ? $this->pluginReferences() : [];
        $inserts = [];
        $log     = [];
        $changed = 0;
        $reduced = 0;
        foreach ($items as $key => $item) {
            $key = (string) $key;
            [$regular, $sale] = [$item['regular'], $item['sale']];
            $old = $state[$key] ?? null;
            if ($old === null) {
                $row = ['item' => $key, 'product_id' => $item['product'], 'regular' => $regular, 'sale' => $sale, 'since' => $stamp,
                    'promo_since' => null, 'lowest' => null, 'source' => null, 'dflt' => (int) $item['default']];
                if (Omnibus::onSale($regular, $sale)) {
                    $row['promo_since'] = $stamp;
                    if ($start && $regular !== null && isset($plugin[$key]) && abs($plugin[$key]['sale'] - $sale) < 0.005
                        && abs($plugin[$key]['regular'] - $regular) < 0.005) {
                        // the same sale as the plugin knew it; never above the regular price (its data stopped in 2025)
                        [$row['lowest'], $row['source']] = [min($plugin[$key]['lowest'], $regular), 'import'];
                    } elseif ($start && $regular !== null) {
                        [$row['lowest'], $row['source']] = [$regular, 'regular'];
                    } else {
                        [$row['lowest'], $row['source']] = [$sale, 'new'];
                    }
                }
                $inserts[] = $row;
                $log[]     = [$key, $item['product'], $regular, $sale, $stamp];
                continue;
            }
            $oldRegular = Omnibus::amount($old['regular']);
            $oldSale    = Omnibus::amount($old['sale']);
            if ($oldRegular === $regular && $oldSale === $sale) {
                if ((int) $old['dflt'] !== (int) $item['default']) {
                    $this->update($key, ['dflt' => (int) $item['default']]);
                }
                continue;
            }
            $changed++;
            $set = ['regular' => $regular, 'sale' => $sale, 'since' => $stamp, 'product_id' => $item['product'], 'dflt' => (int) $item['default']];
            switch (Omnibus::change($oldRegular, $oldSale, $regular, $sale)) {
                case 'reduction':
                    $reduced++;
                    // the price in force when the 30 days began (the last change at or before then) and
                    // every change since, however many there were
                    $from    = gmdate('Y-m-d H:i:s', $now - Omnibus::WINDOW);
                    $history = $db->setQuery('SELECT regular, sale, changed FROM ' . $db->quoteName(self::LOG) . ' WHERE item = ' . $db->quote($key)
                        . ' AND changed >= COALESCE((SELECT MAX(changed) FROM ' . $db->quoteName(self::LOG) . ' WHERE item = ' . $db->quote($key)
                        . ' AND changed <= ' . $db->quote($from) . '), ' . $db->quote($from) . ') ORDER BY changed DESC, id DESC')->loadAssocList() ?: [];
                    $lowest = Omnibus::lowest($history, $from, $stamp);
                    $set += ['promo_since' => $stamp, 'lowest' => $lowest ?? $sale, 'source' => $lowest !== null ? 'history' : 'new'];
                    break;
                case 'clear':
                    $set += ['promo_since' => null, 'lowest' => null, 'source' => null];
                    break;
            }
            $this->update($key, $set);
            $log[] = [$key, $item['product'], $regular, $sale, $stamp];
        }
        foreach (array_chunk($inserts, 300) as $chunk) {
            $this->insertState($chunk);
        }
        foreach (array_chunk($log, 300) as $chunk) {
            $this->insertLog($chunk);
        }
        // versions and products removed from Gridbox: their state goes, their history stays
        $gone = array_diff(array_keys($state), array_map('strval', array_keys($items)));
        foreach (array_chunk($gone, 300) as $chunk) {
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::STATE) . ' WHERE item IN (' . implode(',', array_map([$db, 'quote'], $chunk)) . ')')->execute();
        }
        if ($start || mt_rand(1, 24) === 1) {
            // of what is older than two years, the last change of each item stays: it holds the price that
            // was in force until its next change (a price kept that long still counts in the 30 days after it)
            $db->setQuery('DELETE l FROM ' . $db->quoteName(self::LOG) . ' l JOIN (SELECT item, MAX(changed) AS last FROM ' . $db->quoteName(self::LOG)
                . ' WHERE changed < ' . $db->quote(gmdate('Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400)) . ' GROUP BY item) k ON k.item = l.item AND l.changed < k.last')->execute();
        }

        return ['items' => \count($items), 'changed' => $changed + \count($inserts) * (int) !$start, 'reductions' => $reduced, 'started' => $start, 'next' => $next];
    }

    /**
     * The items of rows of Gridbox's product data with the prices a guest is shown now: Gridbox's own
     * sales running applied as sync() applies them ('via' = the sale). For the product data (JSON-LD).
     *
     * @return array<string, array<string, mixed>> as Omnibus::items()
     */
    public function shownPrices(array $rows, ?int $now = null): array
    {
        $items   = Omnibus::items($rows);
        $local   = $this->localTime($now ?? time());
        $running = $items ? array_values(array_filter($this->sales(), static fn ($s) => Omnibus::saleRuns($s, $local))) : [];

        return $running ? Omnibus::withSales($items, $running, $this->productCategories($items, $running), $this->levels) : $items;
    }

    /** A UTC timestamp as the site's local time, in which Gridbox keeps and compares the dates of its sales. */
    private function localTime(int $now): string
    {
        return (new \DateTimeImmutable('@' . $now))->setTimezone($this->zone ?? new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Every published sale of Gridbox's store with its products or categories ('map'); none when
     * Gridbox has no store tables.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sales(bool $all = false): array
    {
        $db = $this->db;
        try {
            $sales = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__gridbox_store_sales') . ($all ? '' : ' WHERE published = 1') . ' ORDER BY id')->loadAssocList() ?: [];
            if (!$sales) {
                return [];
            }
            $maps = [];
            foreach ($db->setQuery('SELECT sale_id, item_id, variation FROM ' . $db->quoteName('#__gridbox_store_sales_map') . ' WHERE sale_id IN ('
                . implode(',', array_map(static fn ($s) => (int) $s['id'], $sales)) . ')')->loadAssocList() ?: [] as $map) {
                $maps[(int) $map['sale_id']][] = $map;
            }
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($sales as $i => $sale) {
            $sales[$i]['map'] = $maps[(int) $sale['id']] ?? [];
        }

        return $sales;
    }

    /**
     * For the sales of categories: each product's category and the categories above it.
     *
     * @return array<int, int[]>
     */
    private function productCategories(array $items, array $sales): array
    {
        if (!array_filter($sales, static fn ($s) => (string) $s['applies_to'] === 'category')) {
            return [];
        }
        $db     = $this->db;
        $parent = [];
        foreach ($db->setQuery('SELECT id, parent FROM ' . $db->quoteName('#__gridbox_categories'))->loadRowList() ?: [] as [$id, $up]) {
            $parent[(int) $id] = (int) $up;
        }
        $ids = array_values(array_unique(array_map(static fn ($i) => (int) $i['product'], $items)));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($db->setQuery('SELECT id, page_category FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE id IN (' . implode(',', $chunk) . ')')->loadRowList() ?: [] as [$id, $category]) {
                $path = [];
                for ($c = (int) $category, $guard = 0; $c > 0 && $guard < 50; $c = $parent[$c] ?? 0, $guard++) {
                    $path[] = $c;
                }
                $out[(int) $id] = $path;
            }
        }

        return $out;
    }

    /**
     * Gridbox's sales for the panel, each with its state and whether the Omnibus line follows it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesOverview(int $now): array
    {
        $local = $this->localTime($now);
        $out   = [];
        foreach ($this->sales(true) as $sale) {
            $out[] = $sale + Omnibus::saleState($sale, $local, $this->levels);
        }

        return $out;
    }

    /**
     * The lowest prices to show, for the items of these products that are on sale.
     *
     * @param int[] $productIds
     *
     * @return array<string, array{product: int, lowest: float}>
     */
    public function references(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $this->ensureTables();
        $db   = $this->db;
        $rows = $db->setQuery('SELECT item, product_id, lowest FROM ' . $db->quoteName(self::STATE) . ' WHERE product_id IN (' . implode(',', \array_slice($ids, 0, 500))
            . ') AND sale IS NOT NULL AND lowest IS NOT NULL')->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['item']] = ['product' => (int) $row['product_id'], 'lowest' => (float) $row['lowest']];
        }

        return $out;
    }

    /**
     * The lowest prices for the lists: per product, of the item whose price a list shows (its default
     * version when it has one, else the product), when that item is on sale.
     *
     * @param int[] $productIds
     *
     * @return array<int, float>
     */
    public function listReferences(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $this->ensureTables();
        $db    = $this->db;
        $rows  = $db->setQuery('SELECT item, product_id, dflt, sale, lowest FROM ' . $db->quoteName(self::STATE) . ' WHERE product_id IN ('
            . implode(',', \array_slice($ids, 0, 500)) . ')')->loadAssocList() ?: [];
        // compared here, not in SQL: item (text) against product_id would mix collations on MySQL 8
        $shown = [];
        foreach ($rows as $row) {
            $id = (int) $row['product_id'];
            if ((int) $row['dflt'] === 1) {
                $shown[$id] = $row;
            } elseif ((string) $row['item'] === (string) $id && !isset($shown[$id])) {
                $shown[$id] = $row;
            }
        }
        $out = [];
        foreach ($shown as $id => $row) {
            if ($row['sale'] !== null && $row['lowest'] !== null) {
                $out[$id] = (float) $row['lowest'];
            }
        }

        return $out;
    }

    /** Figures for the settings: items watched, on sale, by source of the reference, the start. */
    public function stats(): array
    {
        $this->ensureTables();
        $db  = $this->db;
        $out = ['items' => 0, 'on_sale' => 0, 'sources' => [], 'changes' => 0, 'first' => null];
        $out['items']   = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::STATE))->loadResult();
        $out['changes'] = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::LOG))->loadResult();
        $out['first']   = $db->setQuery('SELECT MIN(changed) FROM ' . $db->quoteName(self::LOG))->loadResult() ?: null;
        foreach ($db->setQuery('SELECT source, COUNT(*) n FROM ' . $db->quoteName(self::STATE) . ' WHERE sale IS NOT NULL GROUP BY source')->loadAssocList() ?: [] as $row) {
            $out['sources'][(string) $row['source']] = (int) $row['n'];
            $out['on_sale'] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * The items on sale with their reference, for the list in the settings (titles from Gridbox).
     *
     * @return array<int, array<string, mixed>>
     */
    public function onSaleList(int $limit = 2000): array
    {
        $this->ensureTables();
        $db = $this->db;

        return $db->setQuery('SELECT s.item, s.product_id, s.regular, s.sale, s.lowest, s.source, s.promo_since, p.title FROM ' . $db->quoteName(self::STATE) . ' s'
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_pages') . ' p ON p.id = s.product_id WHERE s.sale IS NOT NULL ORDER BY p.title, s.item', 0, $limit)->loadAssocList() ?: [];
    }

    /**
     * The sale references of the separate Omnibus plugin (if it is installed): item => regular, sale,
     * lowest — as the plugin last worked them out (on merserwis.pl its price watching was switched off
     * in its code in April 2025, so only a sale with the same regular and sale price is the same sale).
     *
     * @return array<string, array{regular: float, sale: float, lowest: float}>
     */
    private function pluginReferences(): array
    {
        $db = $this->db;
        try {
            $table = $db->replacePrefix(self::PLUGIN_TABLE);
            if (!\in_array($table, $db->getTableList(), true)) {
                return [];
            }
            $rows = $db->setQuery('SELECT product_or_variation_id AS item, price, sale_price, lowest_price FROM ' . $db->quoteName(self::PLUGIN_TABLE) . ' WHERE on_sale + 0 = 1')->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $regular = Omnibus::amount($row['price']);
            $sale    = Omnibus::amount($row['sale_price']);
            $lowest  = Omnibus::amount($row['lowest_price']);
            if ($regular !== null && $sale !== null && $lowest !== null) {
                $out[(string) $row['item']] = ['regular' => $regular, 'sale' => $sale, 'lowest' => $lowest];
            }
        }

        return $out;
    }

    private function update(string $item, array $set): void
    {
        $db    = $this->db;
        $parts = [];
        foreach ($set as $column => $value) {
            $parts[] = $db->quoteName($column) . ' = ' . ($value === null ? 'NULL' : $db->quote((string) $value));
        }
        $db->setQuery('UPDATE ' . $db->quoteName(self::STATE) . ' SET ' . implode(', ', $parts) . ' WHERE item = ' . $db->quote($item))->execute();
    }

    private function insertState(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $db     = $this->db;
        $values = [];
        foreach ($rows as $r) {
            $values[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $db->quote((string) $v),
                [$r['item'], $r['product_id'], $r['regular'], $r['sale'], $r['since'], $r['promo_since'], $r['lowest'], $r['source'], $r['dflt']])) . ')';
        }
        $db->setQuery('INSERT INTO ' . $db->quoteName(self::STATE) . ' (item, product_id, regular, sale, since, promo_since, lowest, source, dflt) VALUES ' . implode(',', $values)
            . ' ON DUPLICATE KEY UPDATE item = item')->execute();
    }

    private function insertLog(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $db     = $this->db;
        $values = [];
        foreach ($rows as $r) {
            $values[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $db->quote((string) $v), $r)) . ')';
        }
        $db->setQuery('INSERT INTO ' . $db->quoteName(self::LOG) . ' (item, product_id, regular, sale, changed) VALUES ' . implode(',', $values))->execute();
    }
}
