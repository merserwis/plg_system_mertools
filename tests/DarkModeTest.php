<?php

define('_JEXEC', 1);
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });
// the media field's value without its #joomlaImage part, and the folder of the site ($GLOBALS['mtRoot'])
eval('namespace Joomla\\CMS\\HTML; class HTMLHelper { public static function cleanImageURL($u) { return (object) ["url" => explode("#", $u)[0]]; } public static function __callStatic($n, $a) { return ""; } }');
eval('namespace Joomla\\CMS\\Uri; class Uri { public static function root($pathOnly = false) { return $GLOBALS["mtRoot"] ?? ""; } public static function __callStatic($n, $a) { return ""; } }');
require __DIR__ . '/../plg_system_mertools/src/Tool/DarkMode.php';

use Joomla\Registry\Registry;
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

// the dark logo: a site in the root and a site in a folder (/shop)
$logo = fn (string $v) => DarkMode::logoUrl(new Registry(['dark_logo' => $v]));
$check('logo: none', $logo(''), null);
$check('logo: media field value, site in the root', $logo('images/logo-dark.svg#joomlaImage://local-images/logo-dark.svg?width=200&height=50'), '/images/logo-dark.svg');
$check('logo: from the root, site in the root', $logo('/images/logo-dark.svg'), '/images/logo-dark.svg');
$GLOBALS['mtRoot'] = '/shop';
$check('logo: media field value, site in a folder', $logo('images/logo-dark.svg'), '/shop/images/logo-dark.svg');
$check('logo: from the root, site in a folder (not /shop/shop)', $logo('/shop/images/logo-dark.svg'), '/shop/images/logo-dark.svg');
$check('logo: full address kept', $logo('https://cdn.example.com/logo.svg'), 'https://cdn.example.com/logo.svg');
$check('logo: protocol-relative kept', $logo('//cdn.example.com/logo.svg'), '//cdn.example.com/logo.svg');
$check('logo: javascript refused', $logo('javascript:alert(1)'), null);
$GLOBALS['mtRoot'] = '';

// the early script: storage the browser blocks does not skip the default theme
$js    = DarkMode::inlineScript(new Registry(['dark_default' => 'dark']));
$first = strpos($js, '}catch(e){}');
$check('early script: storage read in its own try', $first !== false && strrpos($js, 'localStorage') < $first, true);
$check('early script: theme set after the storage try', strpos($js, 'setAttribute("data-mertools-theme"', (int) $first) !== false, true);
$check('early script: default dark handed over', str_contains($js, 'd="dark"'), true);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
