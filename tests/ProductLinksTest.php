<?php

/**
 * Standalone test of ProductLinks::restoredParams (no Joomla needed).
 * Run: php tests/ProductLinksTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ProductLinks.php';

use Merserwis\Plugin\System\MerTools\Tool\ProductLinks;

/** what the plugin gets: the raw query string and PHP's own $_GET for it */
function restore(string $query): array
{
    parse_str($query, $get);

    return ProductLinks::restoredParams($query, $get);
}

$cases = [
    // the link from merserwis.pl
    ['Zestawy+Metrel+MI+3155=MI+3155+EurotestXD+ST%2B+Wielofunkcyjny+miernik+instalacji',
        ['Zestawy Metrel MI 3155' => 'MI 3155 EurotestXD ST+ Wielofunkcyjny miernik instalacji']],
    ['Zestawy+Metrel+MI+3155=MI+3155+EU+2%2C5kV+IT+%2B+Program+PC%2FAndroid+%2B+C%C4%99gi',
        ['Zestawy Metrel MI 3155' => 'MI 3155 EU 2,5kV IT + Program PC/Android + Cęgi']],
    // %20 instead of +
    ['Zestawy%20Metrel%20MI%203155=MI%203155', ['Zestawy Metrel MI 3155' => 'MI 3155']],
    // two option groups, a dot, square brackets and an ampersand in the names
    ['D%C5%82ugo%C5%9B%C4%87+przewodu+%5Bm%5D=1.5+m&Wersja+v2.0+%26+zasilanie=Zasilacz+230+V',
        ['Długość przewodu [m]' => '1.5 m', 'Wersja v2.0 & zasilanie' => 'Zasilacz 230 V']],
    // a dot only
    ['Model.2=X', ['Model.2' => 'X']],
    // names PHP keeps as they are: nothing to do
    ['Kolor=czerwony&utm_source=newsletter', []],
    ['', []],
    ['a=1+2', []],                                   // "+" only in the value
    // other parameters stay as they are; only the renamed one comes back
    ['utm_source=x&Rozmiar+buta=42&gclid=abc', ['Rozmiar buta' => '42']],
    // a name already in $_GET is never overwritten
    ['Zestawy_Metrel=a&Zestawy+Metrel=b', ['Zestawy Metrel' => 'b']],
    // repeated name: the last one wins, as in PHP
    ['Rozmiar+buta=41&Rozmiar+buta=42', ['Rozmiar buta' => '42']],
    // no value
    ['Rozmiar+buta', ['Rozmiar buta' => '']],
    ['Rozmiar+buta=', ['Rozmiar buta' => '']],
    // control characters are refused
    ['Rozmiar+buta=4%002', []],
    ['Rozmi%0Aar+buta=42', []],
    // HTML entities in the option value (GW Instek: "GPT-12002 &#128308;", a red dot): "&" is written
    // as "&amp;", so Joomla's filter gives back the value of the link
    ['Modele+GW+Instek+GPT-12000=GPT-12002+%26%23128308%3B',
        ['Modele GW Instek GPT-12000' => 'GPT-12002 &amp;#128308;']],
    ['Modele+GW+Instek+GPT-12000=GPT-12004+%26amp%3B+%22PRO%22+%26%23128308%3B',
        ['Modele GW Instek GPT-12000' => 'GPT-12004 &amp;amp; "PRO" &amp;#128308;']],
    // a name PHP keeps, value with an entity: the same value, written for the filter
    ['Model=GPT-12002+%26%23128308%3B&utm_source=x', ['Model' => 'GPT-12002 &amp;#128308;']],
    // a name PHP keeps, value with a plain "&"
    ['q=A+%26+B', ['q' => 'A &amp; B']],
    // "&" in the name only: the value stays as it is
    ['Wersja+%26+zasilanie=Zasilacz', ['Wersja & zasilanie' => 'Zasilacz']],
    // a PHP array is never replaced by a string
    ['m%5Ba%5D=A+%26+B', ['m[a]' => 'A &amp; B']],
    // a real PHP array parameter (no space or dot, "[" only) comes back under its literal name
    // too; harmless, the array PHP made is still there
    ['filter%5Bx%5D=1', ['filter[x]' => '1']],
];

