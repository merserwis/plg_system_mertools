<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/PageCache.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Hygiene.php';

use Merserwis\Plugin\System\MerTools\Tool\Hygiene;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// og:url / twitter:url
$u = fn (string $content) => '<meta property="og:url" content="' . $content . '">';
$check('og:url: srsltid out', Hygiene::cleanUrls($u('https://x.pl/a/?srsltid=AfmBOo')), $u('https://x.pl/a/'));
$check('og:url: srsltid kept others', Hygiene::cleanUrls($u('https://x.pl/a?srsltid=A&amp;page=2')), $u('https://x.pl/a?page=2'));
$check('og:url: utm_* and gclid out', Hygiene::cleanUrls($u('https://x.pl/a?utm_source=g&amp;gclid=1&amp;x=2')), $u('https://x.pl/a?x=2'));
$check('og:url: clean one untouched', Hygiene::cleanUrls($u('https://x.pl/a?x=1&amp;y=2')), $u('https://x.pl/a?x=1&amp;y=2'));
$check('og:url: no query untouched', Hygiene::cleanUrls($u('https://x.pl/a')), $u('https://x.pl/a'));
$check('og:url: fragment kept', Hygiene::cleanUrls($u('https://x.pl/a?srsltid=1#opis')), $u('https://x.pl/a#opis'));
$check('og:url: case of the name', Hygiene::cleanUrls($u('https://x.pl/a?SRSLTID=1')), $u('https://x.pl/a'));
$check('twitter:url (name=, single quotes)', Hygiene::cleanUrls("<meta name='twitter:url' content='https://x.pl/a?fbclid=1'>"), "<meta name='twitter:url' content='https://x.pl/a'>");
$check('content before property', Hygiene::cleanUrls('<meta content="https://x.pl/?srsltid=1" property="og:url">'), '<meta content="https://x.pl/" property="og:url">');
$other = '<meta property="og:image" content="https://x.pl/i.jpg?srsltid=1"><a href="/a?srsltid=1">';
$check('other tags and links untouched', Hygiene::cleanUrls($other), $other);
$check('no og:url: same string', Hygiene::cleanUrls('<p>?srsltid=1</p>'), '<p>?srsltid=1</p>');

// language code
$h = fn (string $a) => '<!DOCTYPE html><html ' . $a . ' dir="ltr"><body lang="pl-pl">';
$check('lang: pl-pl -> pl', Hygiene::shortLang($h('lang="pl-pl"')), $h('lang="pl"'));
$check('lang: xml:lang and lang', Hygiene::shortLang($h('xml:lang="pl-pl" lang="pl-pl"')), $h('xml:lang="pl" lang="pl"'));
$check('lang: de-DE -> de', Hygiene::shortLang($h('lang="de-DE"')), $h('lang="de"'));
$check('lang: en-gb stays', Hygiene::shortLang($h('lang="en-gb"')), $h('lang="en-gb"'));
$check('lang: pt-br stays', Hygiene::shortLang($h('lang="pt-br"')), $h('lang="pt-br"'));
$check('lang: pl stays', Hygiene::shortLang($h('lang="pl"')), $h('lang="pl"'));
$check('lang: only the <html> tag', substr_count(Hygiene::shortLang($h('lang="pl-pl"')), 'pl-pl'), 1);

// cookies
$check('cookie: Secure and SameSite added', Hygiene::cookies(['Content-Type: text/html', 'Set-Cookie: a=1; path=/; HttpOnly'], true), ['a=1; path=/; HttpOnly; Secure; SameSite=Lax']);
$check('cookie: http: no Secure', Hygiene::cookies(['Set-Cookie: a=1; path=/'], false), ['a=1; path=/; SameSite=Lax']);
$check('cookie: complete one -> null', Hygiene::cookies(['Set-Cookie: a=1; path=/; Secure; HttpOnly; SameSite=Strict'], true), null);
$check('cookie: SameSite=None kept', Hygiene::cookies(['Set-Cookie: a=1; Secure; SameSite=None'], true), null);
$check('cookie: two lines, one complete', Hygiene::cookies(['Set-Cookie: a=1; Secure; SameSite=Lax', 'set-cookie: b=2'], true), ['a=1; Secure; SameSite=Lax', 'b=2; Secure; SameSite=Lax']);
$check('cookie: none -> null', Hygiene::cookies(['Content-Type: text/html'], true), null);
$check('cookie: a name containing "secure"', Hygiene::cookies(['Set-Cookie: secure_x=1; path=/'], true), ['secure_x=1; path=/; Secure; SameSite=Lax']);

// CSP
$check('csp: off', Hygiene::csp('off', 'default-src x'), null);
$check('csp: report', Hygiene::csp('report', "default-src 'self'\n; img-src *"), ['Content-Security-Policy-Report-Only', "default-src 'self' ; img-src *"]);
$check('csp: enforce default', Hygiene::csp('enforce', '  '), ['Content-Security-Policy', Hygiene::CSP_DEFAULT]);
$check('csp: non-ASCII refused', Hygiene::csp('enforce', "default-src 'self' ą"), null);
$check('csp: junk mode', Hygiene::csp('x', 'a'), null);
// added to the policy the site has, never in its place (a list of policies: the browser applies all)
$strict = "default-src 'self'; script-src 'self'; frame-ancestors 'none'";
$check('csp: none before: only ours', Hygiene::withPolicy([], Hygiene::CSP_DEFAULT), Hygiene::CSP_DEFAULT);
$check('csp: the site\'s stricter one kept first', Hygiene::withPolicy([$strict], Hygiene::CSP_DEFAULT), $strict . ', ' . Hygiene::CSP_DEFAULT);
$check('csp: ours not added twice (also from header())', Hygiene::withPolicy([$strict . ', ' . Hygiene::CSP_DEFAULT, Hygiene::CSP_DEFAULT], Hygiene::CSP_DEFAULT), $strict . ', ' . Hygiene::CSP_DEFAULT);
$check('csp: two of the site\'s, spaces tidied', Hygiene::withPolicy(["a 'self' ", ' b  x'], 'c'), "a 'self', b x, c");

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
