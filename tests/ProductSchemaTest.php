<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ProductSchema.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\ProductSchema;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// settings
$check('off by default', ProductSchema::enabled(new Registry()), false);
$check('mpn: none by default, unknown mode = none', [ProductSchema::mpnMode(new Registry()), ProductSchema::mpnMode(new Registry(['shop_jsonld_mpn' => 'x']))], ['none', 'none']);

// brand field
$fields = [['id' => 1, 'type' => 'product-slideshow', 'label' => 'Zdjęcia'], ['id' => 20, 'type' => 'url', 'label' => 'Producent:'], ['id' => 26, 'type' => 'text', 'label' => 'Uwaga:']];
$check('brand field found by its label', ProductSchema::brandField($fields, 'auto'), 20);
$check('brand field chosen', ProductSchema::brandField($fields, '26'), 26);
$check('brand field chosen but gone', ProductSchema::brandField($fields, '99'), null);
$check('no brand field', ProductSchema::brandField([['id' => 3, 'type' => 'text', 'label' => 'Kolor']], 'auto'), null);
$check('link field: its label', ProductSchema::fieldText('url', '{"label":"Sonel","link":"index.php?option=com_gridbox&view=page&id=256"}'), 'Sonel');
$check('link field without a label', ProductSchema::fieldText('url', '{"link":"x"}'), '');
$check('text field: plain text', ProductSchema::fieldText('text', ' <b>GW&nbsp;Instek</b> '), 'GW Instek');

// mpn
$check('mpn after the slash', ProductSchema::mpn('SONE/WAPRZPN30', 'after_slash'), 'WAPRZPN30');
$check('mpn after the last slash', ProductSchema::mpn('A/B/C-1', 'after_slash'), 'C-1');
$check('mpn: no slash, the whole SKU', ProductSchema::mpn('MPI540', 'after_slash'), 'MPI540');
$check('mpn: the whole SKU', ProductSchema::mpn('SONE/PN30', 'sku'), 'SONE/PN30');
$check('mpn: none or empty', [ProductSchema::mpn('SONE/PN30', 'none'), ProductSchema::mpn(' ', 'sku'), ProductSchema::mpn('SONE/', 'after_slash')], [null, null, null]);

// text and prices
$check('text: tags, entities, lines', ProductSchema::text("<p>Miernik\n„MPI”</p><p>Zakres &amp; 2&nbsp;A</p>"), 'Miernik „MPI” Zakres & 2 A');
$check('text: cut at a word', ProductSchema::text(str_repeat('słowo ', 20), 30), 'słowo słowo słowo słowo słowo…');
$check('amounts', [ProductSchema::amount('3999'), ProductSchema::amount('3 999,50'), ProductSchema::amount('0'), ProductSchema::amount(''), ProductSchema::amount('abc'), ProductSchema::amount(null)], [3999.0, 3999.5, null, null, null, null]);
$check('sale price wins when lower', ProductSchema::price('3999', '3799'), 3799.0);
$check('sale price not lower: the price', ProductSchema::price('100', '120'), 100.0);
$check('only a sale price', ProductSchema::price('', '50'), 50.0);
$check('no price', ProductSchema::price('0', ''), null);

// offers
$base = ['currency' => 'PLN', 'url' => 'https://x.pl/p', 'availability' => 'https://schema.org/InStock'];
$check('price on request: no offer', ProductSchema::offers($base + ['price' => '0', 'sale' => '', 'variations' => []]), null);
$check('versions without prices: no offer', ProductSchema::offers($base + ['price' => '', 'variations' => ['a' => ['price' => '0', 'sale_price' => '']]]), null);
$one = ProductSchema::offers($base + ['price' => '3999', 'sale' => '3799', 'vat' => false]);
$check('one offer: sale price, new, VAT excluded', [$one['@type'], $one['price'], $one['itemCondition'], $one['priceSpecification']['valueAddedTaxIncluded']],
    ['Offer', '3799', 'https://schema.org/NewCondition', false]);
$check('price with cents', ProductSchema::offers($base + ['price' => '19.90'])['price'], '19.9');
$check('VAT unknown: no price specification', isset(ProductSchema::offers($base + ['price' => '10'])['priceSpecification']), false);
$range = ProductSchema::offers($base + ['price' => '0', 'variations' => ['a' => ['price' => '250', 'sale_price' => ''], 'b' => ['price' => '0'], 'c' => ['price' => '120', 'sale_price' => '99']]]);
$check('only versions priced: a range', [$range['@type'], $range['lowPrice'], $range['highPrice'], $range['offerCount']], ['AggregateOffer', '99', '250', 2]);

