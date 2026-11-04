<?php

define('_JEXEC', 1);
require __DIR__ . '/../com_mertools/admin/src/Helper/SettingsFile.php';

use Merserwis\Component\MerTools\Administrator\Helper\SettingsFile;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

// the real manifest
$fields = SettingsFile::fields(simplexml_load_file(__DIR__ . '/../plg_system_mertools/mertools.xml')->config);
$check('settings found', count($fields) > 150, true);
$check('a switch is a setting', $fields['list_dark']['page'] ?? null, 'config');
$check('an app choice (own type) is a setting', isset($fields['sitemap_apps']), true);
$check('a panel is not', isset($fields['sitemap_panel']), false);
$check('a note is not', isset($fields['lists_note']), false);
$check('the PageSpeed key is a secret', $fields['speed_key']['secret'] ?? null, true);

// export
$json = SettingsFile::export(['list_dark' => '0', 'speed_key' => 'SECRET', 'setup_done' => 1, 'sitemap_apps' => ['2', '4']], $fields, '1.10.0', 'www.example.com', '2026-11-04T10:00:00+00:00');
$data = json_decode($json, true);
$check('export: format and version', [$data['format'], $data['version'], $data['site']], ['mertools-settings', '1.10.0', 'www.example.com']);
$check('export: saved value', $data['settings']['list_dark'], '0');
$check('export: default of a setting never saved', $data['settings']['list_pins'], '1');
$check('export: a list kept', $data['settings']['sitemap_apps'], ['2', '4']);
$check('export: a list of apps never chosen is an empty list (importing it empties the list again)', $data['settings']['apps_unlocked'] ?? null, []);
$check('export: a field without a default never saved is empty', $data['settings']['speed_video_phone_image'] ?? null, '');
$check('export: no secret', str_contains($json, 'SECRET') || isset($data['settings']['speed_key']), false);
$check('export: no internal keys', isset($data['settings']['setup_done']), false);

// read
$check('read back', SettingsFile::read($json)['settings']['list_dark'], '0');
$check('read: BOM tolerated', SettingsFile::read("\xEF\xBB\xBF" . $json)['error'], null);
$check('read: another JSON', SettingsFile::read('{"a":1}')['error'], 'format');
$check('read: not JSON', SettingsFile::read('<?php echo 1;')['error'], 'format');
$check('read: too big', SettingsFile::read(str_repeat(' ', SettingsFile::MAX_BYTES + 1))['error'], 'size');

// pick
$p = SettingsFile::pick(['list_dark' => 0, 'list_pins' => true, 'sitemap_apps' => ['2', 4], 'sitemap_panel' => 'x', 'speed_key' => 'k', 'nope' => 1,
    'sitemap_extra' => ['a' => ['b']], 'setup_pending' => 1, 'mt_admin' => 'x'], $fields);
$check('pick: known values (bool as 0/1)', $p['take'], ['list_dark' => 0, 'list_pins' => 1, 'sitemap_apps' => ['2', 4]]);
$check('pick: unknown, panels, secrets, internal left out', $p['unknown'], ['sitemap_panel', 'speed_key', 'nope', 'setup_pending', 'mt_admin']);
$check('pick: a nested value refused', $p['bad'], ['sitemap_extra']);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
