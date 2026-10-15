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
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
