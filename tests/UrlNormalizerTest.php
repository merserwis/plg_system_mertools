<?php

/**
 * Standalone test of UrlNormalizer::canonicalTarget (no Joomla needed).
 * Run: php tests/UrlNormalizerTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/UrlNormalizer.php';

use Merserwis\Plugin\System\MerTools\Tool\UrlNormalizer;

$cases = [
    ['/oferta', null],
    ['/', null],
    ['', null],
    ['//////oferta', '/oferta'],
    ['//oferta', '/oferta'],
    ['/oferta/mierniki////mierniki-instalacji-elektrycznych', '/oferta/mierniki/mierniki-instalacji-elektrycznych'],
    ['//////oferta?foo=bar', '/oferta?foo=bar'],
    ['//oferta?return=https://evil.example//x', '/oferta?return=https://evil.example//x'], // query untouched
    ['/oferta?a=b//c', null],                 // only query has //, path clean
    ['/a//b//c', '/a/b/c'],
    ['/trailing//', '/trailing/'],
    ['//', '/'],
    ['///', '/'],
    ['//evil.example/x', '/evil.example/x'],   // stays same-host, no open redirect
    ['/a%2F%2Fb', null],                       // percent-encoded slashes untouched
    ['/x//y?', '/x/y?'],
];

$fail = 0;
foreach ($cases as [$in, $exp]) {
    $got = UrlNormalizer::canonicalTarget($in);
    $ok  = ($got === $exp);
    if (!$ok) {
        $fail++;
    }
    printf("%s in=%-52s got=%-38s exp=%s\n", $ok ? 'OK ' : 'FAIL', var_export($in, true), var_export($got, true), var_export($exp, true));
}
echo $fail === 0 ? "\nALL PASS (" . count($cases) . ")\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
