<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Omnibus.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/PriceRequest.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// settings
$check('off by default', Omnibus::enabled(new Registry()), false);
$check('lists on by default', Omnibus::lists(new Registry()), true);
$check('template: language default', Omnibus::template(new Registry(), 'Najniższa cena z 30 dni przed obniżką: {price}'), 'Najniższa cena z 30 dni przed obniżką: {price}');
$check('template: own text without {price} gets it', Omnibus::template(new Registry(['omnibus_text' => 'Najniższa cena z 30 dni: ']), 'x'), 'Najniższa cena z 30 dni: {price}');

// prices as Gridbox keeps them
$check('amount: integer string', Omnibus::amount('6616'), 6616.0);
$check('amount: decimal comma', Omnibus::amount('12,5'), 12.5);
$check('amount: DECIMAL from MySQL', Omnibus::amount('6616.0000'), 6616.0);
$check('amount: empty / zero / junk = no price', [Omnibus::amount(''), Omnibus::amount('0'), Omnibus::amount('abc'), Omnibus::amount(null)], [null, null, null, null]);
$check('effective: sale wins', Omnibus::effective(100.0, 80.0), 80.0);
$check('effective: regular', Omnibus::effective(100.0, null), 100.0);

// the rules of a change
$check('sale starts: reduction', Omnibus::change(100.0, null, 100.0, 80.0), 'reduction');
$check('sale goes deeper: a new reduction', Omnibus::change(100.0, 80.0, 100.0, 70.0), 'reduction');
$check('sale price raised: the same sale', Omnibus::change(100.0, 70.0, 100.0, 75.0), 'keep');
$check('regular price changed during the sale: kept', Omnibus::change(100.0, 80.0, 120.0, 80.0), 'keep');
$check('sale ends: cleared', Omnibus::change(100.0, 80.0, 100.0, null), 'clear');
$check('regular price lowered, no sale: nothing to show', Omnibus::change(100.0, null, 90.0, null), 'clear');

// the lowest price of the 30 days before a reduction made on 10.10 (window 10.09 - 10.10)
$h = fn (string $d, $r, $s = '') => ['changed' => $d . ' 12:00:00', 'regular' => $r, 'sale' => $s];
$from = '2026-09-10 12:00:00';
$to   = '2026-10-10 12:00:00';
$check('price lowered in the window counts', Omnibus::lowest([$h('2026-09-01', '100'), $h('2026-09-20', '90')], $from, $to), 90.0);
$check('price in force when the window opened counts', Omnibus::lowest([$h('2026-08-01', '100')], $from, $to), 100.0);
$check('an earlier sale price in the window counts', Omnibus::lowest([$h('2026-08-01', '100'), $h('2026-09-15', '100', '70'), $h('2026-09-25', '100')], $from, $to), 70.0);
$check('a sale price before the window does not', Omnibus::lowest([$h('2026-08-01', '100', '60'), $h('2026-09-01', '100')], $from, $to), 100.0);
$check('offered for less than 30 days: since it is offered', Omnibus::lowest([$h('2026-10-05', '100')], $from, $to), 100.0);
$check('the reduction itself (at "to") is not counted', Omnibus::lowest([$h('2026-09-01', '100'), $h('2026-10-10', '100', '50')], $from, $to), 100.0);
$check('a price raised in the window: the lower one before', Omnibus::lowest([$h('2026-08-01', '90'), $h('2026-10-01', '120')], $from, $to), 90.0);
$check('no price (0) is skipped', Omnibus::lowest([$h('2026-08-01', '0'), $h('2026-09-20', '95')], $from, $to), 95.0);
$check('nothing known', Omnibus::lowest([], $from, $to), null);
$check('rows in any order', Omnibus::lowest([$h('2026-09-20', '90'), $h('2026-08-01', '100')], $from, $to), 90.0);

// Gridbox's product data
$items = Omnibus::items([
    ['product_id' => '815', 'price' => '0', 'sale_price' => '', 'variations' => json_encode(['1699006590350' => ['price' => '6616', 'sale_price' => '', 'default' => false],
        '1699006590491' => ['price' => '7624', 'sale_price' => '7000', 'default' => true]])],
    ['product_id' => '4953', 'price' => '16450', 'sale_price' => '14312', 'variations' => '{}'],
    ['product_id' => '0', 'price' => '1', 'sale_price' => '', 'variations' => null],
    ['product_id' => '5', 'price' => '10', 'sale_price' => '', 'variations' => 'not json'],
]);
$check('items: product and versions', array_map('strval', array_keys($items)), ['815', '1699006590350', '1699006590491', '4953', '5']);
$check('items: a version with its prices and default', $items['1699006590491'], ['product' => 815, 'regular' => 7624.0, 'sale' => 7000.0, 'default' => true]);
$check('items: the product without a price', $items['815'], ['product' => 815, 'regular' => null, 'sale' => null, 'default' => false]);
$check('items: on sale without versions', $items['4953'], ['product' => 4953, 'regular' => 16450.0, 'sale' => 14312.0, 'default' => false]);

// format of the store
$pl = ['symbol' => 'zł netto', 'position' => 'right-currency-position', 'thousand' => ' ', 'separator' => ',', 'decimals' => 2];
$check('format: Polish store', Omnibus::format(6616.0, $pl), '6 616,00 zł netto');
$check('format: symbol on the left', Omnibus::format(1234.5, ['symbol' => '€', 'position' => 'left-currency-position', 'thousand' => '.', 'separator' => ',', 'decimals' => 2]), '€ 1.234,50');
$check('format: no symbol', Omnibus::format(99.0, ['decimals' => 0]), '99');
$tpl = 'Najniższa cena z 30 dni przed obniżką: {price}';

