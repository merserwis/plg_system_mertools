<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/CheckoutLogic.php';

use Merserwis\Plugin\System\MerTools\Tool\CheckoutLogic;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-70s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

// the rules as the editor saves them (mertools-logic.js): numbers of fields, '' while none is chosen
$item = ['type' => 'checkout-form', CheckoutLogic::KEY => [
    ['title' => 'Zamówienie', 'off' => false, 'when' => [['field' => 19, 'op' => 'equal', 'value' => 'Zamówienie']],
        'then' => [['action' => 'show', 'field' => 30], ['action' => 'show', 'field' => '24']]],
    ['title' => 'off', 'off' => true, 'when' => [['field' => 3, 'op' => 'filled', 'value' => '']], 'then' => [['action' => 'hide', 'field' => 1]]],
    ['title' => 'unfinished', 'when' => [['field' => '', 'op' => 'equal', 'value' => '']], 'then' => [['action' => 'show', 'field' => 4]]],
    ['title' => 'no action', 'when' => [['field' => 3, 'op' => 'filled', 'value' => '']], 'then' => [['action' => 'show', 'field' => '']]],
    ['title' => 'mixed', 'when' => [['field' => 3, 'op' => 'filled'], ['field' => 4, 'op' => 'bogus', 'value' => 'x'], ['field' => 4, 'op' => 'greater', 'value' => ['x']]],
        'then' => [['action' => 'hide', 'field' => 1], ['action' => 'toggle', 'field' => 4]]],
    'not a rule',
]];
$rules = CheckoutLogic::rules($item);
$check('a rule switched on, with a condition and an action: kept', $rules[0] ?? null, ['when' => [['field' => 19, 'op' => 'equal', 'value' => 'Zamówienie']],
    'then' => [['action' => 'show', 'field' => 30], ['action' => 'show', 'field' => 24]]]);
$check('switched off, no field chosen, no action: left out', count($rules), 2);
$check('conditions of an unknown kind and actions not show/hide: left out, a value that is no text empty', $rules[1] ?? null, [
    'when' => [['field' => 3, 'op' => 'filled', 'value' => ''], ['field' => 4, 'op' => 'greater', 'value' => '']], 'then' => [['action' => 'hide', 'field' => 1]]]);
$check('an element without rules', CheckoutLogic::rules(['type' => 'checkout-form']), []);
$check('rules that are not a list', CheckoutLogic::rules([CheckoutLogic::KEY => 'x']), []);
$check('no element', CheckoutLogic::rules(null), []);
$check('a long value cut at 1000 characters', mb_strlen(CheckoutLogic::rules([CheckoutLogic::KEY => [['when' => [['field' => 1, 'op' => 'contains', 'value' => str_repeat('ż', 2000)]],
    'then' => [['action' => 'hide', 'field' => 2]]]]])[0]['when'][0]['value']), 1000);

// the page as Gridbox 2.20.5 makes it (an element of the checkout form, its fields by number)
$page = '<html><head><title>t</title></head><body><div class="ba-item-checkout-form ba-item" id="item-15289771305"><div class="ba-checkout-form-wrapper">'
    . '<div class="ba-checkout-form-fields" data-type="dropdown" data-name="19"></div></div></div></body></html>';
$check('the checkout forms of a page', CheckoutLogic::forms($page), ['item-15289771305']);
$check('…with a class of its own (the element\'s settings)', CheckoutLogic::forms('<div class="ba-item-checkout-form ba-item moja-klasa" id="item-7">'), ['item-7']);
$check('a page without one', CheckoutLogic::forms('<div class="ba-item-checkout-order-form ba-item" id="item-1"></div>'), []);

$out = CheckoutLogic::apply($page, ['item-15289771305' => $item], '/media/plg_system_mertools/js/mertools-conditions.min.js?1.16.0', 'n"1');
$check('the style before </head>, with the nonce', str_contains($out, '<style id="mertools-conditions" nonce="n&quot;1">') && str_contains($out, '</style></head>'), true);
$check('…a field a rule shows hidden until the script has looked at the form',
    str_contains($out, '#item-15289771305:not(.mt-logic) .ba-checkout-form-fields[data-name="30"]') && str_contains($out, '[data-name="24"]'), true);
$check('…a field a rule hides: shown until then', str_contains($out, '[data-name="1"]'), false);
$check('…a hidden field hidden', str_contains($out, '.ba-checkout-form-fields.mt-logic-hidden'), true);
preg_match('#<script type="application/json" id="mertools-conditions-config">(.*?)</script>#', $out, $m);
$check('the rules of the form for the script', json_decode($m[1] ?? 'null', true), ['item-15289771305' => $rules]);
$check('the script before </body>, with the nonce (a CSP with nonces would block it)',
    str_contains($out, '<script src="/media/plg_system_mertools/js/mertools-conditions.min.js?1.16.0" nonce="n&quot;1" defer></script></body>'), true);
$check('once (a page made again)', CheckoutLogic::apply($out, ['item-15289771305' => $item], '/x.js'), $out);
$check('a form without rules: the page as it is', CheckoutLogic::apply($page, ['item-15289771305' => ['type' => 'checkout-form']], '/x.js'), $page);
$check('rules of another element: as it is', CheckoutLogic::apply($page, ['item-1' => $item], '/x.js'), $page);
$check('a page without a checkout form: as it is', CheckoutLogic::apply('<html><head></head><body></body></html>', ['item-15289771305' => $item], '/x.js'),
    '<html><head></head><body></body></html>');
$evil = [CheckoutLogic::KEY => [['when' => [['field' => 1, 'op' => 'equal', 'value' => '</script><script>alert(1)</script>']], 'then' => [['action' => 'hide', 'field' => 2]]]]];
$out = CheckoutLogic::apply($page, ['item-15289771305' => $evil], '/x.js');
$check('a value cannot end the script of the rules', substr_count($out, '</script>'), 2);
preg_match('#id="mertools-conditions-config">(.*?)</script>#', $out, $m);
$check('…and is read back as typed', json_decode($m[1] ?? 'null', true)['item-15289771305'][0]['when'][0]['value'] ?? null, '</script><script>alert(1)</script>');
$check('only hiding rules: the style hides only what a rule hid', str_contains($out, ':not(.mt-logic)'), false);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
