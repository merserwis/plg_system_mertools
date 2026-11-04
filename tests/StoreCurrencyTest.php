<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/StoreCurrency.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Omnibus.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry {}'); } });

use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\StoreCurrency;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// merserwis.pl (2026-10): the currency list only, no store.currency
$prod = '{"general":{},"currencies":{"list":[{"symbol":"zł netto","code":"PLN","position":"right-currency-position","thousand":" ","separator":",","decimals":"0","default":true,"rate":"1.0000","title":"Waluta","language":"pl-PL"}],"auto":false}}';
$cur  = StoreCurrency::from($prod);
$check('merserwis.pl: symbol from the list', $cur['symbol'], 'zł netto');
$check('merserwis.pl: no decimals, as the prices', $cur['decimals'], '0');
$check('line as on the site', Omnibus::line('Najniższa cena z 30 dni przed obniżką: {price}', 15595.0, $cur), 'Najniższa cena z 30 dni przed obniżką: 15 595 zł netto');

$check('the default of several', StoreCurrency::from('{"currencies":{"list":[{"symbol":"€","default":false},{"symbol":"zł","default":true}]}}')['symbol'], 'zł');
$check('none marked default: the first', StoreCurrency::from('{"currencies":{"list":[{"symbol":"€"},{"symbol":"zł"}]}}')['symbol'], '€');
$check('an old store: store.currency', StoreCurrency::from('{"currency":{"symbol":"$","position":"left-currency-position","decimals":2}}')['symbol'], '$');
$check('nothing usable: defaults', StoreCurrency::from('not json'), StoreCurrency::DEFAULT);
$check('empty list, no currency: defaults', StoreCurrency::from('{"currencies":{"list":[]}}'), StoreCurrency::DEFAULT);
$check('only the known keys', array_keys(StoreCurrency::from($prod)), ['symbol', 'position', 'thousand', 'separator', 'decimals']);

// the ISO code for the product data (1.7.0: no PLN when the store says nothing)
$code = fn (string $json): string => StoreCurrency::code(json_decode($json, true) ?: []);
$check('code: the one store currency', $code($prod), 'PLN');
$check('code: the default of several, not the first', $code('{"currencies":{"list":[{"code":"EUR","default":false},{"code":"usd","default":true}]}}'), 'USD');
$check('code: none marked default: the first', $code('{"currencies":{"list":[{"code":"EUR"},{"code":"GBP"}]}}'), 'EUR');
$check('code: an old store: store.currency', $code('{"currency":{"code":"CZK","symbol":"Kč"}}'), 'CZK');
$check('code: none at all: empty, nothing invented', $code('{"general":{}}'), '');

// the currency a visitor is shown (Omnibus: its line only in the currency of the history)
$multi  = json_decode('{"currencies":{"list":[{"code":"EUR","default":true,"language":"*"},{"code":"USD","default":false,"language":"en-GB"},{"code":"PLN","default":false,"language":"pl-PL"}]}}', true);
$check('active: nothing chosen: the default', StoreCurrency::activeCode($multi, '', null), 'EUR');
$check('active: chosen in the switcher', StoreCurrency::activeCode($multi, 'USD', null), 'USD');
$check('active: a code not in the list: the default', StoreCurrency::activeCode($multi, 'GBP', null), 'EUR');
$check('active: the one of the page language', StoreCurrency::activeCode($multi, '', 'pl-PL'), 'PLN');
$check('active: the switcher over the language', StoreCurrency::activeCode($multi, 'USD', 'pl-PL'), 'USD');
$check('active: a language without its own currency', StoreCurrency::activeCode($multi, '', 'de-DE'), 'EUR');
$check('active: merserwis.pl, its one currency', StoreCurrency::activeCode(json_decode($prod, true), 'PLN', 'pl-PL'), 'PLN');
$check('active: an old store: store.currency', StoreCurrency::activeCode(['currency' => ['code' => 'CZK']], 'EUR', null), 'CZK');
// 1.11: the Omnibus line in another currency, converted as Gridbox converts the prices shown
$shop = json_decode('{"currencies":{"auto":false,"list":[{"code":"PLN","symbol":"zł","position":"right-currency-position","thousand":" ","separator":",","decimals":"2","default":true,"rate":"1"},'
    . '{"code":"EUR","symbol":"€","position":"left-currency-position","thousand":".","separator":",","decimals":"2","default":false,"rate":"0.2312"},'
    . '{"code":"HUF","symbol":"Ft","decimals":"0","default":false,"rate":"91.5"},{"code":"USD","symbol":"$","default":false,"rate":"0"}]}}', true);
$eur = StoreCurrency::shown($shop, 'EUR', null);
$check('shown: the rate of the list', [$eur['code'], $eur['rate']], ['EUR', 0.2312]);
$check('shown: its own format', [$eur['format']['symbol'], $eur['format']['separator'], $eur['format']['position']], ['€', ',', 'left-currency-position']);
$check('convert: rounded to its decimals, as Gridbox', StoreCurrency::convert(15595.0, $eur['rate'], $eur['format']), 3605.56);
$check('line in euro', Omnibus::line('Najniższa cena: {price}', StoreCurrency::convert(15595.0, $eur['rate'], $eur['format']), $eur['format']), 'Najniższa cena: € 3.605,56');
$huf = StoreCurrency::shown($shop, 'HUF', null);
$check('convert: a currency without decimals', StoreCurrency::convert(99.99, $huf['rate'], $huf['format']), 9149.0);
$check('shown: a rate of 0: none (no line)', StoreCurrency::shown($shop, 'USD', null), null);
$auto = $shop;
$auto['currencies']['auto'] = true;
$check('shown: automatic rates on: the one Gridbox fetched', StoreCurrency::shown($auto, 'EUR', null, ['EUR' => 0.2299, 'HUF' => 92.1])['rate'], 0.2299);
$check('shown: automatic, a rate not fetched: the list\'s', StoreCurrency::shown($auto, 'EUR', null, ['HUF' => 92.1])['rate'], 0.2312);
$check('shown: automatic off: the list\'s, whatever was fetched', StoreCurrency::shown($shop, 'EUR', null, ['EUR' => 0.2299])['rate'], 0.2312);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