// the Product
$gridbox = ['@context' => 'https://schema.org/', '@type' => 'Product', 'name' => 'x', 'description' => '', 'gtin13' => '5901234123457',
    'brand' => ['@type' => 'Brand', 'name' => 'index.php?option=com_gridbox&view=page&id=256'], 'offers' => ['price' => '0']];
$p = ProductSchema::build(['name' => 'Sonel MPI-540', 'url' => 'https://x.pl/p', 'images' => ['https://x.pl/a.jpg', 'https://x.pl/b.jpg', 'https://x.pl/a.jpg', ''],
    'description' => 'Opis', 'brand' => 'Sonel', 'sku' => 'SONE/MPI540', 'mpn' => 'MPI540', 'offers' => null], $gridbox);
$check('Product: context without the slash', $p['@context'], 'https://schema.org');
$check('Product: images, repeated and empty ones out', $p['image'], ['https://x.pl/a.jpg', 'https://x.pl/b.jpg']);
$check('Product: brand as a name', $p['brand'], ['@type' => 'Brand', 'name' => 'Sonel']);
$check('Product: no offer, not Gridbox\'s price 0', isset($p['offers']), false);
$check('Product: a key of Gridbox\'s block kept', $p['gtin13'], '5901234123457');
$check('Product: one image as a string', ProductSchema::build(['name' => 'n', 'url' => 'u', 'images' => ['i']], null)['image'], 'i');
$check('Product: empty values left out', array_keys(ProductSchema::build(['name' => 'n', 'url' => 'u', 'description' => '', 'brand' => '', 'mpn' => null], null)), ['@context', '@type', 'name', 'url']);

// the page
$script = ProductSchema::script(['@type' => 'Product', 'name' => "A \"quoted\"\nname </script> ż"]);
$check('script: valid JSON with quotes, lines, </script>', json_decode(preg_replace('#^<script[^>]*>|</script>$#', '', $script), true)['name'], "A \"quoted\"\nname </script> ż");
$check('script: cannot end early', substr_count($script, '</script>'), 1);
$broken = "<script type=\"application/ld+json\">\n{\"@type\": \"Product\", \"description\": \"line\nbreak\"}\n</script>";
$faq    = '<script type="application/ld+json">{"@type":"FAQPage"}</script>';
$good   = '<script type="application/ld+json">{"@type":"Product","name":"x"}</script>';
$check('find: a readable and a broken Product, not the FAQ', array_map(fn ($b) => $b[1] === null ? 'broken' : $b[1]['name'], ProductSchema::find("<head>$good</head><body>$faq$broken</body>")), ['x', 'broken']);
$page = ProductSchema::replace("<head>$good</head><body>$faq$broken</body>", '<script>NEW</script>');
$check('replace: the first Product replaced, the second removed, FAQ kept', $page, "<head><script>NEW</script></head><body>$faq</body>");
$check('replace: no Product, added before </head>', ProductSchema::replace('<html><head><title>t</title></head></html>', '<s/>'), "<html><head><title>t</title><s/>\n</head></html>");

// availability from a field
$rulesText = "Brak na magazynie = BackOrder\n  tygodni => backorder \nW magazynie = InStock\nzła reguła\nX = Sold";
$rules = ProductSchema::availabilityRules(new Registry(['shop_jsonld_avail_rules' => $rulesText]));
$check('rules: read, value names fixed, bad lines out', $rules, [['Brak na magazynie', 'BackOrder'], ['tygodni', 'BackOrder'], ['W magazynie', 'InStock']]);
$check('availability field: none by default', ProductSchema::availabilityField(new Registry()), 0);
$check('default: keep Gridbox\'s, or a known value', [ProductSchema::availabilityDefault(new Registry()), ProductSchema::availabilityDefault(new Registry(['shop_jsonld_avail_default' => 'BackOrder'])), ProductSchema::availabilityDefault(new Registry(['shop_jsonld_avail_default' => 'Sold']))], ['', 'BackOrder', '']);
$g = 'https://schema.org/InStock';
$check('rule: out of stock → BackOrder', ProductSchema::availability('3288: Brak na magazynie. Zapytaj o dostępność.', $rules, 'BackOrder', $g), 'https://schema.org/BackOrder');
$check('rule: European warehouse → InStock', ProductSchema::availability('L2001: W magazynie europejskim: ok. 1 tydzień', $rules, 'BackOrder', $g), 'https://schema.org/InStock');
$check('rule: weeks → BackOrder, letter case ignored', ProductSchema::availability('OD 5 DO 9 TYGODNI', $rules, '', $g), 'https://schema.org/BackOrder');
$check('no text: the default', ProductSchema::availability('', $rules, 'BackOrder', $g), 'https://schema.org/BackOrder');
$check('no rule, no default: Gridbox\'s', ProductSchema::availability('cokolwiek', $rules, '', 'https://schema.org/OutOfStock'), 'https://schema.org/OutOfStock');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
