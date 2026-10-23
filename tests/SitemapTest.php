<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Sitemap.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Sitemap;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// settings
$none = new Registry();
$check('off by default', Sitemap::enabled($none), false);
$check('split by app by default', Sitemap::split($none), true);
$check('one file', Sitemap::split(new Registry(['sitemap_split' => 'single'])), false);
$check('part limit default and clamped', [Sitemap::partLimit($none), Sitemap::partLimit(new Registry(['sitemap_part_limit' => 10])), Sitemap::partLimit(new Registry(['sitemap_part_limit' => 99999]))], [10000, 1000, 50000]);
$check('types: no tags and authors by default', Sitemap::types($none), ['menu', 'page', 'category', 'app']);
$check('types: tags on, categories off', Sitemap::types(new Registry(['sitemap_type_tag' => '1', 'sitemap_type_category' => '0'])), ['menu', 'page', 'app', 'tag']);
$check('filters on by default', Sitemap::filters($none), ['flag' => true, 'noindex' => true, 'robots-txt' => true]);
$check('excluded apps', Sitemap::excludedApps(new Registry(['sitemap_apps' => ['4', '', '4', '2']])), [4, 2]);
$check('lines: trimmed, empty and repeated dropped', Sitemap::lines(new Registry(['sitemap_paths' => " /sandbox \r\n\n/blog/regulamin/*\n/sandbox"]), 'sitemap_paths'), ['/sandbox', '/blog/regulamin/*']);
$check('unknown date: omit by default', [Sitemap::unknownDate($none), Sitemap::unknownDate(new Registry(['sitemap_unknown_date' => 'created']))], ['omit', 'created']);
$check('ttl default and clamped', [Sitemap::ttl($none), Sitemap::ttl(new Registry(['sitemap_ttl' => 1])), Sitemap::ttl(new Registry(['sitemap_ttl' => 9999]))], [360, 5, 1440]);

// robots.txt
$txt = "# merserwis\nUser-agent: *\nDisallow: /administrator/\nDisallow: /*tag/\nDisallow: /tmp/\nAllow: /tmp/public\nDisallow: /*.pdf$\nDisallow:\n\nUser-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /szukaj\n\nSitemap: https://www.merserwis.pl/sitemap.xml\n";
$g = Sitemap::parseRobots($txt);
$check('groups by agent, lower case', array_keys($g), ['*', 'googlebot', 'bingbot']);
$check('empty Disallow dropped', count($g['*']), 5);
$check('two agents share a group', $g['bingbot'], [[false, '/szukaj']]);
$star = Sitemap::rulesFor($g, 'SomeBot');
$check('unknown agent follows *', $star, $g['*']);
$check('googlebot-image follows googlebot', Sitemap::rulesFor($g, 'Googlebot-Image'), $g['googlebot']);
$check('wildcard: /oferta/tag/metrel', Sitemap::allowed($star, '/oferta/tag/metrel'), false);
$check('wildcard: /blog/tag/x', Sitemap::allowed($star, '/blog/tag/x'), false);
$check('no match is allowed', Sitemap::allowed($star, '/oferta/mierniki'), true);
$check('Allow longer than Disallow wins', Sitemap::allowed($star, '/tmp/public/a'), true);
$check('Disallow without the Allow', Sitemap::allowed($star, '/tmp/private'), false);
$check('$ anchors the end', [Sitemap::allowed($star, '/a.pdf'), Sitemap::allowed($star, '/a.pdf.html')], [false, true]);
$check('Allow wins a tie', Sitemap::allowed([[false, '/a'], [true, '/a']], '/a'), true);
$check('Disallow / blocks all', Sitemap::allowed([[false, '/']], '/'), false);
$check('encoded and raw letters compare equal', [Sitemap::allowed([[false, '/zółw']], '/z%c3%b3%C5%82w'), Sitemap::allowed([[false, '/%C5%BC']], '/ż')], [false, false]);
$check('blocked: for * or for googlebot', [Sitemap::blocked($g, '/oferta/tag/x'), Sitemap::blocked($g, '/szukaj'), Sitemap::blocked($g, '/oferta')], [true, true, false]);
$check('no file: nothing blocked', Sitemap::blocked(Sitemap::parseRobots(''), '/x'), false);
$check('rules before any agent ignored', Sitemap::parseRobots("Disallow: /x\nUser-agent: *\nDisallow: /y"), ['*' => [[false, '/y']]]);
$check('agent lines after rules start a new group', Sitemap::parseRobots("User-agent: a\nDisallow: /x\nUser-agent: b\nDisallow: /y"), ['a' => [[false, '/x']], 'b' => [[false, '/y']]]);

