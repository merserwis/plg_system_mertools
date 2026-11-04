<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The order of the Gridbox lists in the administrator: the pages a user pinned first, then the list
 * sorted by a column MerTools added (a field of the app, the sale price, the tags…) or as Gridbox
 * sorts it. Gridbox builds the query of its lists itself and lets them be sorted only by its own
 * columns; MerTools changes the ORDER BY of that query (see Gridbox\ListFactory), so the order holds
 * over every page of the list, not only for the rows shown. The values sorted by are selected as
 * columns of their own (mt_pin, mt_sort, mt_empty): Gridbox's query is SELECT DISTINCT, which allows
 * no ORDER BY on an expression that is not selected. Empty values are always last.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ListSort
{
    /** pinned pages kept per list, at most */
    public const MAX_PINS = 50;

    /** The key of an added column that can be sorted by. */
    public static function sortable(string $key): bool
    {
        return (bool) preg_match('/^(field_\d{1,9}|sale_price|min|created|end_publishing|tags|badges|variants|creator)$/', $key);
    }

    /**
     * The SQL value a column sorts by, for the page p of Gridbox's query; null = it cannot be sorted by.
     *
     * @param array $field     the Gridbox field of a "field_<id>" column (field_type, options)
     * @param callable(string): string $quote  the database's quote()
     * @param bool  $history   the table of the edit history exists (the column "Author" reads it)
     */
    public static function expression(string $key, array $field, callable $quote, bool $history = false): ?string
    {
        $product = static fn (string $column) => '(SELECT d.' . $column . ' FROM `#__gridbox_store_product_data` AS d WHERE d.product_id = p.id ORDER BY d.id LIMIT 1)';
        $date    = static fn (string $column) => 'CASE WHEN p.' . $column . ' IS NULL OR p.' . $column . ' < ' . $quote('1000-01-01') . ' THEN NULL ELSE p.' . $column . ' END';
        switch ($key) {
            case 'sale_price':
            case 'min':
                return 'CAST(NULLIF(TRIM(' . $product($key) . '), ' . $quote('') . ') AS DECIMAL(20,4))';
            case 'created':
            case 'end_publishing':
                return $date($key);
            case 'tags':
                return '(SELECT GROUP_CONCAT(t.title ORDER BY t.title SEPARATOR ' . $quote(', ') . ') FROM `#__gridbox_tags_map` AS tm'
                    . ' INNER JOIN `#__gridbox_tags` AS t ON t.id = tm.tag_id WHERE tm.page_id = p.id)';
            case 'badges':
                return '(SELECT GROUP_CONCAT(COALESCE(NULLIF(b.title, ' . $quote('') . '), ' . $quote('%') . ') ORDER BY bm.order_list, bm.id SEPARATOR ' . $quote(', ') . ')'
                    . ' FROM `#__gridbox_store_badges_map` AS bm INNER JOIN `#__gridbox_store_badges` AS b ON b.id = bm.badge_id WHERE bm.product_id = p.id)';
            case 'variants':
                $v = $product('variations');

                return 'CASE WHEN ' . $v . ' IS NULL THEN NULL WHEN TRIM(' . $v . ') IN (' . $quote('') . ', ' . $quote('[]') . ', ' . $quote('{}') . ') THEN 0 ELSE 1 END';
            case 'creator':
                // the user who sent the page from the site, else the first one the history knows
                $user = '(SELECT u.name FROM `#__users` AS u WHERE u.id = p.user_id AND p.user_id > 0)';
                if (!$history) {
                    return $user;
                }

                return 'COALESCE(' . $user . ', (SELECT COALESCE(hu.name, h.user_name) FROM `#__mertools_history` AS h LEFT JOIN `#__users` AS hu ON hu.id = h.user_id'
                    . ' WHERE h.page_id = p.id ORDER BY h.action IN (' . $quote('created') . ', ' . $quote('duplicated') . ') DESC, h.id LIMIT 1))';
        }
        if (!preg_match('/^field_(\d{1,9})$/', $key, $m)) {
            return null;
        }
        $value = '(SELECT pf.value FROM `#__gridbox_page_fields` AS pf WHERE pf.page_id = p.id AND pf.field_id = ' . (int) $m[1] . ' ORDER BY pf.id LIMIT 1)';
        $empty = $quote('');
        $type  = (string) ($field['field_type'] ?? '');
        switch ($type) {
            case 'number':
            case 'price':
                return 'CAST(NULLIF(REPLACE(TRIM(' . $value . '), ' . $quote(',') . ', ' . $quote('.') . '), ' . $empty . ') AS DECIMAL(20,4))';
            case 'select':
            case 'radio':
            case 'checkbox':
                // by the title of the chosen option, as the list shows it; ticked options (a JSON list of
                // keys) by the first of them, the one the list shows first; an empty list is empty
                $options = json_decode((string) ($field['options'] ?? ''), true);
                $title   = static function (string $key) use ($options, $quote): string {
                    $cases = '';
                    foreach ((array) ($options['items'] ?? []) as $item) {
                        if (\is_array($item) && isset($item['key'])) {
                            $cases .= ' WHEN ' . $quote((string) $item['key']) . ' THEN ' . $quote((string) ($item['title'] ?? $item['key']));
                        }
                    }

                    return $cases === '' ? $key : 'CASE ' . $key . $cases . ' ELSE ' . $key . ' END';
                };
                if ($type !== 'checkbox') {
                    return 'NULLIF(' . $title('TRIM(' . $value . ')') . ', ' . $empty . ')';
                }

                return 'NULLIF(CASE WHEN JSON_VALID(' . $value . ') THEN CASE WHEN JSON_TYPE(' . $value . ') = ' . $quote('ARRAY') . ' THEN '
                    . $title('JSON_UNQUOTE(JSON_EXTRACT(' . $value . ', ' . $quote('$[0]') . '))') . ' ELSE ' . $title('TRIM(' . $value . ')') . ' END'
                    . ' ELSE ' . $title('TRIM(' . $value . ')') . ' END, ' . $empty . ')';
            case 'url':
                // by the label of the link, else its address, as the list shows it
                $label = 'JSON_EXTRACT(' . $value . ', ' . $quote('$.label') . ')';

                return 'NULLIF(CASE WHEN JSON_VALID(' . $value . ') THEN CASE WHEN JSON_TYPE(' . $value . ') NOT IN (' . $quote('OBJECT') . ', ' . $quote('ARRAY') . ') THEN ' . $value
                    . ' WHEN ' . $label . ' IS NOT NULL AND JSON_TYPE(' . $label . ') <> ' . $quote('NULL') . ' THEN JSON_UNQUOTE(' . $label . ')'
                    . ' ELSE JSON_UNQUOTE(JSON_EXTRACT(' . $value . ', ' . $quote('$.link') . ')) END ELSE ' . $value . ' END, ' . $empty . ')';
        }

        return 'NULLIF(TRIM(' . $value . '), ' . $empty . ')';
    }

    /**
     * Gridbox's list query with the pinned pages first and, with an expression, sorted by it (else in
     * Gridbox's order). Only a query of the pages table (alias p) is changed. Returns whether it was.
     *
     * @param object   $query  Joomla's DatabaseQuery (read: from, order; clear(), select(), order())
     * @param int[]    $pins   ids, the first shown first
     */
    public static function apply(object $query, array $pins, ?string $expression, string $dir): bool
    {
        $from = strtolower(preg_replace('/\s+/', ' ', (string) $query->from) ?? '');
        if (!preg_match('/#__gridbox_pages`? as p\b/', $from)) {
            return false;
        }
        $pins = array_values(array_unique(array_filter(array_map('intval', $pins), static fn ($id) => $id > 0)));
        if (!$pins && $expression === null) {
            return false;
        }
        $old = $query->order;
        $old = \is_object($old) && method_exists($old, 'getElements') ? $old->getElements() : [];
        $query->clear('order');
        if ($pins) {
            // FIELD() counts from the first id given: the first pinned page gets the highest number
            $query->select('FIELD(p.id, ' . implode(',', array_reverse(\array_slice($pins, 0, self::MAX_PINS))) . ') AS mt_pin');
            $query->order('mt_pin DESC');
        }
        if ($expression !== null) {
            $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
            $query->select('(' . $expression . ') AS mt_sort');
            $query->select('((' . $expression . ') IS NULL) AS mt_empty');
            $query->order('mt_empty ASC');
            $query->order('mt_sort ' . $dir);
            $query->order('p.id ' . $dir);
        } elseif ($old) {
            $query->order($old);
        }

        return true;
    }

    /** The pins of a list with a page pinned (first: the newest pin is shown first) or unpinned. */
    public static function pin(array $pins, int $id, bool $pinned): array
    {
        $pins = array_values(array_filter(array_map('intval', $pins), static fn ($p) => $p > 0 && $p !== $id));
        if ($pinned) {
            array_unshift($pins, $id);
        }

        return \array_slice($pins, 0, self::MAX_PINS);
    }
}
