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
$check('items: a version with its prices and default', $items['1699006590491'], ['product' => 815, 'regular' => 7624.0, 'sale' => 7000.0, 'default' => true, 'own' => true]);
$check('items: the product without a price', $items['815'], ['product' => 815, 'regular' => null, 'sale' => null, 'default' => false, 'own' => false]);
$check('items: on sale without versions', $items['4953'], ['product' => 4953, 'regular' => 16450.0, 'sale' => 14312.0, 'default' => false, 'own' => true]);
$check('items: a version without a sale price of its own', $items['1699006590350']['own'], false);

// Gridbox's own sales (Gridbox → Discounts)
$sale = fn (array $over = []) => $over + ['id' => 1, 'title' => 'S', 'published' => '1', 'unit' => '%', 'discount' => '10', 'applies_to' => '*',
    'publish_up' => '0000-00-00 00:00:00', 'publish_down' => '0000-00-00 00:00:00', 'access' => '1', 'map' => []];
$now = '2026-10-19 12:00:00';
$check('sale runs: no dates', Omnibus::saleRuns($sale(), $now), true);
$check('sale runs: unpublished', Omnibus::saleRuns($sale(['published' => '0']), $now), false);
$check('sale runs: no discount', Omnibus::saleRuns($sale(['discount' => '']), $now), false);
$check('sale runs: discount 0', Omnibus::saleRuns($sale(['discount' => '0']), $now), false);
$check('sale runs: a cart discount lowers no product price', Omnibus::saleRuns($sale(['applies_to' => 'cart']), $now), false);
$check('sale runs: not started', Omnibus::saleRuns($sale(['publish_up' => '2026-10-20 00:00:00']), $now), false);
$check('sale runs: started', Omnibus::saleRuns($sale(['publish_up' => '2026-10-19 12:00:00']), $now), true);
$check('sale runs: in its last second', Omnibus::saleRuns($sale(['publish_down' => '2026-10-19 12:00:00']), $now), true);
$check('sale runs: ended', Omnibus::saleRuns($sale(['publish_down' => '2026-10-19 11:59:59']), $now), false);
$check('sale runs: NULL dates', Omnibus::saleRuns($sale(['publish_up' => null, 'publish_down' => null]), $now), true);
$check('sale price: percent', Omnibus::salePrice(30000.0, $sale()), 27000.0);
$check('sale price: amount', Omnibus::salePrice(100.0, $sale(['unit' => 'zł', 'discount' => '15.5'])), 84.5);
$check('applies: every product', Omnibus::saleAppliesTo($sale(), 4, '', []), true);
$check('applies: category of the product', Omnibus::saleAppliesTo($sale(['applies_to' => 'category', 'map' => [['item_id' => '15', 'variation' => '']]]), 4, '', [15, 14, 10]), true);
$check('applies: a category above it', Omnibus::saleAppliesTo($sale(['applies_to' => 'category', 'map' => [['item_id' => '10', 'variation' => '']]]), 4, '', [15, 14, 10]), true);
$check('applies: another category', Omnibus::saleAppliesTo($sale(['applies_to' => 'category', 'map' => [['item_id' => '12', 'variation' => '']]]), 4, '', [15, 14, 10]), false);
$check('applies: the product', Omnibus::saleAppliesTo($sale(['applies_to' => 'product', 'map' => [['item_id' => '4', 'variation' => '']]]), 4, '', []), true);
$check('applies: the product, not its version', Omnibus::saleAppliesTo($sale(['applies_to' => 'product', 'map' => [['item_id' => '4', 'variation' => '']]]), 4, 'k1', []), false);
$check('applies: a chosen version', Omnibus::saleAppliesTo($sale(['applies_to' => 'product', 'map' => [['item_id' => '4', 'variation' => 'k1']]]), 4, 'k1', []), true);
$check('applies: cart never', Omnibus::saleAppliesTo($sale(['applies_to' => 'cart']), 4, '', []), false);

