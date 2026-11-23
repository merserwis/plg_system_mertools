<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/GridboxReads.php';

use Merserwis\Plugin\System\MerTools\Tool\GridboxReads;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

$check('the calendar of a month, POST', GridboxReads::needsToken('com_gridbox', 'calendar.render', 'POST'), true);
$check('a product\'s free hours, as Gridbox writes it', GridboxReads::needsToken('com_gridbox', 'calendar.getSingleSlots', 'post'), true);
$check('GET: nothing to do', GridboxReads::needsToken('com_gridbox', 'calendar.render', 'GET'), false);
$check('another component', GridboxReads::needsToken('com_content', 'calendar.render', 'POST'), false);
// the panel's other calls to the site change something: their token check stays
$check('the licence (a change) not let through', GridboxReads::needsToken('com_gridbox', 'editor.setAppLicense', 'POST'), false);
$check('deleting an attachment not let through', GridboxReads::needsToken('com_gridbox', 'store.removeAttachment', 'POST'), false);
$check('another task of the calendar controller not let through', GridboxReads::needsToken('com_gridbox', 'calendar.save', 'POST'), false);
$check('no task', GridboxReads::needsToken('com_gridbox', '', 'POST'), false);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
