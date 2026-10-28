<?php

// The map of the administrator (com_mertools Helper/Catalog.php): the states of the tiles and the quick setup.

define('_JEXEC', 1);
spl_autoload_register(function ($c) {
    if ($c === 'Joomla\\Registry\\Registry') {
        eval('namespace Joomla\\Registry; class Registry { private array $d; public function __construct($d = []) { $this->d = is_array($d) ? $d : []; }'
            . ' public function get($k, $def = null) { return array_key_exists($k, $this->d) ? $this->d[$k] : $def; } public function toArray(): array { return $this->d; } }');
    }
});
require __DIR__ . '/../com_mertools/admin/src/Helper/Catalog.php';

use Joomla\Registry\Registry;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog as C;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$defaults = ['speed_cache' => '0', 'sitemap_enabled' => '0', 'hygiene_csp_mode' => 'off', 'apps_backup_auto' => 'daily', 'list_hide_words' => ''];

// a setting that is on
$check('on: a switch at 1', C::isOn(new Registry(['speed_cache' => '1']), $defaults, 'speed_cache'), true);
$check('on: the default when never saved', C::isOn(new Registry([]), $defaults, 'speed_cache'), false);
$check('on: a list "off" is off', C::isOn(new Registry([]), $defaults, 'hygiene_csp_mode'), false);
$check('on: a list "report" is on', C::isOn(new Registry(['hygiene_csp_mode' => 'report']), $defaults, 'hygiene_csp_mode'), true);
$check('on: a list default "daily" is on', C::isOn(new Registry([]), $defaults, 'apps_backup_auto'), true);
$check('on: an empty text is off, a text is on', [C::isOn(new Registry([]), $defaults, 'list_hide_words'),
    C::isOn(new Registry(['list_hide_words' => "[ENDED]"]), $defaults, 'list_hide_words')], [false, true]);
$check('on: an unknown setting without a default is off', C::isOn(new Registry([]), [], 'nothing'), false);

// the state of a tile
$check('state: any switch on', C::state(['sitemap_enabled', 'speed_cache'], new Registry(['speed_cache' => 1]), $defaults), 'on');
$check('state: none on', C::state(['sitemap_enabled', 'speed_cache'], new Registry([]), $defaults), 'off');
$check('state: no switches, no state', C::state([], new Registry([]), $defaults), '');

// the tiles
$tiles = C::tiles(new Registry([]), $defaults, false);
$check('tiles: no store, no shop section', isset($tiles['shop']), false);
$check('tiles: no store, no shop tab among the configuration', in_array('shop', array_column($tiles['config'], 'name'), true), false);
$check('tiles: the configuration page itself is not a tile', in_array('config', array_merge(...array_values(array_map(fn ($g) => array_column($g, 'name'), $tiles))), true), false);
$withStore = C::tiles(new Registry([]), $defaults, true);
$check('tiles: with a store, the shop section', array_column($withStore['shop'], 'name'), ['omnibus', 'carts']);
$check('tiles: a configuration tile opens its tab', $withStore['config'][0]['link'], 'index.php?option=com_mertools&view=page&page=config&tab=url');
$check('tiles: sections in order, configuration last', array_keys($withStore), ['speed', 'seo', 'shop', 'gridbox', 'config']);

// quick setup
$hyg = C::SETUP['site']['qs_hygiene'];
$check('setup value: all of its settings on', C::setupValue($hyg, new Registry(['hygiene_og_url' => 1, 'hygiene_cookies' => 1, 'hygiene_xss' => 1]), []), 1);
$check('setup value: one of them off', C::setupValue($hyg, new Registry(['hygiene_og_url' => 1, 'hygiene_cookies' => 0, 'hygiene_xss' => 1]), []), 0);
$check('setup: an unchanged switch writes nothing', C::setupChanges(['qs_hygiene' => '0'], ['qs_hygiene' => '0'], true), []);
$check('setup: a changed switch writes all its settings', C::setupChanges(['qs_hygiene' => '1'], ['qs_hygiene' => '0'], true),
    ['hygiene_og_url' => 1, 'hygiene_cookies' => 1, 'hygiene_xss' => 1]);
$check('setup: turned off', C::setupChanges(['lock_enabled' => '0'], ['lock_enabled' => '1'], true), ['lock_enabled' => 0]);
$check('setup: a switch not sent writes nothing', C::setupChanges([], ['lock_enabled' => '1'], true), []);
$check('setup: an unknown switch is ignored', C::setupChanges(['speed_key' => 'x', 'evil' => '1'], [], true), []);
$check('setup: no store, the shop switches are ignored', C::setupChanges(['shop_omnibus' => '1'], ['shop_omnibus' => '0'], false), []);
$check('setup: with a store they are saved', C::setupChanges(['shop_omnibus' => '1'], ['shop_omnibus' => '0'], true), ['shop_omnibus' => 1]);
$check('setup: any value but 1 is off', C::setupChanges(['lock_enabled' => '2'], ['lock_enabled' => '1'], true), ['lock_enabled' => 0]);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