// noindex: menu item, then the item, then the Global Configuration
$check('menu item wins', Sitemap::effectiveRobots('index, follow', 'noindex, follow', ''), 'index, follow');
$check('the item when the menu has none', Sitemap::effectiveRobots('', 'noindex, follow', 'index, follow'), 'noindex, follow');
$check('global when both empty', Sitemap::effectiveRobots(' ', '', 'noindex'), 'noindex');
$check('noindex variants', array_map([Sitemap::class, 'isNoindex'], ['noindex, follow', 'NOINDEX,NOFOLLOW', 'none', 'index, nofollow', '', 'noindexed']), [true, true, true, false, false, false]);

// Gridbox's include flag
$check('own flag when overriding', [Sitemap::flagged(1, 0, '1'), Sitemap::flagged(1, 1, '0')], [false, true]);
$check('default of the type otherwise', [Sitemap::flagged(0, 0, '1'), Sitemap::flagged(0, 1, '0'), Sitemap::flagged(0, 0, null)], [true, false, true]);

// paths and titles
$check('path of an address', [Sitemap::path('https://x.pl'), Sitemap::path('https://x.pl/'), Sitemap::path('https://x.pl/a/b/?q=1')], ['/', '/', '/a/b']);
$check('exact path', Sitemap::pathMatches('/sandbox', ['/sandbox']), true);
$check('exact path: not a longer one', Sitemap::pathMatches('/sandbox-2', ['/sandbox']), false);
$check('trailing slash ignored', Sitemap::pathMatches('/sandbox/', ['sandbox/']), true);
$check('wildcard below a folder', [Sitemap::pathMatches('/blog/regulamin/voucher', ['/blog/regulamin/*']), Sitemap::pathMatches('/blog/regulamin', ['/blog/regulamin/*'])], [true, false]);
$check('wildcard in the middle', Sitemap::pathMatches('/promocje/zakonczone-2025', ['/promocje/*2025']), true);
$check('letter case ignored', Sitemap::pathMatches('/Sandbox', ['/sandbox']), true);
$check('a full address as a pattern', Sitemap::pathMatches('/youtube', ['https://www.merserwis.pl/youtube']), true);
$check('title fragment', [Sitemap::titleMatches('Promocja Metrel [ZAKOŃCZONE]', ['[zakończone]']), Sitemap::titleMatches('Promocja', ['[ZAKOŃCZONE]'])], [true, false]);

// the reasons, in order
$rules = Sitemap::rules(new Registry(['sitemap_paths' => '/sandbox', 'sitemap_titles' => '[ZAKOŃCZONE]', 'sitemap_apps' => [7]]), $txt);
$item = fn (array $o = []): array => $o + ['type' => 'page', 'app' => 2, 'live' => true, 'flag' => true, 'robots' => '', 'loc' => 'https://x.pl/oferta/a', 'title' => 'A'];
$seen = [];
$reasons = [
    Sitemap::reason($item(['live' => false, 'robots' => 'noindex']), $rules, $seen),
    Sitemap::reason($item(['flag' => false, 'robots' => 'noindex']), $rules, $seen),
    Sitemap::reason($item(['robots' => 'noindex', 'loc' => 'https://x.pl/oferta/tag/a']), $rules, $seen),
    Sitemap::reason($item(['loc' => 'https://x.pl/oferta/tag/a', 'type' => 'tag']), $rules, $seen),
    Sitemap::reason($item(['type' => 'tag']), $rules, $seen),
    Sitemap::reason($item(['app' => 7]), $rules, $seen),
    Sitemap::reason($item(['loc' => 'https://x.pl/sandbox']), $rules, $seen),
    Sitemap::reason($item(['title' => 'X [ZAKOŃCZONE]']), $rules, $seen),
    Sitemap::reason($item(['loc' => 'https://x.pl/oferta/a?page=2']), $rules, $seen),
    Sitemap::reason($item(), $rules, $seen),
    Sitemap::reason($item(), $rules, $seen),
];
$check('status, flag, noindex, robots-txt, type, app, path, title, query, in map, dup', $reasons, ['status', 'flag', 'noindex', 'robots-txt', 'type', 'app', 'path', 'title', 'query', null, 'dup']);
$check('only the address in the map is remembered', array_keys($seen), ['https://x.pl/oferta/a']);
$off = Sitemap::rules(new Registry(['sitemap_flags' => 0, 'sitemap_noindex' => 0, 'sitemap_robots' => 0]), $txt);
$seen = [];
$check('filters switched off', Sitemap::reason($item(['flag' => false, 'robots' => 'noindex', 'loc' => 'https://x.pl/oferta/tag/a']), $off, $seen), null);
$seen = [];
$check('no robots.txt: nothing blocked', Sitemap::reason($item(['loc' => 'https://x.pl/oferta/tag/a']), Sitemap::rules($none, null), $seen), null);

