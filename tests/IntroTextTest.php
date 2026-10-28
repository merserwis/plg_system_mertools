<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/IntroText.php';

use Merserwis\Plugin\System\MerTools\Tool\IntroText;

$fail = 0;
$check = function (string $name, $got, $want) use (&$fail): void {
    if ($got !== $want) {
        $fail++;
        echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
    }
};

// escape: only a "<" that starts no tag
foreach ([
    ['czułość <30 mK i pomiar', 'czułość &lt;30 mK i pomiar'],
    ['(RE < 0,5 Ω)', '(RE &lt; 0,5 Ω)'],
    ['<b>Nowość</b> <br/>tekst', '<b>Nowość</b> <br/>tekst'],
    ['<!-- c --> <?x ?>', '<!-- c --> <?x ?>'],
    ['a<-b, <= 5, <<', 'a&lt;-b, &lt;= 5, &lt;&lt;'],
    ['koniec <', 'koniec &lt;'],
    ['już &lt;30 mK', 'już &lt;30 mK'],
    ['', ''],
] as [$in, $want]) {
    $check('escape ' . $in, IntroText::escape($in), $want);
    $check('idempotent ' . $in, IntroText::escape(IntroText::escape($in)), $want);
}
$check('needs 1', IntroText::needsEscape('x <3'), true);
$check('needs 2', IntroText::needsEscape('<p>x</p> &lt;3'), false);

// the stored introductions are repaired only with a libxml that loses the "<"
$check('libxml 2.9.10 (merserwis.pl) repairs', IntroText::oldLibxml(20910), true);
$check('libxml 2.9.11 does not', IntroText::oldLibxml(20911), false);
$check('libxml 2.13 does not', IntroText::oldLibxml(21308), false);
$check('no libxml known: nothing', IntroText::oldLibxml(0), false);
// this server: a newer libxml keeps a bare "<" as text (an old one would cut it off)
if (!IntroText::oldLibxml(LIBXML_VERSION)) {
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?><div class="i">czułość <30 mK, RE < 0,5 Ω.</div><div class="c">CENA</div>');
    $check('newer libxml reads a bare "<" as text', $d->getElementsByTagName('div')->item(0)->textContent, 'czułość <30 mK, RE < 0,5 Ω.');
}

// libxml 2.9.10 keeps the escaped text whole (what Gridbox's phpQuery does to its lists)
$d = new DOMDocument();
@$d->loadHTML('<?xml encoding="utf-8"?><div class="i">' . IntroText::escape('czułość <30 mK, RE < 0,5 Ω.') . '</div><div class="c">CENA</div>');
$check('dom text', $d->getElementsByTagName('div')->item(0)->textContent, 'czułość <30 mK, RE < 0,5 Ω.');

// meta tags: Joomla's second escaping undone, only there
$html = '<html><head><meta name="description" content="czułość &amp;lt;30 mK &amp;amp; x">' . "\n"
    . '<meta property="og:description" content="RE &amp;lt; 0,5">' . '<meta content="a &amp;lt; b" name="twitter:description">'
    . '<meta name="keywords" content="k &amp;lt; 1"><title>T &amp;lt; 1</title></head><body>&amp;lt;</body></html>';
$want = '<html><head><meta name="description" content="czułość &lt;30 mK &amp;amp; x">' . "\n"
    . '<meta property="og:description" content="RE &lt; 0,5">' . '<meta content="a &lt; b" name="twitter:description">'
    . '<meta name="keywords" content="k &amp;lt; 1"><title>T &amp;lt; 1</title></head><body>&amp;lt;</body></html>';
$check('meta', IntroText::fixMeta($html), $want);
$check('meta untouched', IntroText::fixMeta('<head><meta name="description" content="ok"></head>'), '<head><meta name="description" content="ok"></head>');

echo $fail ? "$fail failed\n" : "IntroTextTest: all passed\n";
exit($fail ? 1 : 0);
