<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/History.php';

use Merserwis\Plugin\System\MerTools\Tool\History;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

// tasks
$check('editor save tracked', History::tracked('editor.gridboxAjaxSave'), true);
$check('list unpublish tracked', History::tracked('pages.unpublish'), true);
$check('read task not tracked', History::tracked('editor.getItems'), false);
$check('trash delete tracked', History::tracked('trashed.delete'), true);
$check('duplicate creates', History::creates('apps.duplicate'), true);
$check('context duplicate creates', History::creates('pages.contextDuplicate'), true);
$check('publish does not create', History::creates('apps.publish'), false);
$check('new page creates', History::creates('gridbox.createPage'), true);
$check('new page tracked', History::tracked('gridbox.createPage'), true);
$check('new page is no copy', History::duplicates('gridbox.createPage'), false);
$check('duplicate is a copy', History::duplicates('apps.contextDuplicate'), true);

// pages named by a request
$check('editor obj page', History::requestIds(['obj' => json_encode(['page' => ['id' => 1021], 'items' => []])]), [1021]);
$check('editor layout (edit_type) skipped', History::requestIds(['obj' => json_encode(['edit_type' => 'post-layout', 'page' => ['id' => 3]])]), []);
$check('editor header skipped', History::requestIds(['obj' => json_encode(['edit_type' => 'header', 'page' => ['id' => 5]])]), []);
$check('cid rows', History::requestIds(['cid' => ['5', '7', '5', '0', 'x']]), [5, 7]);
$check('context item + id', History::requestIds(['id' => 3, 'context-item' => 9]), [3, 9]);
$check('broken obj ignored', History::requestIds(['obj' => '{not json', 'id' => 4]), [4]);
$check('nothing', History::requestIds([]), []);
$check('at most 500', count(History::requestIds(['cid' => range(1, 900)])), 500);

// what changed
$base = ['id' => 1, 'title' => 'A', 'published' => '1', 'page_category' => '10', 'saved_time' => 't1', 'crc_content' => '111', 'crc_fields' => '5', 'product_price' => '100'];
$check('nothing changed (list)', History::diff($base, $base), null);
$check('nothing changed (editor save)', History::diff($base, ['saved_time' => 't2'] + $base, true), ['action' => 'saved', 'changes' => []]);
$check('created', History::diff(null, $base), ['action' => 'created', 'changes' => []]);
$check('deleted', History::diff($base, null), ['action' => 'deleted', 'changes' => []]);
$check('both missing', History::diff(null, null), null);
$check('title', History::diff($base, ['title' => 'B'] + $base), ['action' => 'edited', 'changes' => [['f' => 'title', 'from' => 'A', 'to' => 'B']]]);
$check('unpublished only', History::diff($base, ['published' => '0'] + $base)['action'], 'unpublished');
$check('published only', History::diff(['published' => '0'] + $base, $base)['action'], 'published');
$check('published + title = edited', History::diff($base, ['published' => '0', 'title' => 'B'] + $base)['action'], 'edited');
$check('trashed', History::diff($base, ['page_category' => 'trashed'] + $base)['action'], 'trashed');
$check('restored', History::diff(['page_category' => 'trashed'] + $base, $base)['action'], 'restored');
$check('content = flag only', History::diff($base, ['crc_content' => '222'] + $base)['changes'], [['f' => 'content']]);
$check('price', History::diff($base, ['product_price' => '120'] + $base)['changes'], [['f' => 'product_price', 'from' => '100', 'to' => '120']]);
$check('fields flag', History::diff($base, ['crc_fields' => '6'] + $base)['changes'], [['f' => 'fields']]);
$check('saved_time alone is not a change', History::diff($base, ['saved_time' => 'x'] + $base), null);
$check('long value cut', mb_strlen(History::diff($base, ['title' => str_repeat('ż', 300)] + $base)['changes'][0]['to']), 200);
$check('null = empty', History::diff(['title' => null] + $base, ['title' => ''] + $base), null);
$check('new key not in before ignored', History::diff($base, ['crc_tags' => '9'] + $base), null);

// pages added while a request ran: only those the request made
$page = fn (string $crc, int $app, string $title = 'X', string $cat = '10') => ['crc_content' => $crc, 'app_id' => $app, 'title' => $title, 'page_category' => $cat];
$check('copy of one page', History::ownNew([20 => $page('111', 2)], [5 => $page('111', 2)], null), [20 => 5]);
$check('copy + a page somebody else made', History::ownNew([20 => $page('111', 2), 21 => $page('999', 2)], [5 => $page('111', 2)], null), [20 => 5]);
$check('somebody else copied the same page too', History::ownNew([20 => $page('111', 2), 21 => $page('111', 2)], [5 => $page('111', 2)], null), []);
$check('two pages copied', History::ownNew([20 => $page('111', 2), 21 => $page('222', 2)], [5 => $page('111', 2), 6 => $page('222', 2)], null), [20 => 5, 21 => 6]);
$check('two same pages copied: no source', History::ownNew([20 => $page('111', 2), 21 => $page('111', 2)], [5 => $page('111', 2), 6 => $page('111', 2)], null), [20 => 0, 21 => 0]);
$check('same content, other app', History::ownNew([20 => $page('111', 3)], [5 => $page('111', 2)], null), []);
$check('nothing added', History::ownNew([], [5 => $page('111', 2)], null), []);
$asked = ['title' => 'Nowy produkt', 'app_id' => 2, 'page_category' => '10'];
$check('new page asked for', History::ownNew([30 => $page('1', 2, 'Nowy produkt'), 31 => $page('2', 2, 'Inny')], [], $asked), [30 => 0]);
$check('new page, other category', History::ownNew([30 => $page('1', 2, 'Nowy produkt', '11')], [], $asked), []);
$check('two new pages of that title: not clear', History::ownNew([30 => $page('1', 2, 'Nowy produkt'), 31 => $page('2', 2, 'Nowy produkt')], [], $asked), []);
$check('single page (no category)', History::ownNew([40 => $page('1', 0, 'O nas', '')], [], ['title' => 'O nas', 'app_id' => 0, 'page_category' => '']), [40 => 0]);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