// addresses as Gridbox writes them
$check('no trailing slash (merserwis.pl)', [Sitemap::loc('/', 'https://www.merserwis.pl', false), Sitemap::loc('/oferta/', 'https://www.merserwis.pl', false)], ['https://www.merserwis.pl', 'https://www.merserwis.pl/oferta']);
$check('trailing slash added', [Sitemap::loc('/oferta', 'https://x.pl/', true), Sitemap::loc('/a?b=1', 'https://x.pl', true)], ['https://x.pl/oferta/', 'https://x.pl/a/?b=1']);
$check('requested files', array_map([Sitemap::class, 'requested'], ['/sitemap.xml', '/sitemap-products-2.xml', '/sitemap-blog-4-p2.xml', '/sitemap-pages.xml']), ['sitemap.xml', 'sitemap-products-2.xml', 'sitemap-blog-4-p2.xml', 'sitemap-pages.xml']);
$check('not a map', array_map([Sitemap::class, 'requested'], ['/sitemap.xml/x', '/Sitemap.xml', '/sitemap-.xml', '/sitemap-a--b.xml', '/x/sitemap.xml', '/sitemap-../a.xml', '/sitemap.xml.gz']), [null, null, null, null, null, null, null]);
$check('group names', [Sitemap::group('products', 2), Sitemap::group('blog', 4), Sitemap::group('single', 0), Sitemap::group('', 9)], ['products-2', 'blog-4', 'pages', 'app-9']);

// files
$e = fn (int $n, string $p = 'a'): array => array_map(fn ($i) => ["https://x.pl/$p$i", null], range(1, $n));
$l = Sitemap::layout(['pages' => $e(2), 'blog-4' => $e(5, 'b'), 'empty-9' => []], true, 2);
$check('split: index and parts', [$l['index'], array_keys($l['files'])], [true, ['sitemap-pages.xml', 'sitemap-blog-4.xml', 'sitemap-blog-4-p2.xml', 'sitemap-blog-4-p3.xml']]);
$check('parts keep the order', $l['files']['sitemap-blog-4-p3.xml'], [['https://x.pl/b5', null]]);
$l = Sitemap::layout(['pages' => $e(2), 'blog-4' => $e(3, 'b')], false, 2);
$check('one file: sitemap.xml itself, limit ignored', [$l['index'], array_keys($l['files']), count($l['files']['sitemap.xml'])], [false, ['sitemap.xml'], 5]);
$l = Sitemap::layout(['pages' => $e(50001)], false, 1000);
$check('one file over 50,000: index of all', [$l['index'], array_keys($l['files'])], [true, ['sitemap-all.xml', 'sitemap-all-p2.xml']]);
$check('nothing at all: an empty map', Sitemap::layout([], false, 10), ['index' => false, 'files' => ['sitemap.xml' => []]]);

