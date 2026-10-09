<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Database indexes for Gridbox's tables. Gridbox creates its tables with a primary key only, so the
 * lookups it makes on every product, list and cart page (product data by product, categories and tags
 * by page, cart products by cart) read whole tables: on merserwis.pl a query of Better Search took
 * 6 s, 0.07 s with the index. Plain (non-unique) indexes: no data is changed, Gridbox works the same.
 *
 * Kept after Gridbox updates: checked after Gridbox or MerTools is installed or updated, once a day
 * and from the button in the settings; missing ones are created again. An index Gridbox may add one
 * day on the same column counts as present (no second one). Uses SHOW TABLES / SHOW INDEX only:
 * hosting accounts often cannot read information_schema.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class DbIndexes
{
    /** table, index name, column */
    public const LIST = [
        ['#__gridbox_store_product_data', 'idx_mt_product_id', 'product_id'],
        ['#__gridbox_store_product_variations_map', 'idx_mt_product_id', 'product_id'],
        ['#__gridbox_store_cart_products', 'idx_mt_cart_id', 'cart_id'],
        ['#__gridbox_category_page_map', 'idx_mt_page_id', 'page_id'],
        ['#__gridbox_category_page_map', 'idx_mt_category_id', 'category_id'],
        ['#__gridbox_tags_map', 'idx_mt_page_id', 'page_id'],
        ['#__gridbox_tags_map', 'idx_mt_tag_id', 'tag_id'],
        ['#__gridbox_page_fields', 'idx_mt_page_id', 'page_id'],
    ];

    /** the Gridbox extensions whose install or update triggers a check */
    public const GRIDBOX_ELEMENTS = ['com_gridbox', 'pkg_gridbox'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The state of every index: 'ok' (ours or another index starting with the column), 'missing',
     * or 'no-table' (that part of Gridbox is not installed: nothing to do).
     *
     * @return array<int, array{table: string, name: string, column: string, state: string, by: string}>
     */
    public function status(): array
    {
        $out    = [];
        $tables = [];
        foreach (self::LIST as [$table, $name, $column]) {
            $real = $this->db->replacePrefix($table);
            $tables[$real] ??= $this->tableExists($real);
            $by    = $tables[$real] ? $this->indexOn($real, $column) : '';
            $out[] = ['table' => $real, 'name' => $name, 'column' => $column,
                'state' => !$tables[$real] ? 'no-table' : ($by !== '' ? 'ok' : 'missing'), 'by' => $by];
        }

        return $out;
    }

    /**
     * Creates the missing indexes.
     *
     * @return array{created: string[], failed: array<string, string>, status: array}
     */
    public function ensure(): array
    {
        $created = $failed = [];
        $status  = $this->status();
        foreach ($status as $row) {
            if ($row['state'] !== 'missing') {
                continue;
            }
            $label = $row['table'] . '.' . $row['column'];
            try {
                $this->db->setQuery('ALTER TABLE ' . $this->db->quoteName($row['table']) . ' ADD INDEX '
                    . $this->db->quoteName($row['name']) . ' (' . $this->db->quoteName($row['column']) . ')')->execute();
                $created[] = $label;
            } catch (\Throwable $e) {
                $failed[$label] = $e->getMessage();
            }
        }

        return ['created' => $created, 'failed' => $failed, 'status' => $created || $failed ? $this->status() : $status];
    }

    /** Is this one of Gridbox's extensions (its install or update can recreate its tables)? */
    public static function isGridbox(string $element): bool
    {
        return \in_array(strtolower($element), self::GRIDBOX_ELEMENTS, true);
    }

    private function tableExists(string $table): bool
    {
        // LIKE with the underscores escaped: "_" would match any character
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);

        return (bool) $this->db->setQuery('SHOW TABLES LIKE ' . $this->db->quote($like))->loadResult();
    }

    /** The name of an index whose first column is $column ('' if there is none). */
    private function indexOn(string $table, string $column): string
    {
        foreach ($this->db->setQuery('SHOW INDEX FROM ' . $this->db->quoteName($table))->loadAssocList() ?: [] as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ((int) ($row['seq_in_index'] ?? 0) === 1 && strcasecmp((string) ($row['column_name'] ?? ''), $column) === 0) {
                return (string) ($row['key_name'] ?? '');
            }
        }

        return '';
    }
}
