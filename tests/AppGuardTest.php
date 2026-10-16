<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/AppGuard.php';

use Merserwis\Plugin\System\MerTools\Tool\AppGuard;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

$check('X of a card is a deletion', AppGuard::isDeleteTask('pages.deleteGridboxAppItem'), true);
$check('toolbar delete is a deletion', AppGuard::isDeleteTask(' Pages.DeleteApp '), true);
$check('apps.deleteApp is a deletion', AppGuard::isDeleteTask('apps.deleteApp'), true);
$check('trash of pages is not', AppGuard::isDeleteTask('pages.addTrash'), false);
$check('category delete is not', AppGuard::isDeleteTask('apps.deleteCategory'), false);

$check('locked by default', AppGuard::deletable(3, true, []), false);
$check('chosen app may go', AppGuard::deletable(3, true, ['3', '7']), true);
$check('other app stays locked', AppGuard::deletable(4, true, ['3']), false);
$check('protection off', AppGuard::deletable(4, false, []), true);

$check('folder default', AppGuard::folder('', '/srv/site', '/srv/site/administrator'), '/srv/site/administrator/backups/mertools');
$check('folder relative', AppGuard::folder('backups/gb/', '/srv/site', '/srv/site/administrator'), '/srv/site/backups/gb');
$check('folder ./relative', AppGuard::folder('./bk', '/srv/site', '/srv/site/administrator'), '/srv/site/bk');
$check('folder absolute', AppGuard::folder('/home/u/copies', '/srv/site', '/x'), '/home/u/copies');
$check('folder windows', AppGuard::folder('C:\\copies\\', '/srv/site', '/x'), 'C:/copies');

$check('name ok', AppGuard::validName('app-3-sklep-test-20261016-114159-9690b0c9.jsonl.gz'), true);
$check('name without slug', AppGuard::validName('app-12-20261016-114159-9690b0c9.jsonl.gz'), true);
$check('name climbing out', AppGuard::validName('../app-3-x-20261016-114159-9690b0c9.jsonl.gz'), false);
$check('name other file', AppGuard::validName('configuration.php'), false);
$check('name .part', AppGuard::validName('app-3-x-20261016-114159-9690b0c9.jsonl.gz.part'), false);

$check('path css', AppGuard::safePath('components/com_gridbox/assets/css/storage/style-5.css'), true);
$check('path upload', AppGuard::safePath('components/com_gridbox/assets/uploads/app-3/plik ze spacją.pdf'), true);
$check('path climbing', AppGuard::safePath('components/com_gridbox/assets/uploads/../../../../configuration.php'), false);
$check('path elsewhere', AppGuard::safePath('images/a.jpg'), false);
$check('path absolute', AppGuard::safePath('/components/com_gridbox/assets/uploads/a'), false);
$check('path backslash', AppGuard::safePath('components/com_gridbox/assets/uploads/a\\..\\b'), false);
$check('path nul', AppGuard::safePath("components/com_gridbox/assets/uploads/a\0.php"), false);
$check('path double slash', AppGuard::safePath('components/com_gridbox/assets/uploads//a'), false);

$files = AppGuard::appFiles(3, [10, 11], [['app_id' => 3, 'filename' => 'f.pdf'], ['app_id' => 3, 'filename' => '../x.php']], ['c.jpg'], ['r.png']);
$check('files of an app', $files, [
    'components/com_gridbox/assets/css/storage/app-3.css',
    'components/com_gridbox/assets/css/storage/post-3.css',
    'components/com_gridbox/assets/css/storage/style-10.css',
    'components/com_gridbox/assets/css/storage/style-11.css',
    'components/com_gridbox/assets/uploads/app-3/f.pdf',
    'components/com_gridbox/assets/uploads/comments/c.jpg',
    'components/com_gridbox/assets/uploads/reviews/r.png',
]);

$row  = ['id' => '5', 'title' => 'Zażółć „gęślą”', 'blob' => "\xff\xfe\x00bin", 'empty' => null];
$line = AppGuard::encodeRow('#__gridbox_pages', $row);
$back = json_decode($line, true);
$check('line names the table', $back['t'], '#__gridbox_pages');
$check('binary value kept apart', isset($back['r']['blob']['b64']), true);
$check('row comes back whole', AppGuard::decodeRow($back['r']), $row);
$check('plain row stays plain', json_decode(AppGuard::encodeRow('#__x', ['a' => 'b/c']), true)['r'], ['a' => 'b/c']);
$check('number from JSON as string', AppGuard::decodeRow(['n' => 7]), ['n' => '7']);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
