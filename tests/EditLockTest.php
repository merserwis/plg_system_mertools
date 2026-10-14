<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/EditLock.php';

use Merserwis\Plugin\System\MerTools\Tool\EditLock;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
$now = strtotime('2026-10-14 10:00:00 UTC');
$at  = fn (int $ago) => gmdate('Y-m-d H:i:s', $now - $ago);

$check('key page', EditLock::key('', 12), 'page:12');
$check('key layout', EditLock::key('post-layout', 3), 'post-layout:3');
$check('key cleaned', EditLock::key('Head"er<x>', 5), 'headerx:5');
$check('key negative id', EditLock::key('', -4), 'page:0');

$row = ['user_id' => 7, 'seen' => $at(10), 'active' => $at(60)];
$check('fresh lock held', EditLock::held($row, $now, 1800), true);
$check('no row not held', EditLock::held(null, $now, 1800), false);
$check('not confirmed for 3 min = free', EditLock::held(['seen' => $at(180)] + $row, $now, 1800), false);
$check('confirmed 119 s ago still held', EditLock::held(['seen' => $at(119)] + $row, $now, 1800), true);
$check('idle 31 min = free', EditLock::held(['active' => $at(1860)] + $row, $now, 1800), false);
$check('idle 31 min, idle off = held', EditLock::held(['active' => $at(1860)] + $row, $now, 0), true);
$check('blocks another user', EditLock::blocks($row, 8, $now, 1800), true);
$check('does not block the holder', EditLock::blocks($row, 7, $now, 1800), false);
$check('stale lock blocks nobody', EditLock::blocks(['seen' => $at(500)] + $row, 8, $now, 1800), false);
$check('bad date = free', EditLock::held(['seen' => 'x'] + $row, $now, 1800), false);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
