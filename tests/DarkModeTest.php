<?php

define('_JEXEC', 1);
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });
foreach (['Joomla\\CMS\\HTML\\HTMLHelper', 'Joomla\\CMS\\Uri\\Uri'] as $stub) {
    $ns = substr($stub, 0, strrpos($stub, '\\'));
    $cl = substr($stub, strrpos($stub, '\\') + 1);
    eval("namespace $ns; class $cl { public static function __callStatic(\$n, \$a) { return ''; } }");
}
require __DIR__ . '/../plg_system_mertools/src/Tool/DarkMode.php';

use Merserwis\Plugin\System\MerTools\Tool\DarkMode;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$d = 'header img';
$check('selector: plain', DarkMode::cssSelector('header .ba-item-logo img', $d), 'header .ba-item-logo img');
$check('selector: list with attribute', DarkMode::cssSelector('img[src*="logo"], .brand > img:not(.x)', $d), 'img[src*="logo"], .brand > img:not(.x)');
$check('selector: whitespace folded', DarkMode::cssSelector("  header\n img ", $d), 'header img');
$check('selector: empty = default', DarkMode::cssSelector('', $d), $d);
$check('selector: open quote = default', DarkMode::cssSelector('header img"', $d), $d);
$check('selector: comment = default', DarkMode::cssSelector('header /* img', $d), $d);
$check('selector: brace = default', DarkMode::cssSelector('header img}', $d), $d);
$check('selector: semicolon = default', DarkMode::cssSelector('header img;color:red', $d), $d);
$check('selector: open bracket = default', DarkMode::cssSelector('img[src', $d), $d);
$check('selector: tag = default', DarkMode::cssSelector('img<style>', $d), $d);
$check('selector: backslash = default', DarkMode::cssSelector('img\\:x', $d), $d);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
