<?php

/**
 * Standalone test of the NotFound helpers (no Joomla needed).
 * Run: php tests/NotFoundTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/NotFound.php';

use Merserwis\Plugin\System\MerTools\Tool\NotFound;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

$site = 'https://www.merserwis.pl/';
// target from the settings -> absolute URL
$check('home', NotFound::absoluteTarget($site, $site), 'https://www.merserwis.pl/');
$check('path', NotFound::absoluteTarget('/kontakt', $site), 'https://www.merserwis.pl/kontakt');
$check('path with query', NotFound::absoluteTarget('/sklep?x=1', $site), 'https://www.merserwis.pl/sklep?x=1');
$check('relative path', NotFound::absoluteTarget('kontakt', $site), 'https://www.merserwis.pl/kontakt');
$check('site in a subfolder, path', NotFound::absoluteTarget('/kontakt', 'https://example.com/joomla/'), 'https://example.com/kontakt');
$check('site in a subfolder, relative', NotFound::absoluteTarget('kontakt', 'https://example.com/joomla/'), 'https://example.com/joomla/kontakt');
$check('full address', NotFound::absoluteTarget('https://sklep.merserwis.pl/x', $site), 'https://sklep.merserwis.pl/x');
$check('empty', NotFound::absoluteTarget('  ', $site), null);
$check('protocol-relative refused', NotFound::absoluteTarget('//evil.example/x', $site), null);
$check('javascript: refused', NotFound::absoluteTarget('javascript:alert(1)', $site), null);
$check('mailto: refused', NotFound::absoluteTarget('mailto:a@b.c', $site), null);
$check('header injection refused', NotFound::absoluteTarget("/x\r\nSet-Cookie: a=b", $site), null);
$check('space refused', NotFound::absoluteTarget('/a b', $site), null);

// should the missing page be redirected?
$home = 'https://www.merserwis.pl/';
$check('missing page', NotFound::shouldRedirect('/stara-strona', $home, 'www.merserwis.pl', []), true);
$check('missing page with query', NotFound::shouldRedirect('/stara?x=1', $home, 'www.merserwis.pl', []), true);
$check('the target itself is missing: no loop', NotFound::shouldRedirect('/kontakt', 'https://www.merserwis.pl/kontakt', 'www.merserwis.pl', []), false);
$check('the target itself, trailing slash', NotFound::shouldRedirect('/kontakt/', 'https://www.merserwis.pl/kontakt', 'www.merserwis.pl', []), false);
$check('home missing: no loop', NotFound::shouldRedirect('/', $home, 'www.merserwis.pl', []), false);
$check('same path on another host is fine', NotFound::shouldRedirect('/kontakt', 'https://sklep.merserwis.pl/kontakt', 'www.merserwis.pl', []), true);
$check('host case', NotFound::shouldRedirect('/kontakt', 'https://WWW.merserwis.pl/kontakt', 'www.merserwis.pl', []), false);
$check('excluded', NotFound::shouldRedirect('/api/v1/x', $home, 'www.merserwis.pl', ['/api/']), false);
$check('excluded, case', NotFound::shouldRedirect('/API/v1/x', $home, 'www.merserwis.pl', ['/api/']), false);
$check('not excluded', NotFound::shouldRedirect('/apis', $home, 'www.merserwis.pl', ['/api/']), true);

// excludes from the setting
$check('excludes lines', NotFound::excludes("/api/\r\n\n /images/ \n"), ['/api/', '/images/']);
$check('excludes commas', NotFound::excludes('/api/, /tmp/'), ['/api/', '/tmp/']);
$check('excludes empty', NotFound::excludes(''), []);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
