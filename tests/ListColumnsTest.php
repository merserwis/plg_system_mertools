<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ListColumns.php';

use Merserwis\Plugin\System\MerTools\Tool\ListColumns;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

$html   = file_get_contents(__DIR__ . '/fixtures/gridbox-list.html');
$bounds = ListColumns::tableBounds($html);
$check('table found', is_array($bounds), true);
$table = substr($html, $bounds[0], $bounds[1] - $bounds[0]);
$head  = ListColumns::headCells($table);
$check('head keys', array_column($head, 'key'), ['select', 'published', 'title', 'price', 'stock', 'sku', 'page_category', 'hits', 'id']);
$check('labels without tooltip', array_column($head, 'label')[3], 'Price');
$check('status label without filter', array_column($head, 'label')[1], 'Status');

$builtin = array_column($head, 'key');
$check('no prefs = Gridbox order', ListColumns::visible($builtin, ['sale_price'], null), $builtin);
$check('saved order', ListColumns::visible($builtin, ['sale_price'], ['id', 'title', 'sale_price', 'price'], $builtin), ['select', 'id', 'title', 'sale_price', 'price']);
$check('title always shown', ListColumns::visible($builtin, [], ['price'], $builtin), ['select', 'title', 'price']);
$check('unknown keys dropped', ListColumns::visible($builtin, [], ['title', 'nope', 'select'], $builtin), ['select', 'title']);
$check('new Gridbox column shown after title', ListColumns::visible($builtin, [], ['title', 'price'], array_diff($builtin, ['sku'])), ['select', 'title', 'sku', 'price']);

$out = ListColumns::apply($table, ['select', 'title', 'sale_price', 'price', 'id'], ['sale_price' => 'Sale price'],
    fn ($key, $id) => 'S' . $id, fn ($key, $cell, $id) => $key === 'price' ? ListColumns::withAttributes($cell, ['data-mt-edit' => 'price', 'data-mt-id' => $id]) : $cell);
$newHead = ListColumns::headCells($out);
$check('new head', array_column($newHead, 'key'), ['select', 'title', 'col2', 'price', 'id']);
$check('added head label', $newHead[2]['label'], 'Sale price');
preg_match_all('#<tr>(.*?)</tr>#s', substr($out, strpos($out, '<tbody')), $rows);
preg_match_all('#<td\b[^>]*>#', $rows[1][0], $tds);
$check('row has 5 cells', count($tds[0]), 5);
$check('first cell select', str_contains($tds[0][0], 'select-td'), true);
$check('title cell 2nd', str_contains($tds[0][1], 'title-cell'), true);
$check('added cell 3rd', str_contains($tds[0][2], 'mt-col-sale_price'), true);
$check('price decorated', str_contains($tds[0][3], 'data-mt-edit="price"'), true);
$check('cid kept', substr_count($out, 'name="cid[]"'), 3);
$check('row with other cell count untouched', ListColumns::apply('<table><thead><tr><th>a</th></tr></thead><tbody><tr><td><input name="cid[]" value="1"></td><td>x</td></tr></tbody></table>',
    ['col0'], [], fn () => '', fn ($k, $c) => $c) === '<table><thead><tr><th>a</th></tr></thead><tbody><tr><td><input name="cid[]" value="1"></td><td>x</td></tr></tbody></table>', true);

// typed values
$check('money comma', ListColumns::clean('money', '1234,5'), '1234.5');
$check('money grouped', ListColumns::clean('money', '1.234,50'), '1234.5');
$check('money en grouped', ListColumns::clean('money', '1,234.50'), '1234.5');
$check('money spaces', ListColumns::clean('money', '12 345,00'), '12345');
$check('money integer', ListColumns::clean('money', '8603'), '8603');
$check('money empty (sale)', ListColumns::clean('money', ''), '');
$check('money bad', ListColumns::clean('money', '12a'), null);
$check('money 3 decimals', ListColumns::clean('money', '1,234'), null);
$check('money negative', ListColumns::clean('money', '-5'), null);
$check('int', ListColumns::clean('int', ' 7 '), '7');
$check('int bad', ListColumns::clean('int', '7.5'), null);
$check('text', ListColumns::clean('text', ' ABC/1 '), 'ABC/1');
$check('text control char', ListColumns::clean('text', "a\x01"), null);
$check('money format', ListColumns::money('1234.5', ['symbol' => 'zł', 'position' => '', 'thousand' => ' ', 'separator' => ',', 'decimals' => 2]), '1 234,50 zł');
$check('money format left', ListColumns::money('9', ['symbol' => '€', 'position' => 'left-currency-position', 'thousand' => '.', 'separator' => ',', 'decimals' => 2]), '€ 9,00');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