// lastmod
$now = 1761200000;
$check('first time: the date from the history', Sitemap::nextState(null, 'h1', $now, 1700000000), ['hash' => 'h1', 'lastmod' => 1700000000]);
$check('first time, no history: unknown', Sitemap::nextState(null, 'h1', $now, null), ['hash' => 'h1', 'lastmod' => null]);
$check('same hash (saved again, CSS rewritten): date kept', Sitemap::nextState(['hash' => 'h1', 'lastmod' => 1700000000], 'h1', $now, 1750000000), ['hash' => 'h1', 'lastmod' => 1700000000]);
$check('content changed: dated now', Sitemap::nextState(['hash' => 'h1', 'lastmod' => 1700000000], 'h2', $now, null), ['hash' => 'h2', 'lastmod' => $now]);
$check('unknown date stays unknown with the same hash', Sitemap::nextState(['hash' => 'h1', 'lastmod' => null], 'h1', $now, null), ['hash' => 'h1', 'lastmod' => null]);
$check('date in the map', [Sitemap::dateFor(5, 3, 'omit'), Sitemap::dateFor(null, 3, 'omit'), Sitemap::dateFor(null, 3, 'created'), Sitemap::dateFor(null, null, 'created')], [5, null, 3, null]);
$check('category dates from the subtree', Sitemap::categoryDates([1 => 0, 2 => 1, 3 => 2, 4 => 0, 5 => 1], [3 => 30, 5 => 10, 4 => null]), [1 => 30, 2 => 30, 3 => 30, 4 => null, 5 => 10]);
$check('category dates survive a loop', Sitemap::categoryDates([1 => 2, 2 => 1], [1 => 5]), [1 => 5, 2 => 5]);
$check('newest', [Sitemap::newest([3, null, 7]), Sitemap::newest([null]), Sitemap::newest([])], [7, null, null]);
$recent = array_fill(0, 95, $now - 86400);
$old    = array_fill(0, 5, $now - 90 * 86400);
$check('mass dates: 95% in a week', Sitemap::massDates(array_merge($recent, $old), $now), true);
$check('mass dates: fewer than 100 known', Sitemap::massDates(array_merge(array_slice($recent, 0, 90), array_fill(0, 20, null)), $now), false);
$check('mass dates: 50% in a week', Sitemap::massDates(array_merge(array_slice($recent, 0, 50), array_fill(0, 50, $now - 30 * 86400)), $now), false);

// XML
$xml = Sitemap::urlset([['https://x.pl/a?b=1&c=2', $now], ['https://x.pl/ż"<', null]]);
$d = new DOMDocument();
$check('urlset is well-formed XML', $d->loadXML($xml), true);
$x = new DOMXPath($d);
$x->registerNamespace('s', Sitemap::NS);
$check('& escaped and read back', $x->evaluate('string(//s:url[1]/s:loc)'), 'https://x.pl/a?b=1&c=2');
$check('quotes and < escaped', $x->evaluate('string(//s:url[2]/s:loc)'), 'https://x.pl/ż"<');
$check('lastmod in UTC, full precision', $x->evaluate('string(//s:url[1]/s:lastmod)'), gmdate('Y-m-d\TH:i:s\Z', $now));
$check('no lastmod when unknown', (int) $x->evaluate('count(//s:url[2]/s:lastmod)'), 0);
$check('no changefreq or priority', str_contains($xml, 'changefreq') || str_contains($xml, 'priority'), false);
$check('empty urlset still valid', (new DOMDocument())->loadXML(Sitemap::urlset([])), true);
$idx = Sitemap::index([['https://x.pl/sitemap-pages.xml', $now], ['https://x.pl/sitemap-blog-4.xml', null]]);
$d->loadXML($idx);
$x = new DOMXPath($d);
$x->registerNamespace('s', Sitemap::NS);
$check('index: maps with their lastmod', [(int) $x->evaluate('count(/s:sitemapindex/s:sitemap)'), $x->evaluate('string(//s:sitemap[1]/s:lastmod)'), (int) $x->evaluate('count(//s:sitemap[2]/s:lastmod)')], [2, Sitemap::time($now), 0]);
// the schemas of sitemaps.org (tests/fixtures/sitemap.xsd, siteindex.xsd)
libxml_use_internal_errors(true);
$valid = function (string $doc, string $xsd): bool {
    $u = new DOMDocument();

    return $u->loadXML($doc) && $u->schemaValidate(__DIR__ . '/fixtures/' . $xsd);
};
$check('urlset valid against sitemap.xsd', $valid($xml, 'sitemap.xsd'), true);
$check('index valid against siteindex.xsd', $valid($idx, 'siteindex.xsd'), true);
$check('a broken urlset fails the schema (the check works)', $valid(str_replace('<loc>', '<lok>', $xml), 'sitemap.xsd'), false);

// conditional requests
$check('ETag matches', Sitemap::notModified('"a1"', $now, 'W/"a1"', ''), true);
$check('ETag differs, date ignored', Sitemap::notModified('"a1"', $now, '"b2"', gmdate('D, d M Y H:i:s', $now + 60) . ' GMT'), false);
$check('If-Modified-Since not older', Sitemap::notModified('"a1"', $now, '', gmdate('D, d M Y H:i:s', $now) . ' GMT'), true);
$check('If-Modified-Since older', Sitemap::notModified('"a1"', $now, '', gmdate('D, d M Y H:i:s', $now - 1) . ' GMT'), false);
$check('neither header', Sitemap::notModified('"a1"', $now, '', ''), false);
$check('garbage date', Sitemap::notModified('"a1"', $now, '', 'yesterday-ish?'), false);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
