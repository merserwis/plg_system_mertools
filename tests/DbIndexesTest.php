<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/DbIndexes.php';

use Merserwis\Plugin\System\MerTools\Tool\DbIndexes;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$check('Gridbox component', DbIndexes::isGridbox('com_gridbox'), true);
$check('Gridbox package (any case)', DbIndexes::isGridbox('pkg_Gridbox'), true);
$check('other extension', DbIndexes::isGridbox('com_content'), false);
$check('Gridbox system plugin is not the trigger (the package is)', DbIndexes::isGridbox('gridbox'), false);

$names = $pairs = [];
foreach (DbIndexes::LIST as [$table, $name, $column]) {
    $names[]                 = $name;
    $pairs[$table . '.' . $column] = ($pairs[$table . '.' . $column] ?? 0) + 1;
    $check("$table: Joomla prefix", str_starts_with($table, '#__gridbox_'), true);
    $check("$name: our prefix", str_starts_with($name, 'idx_mt_'), true);
    $check("$column: plain column name", (bool) preg_match('/^[a-z_]+$/', $column), true);
}
$check('8 indexes', count(DbIndexes::LIST), 8);

// an index found in the rows of SHOW INDEX (read once per table)
$show = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => '1', 'Column_name' => 'id'],
    ['Key_name' => 'idx_mt_page_id', 'Seq_in_index' => '1', 'Column_name' => 'page_id'],
    ['Key_name' => 'gb_both', 'Seq_in_index' => '1', 'Column_name' => 'Category_ID'],
    ['Key_name' => 'gb_both', 'Seq_in_index' => '2', 'Column_name' => 'page_id'],
    ['Key_name' => 'only_second', 'Seq_in_index' => '2', 'Column_name' => 'tag_id'],
];
$check('our index', DbIndexes::indexOn($show, 'page_id'), 'idx_mt_page_id');
$check('another index starting with the column (any case)', DbIndexes::indexOn($show, 'category_id'), 'gb_both');
$check('column only second in an index: missing', DbIndexes::indexOn($show, 'tag_id'), '');
$check('no rows', DbIndexes::indexOn([], 'page_id'), '');
$check('no table/column twice', max($pairs), 1);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
