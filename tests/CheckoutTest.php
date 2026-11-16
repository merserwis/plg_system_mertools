<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Checkout.php';

use Merserwis\Plugin\System\MerTools\Tool\Checkout;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-70s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

// the rows as Gridbox 2.20.5 writes them (templates/…/store/checkout-order.php)
$row = static function (string $type, string $symbol, string $value = '1 000', bool $code = false): string {
    $cur = $code ? '<span class="ba-checkout-order-price-currency" data-currency="PLN">' . "\n                " . $symbol . "            </span>"
        : '<span class="ba-checkout-order-price-currency">' . $symbol . '</span>';

    return '<div class="ba-checkout-order-form-row ba-checkout-order-form-' . $type . '">'
        . "\n        <span class=\"ba-checkout-order-form-row-title\">Title</span>"
        . "\n        <span class=\"ba-checkout-order-price-wrapper right-currency-position\">\n            " . $cur
        . "\n            <span class=\"ba-checkout-order-price-value\">" . $value . "</span>\n        </span>\n    </div>";
};
$form = static fn (string $rows): string => '<div class="ba-checkout-order-form-section ba-checkout-order-form-total-wrapper">' . $rows . '</div>';
$cur  = static function (string $html): array {
    preg_match_all('#<span class="ba-checkout-order-price-currency"[^>]*>([^<]*)</span>#', $html, $m);

    return array_map('trim', $m[1]);
};

$page = $form($row('subtotal', 'zł netto') . $row('discount', 'zł netto') . $row('shipping', 'zł netto') . $row('shipping-tax', 'zł netto')
    . $row('tax', 'zł netto') . $row('total', 'zł netto', '5 897,85', true));
$check('net rows kept, tax without the word, total gross', $cur(Checkout::currencyWords($page)), ['zł netto', 'zł netto', 'zł netto', 'zł', 'zł', 'zł brutto']);
$check('…the amounts untouched', substr_count(Checkout::currencyWords($page), '5 897,85'), 1);

$later = $form($row('subtotal', 'zł netto') . $row('tax', 'zł netto') . $row('paying-now', 'zł netto', '100', true) . $row('pay-leter', 'zł netto', '900'));
$check('paying now and left to pay: gross', $cur(Checkout::currencyWords($later)), ['zł netto', 'zł', 'zł brutto', 'zł brutto']);

$check('Netto → Brutto', $cur(Checkout::currencyWords($form($row('total', 'PLN Netto')))), ['PLN Brutto']);
$check('NETTO → BRUTTO', $cur(Checkout::currencyWords($form($row('total', 'ZŁ NETTO')))), ['ZŁ BRUTTO']);
$check('the word before the symbol (left position)', $cur(Checkout::currencyWords($form($row('tax', 'netto zł') . $row('total', 'netto zł')))), ['zł', 'brutto zł']);
$check('a currency that is only the word: tax keeps it', $cur(Checkout::currencyWords($form($row('tax', 'netto')))), ['netto']);
$check('a currency without the word: as it is', Checkout::currencyWords($form($row('total', 'zł'))), $form($row('total', 'zł')));
$check('"nettoX" is not the word', $cur(Checkout::currencyWords($form($row('total', 'nettox')))), ['nettox']);
$check('a row of a total elsewhere (cart): as it is', Checkout::currencyWords('<div class="ba-cart-total"><span class="ba-checkout-order-price-currency">zł netto</span></div>'),
    '<div class="ba-cart-total"><span class="ba-checkout-order-price-currency">zł netto</span></div>');
$check('a total row without a currency does not take the next row\'s',
    $cur(Checkout::currencyWords($form('<div class="ba-checkout-order-form-row ba-checkout-order-form-total"><span>x</span></div>' . $row('subtotal', 'zł netto')))), ['zł netto']);

// the drop-down fields of the checkout form
$html = '<html><head><title>t</title></head><body><div class="ba-item-checkout-form"><select></select></div></body></html>';
$out  = Checkout::apply($html);
$check('the style for the drop-down fields in the head', substr_count($out, '<style id="mertools-checkout">'), 1);
$check('…before </head>', strpos($out, '</style></head>') !== false, true);
$check('…once (a page made again)', Checkout::apply($out), $out);
$check('…with the nonce of the CSP', str_contains(Checkout::apply($html, 'abc"'), '<style id="mertools-checkout" nonce="abc&quot;">'), true);
$check('a page without the checkout form: as it is', Checkout::apply('<html><head></head><body><p>x</p></body></html>'), '<html><head></head><body><p>x</p></body></html>');
$check('the order form alone gets no style', str_contains(Checkout::apply('<head></head><div class="ba-item-checkout-order-form"></div>'), 'mertools-checkout'), false);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
