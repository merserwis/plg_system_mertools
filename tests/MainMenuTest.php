<?php

/*
 * The main menu on screens a little too narrow for it: holding the panel on the way to it is on by default,
 * fitting the menu on one line is off by default, the script gets both switches, and the style that shows a
 * held panel only touches the direct panels of a held item of a Gridbox main menu.
 */

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/MainMenu.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\MainMenu;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$check('defaults: hold on, fit off', MainMenu::jsConfig(new Registry([])), ['hold' => 1, 'fit' => 0]);
$check('defaults: enabled', MainMenu::enabled(new Registry([])), true);
$check('both off: not enabled', MainMenu::enabled(new Registry(['layout_menu_hold' => '0', 'layout_menu_fit' => '0'])), false);
$check('fit only', MainMenu::jsConfig(new Registry(['layout_menu_hold' => '0', 'layout_menu_fit' => '1'])), ['hold' => 0, 'fit' => 1]);
$check('fit only: enabled', MainMenu::enabled(new Registry(['layout_menu_hold' => '0', 'layout_menu_fit' => '1'])), true);

$css = MainMenu::css();
$check('css: braces balance', substr_count($css, '{') === substr_count($css, '}'), true);
$check('css: every selector is of a held item of a main menu', (bool) preg_match_all('/(^|[,}])\.ba-item-main-menu li\.mt-menu-hold>/', $css) && !preg_match('/(^|[,}])(?!\.ba-item-main-menu li\.mt-menu-hold>)[^,{}]+[,{]/', $css), true);
$check('css: the panel shown', str_contains($css, 'display:block!important'), true);

exit($fail ? 1 : 0);
