<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/PriceRequest.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$check('off by default', PriceRequest::enabled(new Registry()), false);
$check('on when set', PriceRequest::enabled(new Registry(['price_request' => '1'])), true);
$check('empty text = language default', PriceRequest::text(new Registry(), 'Na zapytanie'), 'Na zapytanie');
$check('spaces only = language default', PriceRequest::text(new Registry(['price_request_text' => '  ']), 'On request'), 'On request');
$check('own text, trimmed', PriceRequest::text(new Registry(['price_request_text' => ' Zapytaj o cenę ']), 'x'), 'Zapytaj o cenę');
$check('no text at all', PriceRequest::text(new Registry(), ''), '');
$check('js config carries both texts', PriceRequest::jsConfig(new Registry(), 'Na zapytanie', 'Wybierz model z listy'), ['text' => 'Na zapytanie', 'choose' => 'Wybierz model z listy']);
$check('own choose text', PriceRequest::chooseText(new Registry(['price_request_choose' => ' Wybierz zestaw ']), 'x'), 'Wybierz zestaw');
$check('empty choose text = language default', PriceRequest::chooseText(new Registry(), 'Choose a model from the list'), 'Choose a model from the list');
$check('Omnibus 0,00 line hidden', str_contains(PriceRequest::css(new Registry()), '.omnibus_info.mt-price-zero{display:none!important}'), true);

// list products: ids, products asking for a model, the mark
$page = '<ul><li class="item ba-store-app-product active" data-id="815"><div class="ba-blog-post-add-to-cart-wrapper"> </div></li>'
    . '<li class="item ba-store-app-product" data-id="3440"><b>x</b></li></ul>'
    . '<div class="ba-blog-post ba-store-app-product" data-id="1471"></div><div class="ba-store-app-product-x" data-id="9"></div>'
    . '<div class="ba-blog-post ba-store-app-product" data-id="815"></div><div data-id="7" class="ba-store-app-product"></div>';
$check('tile ids: each once, other classes ignored', PriceRequest::tileIds($page), [815, 3440, 1471, 7]);
$check('no tiles', PriceRequest::tileIds('<div class="ba-blog-post">x</div>'), []);
$v = fn (array $prices) => json_encode(array_combine(array_map(fn ($i) => (string) (1699006590350 + $i), array_keys($prices)), array_map(fn ($p) => ['price' => $p, 'sale_price' => '', 'default' => false], $prices)));
$rows = [
    ['product_id' => '815', 'price' => '0', 'variations' => $v(['6616', '7624'])],          // GW Instek GPT-12000: models with prices
    ['product_id' => '816', 'price' => '', 'variations' => $v(['', '0'])],                  // models without prices: on request
    ['product_id' => '817', 'price' => '0', 'variations' => '{}'],                          // no models
    ['product_id' => '818', 'price' => '5599', 'variations' => $v(['5599'])],               // a price of its own
    ['product_id' => '819', 'price' => '0.00', 'variations' => $v(['0', '12,50'])],         // one priced model is enough
    ['product_id' => '820', 'price' => '0', 'variations' => null],
    ['product_id' => '821', 'price' => '0', 'variations' => 'not json'],
];
$check('products asking for a model', PriceRequest::chooseIds($rows), [815, 819]);
$marked = PriceRequest::markTiles($page, [815]);
$check('both list products of 815 marked', substr_count($marked, 'data-id="815" data-mt-choose>'), 2);
$check('other products not marked', substr_count($marked, 'data-mt-choose'), 2);
$check('marking twice changes nothing', PriceRequest::markTiles($marked, [815]), $marked);
$check('no ids, page as it was', PriceRequest::markTiles($page, []), $page);
$css = PriceRequest::css(new Registry());
$check('default size 18 px', str_contains($css, 'font-size:18px'), true);
$check('never broken in two', str_contains($css, 'white-space:nowrap'), true);
$check('only the boxes it filled', substr_count($css, '.mt-price-request ') >= 2 && !str_contains($css, ',.ba-add-to-cart-price-value{'), true);
$check('size 0 = Gridbox size', str_contains(PriceRequest::css(new Registry(['price_request_size' => '0'])), 'font-size'), false);
$check('size capped', str_contains(PriceRequest::css(new Registry(['price_request_size' => '999'])), 'font-size:200px'), true);
$check('negative size = Gridbox size', str_contains(PriceRequest::css(new Registry(['price_request_size' => '-5'])), 'font-size'), false);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
