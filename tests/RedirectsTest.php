<?php

/**
 * Standalone test of the Redirects helpers (no Joomla needed).
 * Run: php tests/RedirectsTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/History.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Redirects.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/NotFound.php';

use Merserwis\Plugin\System\MerTools\Tool\NotFound;
use Merserwis\Plugin\System\MerTools\Tool\Redirects;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// the alias a missing address asks for
$check('slug: product', Redirects::slug('/oferta/mierniki/sonel-mpi-540'), 'sonel-mpi-540');
$check('slug: trailing slash', Redirects::slug('/oferta/sonel-mpi-540/'), 'sonel-mpi-540');
$check('slug: double slashes', Redirects::slug('//oferta//sonel-mpi-540'), 'sonel-mpi-540');
$check('slug: decoded', Redirects::slug('/blog/za%C5%BC%C3%B3%C5%82%C4%87'), 'zażółć');
$check('slug: .html suffix', Redirects::slug('/oferta/sonel-mpi-540.html', '', true), 'sonel-mpi-540');
$check('slug: .html without the setting', Redirects::slug('/oferta/sonel-mpi-540.html'), 'sonel-mpi-540');
$check('slug: site in a subfolder', Redirects::slug('/joomla/oferta/x', '/joomla'), 'x');
$check('slug: subfolder itself', Redirects::slug('/joomla/', '/joomla'), null);
$check('slug: home', Redirects::slug('/'), null);
$check('slug: index.php', Redirects::slug('/index.php'), null);
$check('slug: encoded slash refused', Redirects::slug('/a/b%2Fc'), null);
$check('slug: control char refused', Redirects::slug('/a/b%0A'), null);
$check('slug: too long', Redirects::slug('/' . str_repeat('a', 300)), null);

// the address kept in the log
$check('log path: query dropped', Redirects::logPath('/oferta/x', 'utm_source=fb'), '/oferta/x');
$check('log path: index.php keeps the query', Redirects::logPath('/index.php', 'option=com_content&id=5'), '/index.php?option=com_content&id=5');
$check('log path: root keeps the query', Redirects::logPath('/', 'p=12'), '/?p=12');
$check('log path: no leading slash', Redirects::logPath('oferta'), '/oferta');
$check('log path: at most 1024', mb_strlen(Redirects::logPath('/' . str_repeat('ą', 2000))), 1024);
$check('log key', Redirects::key('/a'), sha1('/a'));

// the best of several addresses
$urls = ['https://ex.pl/sklep/mierniki/sonel-mpi-540', 'https://ex.pl/oferta/mierniki/izolacji/sonel-mpi-540'];
$check('best: same app wins', Redirects::best($urls, '/oferta/stara-kategoria/sonel-mpi-540'), $urls[1]);
$check('best: tie: the first', Redirects::best($urls, '/inna/sonel-mpi-540'), $urls[0]);
$check('best: never the address itself', Redirects::best(['https://ex.pl/oferta/x'], '/oferta/x'), null);
$check('best: itself skipped, other taken', Redirects::best(['https://ex.pl/oferta/x', 'https://ex.pl/sklep/x'], '/oferta/x'), 'https://ex.pl/sklep/x');
$check('best: case and encoding', Redirects::best(['/Oferta/%C5%BC'], '/oferta/ż'), null);
$check('best: none', Redirects::best([], '/x'), null);
$check('best: deeper match wins', Redirects::best(['/oferta/a/x', '/oferta/b/c/x'], '/oferta/b/c/old/x'), '/oferta/b/c/x');

// a suggestion
$aliases = [
    ['kind' => 'p', 'id' => 1, 'alias' => 'sonel-mpi-540'],
    ['kind' => 'p', 'id' => 2, 'alias' => 'sonel-mpi-530'],
    ['kind' => 'p', 'id' => 3, 'alias' => 'metrel-mi-3155'],
    ['kind' => 'c', 'id' => 9, 'alias' => 'kamery-termowizyjne'],
];
$check('suggest: typo', Redirects::suggest('sonel-mpi-54', $aliases), ['kind' => 'p', 'id' => 1, 'alias' => 'sonel-mpi-540', 'score' => 0.96]);
$check('suggest: renamed, longer alias beats a short look-alike', Redirects::suggest('sonel-mpi-54', [['kind' => 'p', 'id' => 5, 'alias' => 'sonel-mic-10'],
    ['kind' => 'p', 'id' => 2, 'alias' => 'sonel-mpi-540-nowy']])['id'], 2);
$check('suggest: equal share, longer common beginning', Redirects::suggest('abcd-xyz', [['kind' => 'p', 'id' => 1, 'alias' => 'xyz-abcd'], ['kind' => 'p', 'id' => 2, 'alias' => 'abcd-xyw']])['id'], 2);
$check('suggest: category', Redirects::suggest('kamery-termowizyjne-flir', $aliases)['id'], 9);
$check('suggest: nothing alike', Redirects::suggest('regulamin', $aliases), null);
$check('suggest: short words only', Redirects::suggest('a-b', $aliases), null);
$check('suggest: same alias skipped', Redirects::suggest('metrel-mi-3155', [['kind' => 'p', 'id' => 3, 'alias' => 'metrel-mi-3155']]), null);

// alias changes of the edit history
$rows = [
    ['page_id' => '7', 'created' => '2026-10-01 10:00:00', 'changes' => json_encode([['f' => 'title', 'from' => 'A', 'to' => 'B'], ['f' => 'page_alias', 'from' => 'stary', 'to' => 'nowy']])],
    ['page_id' => '8', 'created' => '2026-10-02 10:00:00', 'changes' => json_encode([['f' => 'page_alias', 'from' => '', 'to' => 'x']])],
    ['page_id' => '9', 'created' => '2026-10-03 10:00:00', 'changes' => 'not json'],
];
$check('history aliases', Redirects::historyAliases($rows), [['id' => 7, 'alias' => 'stary', 'changed' => '2026-10-01 10:00:00']]);

// excluded paths
$check('excluded', NotFound::excluded('/api/v1?x=1', ['/api/']), true);
$check('not excluded', NotFound::excluded('/apis', ['/api/']), false);
$check('excluded, case', NotFound::excluded('/IMAGES/a', ['/images/']), true);

// withdrawn pages (410): deleted, unpublished, in the trash, past the end of publishing
$now  = '2026-10-23 12:00:00';
$page = fn (array $o = []) => $o + ['published' => 1, 'page_category' => '12', 'end_publishing' => '0000-00-00 00:00:00'];
$check('gone: deleted (no row)', Redirects::gone(null, 'p', $now), true);
$check('gone: unpublished', Redirects::gone($page(['published' => 0]), 'p', $now), true);
$check('gone: in the trash', Redirects::gone($page(['page_category' => 'trashed']), 'p', $now), true);
$check('gone: publishing ended', Redirects::gone($page(['end_publishing' => '2026-10-01 00:00:00']), 'p', $now), true);
$check('not gone: published', Redirects::gone($page(), 'p', $now), false);
$check('not gone: ends later', Redirects::gone($page(['end_publishing' => '2026-12-31 00:00:00']), 'p', $now), false);
$check('not gone: empty end', Redirects::gone($page(['end_publishing' => '']), 'p', $now), false);
$check('category: unpublished gone, published not', [Redirects::gone(['published' => 0], 'c', $now), Redirects::gone(['published' => 1], 'c', $now)], [true, false]);
$check('results: gone and category before the general target', array_slice(Redirects::RESULTS, 2, 3), ['gone', 'category', 'fallback']);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