$items = Omnibus::items([
    ['product_id' => '4', 'price' => '30000', 'sale_price' => '', 'variations' => json_encode(['k1' => ['price' => '100', 'sale_price' => ''], 'k2' => ['price' => '200', 'sale_price' => '150']])],
    ['product_id' => '5', 'price' => '1000', 'sale_price' => '900', 'variations' => '{}'],
    ['product_id' => '6', 'price' => '', 'sale_price' => '', 'variations' => '{}'],
    ['product_id' => '7', 'price' => '500', 'sale_price' => '', 'variations' => '{}'],
]);
$cats  = [4 => [15, 14, 10], 5 => [15, 14, 10], 6 => [15], 7 => [12, 11, 10]];
$cat15 = $sale(['id' => 3, 'applies_to' => 'category', 'map' => [['item_id' => '15', 'variation' => '']]]);
$with  = Omnibus::withSales($items, [$cat15], $cats, [1, 5]);
$check('with sales: product of the category', [$with['4']['sale'], $with['4']['via']], [27000.0, 3]);
$check('with sales: its version too', $with['k1']['sale'], 90.0);
$check('with sales: a version with its own sale price keeps it', [$with['k2']['sale'], isset($with['k2']['via'])], [150.0, false]);
$check('with sales: a product with its own sale price keeps it', $with['5']['sale'], 900.0);
$check('with sales: no price, nothing', $with['6']['sale'], null);
$check('with sales: other category untouched', $with['7']['sale'], null);
$all = $sale(['id' => 9, 'discount' => '50']);
$with = Omnibus::withSales($items, [$all, $cat15], $cats, [1]);
$check('with sales: the first sale (lowest number) wins', [$with['4']['sale'], $with['4']['via']], [27000.0, 3]);
$check('with sales: the other one for the rest', [$with['7']['sale'], $with['7']['via']], [250.0, 9]);
$check('with sales: a sale for registered users only is not public', Omnibus::withSales($items, [$sale(['access' => '2'])], $cats, [1, 5])['7']['sale'], null);
$check('with sales: no sales, items as they were', Omnibus::withSales($items, [], $cats, [1]), $items);

$zone = new DateTimeZone('Europe/Warsaw');
$check('next change: a planned start (Warsaw time to UTC)', Omnibus::nextSaleChange([$sale(['publish_up' => '2026-10-20 08:00:00'])], $now, $zone), strtotime('2026-10-20 06:00:00 UTC'));
$check('next change: the end, a second after its last one', Omnibus::nextSaleChange([$sale(['publish_down' => '2026-10-25 23:59:59'])], $now, $zone), strtotime('2026-10-25 23:00:00 UTC'));
$check('next change: the sooner of several', Omnibus::nextSaleChange([$sale(['publish_up' => '2026-11-01 00:00:00']), $sale(['publish_down' => '2026-10-30 00:00:00'])], $now, $zone), strtotime('2026-10-29 23:00:01 UTC'));
$check('next change: nothing planned', Omnibus::nextSaleChange([$sale(), $sale(['publish_down' => '2026-10-01 00:00:00'])], $now, $zone), null);
$check('next change: cart and unpublished ignored', Omnibus::nextSaleChange([$sale(['applies_to' => 'cart', 'publish_up' => '2026-10-20 00:00:00']), $sale(['published' => '0', 'publish_up' => '2026-10-20 00:00:00'])], $now, $zone), null);

$check('state: running', Omnibus::saleState($sale(), $now, [1, 5]), ['state' => 'running', 'covered' => true, 'reason' => '']);
$check('state: planned', Omnibus::saleState($sale(['publish_up' => '2026-12-01 00:00:00']), $now, [1])['state'], 'planned');
$check('state: ended', Omnibus::saleState($sale(['publish_down' => '2026-01-01 00:00:00']), $now, [1])['state'], 'ended');
$check('state: off', Omnibus::saleState($sale(['published' => '0']), $now, [1])['state'], 'off');
$check('state: cart not covered', Omnibus::saleState($sale(['applies_to' => 'cart']), $now, [1]), ['state' => 'running', 'covered' => false, 'reason' => 'cart']);
$check('state: registered only not covered', Omnibus::saleState($sale(['access' => '2']), $now, [1, 5])['reason'], 'access');

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
