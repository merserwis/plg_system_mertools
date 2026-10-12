<?php

/**
 * Standalone test of the recommended-settings checks (no Joomla needed).
 * Run: php tests/ChecksTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Checks.php';

use Merserwis\Plugin\System\MerTools\Tool\Checks;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
$state = fn (array $list, string $id) => (array_values(array_filter($list, fn ($c) => $c['id'] === $id))[0] ?? ['state' => null])['state'];

$good  = ['defer_loading' => 1, 'page_cache' => 0, 'images_lazy_load' => 1, 'compress_images' => 0, 'adaptive_images' => 0, 'compress_css' => 0];
$opcOk = ['enabled' => true, 'ini' => ['memory_consumption' => 512, 'max_accelerated_files' => 32531],
    'status' => ['memory_usage' => ['used_memory' => 200e6, 'free_memory' => 300e6, 'wasted_memory' => 0], 'opcache_statistics' => ['hits' => 990000, 'misses' => 4000, 'num_cached_scripts' => 5000]]];
$srv   = ['php' => '8.5.11', 'webp' => true, 'opcache' => $opcOk];
$all   = Checks::evaluate($good, ['platform_prefix' => false, 'page_cache_plugin' => false], [], $srv);
$check('all good: no bad or warn', array_values(array_filter(array_column($all, 'state'), fn ($s) => $s === 'bad' || $s === 'warn')), []);
$check('all good: Rocket Loader left to the browser', $state($all, 'CF_ROCKET'), 'info');
$check('defer on but Page shown at once off = bad', $state(Checks::evaluate($good, [], ['speed_show' => 0], $srv), 'GB_DEFER'), 'bad');
$check('defer off = warn', $state(Checks::evaluate(['defer_loading' => 0] + $good, [], [], $srv), 'GB_DEFER'), 'warn');
$check('Gridbox page cache on = bad', $state(Checks::evaluate(['page_cache' => 1] + $good, [], [], $srv), 'GB_PAGECACHE'), 'bad');
$check('Gridbox images on with MerTools WebP = info', $state(Checks::evaluate(['compress_images' => 1] + $good, [], [], $srv), 'GB_IMAGES'), 'info');
$check('Gridbox images on, WebP off = ok', $state(Checks::evaluate(['adaptive_images' => 1] + $good, [], ['speed_webp' => 0], $srv), 'GB_IMAGES'), 'ok');
$check('no Gridbox: no Gridbox rows', count(array_filter(Checks::evaluate(null, [], [], $srv), fn ($c) => $c['group'] === 'gridbox')), 0);
$check('both page caches = bad', $state(Checks::evaluate($good, ['page_cache_plugin' => true], ['speed_cache' => 1], $srv), 'JM_PAGECACHE'), 'bad');
$check('Joomla page cache alone = ok', $state(Checks::evaluate($good, ['page_cache_plugin' => true], ['speed_cache' => 0], $srv), 'JM_PAGECACHE'), 'ok');
$check('platform specific caching = bad', $state(Checks::evaluate($good, ['platform_prefix' => true], [], $srv), 'JM_PLATFORM'), 'bad');
$check('old PHP = bad', $state(Checks::evaluate($good, [], [], ['php' => '8.1.2'] + $srv), 'SRV_PHP'), 'bad');
$check('no WebP = warn', $state(Checks::evaluate($good, [], [], ['webp' => false] + $srv), 'SRV_WEBP'), 'warn');
// OPcache as on merserwis.pl before the fix: 128 MB, 12 KB free, hits < misses, 4376 of 5000 files
$full = ['enabled' => true, 'ini' => ['memory_consumption' => 128, 'max_accelerated_files' => 5000],
    'status' => ['memory_usage' => ['used_memory' => 134e6, 'free_memory' => 12e3, 'wasted_memory' => 0], 'opcache_statistics' => ['hits' => 1390000, 'misses' => 2360000, 'num_cached_scripts' => 4376]]];
$check('OPcache full (merserwis before) = bad', Checks::opcache($full)[0], 'bad');
$check('OPcache full: values', Checks::opcache($full)[1], ['OPC_STATUS', 128, 0, 37, 4376, 5000]);
$check('OPcache healthy = ok', Checks::opcache($opcOk)[0], 'ok');
$check('OPcache small but fine = warn', Checks::opcache(['ini' => ['memory_consumption' => 128, 'max_accelerated_files' => 32531]] + $opcOk)[0], 'warn');
$check('OPcache off = bad', Checks::opcache(['enabled' => false])[0], 'bad');
$check('OPcache details hidden, good limits = ok', Checks::opcache(['enabled' => true, 'status' => null, 'ini' => ['memory_consumption' => 256, 'max_accelerated_files' => 20000]]), ['ok', ['OPC_LIMITS', 256, 20000]]);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
