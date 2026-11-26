<?php

/**
 * Standalone test of DbHealth's judgement of a table's collation: converted only where nothing can be lost.
 * Run: php tests/DbHealthTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/DbHealth.php';

use Merserwis\Plugin\System\MerTools\Tool\DbHealth;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$check('the character set of a collation', [DbHealth::charset('utf8mb3_general_ci'), DbHealth::charset('UTF8MB4_bin'), DbHealth::charset('latin1')], ['utf8mb3', 'utf8mb4', 'latin1']);
foreach ([
    'utf8mb4_unicode_ci' => 'ok', 'UTF8MB4_UNICODE_CI' => 'ok',
    'utf8mb3_general_ci' => 'convert', 'utf8_general_ci' => 'convert', 'utf8mb3_unicode_ci' => 'convert',
    'utf8mb4_general_ci' => 'convert', 'utf8mb4_0900_ai_ci' => 'convert',
    // case-sensitive on purpose (tokens, hashes): kept
    'utf8mb4_bin' => 'ok', 'utf8mb4_0900_as_cs' => 'ok', 'utf8mb3_bin' => 'manual',
    'latin1_swedish_ci' => 'manual', 'cp1250_general_ci' => 'manual', 'ascii_bin' => 'manual',
    '' => 'none',
] as $collation => $want) {
    $check('verdict: ' . ($collation === '' ? '(a view)' : $collation), DbHealth::verdict($collation), $want);
}
$check('verdict: null (a view)', DbHealth::verdict(null), 'none');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
