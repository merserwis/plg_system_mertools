<?php

/**
 * Standalone test of the pure helpers of CartCleaner (no Joomla, no database).
 * Run: php tests/CartCleanerTest.php  (needs joomla/database for the type only: a stub is enough)
 */
define('_JEXEC', 1);
if (!interface_exists('Joomla\Database\DatabaseInterface')) {
    eval('namespace Joomla\Database; interface DatabaseInterface {}');
}
require __DIR__ . '/../plg_system_mertools/src/Tool/CartCleaner.php';

use Merserwis\Plugin\System\MerTools\Tool\CartCleaner;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-58s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

// days: never below the cookie lifetime + 1
$check('days 30', CartCleaner::effectiveDays(30), 30);
$check('days 8', CartCleaner::effectiveDays(8), 8);
$check('days 3 -> 8', CartCleaner::effectiveDays(3), 8);
$check('days 0 -> 8', CartCleaner::effectiveDays(0), 8);
$check('days -5 -> 8', CartCleaner::effectiveDays(-5), 8);

// abandoned carts can go N days after watching began
$t = 1_700_000_000;
$check('not watching', CartCleaner::abandonedFrom(null, 30), null);
$check('watching + 30 days', CartCleaner::abandonedFrom($t, 30), $t + 30 * 86400);
$check('watching + at least 8 days', CartCleaner::abandonedFrom($t, 2), $t + 8 * 86400);

// a break in watching restarts the count
$check('first tick', CartCleaner::restartsWatch(null, $t), true);
$check('hourly ticks', CartCleaner::restartsWatch($t - 3600, $t), false);
$check('a day without visits', CartCleaner::restartsWatch($t - 86400, $t), false);
$check('two days', CartCleaner::restartsWatch($t - 172800, $t), false);
$check('more than two days', CartCleaner::restartsWatch($t - 172801, $t), true);

// the cart table emptied (ids start again from 1)
$check('ids growing', CartCleaner::idsRestarted(5000, 4000), false);
$check('ids equal to the mark', CartCleaner::idsRestarted(4000, 4000), false);
$check('no marks yet', CartCleaner::idsRestarted(10, 0), false);
$check('table emptied: ids below the mark', CartCleaner::idsRestarted(12, 192229), true);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
