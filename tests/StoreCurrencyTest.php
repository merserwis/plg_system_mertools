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
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
