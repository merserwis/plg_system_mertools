<?php

/**
 * Standalone test of Tick (no Joomla needed).
 * Run: php tests/TickTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Tick.php';

use Merserwis\Plugin\System\MerTools\Tool\Tick;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$dir  = sys_get_temp_dir() . '/mertools-tick-' . getmypid();
@mkdir($dir);
$gate = $dir . '/gate';
$now  = time();

// ---------------------------------------------------------------- no gate file yet
$a = Tick::claim($gate, 60, $now);
$check('first: no file, the job is due', $a instanceof Tick, true);
$check('first: the time noted in the file', (int) file_get_contents($gate), $now);
$check('while held: a second request at the same moment leaves', Tick::claim($gate, 60, $now), null);
$a->release();
$check('released: not due again within the interval', Tick::claim($gate, 60, $now + 30), null);

// ---------------------------------------------------------------- both found the date old (the race)
// the second request passed the date check before the first touched the file: it waits for nothing,
// under the lock it reads the time the first noted and leaves
file_put_contents($gate, (string) ($now - 120));
touch($gate, $now - 120);
$b = Tick::claim($gate, 60, $now);
$check('old: the job is due', $b instanceof Tick, true);
$b->release();
touch($gate, $now - 120); // as if this request had read the date before the first one noted it
$check('old date, but the content says it ran: leaves', Tick::claim($gate, 60, $now), null);

// ---------------------------------------------------------------- gate files of older versions (empty)
file_put_contents($gate, '');
touch($gate, $now - 4000);
$c = Tick::claim($gate, 3600, $now);
$check('empty gate of an older version: due', $c instanceof Tick, true);
unset($c); // released when the object goes
$check('released by the destructor: taken again later', Tick::claim($gate, 3600, $now + 3601) instanceof Tick, true);

// ---------------------------------------------------------------- a folder that cannot be written
$check('no folder: nothing done, no error', Tick::claim($dir . '/none/gate', 60, $now), null);

@unlink($gate);
@rmdir($dir);

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
