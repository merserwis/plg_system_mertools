<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Tabs.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Tabs;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$check('on by default', Tabs::enabled(new Registry()), true);
$check('switched off', Tabs::enabled(new Registry(['tabs_accordion' => '0'])), false);
$check('defaults', Tabs::jsConfig(new Registry()), ['width' => 768, 'first' => true]);
$check('width clamped low', Tabs::jsConfig(new Registry(['tabs_accordion_width' => '100']))['width'], 320);
$check('width clamped high', Tabs::jsConfig(new Registry(['tabs_accordion_width' => '9999']))['width'], 1600);
$check('first closed', Tabs::jsConfig(new Registry(['tabs_accordion_first' => '0']))['first'], false);
$css = Tabs::css();
$check('rows hidden outside phone mode', str_starts_with($css, '.mt-tabacc-head{display:none}'), true);
$check('theme colours used', str_contains($css, 'var(--primary') && str_contains($css, 'var(--title'), true);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