$fail = 0;
foreach ($cases as [$in, $exp]) {
    $got = restore($in);
    $ok  = ($got === $exp);
    if (!$ok) {
        $fail++;
    }
    printf("%s %-70s %s\n", $ok ? 'OK ' : 'FAIL', substr($in, 0, 70), $ok ? '' : 'got=' . json_encode($got, JSON_UNESCAPED_UNICODE) . ' exp=' . json_encode($exp, JSON_UNESCAPED_UNICODE));
}

// too many pairs: not a product link
$many = implode('&', array_map(fn ($i) => "a+$i=$i", range(1, 51)));
$ok   = restore($many) === [];
$fail += $ok ? 0 : 1;
printf("%s 51 pairs are ignored\n", $ok ? 'OK ' : 'FAIL');

// radio buttons: the one the server selected gets a data-value, nothing else changes
$radios = [
    // Gridbox's markup after its HTML pass (one line per input)
    ['<input type="radio" name="variation-8" class="" value="91"><input type="radio" name="variation-8" class="active" value="92" checked>',
     '<input type="radio" name="variation-8" class="" value="91"><input type="radio" name="variation-8" class="active" value="92" data-value="92" checked>'],
    // attributes on several lines, as in the template
    ["<input type=\"radio\" name=\"variation-3\"\n    class=\"active\"\n    value=\"1716187482786\" checked>",
     "<input type=\"radio\" name=\"variation-3\"\n    class=\"active\"\n    value=\"1716187482786\" data-value=\"1716187482786\" checked>"],
    // already has one: left alone (no double attribute)
    ['<input type="radio" name="variation-8" class="active" data-value="92" value="92" checked>',
     '<input type="radio" name="variation-8" class="active" data-value="92" value="92" checked>'],
    // other radios (extra options, forms) and other inputs are not touched
    ['<input type="radio" name="extra-5" class="active" value="7" checked><input type="text" name="variation-8" class="active" value="9">',
     '<input type="radio" name="extra-5" class="active" value="7" checked><input type="text" name="variation-8" class="active" value="9">'],
    ['<p>no options</p>', '<p>no options</p>'],
];
foreach ($radios as $i => [$in, $exp]) {
    $got = ProductLinks::markChosenRadios($in);
    $ok  = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s radio %d %s\n", $ok ? 'OK ' : 'FAIL', $i + 1, $ok ? '' : 'got=' . $got);
}

// with Joomla at hand (the test site): every value goes through Joomla's input filter, as Gridbox
// reads it, and has to come back exactly as in the link
$autoload = getenv('JOOMLA_AUTOLOAD') ?: '/var/www/html/libraries/vendor/autoload.php';
$extra = 0;
if (is_file($autoload)) {
    require $autoload;
    $filter = new Joomla\Filter\InputFilter();
    foreach (['GPT-12002 &#128308;', 'GPT-12004 &amp; "PRO" &#128308;', 'A & B', 'A &amp;amp; B', 'MI 3155 EU 2,5kV IT + Program PC/Android + Cęgi'] as $raw) {
        $q      = 'Modele+GW=' . urlencode($raw);
        $stored = restore($q)['Modele GW'];
        $back   = $filter->clean($stored, 'unknown');
        $ok     = $back === $raw;
        $fail  += $ok ? 0 : 1;
        $extra++;
        printf("%s filter round trip %-40s %s\n", $ok ? 'OK ' : 'FAIL', $raw, $ok ? '' : 'got=' . $back);
    }
}

echo $fail === 0 ? "\nALL PASS (" . (count($cases) + 1 + count($radios) + $extra) . ")\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
