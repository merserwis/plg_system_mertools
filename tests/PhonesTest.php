<?php

/*
 * Click to call: numbers written without a prefix get a country code only when one is set; a new site
 * (setting never saved) links only numbers written with + or 00.
 */

define('_JEXEC', 1);
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });
require __DIR__ . '/../plg_system_mertools/src/Tool/Phones.php';

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Phones;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$check('on by default', Phones::enabled(new Registry()), true);
$check('no country when never saved (no +48 guessed)', Phones::jsConfig(new Registry())['cc'], '');
$check('saved Poland stays Poland', Phones::jsConfig(new Registry(['tel_country' => '48']))['cc'], '48');
$check('country written with + and spaces', Phones::jsConfig(new Registry(['tel_country' => '+ 34']))['cc'], '34');
$check('country cleared by the administrator', Phones::jsConfig(new Registry(['tel_country' => '']))['cc'], '');
$check('all devices by default', Phones::jsConfig(new Registry())['touch'], false);
$check('touch screens only', Phones::jsConfig(new Registry(['tel_devices' => 'touch']))['touch'], true);
$check('exclude: rule breakers removed', Phones::jsConfig(new Registry(['tel_exclude' => ' .codes}{<b>; #list ']))['exclude'], '.codesb #list');
$check('style as text by default', str_contains(Phones::css(new Registry()), 'color:inherit'), true);
$check('style as links', str_contains(Phones::css(new Registry(['tel_style' => 'link'])), 'color:inherit'), false);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
