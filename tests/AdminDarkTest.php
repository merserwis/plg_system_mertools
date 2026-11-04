<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/AdminDark.php';

use Merserwis\Plugin\System\MerTools\Tool\AdminDark;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
$light = static function (string $hex): float {
    $c = AdminDark::parse($hex);

    return (0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]) / 255;
};

// colours
$check('white background turns dark', $light(AdminDark::value('#fff', 'bg')) < 0.2, true);
$check('dark text turns light', $light(AdminDark::value('#1a1a1a', 'text')) > 0.75, true);
$check('grey text stays readable', $light(AdminDark::value('#757575', 'text')) > 0.5, true);
$check('light border turns dark', $light(AdminDark::value('#f3f3f3', 'line')) < 0.3, true);
$check('Gridbox blue background kept', AdminDark::value('#1da6f4', 'bg'), '#1da6f4');
$check('red button kept', AdminDark::value('#f64231', 'bg'), '#f64231');
$check('dark sidebar kept', AdminDark::value('#2f3243', 'bg'), '#2f3243');
$check('white text kept (on coloured buttons)', AdminDark::value('#fff', 'text'), '#fff');
$check('blue link text kept', AdminDark::value('#1887cf', 'text'), '#1887cf');
$check('alpha kept', str_starts_with(AdminDark::value('rgba(0,0,0,.54)', 'text'), 'rgba(') && str_ends_with(AdminDark::value('rgba(0,0,0,.54)', 'text'), ',0.54)'), true);
$check('black overlay kept', AdminDark::value('rgba(0,0,0,.5)', 'bg'), 'rgba(0,0,0,.5)');
$check('named white', $light(AdminDark::value('white', 'bg')) < 0.2, true);
$check('url left alone', AdminDark::value('url(data:image/png;base64,#fff) #fff', 'bg') === 'url(data:image/png;base64,#fff) ' . AdminDark::value('#fff', 'bg'), true);
$check('gradient colours turned', str_contains(AdminDark::value('linear-gradient(#fff, #eee)', 'bg'), '#fff'), false);
$check('transparent left', AdminDark::value('transparent', 'bg'), 'transparent');

// selectors
$check('scope: plain', AdminDark::scope('.a .b, td'), 'html[data-color-scheme="dark"] .a .b,html[data-color-scheme="dark"] td');
$check('scope: html', AdminDark::scope('html body.x'), 'html[data-color-scheme="dark"] body.x');
$check('scope: :root', AdminDark::scope(':root'), ':root[data-color-scheme="dark"]');
$check('scope: comma inside :not()', AdminDark::scope('a:not(.x, .y)'), 'html[data-color-scheme="dark"] a:not(.x, .y)');

// stylesheet
$css = '/* c */ @charset "utf-8"; .box{background:#fff;padding:4px;color:#333} .none{margin:0}'
    . '@media (max-width:600px){.m{border:1px solid #ddd}} @keyframes k{from{color:#000}} .v{color:var(--x)} .act{color:#fff!important} .no{border:none}';
$out = AdminDark::css($css);
$check('only colour declarations', str_contains($out, 'padding'), false);
$check('rule without colours left out', str_contains($out, '.none'), false);
$check('media kept around its rules', (bool) preg_match('/@media \(max-width:600px\)\{html\[data-color-scheme="dark"\] \.m\{border:1px solid #[0-9a-f]{6}\}\}/', $out), true);
$check('keyframes left out', str_contains($out, '@keyframes'), false);
$check('variables left to Joomla', str_contains($out, '.v{'), false);
$check('unchanged colour repeated (keeps the cascade)', str_contains($out, 'html[data-color-scheme="dark"] .act{color:#fff!important}'), true);
$check('border none repeated', str_contains($out, '.no{border:none}'), true);
$check('charset skipped', str_contains($out, 'charset'), false);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
