<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/StaleCode.php';

use Merserwis\Plugin\System\MerTools\Tool\StaleCode;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-70s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
/** the error PHP itself throws for $code */
$thrown = function (string $code): \Throwable {
    try {
        eval($code);
    } catch (\Throwable $e) {
        return $e;
    }
    throw new \RuntimeException('nothing thrown: ' . $code);
};

eval('namespace Merserwis\Plugin\System\MerTools\Feature; final class Old { public function a(int $x, int $y) {} private function p() {} public const A = 1; }');
eval('namespace Merserwis\Component\MerTools\Administrator\Helper; final class Old2 {}');
eval('namespace Other\Vendor; final class Thing {}');

// what files of two versions of MerTools do to each other: PHP's own messages
$check('undefined method (1.13.1: Compress::boxes())', StaleCode::matches($thrown('(new \Merserwis\Plugin\System\MerTools\Feature\Old())->boxes();')), true);
$check('undefined static method', StaleCode::matches($thrown('\Merserwis\Plugin\System\MerTools\Feature\Old::boxes();')), true);
$check('class not there yet', StaleCode::matches($thrown('new \Merserwis\Plugin\System\MerTools\Tool\NotYet();')), true);
$check('class of the component not there yet', StaleCode::matches($thrown('new \Merserwis\Component\MerTools\Administrator\Helper\NotYet();')), true);
$check('undefined constant', StaleCode::matches($thrown('return \Merserwis\Plugin\System\MerTools\Feature\Old::NEW_ONE;')), true);
$check('undefined function', StaleCode::matches($thrown('\Merserwis\Plugin\System\MerTools\Tool\newFunction();')), true);
$check('private method', StaleCode::matches($thrown('(new \Merserwis\Plugin\System\MerTools\Feature\Old())->p();')), true);
$check('too few arguments', StaleCode::matches($thrown('(new \Merserwis\Plugin\System\MerTools\Feature\Old())->a(1);')), true);

// anything else goes on as before
$check('another vendor\'s missing method', StaleCode::matches($thrown('(new \Other\Vendor\Thing())->boxes();')), false);
$check('another vendor\'s missing class', StaleCode::matches($thrown('new \Other\Vendor\NotThere();')), false);
$check('a method on null', StaleCode::matches($thrown('$x = null; $x->boxes();')), false);
$check('a wrong type', StaleCode::matches($thrown('(new \Merserwis\Plugin\System\MerTools\Feature\Old())->a("x", 2);')), false);
$check('an exception with the same words', StaleCode::matches(new \RuntimeException('Call to undefined method Merserwis\Plugin\System\MerTools\Feature\Old::a()')), false);
$check('a subclass of Error (division by zero)', StaleCode::matches($thrown('return intdiv(1, 0);')), false);
$check('MerTools named later in the message only', StaleCode::matches(new \Error('Call to undefined method Other\X::a() in Merserwis\Plugin\System\MerTools\Feature\Old')), false);

// OPcache: nothing to do without it, folders that are not there skipped
$check('refresh of missing folders', StaleCode::refreshOpcache(['/nonexistent-' . bin2hex(random_bytes(4))]), false);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
