<?php

/**
 * Standalone test of the Speed helpers (no Joomla, no database).
 * Run: php tests/SpeedTest.php
 */
define('_JEXEC', 1);
if (!interface_exists('Joomla\Database\DatabaseInterface')) {
    eval('namespace Joomla\Database; interface DatabaseInterface {}');
}
require __DIR__ . '/../plg_system_mertools/src/Tool/Speed.php';

use Merserwis\Plugin\System\MerTools\Tool\Speed;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-56s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . json_encode($got, JSON_UNESCAPED_UNICODE) . ' exp=' . json_encode($exp, JSON_UNESCAPED_UNICODE));
};

$root = 'https://www.merserwis.pl/';
$check('empty setting: home page', Speed::urls('', $root), ['https://www.merserwis.pl/']);
$check('paths', Speed::urls("/\n/oferta\r\n /kontakt ", $root), ['https://www.merserwis.pl/', 'https://www.merserwis.pl/oferta', 'https://www.merserwis.pl/kontakt']);
$check('duplicates once', Speed::urls("/oferta\n/oferta", $root), ['https://www.merserwis.pl/oferta']);
$check('full address, other site too', Speed::urls("https://www.merserwis.pl/x\nhttps://example.com/", $root), ['https://www.merserwis.pl/x', 'https://example.com/']);
$check('junk left out', Speed::urls("javascript:alert(1)\n//evil.example/\nftp://x/y\n/a b\n/ok", $root), ['https://www.merserwis.pl/ok']);
$check('site in a subfolder', Speed::urls('/kontakt', 'https://example.com/joomla/'), ['https://example.com/kontakt']);
$check('at most 10', count(Speed::urls(implode("\n", array_map(fn ($i) => "/p$i", range(1, 15))), $root)), 10);

$good = ['url' => 'https://www.merserwis.pl/', 'strategy' => 'mobile', 'runs' => 3, 'score' => 43.4, 'fcp' => 7053.36, 'lcp' => 9601.02,
    'tbt' => 504.5, 'cls' => 0.02288, 'si' => 13829.3, 'ttfb' => 30,
    'field' => ['page' => ['lcp' => [6276, 'SLOW'], 'cls' => [0.2, 'AVERAGE'], 'bad' => [1, 'FAST']], 'origin' => ['ttfb' => [2971, 'SLOW'], 'inp' => ['x', 'FAST'], 'fcp' => [4799, 'WRONG']]],
    'audits' => [['id' => 'render-blocking-insight', 'title' => '<b>Render</b> blocking', 'ms' => 934.4, 'kb' => 12], ['id' => 'BAD ID', 'title' => 'x', 'ms' => 1]]];
$c = Speed::clean($good);
$check('clean: numbers rounded', [$c['score'], $c['fcp'], $c['lcp'], $c['tbt'], $c['cls'], $c['si'], $c['ttfb'], $c['runs']], [43, 7053, 9601, 505, 0.023, 13829, 30, 3]);
$check('clean: field kept only valid', $c['field'], ['page' => ['lcp' => [6276, 'SLOW'], 'cls' => [0.2, 'AVERAGE']], 'origin' => ['ttfb' => [2971, 'SLOW']]]);
$check('clean: audits checked, tags stripped', $c['audits'], [['id' => 'render-blocking-insight', 'title' => 'Render blocking', 'ms' => 934, 'kb' => 12]]);
$check('clean: bad strategy', Speed::clean(['strategy' => 'tablet'] + $good), null);
$check('clean: bad url', Speed::clean(['url' => 'javascript:alert(1)'] + $good), null);
$check('clean: missing metric', Speed::clean(array_diff_key($good, ['lcp' => 1])), null);
$check('clean: score clamped', Speed::clean(['score' => 250] + $good)['score'], 100);
$check('clean: runs clamped', Speed::clean(['runs' => 99] + $good)['runs'], 10);

$check('tools: defaults', Speed::enabledTools([], ['layout_clip_x' => 1, 'dark_enabled' => 0]), ['layout']);
$check('clean: document time kept', Speed::clean(['doc' => 3653.2] + $good)['doc'], 3653);
$check('clean: no document time (older panel)', $c['doc'], null);
$check('clean: bad document time', Speed::clean(['doc' => 'x'] + $good)['doc'], null);
$check('tools: phone video mode', Speed::enabledTools(['speed_video_phone' => 'none', 'speed_cache' => '1'], ['speed_cache_data' => 1]), ['videophone', 'pagecache', 'gridboxdata']);
$check('tools: phone video as on computers', Speed::enabledTools(['speed_video_phone' => 'video', 'speed_cache_data' => '0']), []);
$check('tools: saved win', Speed::enabledTools(['layout_clip_x' => '0', 'dark_enabled' => '1', 'tel_enabled' => 1], ['layout_clip_x' => 1]), ['tel', 'dark']);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