// the product page
$page = '<div class="ba-item-add-to-cart ba-item" id="item-1"><div class="ba-add-to-cart-price"> <span class="ba-add-to-cart-sale-price-wrapper"><span>7 000</span></span> </div>'
    . '<div class="omnibus_info" data-omnibus-id="815" style="display:none;">Najniższa cena z 30 dni przed promocją: 0,00 zł</div>'
    . '<div class="ba-add-to-cart-button-wrapper"></div></div>';
$clean = Omnibus::dropPluginLines($page);
$check('plugin lines taken off', str_contains($clean, 'przed promocją'), false);
$out = Omnibus::productLines($clean, 815, ['1699006590491' => 7624.0, '815' => 100.0], $tpl, $pl);
$check('a line per item on sale, after the price box', substr_count($out, 'class="omnibus_info mt-omnibus"'), 2);
$check('placed right after the price box', str_contains($out, "</div>\n<div class=\"omnibus_info mt-omnibus\" data-omnibus-id=\"1699006590491\">Najniższa cena z 30 dni przed obniżką: <span class=\"mt-omnibus-price\">7 624,00 zł netto</span></div>"), true);
$check('the product line marked as its own', str_contains($out, 'data-omnibus-id="815" data-mt-own>'), true);
$check('own lines survive taking the plugin lines off', Omnibus::dropPluginLines($out), $out);
$check('nothing on sale: page as it was', Omnibus::productLines($clean, 815, [], $tpl, $pl), $clean);
$check('text escaped', str_contains(Omnibus::productLines($clean, 815, ['815' => 5.0], 'A <b> & {price}', $pl), 'A &lt;b&gt; &amp; <span class="mt-omnibus-price">5,00 zł netto</span>'), true);

// the lists
$tile = fn (int $id, bool $sale) => '<li class="item ba-store-app-product active" data-id="' . $id . '"><div class="ba-blog-post-title"><a>P' . $id . '</a></div>'
    . '<div class="ba-blog-post-add-to-cart-wrapper"><div class="ba-blog-post-add-to-cart-price">'
    . ($sale ? '<span class="ba-blog-post-add-to-cart-sale-price-wrapper right-currency-position"><span class="ba-blog-post-add-to-cart-price-value">16 450</span></span>' : '')
    . '<span class="ba-blog-post-add-to-cart-price-wrapper right-currency-position"><span class="ba-blog-post-add-to-cart-price-value">14 312</span></span></div>'
    . '<div class="ba-blog-post-add-to-cart">btn</div></div></li>';
$list = '<ul>' . $tile(4953, true) . $tile(3440, false) . $tile(7, true) . '</ul>';
$out  = Omnibus::listLines($list, [4953 => 16450.0, 3440 => 100.0], $tpl, $pl);
$check('a line under the price of a product on sale', str_contains($out, '<span class="ba-blog-post-add-to-cart-price-value">14 312</span></span><span class="mt-omnibus-list">Najniższa cena z 30 dni przed obniżką: <span class="mt-omnibus-price">16 450,00 zł netto</span></span></div>'), true);
$check('only there (not on sale / not known)', substr_count($out, 'mt-omnibus-list'), 1);
$check('the button stays after the price box', str_contains($out, '</span></span></div><div class="ba-blog-post-add-to-cart">btn</div>'), true);
$check('price kept on one row', str_contains(Omnibus::css(), '.mt-omnibus-price{white-space:nowrap}'), true);
$check('twice: no second line', Omnibus::listLines($out, [4953 => 16450.0], $tpl, $pl), $out);
$check('nothing known: page as it was', Omnibus::listLines($list, [], $tpl, $pl), $list);

// scripts of the separate plugins
$nonce = 'ZDZjMGRjNjQyMjVmN2JhMWFmMWMy';
$head  = "<head>\n<script src=\"/media/system/js/core.min.js?d453df\"></script>\n"
    . "<script src=\"/plugins/system/omnibus/assets/scprice.js\" nonce=\"$nonce\"></script>\n"
    . "<script src=\"/plugins/system/omnibus/assets/scr_omnibus.js\" nonce=\"$nonce\"></script>\n"
    . "<script>var x = '/plugins/system/omnibus/assets/scr_omnibus.js';</script>\n</head>";
$noOmnibus = Omnibus::dropScript($head, Omnibus::SCRIPT);
$check('Omnibus script tag gone', str_contains($noOmnibus, 'src="/plugins/system/omnibus/assets/scr_omnibus.js"'), false);
$check('the price script stays', str_contains($noOmnibus, 'assets/scprice.js'), true);
$check('other scripts and inline code stay', substr_count($noOmnibus, '<script'), 3);
$both = Omnibus::dropScript($noOmnibus, PriceRequest::OLD_SCRIPT);
$check('old price script gone', str_contains($both, 'scprice.js'), false);
$check('with a version and the full address', Omnibus::dropScript('<script defer src="https://www.merserwis.pl/plugins/system/omnibus/assets/scr_omnibus.js?v=2"></script>', Omnibus::SCRIPT), '');
$check('a file of another name stays', Omnibus::dropScript('<script src="/plugins/system/omnibus/assets/scr_omnibus.js.bak"></script>', Omnibus::SCRIPT), '<script src="/plugins/system/omnibus/assets/scr_omnibus.js.bak"></script>');
$check('lines hidden unless shown by MerTools', str_starts_with(Omnibus::css(), '.omnibus_info{display:none!important}.omnibus_info.mt-omnibus-on{display:block!important}'), true);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
