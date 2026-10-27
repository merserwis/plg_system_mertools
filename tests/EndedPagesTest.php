<?php

define('_JEXEC', 1);
if (!interface_exists('Joomla\Database\DatabaseInterface')) {
    eval('namespace Joomla\Database; interface DatabaseInterface {}');
}
require __DIR__ . '/../plg_system_mertools/src/Tool/EndedItems.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/EndedPages.php';

use Merserwis\Plugin\System\MerTools\Tool\EndedPages;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$null  = '0000-00-00 00:00:00';
$words = ['[ZAKOŃCZONE]'];
$set   = '2026-10-27 12:00:00';

$p = EndedPages::plan([1 => ['Promocja [zakończone]', $null], 2 => ['Promocja', $null], 3 => ['Stara [ZAKOŃCZONE]', '2026-01-01 00:00:00']], [], $words, $null);
$check('a title with the word and no date gets one', $p['end'], [1]);
$check('a date of its own is left alone', [$p['back'], $p['forget']], [[], []]);
$check('an empty date of another form counts as none', EndedPages::plan([4 => ['X [ZAKOŃCZONE]', '']], [], $words, $null)['end'], [4]);

$p = EndedPages::plan([1 => ['Promocja', $set]], [1 => $set], $words, $null);
$check('the word gone from the title: the date goes', [$p['end'], $p['back'], $p['forget']], [[], [1], []]);
$p = EndedPages::plan([1 => ['Promocja [ZAKOŃCZONE]', $set]], [1 => $set], $words, $null);
$check('still finished: nothing to do', [$p['end'], $p['back'], $p['forget']], [[], [], []]);
$p = EndedPages::plan([1 => ['Promocja [ZAKOŃCZONE]', $set]], [1 => $set], [], $null);
$check('the setting emptied: the date goes', $p['back'], [1]);
$p = EndedPages::plan([1 => ['Promocja', '2026-12-31 00:00:00']], [1 => $set], $words, $null);
$check('a date changed in Gridbox is the user\'s: only forgotten', [$p['back'], $p['forget']], [[], [1]]);
$p = EndedPages::plan([1 => ['Promocja [ZAKOŃCZONE]', $null]], [1 => $set], $words, $null);
$check('a date taken away in Gridbox: forgotten (the word gives it again)', [$p['end'], $p['forget']], [[], [1]]);
$p = EndedPages::plan([], [7 => $set], $words, $null);
$check('a page deleted: forgotten', $p['forget'], [7]);

$p = EndedPages::plan([1 => ['Promocja', $set, true]], [1 => $set], $words, $null);
$check('in the trash, the word gone: left as it is, still kept', [$p['end'], $p['back'], $p['forget']], [[], [], []]);
$p = EndedPages::plan([1 => ['Promocja', $set, false]], [1 => $set], $words, $null);
$check('back from the trash without the word: the date goes', $p['back'], [1]);
$p = EndedPages::plan([2 => ['X [ZAKOŃCZONE]', $null, true]], [], $words, $null);
$check('in the trash: no date given', $p['end'], []);
$p = EndedPages::plan([1 => ['X [ZAKOŃCZONE]', $null, false]], [1 => $set], $words, $null);
$check('a note whose date never came (stopped): forgotten, not taken back', [$p['back'], $p['forget']], [[], [1]]);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
