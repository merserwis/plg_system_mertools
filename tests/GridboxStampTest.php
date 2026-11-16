<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/GridboxStamp.php';

use Merserwis\Plugin\System\MerTools\Tool\GridboxStamp;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-70s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
$apply = fn (string $html) => GridboxStamp::apply($html, '2.20.5.0', 'tk3x9a');

// the version Gridbox's scripts load their modules with (templates/gridbox: index.php, component.php, error.php, offline.php)
$check('window.gridboxVersion', $apply("window.gridboxVersion = '2.20.5.0';"), "window.gridboxVersion = '2.20.5.0.tk3x9a';");
$check('in a var list (error.php)', $apply("var disableResponsive = 0, gridboxVersion = '2.20.5.0';"), "var disableResponsive = 0, gridboxVersion = '2.20.5.0.tk3x9a';");
$check('double quotes', $apply('gridboxVersion="2.20.5.0"'), 'gridboxVersion="2.20.5.0.tk3x9a"');
$check('another version left', $apply("gridboxVersion = '2.20.4.0';"), "gridboxVersion = '2.20.4.0';");

// Gridbox's files in the page
$check('a module of the site', $apply('<script src="https://x.pl/components/com_gridbox/libraries/modules/initItems.js?2.20.5.0"></script>'),
    '<script src="https://x.pl/components/com_gridbox/libraries/modules/initItems.js?2.20.5.0.tk3x9a"></script>');
$check('the template\'s script', $apply('<script src="/templates/gridbox/js/gridbox.js?2.20.5.0" nonce="n"></script>'),
    '<script src="/templates/gridbox/js/gridbox.js?2.20.5.0.tk3x9a" nonce="n"></script>');
$check('a style', $apply("<link href='/templates/gridbox/css/gridbox.css?2.20.5.0' rel='stylesheet'>"),
    "<link href='/templates/gridbox/css/gridbox.css?2.20.5.0.tk3x9a' rel='stylesheet'>");
$check('the language module (&amp;)', $apply('<script src="/index.php?option=com_gridbox&amp;task=editor.loadModule&amp;module=gridboxLanguage&amp;2.20.5.0"></script>'),
    '<script src="/index.php?option=com_gridbox&amp;task=editor.loadModule&amp;module=gridboxLanguage&amp;2.20.5.0.tk3x9a"></script>');
$check('…and with a bare &', $apply('<script src="/index.php?option=com_gridbox&task=editor.loadModule&module=gridboxLanguage&2.20.5.0"></script>'),
    '<script src="/index.php?option=com_gridbox&task=editor.loadModule&module=gridboxLanguage&2.20.5.0.tk3x9a"></script>');
$check('administrator\'s Gridbox files', $apply('<script src="/administrator/components/com_gridbox/assets/js/ba-admin.js?2.20.5.0"></script>'),
    '<script src="/administrator/components/com_gridbox/assets/js/ba-admin.js?2.20.5.0.tk3x9a"></script>');

// nothing else
$check('another extension with the same number', $apply('<script src="/media/other/x.js?2.20.5.0"></script>'), '<script src="/media/other/x.js?2.20.5.0"></script>');
$check('the version in the text', $apply('<p>Gridbox 2.20.5.0 in /components/com_gridbox/</p>'), '<p>Gridbox 2.20.5.0 in /components/com_gridbox/</p>');
$check('a longer version', $apply('<script src="/templates/gridbox/js/gridbox.js?2.20.5.01"></script>'), '<script src="/templates/gridbox/js/gridbox.js?2.20.5.01"></script>');
$check('dots are dots', $apply('<script src="/templates/gridbox/js/gridbox.js?2x20x5x0"></script>'), '<script src="/templates/gridbox/js/gridbox.js?2x20x5x0"></script>');
$check('the version not at the end of the query', $apply('<script src="/templates/gridbox/js/gridbox.js?2.20.5.0&x=1"></script>'), '<script src="/templates/gridbox/js/gridbox.js?2.20.5.0&x=1"></script>');
$once = $apply('<script src="/templates/gridbox/js/gridbox.js?2.20.5.0"></script><script>window.gridboxVersion = \'2.20.5.0\';</script>');
$check('twice is once (a kept page)', $apply($once), $once);
$check('an empty stamp: nothing', GridboxStamp::apply('<script src="/templates/gridbox/js/gridbox.js?2.20.5.0"></script>', '2.20.5.0', ''),
    '<script src="/templates/gridbox/js/gridbox.js?2.20.5.0"></script>');
$page = str_repeat('<div class="ba-row">text</div>', 2000) . '<script src="/components/com_gridbox/libraries/modules/initText.js?2.20.5.0"></script>';
$check('a long page', substr($apply($page), -60), substr(str_replace('?2.20.5.0"', '?2.20.5.0.tk3x9a"', $page), -60));

// a data: image of megabytes in a src (Codex review: the page came out empty past PCRE's backtrack limit)
$big = '<script src="/templates/gridbox/js/gridbox.js?2.20.5.0"></script><img src="data:image/png;base64,' . str_repeat('A', 2100000) . '">';
$out = $apply($big);
$check('a data: image of 2 MB: the page whole', strlen($out), strlen($big) + strlen('.tk3x9a'));
$check('…Gridbox\'s file stamped', str_contains($out, 'gridbox.js?2.20.5.0.tk3x9a"'), true);
// a pattern that cannot finish: the page as it was, never an empty one
$limit = ini_get('pcre.backtrack_limit');
$jit   = ini_get('pcre.jit');
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '1');
$small = '<script src="/templates/gridbox/js/gridbox.js?2.20.5.0"></script><p>x</p>';
$check('PCRE stopped by its limit: the page as it was', $apply($small), $small);
ini_set('pcre.backtrack_limit', (string) $limit);
ini_set('pcre.jit', (string) $jit);

// the installed Gridbox: the time of its manifest
$root = sys_get_temp_dir() . '/mt-gbstamp-' . bin2hex(random_bytes(4));
mkdir($root . '/components/com_gridbox', 0777, true);
$check('no Gridbox', GridboxStamp::installed($root), null);
file_put_contents($root . GridboxStamp::MANIFEST, "<?xml version=\"1.0\"?>\n<extension type=\"component\">\n<name>Gridbox</name>\n<version>2.20.5.0</version>\n</extension>\n");
touch($root . GridboxStamp::MANIFEST, 1794135496);
clearstatcache();
$check('version and stamp', GridboxStamp::installed($root), ['version' => '2.20.5.0', 'stamp' => base_convert('1794135496', 10, 36)]);
touch($root . GridboxStamp::MANIFEST, 1794135496 + 3600);
clearstatcache();
$check('the package installed again: another stamp', GridboxStamp::installed($root)['stamp'] !== base_convert('1794135496', 10, 36), true);
file_put_contents($root . GridboxStamp::MANIFEST, '<extension><name>Gridbox</name></extension>');
$check('a manifest without a version', GridboxStamp::installed($root), null);
unlink($root . GridboxStamp::MANIFEST);
rmdir($root . '/components/com_gridbox');
rmdir($root . '/components');
rmdir($root);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
