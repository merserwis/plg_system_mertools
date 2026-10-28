<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Columns of the Gridbox lists (products, posts, pages) chosen by each user: Gridbox's own columns
 * shown, hidden or moved, and columns added by MerTools (sale price, minimum, dates, tags, the last
 * change, the fields of the app…). The finished list page is rewritten on the server: the cells of
 * the head and of every row are put in the chosen order, so the list never flickers. Gridbox's list
 * script finds its cells by class (select-td, title-cell…), never by position, so this is safe;
 * a row whose cells do not match the head is left as Gridbox made it. The rows of products shown in a
 * folder that is not their main one ("ghosts", in a tbody of their own) are rearranged the same way.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ListColumns
{
    /** always shown: the tick box first, then the title wherever the user puts it */
    public const LOCKED = ['select', 'title'];

    /** Product fields that can be edited in the list: key => kind */
    public const EDITABLE = ['price' => 'money', 'sale_price' => 'money', 'stock' => 'int', 'sku' => 'text'];

    /**
     * The list table of the page: [start, end] of <table…>…</table> after "main-table", or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function tableBounds(string $html): ?array
    {
        $main = strpos($html, 'class="main-table');
        if ($main === false) {
            return null;
        }
        $start = strpos($html, '<table', $main);
        $end   = $start === false ? false : strpos($html, '</table>', $start);
        if ($start === false || $end === false) {
            return null;
        }

        return [$start, $end + 8];
    }

    /**
     * Keys and labels of the head cells: the column Gridbox sorts by (data-sorting), "select" for the
     * tick box, "col<n>" for any other.
     *
     * @return array<int, array{key: string, label: string, html: string}>
     */
    public static function headCells(string $table): array
    {
        if (!preg_match('#<thead\b[^>]*>.*?<tr\b[^>]*>(.*?)</tr>#s', $table, $m) || !preg_match_all('#<th\b.*?</th>#s', $m[1], $cells)) {
            return [];
        }
        $out = [];
        foreach ($cells[0] as $i => $cell) {
            if (preg_match('/data-sorting="([a-z_]+)"/', $cell, $k)) {
                $key = $k[1];
            } else {
                $key = $i === 0 && str_contains($cell, 'checkall') ? 'select' : 'col' . $i;
            }
            // the label without the tooltip ("Sort by this column") and the state filter of Status
            // without the tooltip and the filter Gridbox keeps in some heads (state, author, language: its list repeats the label)
            $label = preg_replace('#<span class="ba-tooltip.*?</span>|<div class="[a-z]+-filter\b.*$#s', '', $cell) ?? $cell;
            $label = trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($label)) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $out[] = ['key' => $key, 'label' => $label, 'html' => $cell];
        }

        return $out;
    }

    /**
     * The visible columns in their order: the saved order (unknown keys dropped), else Gridbox's own.
     * The tick box is always first, the title always shown; a Gridbox column added by a later Gridbox
     * version (not in the saved order) is shown after the title until the user hides it.
     *
     * @param string[] $builtin  Gridbox's columns, in Gridbox's order
     * @param string[] $extra    the columns MerTools can add
     * @param string[]|null $saved
     *
     * @return string[]
     */
    public static function visible(array $builtin, array $extra, ?array $saved, array $knownWhenSaved = []): array
    {
        if (!$saved) {
            return $builtin;
        }
        $all   = array_merge($builtin, $extra);
        $order = array_values(array_unique(array_filter($saved, static fn ($k) => \is_string($k) && \in_array($k, $all, true) && $k !== 'select')));
        if (!\in_array('title', $order, true)) {
            array_unshift($order, 'title');
        }
        foreach ($builtin as $key) {
            if ($key !== 'select' && !\in_array($key, $order, true) && $knownWhenSaved && !\in_array($key, $knownWhenSaved, true)) {
                array_splice($order, array_search('title', $order, true) + 1, 0, [$key]);
            }
        }

        return \in_array('select', $builtin, true) ? array_merge(['select'], $order) : $order;
    }

    /**
     * The table with the chosen columns.
     *
     * @param string[] $visible
     * @param array<string, string> $extraLabels  key => label of the added columns
     * @param callable(string $key, int $id): string $extraCell  inner HTML of an added cell
     * @param callable(string $key, string $cell, int $id): string $decorate  a Gridbox cell, changed or not
     */
    public static function apply(string $table, array $visible, array $extraLabels, callable $extraCell, callable $decorate): string
    {
        $head = self::headCells($table);
        if (!$head) {
            return $table;
        }
        $keys  = array_column($head, 'key');
        $byKey = array_combine($keys, array_column($head, 'html'));

        $headHtml = '';
        foreach ($visible as $key) {
            if (isset($byKey[$key])) {
                $headHtml .= $byKey[$key];
            } elseif (isset($extraLabels[$key])) {
                $headHtml .= '<th class="mt-col-head mt-col-' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"><span>' . htmlspecialchars($extraLabels[$key], ENT_QUOTES, 'UTF-8') . '</span></th>';
            }
        }
        $table = preg_replace_callback('#(<thead\b[^>]*>.*?<tr\b[^>]*>)(.*?)(</tr>)#s', static fn ($m) => $m[1] . $headHtml . $m[3], $table, 1) ?? $table;

        return preg_replace_callback('#(<tbody\b[^>]*>)(.*?)(</tbody>)#s', static function ($body) use ($keys, $visible, $extraLabels, $extraCell, $decorate): string {
            $rows = preg_replace_callback('#(<tr\b[^>]*>)(.*?)(</tr>)#s', static function ($row) use ($keys, $visible, $extraLabels, $extraCell, $decorate): string {
                if (!preg_match_all('#<td\b.*?</td>#s', $row[2], $cells) || \count($cells[0]) !== \count($keys)
                    || !preg_match('/name="cid\[\]" value="(\d+)"|data-mt-ghost="(\d+)"/', $row[2], $id)) {
                    return $row[0];
                }
                $id     = [1 => (int) ($id[1] ?: ($id[2] ?? 0))];
                $cellBy = array_combine($keys, $cells[0]);
                $out    = '';
                foreach ($visible as $key) {
                    if (isset($cellBy[$key])) {
                        $out .= $decorate($key, $cellBy[$key], (int) $id[1]);
                    } elseif (isset($extraLabels[$key])) {
                        $out .= $decorate($key, '<td class="mt-col mt-col-' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $extraCell($key, (int) $id[1]) . '</td>', (int) $id[1]);
                    }
                }

                return $row[1] . $out . $row[3];
            }, $body[2]) ?? $body[2];

            return $body[1] . $rows . $body[3];
        }, $table) ?? $table;
    }

    /**
     * Is this the last page of the list? Read from Gridbox's "1 - 20 / 26 items" under the table
     * (the HTML after the table); yes when it cannot be read.
     */
    public static function lastPage(string $after): bool
    {
        $nav = strpos($after, 'pagination__wrapper');
        if ($nav === false || !preg_match('#(\d+)\s*-\s*(\d+)\s*/\s*(\d+)#', strip_tags(substr($after, $nav, 1500)), $m)) {
            return true;
        }

        return (int) $m[2] >= (int) $m[3];
    }

    /** Attributes added to the opening tag of a cell. */
    public static function withAttributes(string $cell, array $attributes): string
    {
        $attr = '';
        foreach ($attributes as $name => $value) {
            $attr .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return preg_replace('/^<td\b/', '<td' . $attr, $cell, 1) ?? $cell;
    }

    /**
     * A value typed in the list, checked and in the form Gridbox keeps: money "12.5" / "" (no sale
     * price), stock "7" / "" (not counted), SKU as text. null = not accepted. Money takes as many
     * decimals as the store's currency shows (2 at least, 4 at most).
     */
    public static function clean(string $kind, string $value, int $decimals = 2): ?string
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
        if ($kind === 'text') {
            return mb_strlen($value) <= 255 && !preg_match('/[\x00-\x1F]/', $value) ? $value : null;
        }
        if ($value === '') {
            return '';
        }
        $value = str_replace(' ', '', $value);
        if ($kind === 'int') {
            return preg_match('/^\d{1,9}$/', $value) ? (string) (int) $value : null;
        }
        // money: 1234,5 / 1234.50 / 1.234,50 / 1,234.50
        $places = max(2, min(4, $decimals));
        if (preg_match('/^\d{1,3}([.,])\d{3}([.,])\d{1,' . $places . '}$/', $value, $g) && $g[1] !== $g[2]) {
            $value = str_replace($g[1], '', $value);
        }
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^\d{1,10}(\.\d{1,' . $places . '})?$/', $value)) {
            return null;
        }
        $number = rtrim(rtrim(number_format((float) $value, $places, '.', ''), '0'), '.');

        return $number === '' ? '0' : $number;
    }

    /** A discount typed as a percentage ("10%", "12,5 %"): the number (0–99.99), else null. */
    public static function percent(string $value): ?float
    {
        if (!preg_match('/^\s*(\d{1,2}(?:[.,]\d{1,2})?)\s*%\s*$/u', $value, $m)) {
            return null;
        }

        return (float) str_replace(',', '.', $m[1]);
    }

    /** A price as Gridbox shows it in its lists (the store's currency settings). */
    public static function money(string $value, array $currency): string
    {
        if ($value === '') {
            return '';
        }
        $number = number_format((float) $value, max(0, min(4, (int) ($currency['decimals'] ?? 2))), (string) ($currency['separator'] ?? ','),
            (string) ($currency['thousand'] ?? ' '));
        $symbol = trim((string) ($currency['symbol'] ?? ''));
        if ($symbol === '') {
            return $number;
        }

        return ($currency['position'] ?? '') === 'left-currency-position' ? $symbol . ' ' . $number : $number . ' ' . $symbol;
    }
}
