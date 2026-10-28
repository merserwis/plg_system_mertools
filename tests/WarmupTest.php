<?php

/**
 * Standalone test of Warmup and PageCache::fresh (no Joomla needed).
 * Run: php tests/WarmupTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/PageCache.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Warmup.php';

use Merserwis\Plugin\System\MerTools\Tool\PageCache;
use Merserwis\Plugin\System\MerTools\Tool\Warmup;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// ---------------------------------------------------------------- sitemap files
$index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://www.merserwis.pl/sitemap-pages.xml</loc></sitemap><sitemap><loc> https://www.merserwis.pl/sitemap-blog.xml </loc></sitemap></sitemapindex>';
$set   = '<urlset><url><loc>https://www.merserwis.pl/oferta/a?x=1&amp;y=2</loc></url><url><loc><![CDATA[https://www.merserwis.pl/blog]]></loc></url><url><loc>ftp://x/y</loc></url><url><loc>/relative</loc></url></urlset>';
$check('index: recognised', Warmup::locs($index)['index'], true);
$check('index: two files, trimmed', Warmup::locs($index)['locs'], ['https://www.merserwis.pl/sitemap-pages.xml', 'https://www.merserwis.pl/sitemap-blog.xml']);
$check('set: not an index', Warmup::locs($set)['index'], false);
$check('set: entities decoded, CDATA read, foreign schemes and relative left out', Warmup::locs($set)['locs'], ['https://www.merserwis.pl/oferta/a?x=1&y=2', 'https://www.merserwis.pl/blog']);
$check('garbage: nothing', Warmup::locs('<html>403</html>'), ['index' => false, 'locs' => []]);
$big = '<urlset>' . implode('', array_map(fn ($i) => "<url><loc>https://www.merserwis.pl/p$i</loc></url>", range(1, 50))) . '</urlset>';
$check('a large map is read up to the limit only', Warmup::locs($big, 3)['locs'], ['https://www.merserwis.pl/p1', 'https://www.merserwis.pl/p2', 'https://www.merserwis.pl/p3']);
$check('the limit counts usable addresses', Warmup::locs($set, 1)['locs'], ['https://www.merserwis.pl/oferta/a?x=1&y=2']);
$check('default limit: the whole map', count(Warmup::locs($big)['locs']), 50);

// ---------------------------------------------------------------- the list
$urls = ['https://www.merserwis.pl/oferta/a/b', 'https://www.merserwis.pl/oferta', 'https://www.merserwis.pl/', 'https://www.merserwis.pl/oferta/a', 'https://other.pl/x',
    'https://www.merserwis.pl/szukaj?query=a', 'https://www.merserwis.pl/oferta', 'https://www.merserwis.pl/blog', 'https://WWW.merserwis.pl/kontakt'];
$check('order: the home page without its slash = with it, once', Warmup::order(['https://www.merserwis.pl', 'https://www.merserwis.pl/', 'https://www.merserwis.pl/a'], 'https://www.merserwis.pl'),
    ['https://www.merserwis.pl/', 'https://www.merserwis.pl/a']);
$check('order: shallow first, this host only, no query, no repeats', Warmup::order($urls, 'https://www.merserwis.pl'), [
    'https://www.merserwis.pl/', 'https://www.merserwis.pl/oferta', 'https://www.merserwis.pl/blog', 'https://WWW.merserwis.pl/kontakt',
    'https://www.merserwis.pl/oferta/a', 'https://www.merserwis.pl/oferta/a/b']);

// ---------------------------------------------------------------- the address of the site
$check('base: live_site first, host lower case, folder kept without its slash', Warmup::siteBase('https://WWW.Merserwis.pl/joomla/', 'https://other.pl', 'https://x.pl'), 'https://www.merserwis.pl/joomla');
$check('base: root = origin only', Warmup::siteBase('https://www.merserwis.pl/'), 'https://www.merserwis.pl');
$check('same site: in the folder of the site', Warmup::sameSite('https://x.pl/joomla/oferta', 'https://x.pl/joomla'), true);
$check('same site: the folder itself', Warmup::sameSite('https://x.pl/joomla', 'https://x.pl/joomla'), true);
$check('same site: outside the folder', Warmup::sameSite('https://x.pl/sitemap.xml', 'https://x.pl/joomla'), false);
$check('same site: a folder only beginning alike', Warmup::sameSite('https://x.pl/joomla2/a', 'https://x.pl/joomla'), false);
$check('same site: a redirect to a local service', Warmup::sameSite('http://127.0.0.1:8080/', 'https://x.pl'), false);
$check('base: empty ones skipped, a bare domain = https', Warmup::siteBase('', ' ', 'www.merserwis.pl'), 'https://www.merserwis.pl');
$check('base: port kept', Warmup::siteBase('http://localhost:8080/'), 'http://localhost:8080');
$check('base: not http(s) skipped', Warmup::siteBase('ftp://x.pl', 'javascript:alert(1)', 'https://ok.pl'), 'https://ok.pl');
$check('base: none', Warmup::siteBase('', ''), null);
$check('same site: yes, host case ignored', Warmup::sameSite('https://WWW.merserwis.pl/a', 'https://www.merserwis.pl'), true);
$check('same site: default port written out', Warmup::sameSite('https://www.merserwis.pl:443/a', 'https://www.merserwis.pl'), true);
$check('same site: other port', Warmup::sameSite('http://www.merserwis.pl:8080/', 'http://www.merserwis.pl'), false);
$check('same site: other host', Warmup::sameSite('https://attacker.tld/sitemap.xml', 'https://www.merserwis.pl'), false);
$check('same site: metadata address', Warmup::sameSite('http://169.254.169.254/latest/', 'https://www.merserwis.pl'), false);
$check('same site: other scheme', Warmup::sameSite('http://www.merserwis.pl/a', 'https://www.merserwis.pl'), false);
$check('same site: a host only looking alike', Warmup::sameSite('https://www.merserwis.pl.attacker.tld/', 'https://www.merserwis.pl'), false);
$check('same site: user info trick', Warmup::sameSite('https://www.merserwis.pl@attacker.tld/', 'https://www.merserwis.pl'), false);
$check('order: fragments dropped (one address), excluded paths out', Warmup::order(['https://s.pl/a#f', 'https://s.pl/a', 'https://s.pl/koszyk/x', 'https://s.pl/b'], 'https://s.pl', ['/koszyk']),
    ['https://s.pl/a', 'https://s.pl/b']);

// ---------------------------------------------------------------- what is next
$list  = ['https://s/a', 'https://s/b', 'https://s/c', 'https://s/d', 'https://s/e'];
$fresh = fn (string $u) => $u === 'https://s/a';
$check('next: kept ones skipped, count respected', Warmup::next($list, $fresh, [], 1000, 2), ['https://s/b', 'https://s/c']);
$check('next: a tried one is left alone until its time', Warmup::next($list, $fresh, ['https://s/b' => 1500], 1000, 2), ['https://s/c', 'https://s/d']);
$check('next: ... and asked for again after it', Warmup::next($list, $fresh, ['https://s/b' => 900], 1000, 1), ['https://s/b']);
$check('next: all kept = nothing to do', Warmup::next($list, fn () => true, [], 1000, 5), []);

// ---------------------------------------------------------------- state file
$dir  = sys_get_temp_dir() . '/mt-warm-' . bin2hex(random_bytes(4));
$file = $dir . '/state.json';
$w    = new Warmup($file);
$check('state: new one is due', $w->listDue(1800000000), true);
$w->setUrls($list, 1000);
$w->tried('https://s/b', 1000, 600);
$w->tried('https://s/c', 1100, 600);
$check('state: saved', $w->save(), true);
$r = new Warmup($file);
$check('state: urls read back', $r->urls(), $list);
$check('state: not due within 6 hours', $r->listDue(1000 + Warmup::LIST_TTL - 1), false);
$check('state: due after', $r->listDue(1000 + Warmup::LIST_TTL + 1), true);
$t = time();
$r->tried('https://s/d', $t, 600);
$check('state: skips kept', array_keys($r->skips($t)), ['https://s/d']);
$check('state: ... until their time', $r->skips($t + 601), []);
$check('state: expired ones (1000, 1100) are gone at once', $r->skips($t), ['https://s/d' => $t + 600]);
usleep(10000);
$check('state: an emptying after the visit makes the address due again', $r->skips($t, microtime(true)), []);
$check('state: an emptying before the visit does not', array_keys($r->skips($t, microtime(true) - 5)), ['https://s/d']);
$r->tried('https://s/e', $t, 600, microtime(true));
$check('state: tried() drops the entries an emptying made void', array_keys($r->skips($t)), ['https://s/e']);
file_put_contents($file, 'not json');
$check('state: a broken file = empty state', (new Warmup($file))->urls(), []);

// ---------------------------------------------------------------- the way to the site, what came of it
$check('resolve: IPv4', Warmup::resolveLine('WWW.Merserwis.pl', 443, '10.0.0.5'), 'www.merserwis.pl:443:10.0.0.5');
$check('resolve: IPv6 in brackets', Warmup::resolveLine('www.merserwis.pl', 443, '2001:db8::1'), 'www.merserwis.pl:443:[2001:db8::1]');
$check('resolve: no address', Warmup::resolveLine('www.merserwis.pl', 443, ''), null);
$check('resolve: not an address', Warmup::resolveLine('www.merserwis.pl', 443, 'localhost'), null);
$check('resolve: a host with a colon is refused', Warmup::resolveLine('a:1:b', 443, '10.0.0.5'), null);
$check('reached: 200, 301, 404 yes', [Warmup::reached(200), Warmup::reached(301), Warmup::reached(404)], [true, true, true]);
$check('reached: none, 403, 429, 503 no', [Warmup::reached(0), Warmup::reached(403), Warmup::reached(429), Warmup::reached(503)], [false, false, false, false]);
$w = new Warmup($file);
$check('route: none yet', $w->route(), '');
$check('status: nothing noted yet', $w->status(), ['route' => '', 'urls' => 0, 'built' => 0, 'list' => null, 'page' => null]);
$w->setRoute('direct');
$w->setUrls(['https://s/a', 'https://s/b'], 1000);
$w->note('list', 1000, 'MerTools sitemap', 'ok: 2');
$w->note('page', 1001, 'https://s/a', 'direct curl 7: refused; public ok');
$w->save();
$w = new Warmup($file);
$check('status: kept in the file', $w->status(), ['route' => 'direct', 'urls' => 2, 'built' => 1000,
    'list' => ['time' => 1000, 'url' => 'MerTools sitemap', 'result' => 'ok: 2'], 'page' => ['time' => 1001, 'url' => 'https://s/a', 'result' => 'direct curl 7: refused; public ok']]);
file_put_contents($file, '{"urls":[],"built":5,"skip":[]}');
$check('status: a file of 1.6.9 is read', (new Warmup($file))->status()['built'], 5);
file_put_contents($file, '{"route":"elsewhere","last":"x"}');
$check('status: bad values are harmless', (new Warmup($file))->status(), ['route' => '', 'urls' => 0, 'built' => 0, 'list' => null, 'page' => null]);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

// ---------------------------------------------------------------- PageCache::fresh
$cdir  = sys_get_temp_dir() . '/mt-pc-' . bin2hex(random_bytes(4));
$cache = new PageCache($cdir);
$key   = $cache->key('page', 'https://s/a');
$check('fresh: nothing kept', $cache->fresh($key, 3600, microtime(true)), false);
$cache->write($key, ['kind' => 'page', 'time' => microtime(true) - 100], '<html>x</html>', microtime(true));
$check('fresh: kept page', $cache->fresh($key, 3600, microtime(true)), true);
$check('fresh: expired by TTL', $cache->fresh($key, 50, microtime(true)), false);
$check('fresh: agrees with read()', $cache->fresh($key, 3600, microtime(true)), $cache->read($key, 3600, microtime(true)) !== null);
$cache->purge(false);
$check('fresh: emptied since', $cache->fresh($key, 3600, microtime(true)), false);
$cache->purge();
foreach ([$cdir . '/' . substr($key, 0, 2), $cdir] as $d) {
    array_map('unlink', glob($d . '/*') ?: []);
    rmdir($d);
}
$check('fresh: the test folder is gone', is_dir($cdir), false);

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
