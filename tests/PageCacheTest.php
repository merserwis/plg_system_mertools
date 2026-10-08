<?php

/**
 * Standalone test of PageCache (no Joomla needed): the rules and the files in a temporary folder.
 * Run: php tests/PageCacheTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/PageCache.php';

use Merserwis\Plugin\System\MerTools\Tool\PageCache;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// ---------------------------------------------------------------- addresses
$check('query: campaign tags only', PageCache::cleanQuery('utm_source=fb&utm_medium=cpc&gclid=x&fbclid=y&srsltid=z'), '');
$check('query: other utm_ tag', PageCache::cleanQuery('utm_whatever=1'), '');
$check('query: real parameter stays', PageCache::cleanQuery('utm_source=a&page=2'), 'page=2');
$check('query: search', PageCache::cleanQuery('query=miernik'), 'query=miernik');
$check('query: empty', PageCache::cleanQuery(''), '');
$check('query: encoded name', PageCache::cleanQuery('%75tm_source=a'), '');

// ---------------------------------------------------------------- visitors
$check('cookie: cart', PageCache::hasCookie(['gridbox_store_cart' => '15'], PageCache::SKIP_COOKIES), true);
$check('cookie: currency', PageCache::hasCookie(['gridbox-currency' => 'EUR'], PageCache::SKIP_COOKIES), true);
$check('cookie: dotted name as PHP sees it', PageCache::hasCookie(['my_cookie' => '1'], ['my.cookie']), true);
$check('cookie: empty value does not count', PageCache::hasCookie(['gridbox_store_cart' => ''], PageCache::SKIP_COOKIES), false);
$check('cookie: session only', PageCache::hasCookie(['64b2f93c' => 'abc', 'gridbox_viewed_products' => ['3218']], PageCache::SKIP_COOKIES), false);
$check('viewed: none', PageCache::viewedOthers([], 3218), false);
$check('viewed: only this product', PageCache::viewedOthers(['3218'], 3218), false);
$check('viewed: another one', PageCache::viewedOthers(['3218', '17'], 3218), true);
$check('viewed: not an array', PageCache::viewedOthers('17', 3218), true);

// ---------------------------------------------------------------- pages
$page = '<!DOCTYPE html><html><head></head><body><div class="ba-item-cart ba-item">0</div></body></html>';
$check('storable page', PageCache::storable($page), true);
$check('checkout not kept', PageCache::storable(str_replace('ba-item-cart ba-item', 'ba-item-checkout-form ba-item', $page)), false);
$check('login form not kept', PageCache::storable(str_replace('ba-item-cart ba-item', 'ba-item-login ba-item', $page)), false);
$check('cut page not kept', PageCache::storable('<html><body>'), false);
$check('compressed body not kept', PageCache::storable("\x1f\x8b" . $page), false);

$sess = '64b2f93c57cea3c84a13c1976a109688';
$check('cookies: session only', PageCache::cookieCheck(['Content-Type: text/html', "Set-Cookie: $sess=abc; path=/; HttpOnly"], $sess), 0);
$check('cookies: viewed product', PageCache::cookieCheck(["Set-Cookie: $sess=abc", 'Set-Cookie: gridbox_viewed_products%5B0%5D=3218; expires=x'], $sess), 3218);
$check('cookies: viewed (raw brackets)', PageCache::cookieCheck(['Set-Cookie: gridbox_viewed_products[0]=3218; expires=x'], $sess), 3218);
$check('cookies: someone\'s own list', PageCache::cookieCheck(['Set-Cookie: gridbox_viewed_products[0]=3218', 'Set-Cookie: gridbox_viewed_products[1]=17'], $sess), null);
$check('cookies: unknown cookie', PageCache::cookieCheck(['Set-Cookie: gridbox-preloader=1'], $sess), null);
$check('headers replayed', PageCache::replayHeaders(['Content-Type: text/html; charset=utf-8', 'Link: <https://x/?output=markdown>; rel="alternate"', 'Vary: Accept',
    'Set-Cookie: a=b', 'X-Frame-Options: SAMEORIGIN']), [['Link', '<https://x/?output=markdown>; rel="alternate"'], ['Vary', 'Accept']]);
$check('content type', PageCache::contentType(['X-A: b', 'Content-Type: text/html; charset=utf-8']), 'text/html; charset=utf-8');
$check('content type unknown', PageCache::contentType(['Content-Type: application/json']), 'text/html; charset=utf-8');

$token = 'dbed7d4b34f6405b0498ea8fcdc70d19';
$nonce = 'ZGJhMDA4ZDY4ODZiYzAyMjVhNDgyMWE2ZGZjOWEyNTc5MTFk';
$html  = '<script nonce="' . $nonce . '" type="application/json">{"csrf.token":"' . $token . '"}</script><input type="hidden" name="' . $token . '" value="1">';
$kept  = PageCache::forStore($html, $token, $nonce);
$check('kept page: no token or nonce of the first visitor', !str_contains($kept, $token) && !str_contains($kept, $nonce), true);
$served = PageCache::forServe($kept, 'c9fd22df85d44793f04e1824a14a3ea8', 'TkVX');
$check('served: next visitor\'s token and nonce', $served, '<script nonce="TkVX" type="application/json">{"csrf.token":"c9fd22df85d44793f04e1824a14a3ea8"}</script><input type="hidden" name="c9fd22df85d44793f04e1824a14a3ea8" value="1">');
$check('served: no nonce now', PageCache::forServe($kept, $token, null), str_replace('nonce="' . $nonce . '"', 'nonce=""', $html));
$check('not a token: left alone', PageCache::forStore('abc', 'abc', null), 'abc');

// ---------------------------------------------------------------- what empties the cache
$check('editor save (logged in)', PageCache::changes('POST', false, 'com_gridbox', 'editor.gridboxAjaxSave'), true);
$check('article save', PageCache::changes('POST', false, 'com_content', 'article.apply'), true);
$check('cache cleared in Joomla', PageCache::changes('POST', false, 'com_cache', 'cache.deleteAll'), true);
$check('editor reads (POST, logged in)', PageCache::changes('POST', false, 'com_gridbox', 'editor.loadModule'), false);
$check('a page view (logged in)', PageCache::changes('GET', false, 'com_gridbox', ''), false);
$check('guest posting a save task', PageCache::changes('POST', true, 'com_content', 'article.save'), false);
$check('order (guest)', PageCache::changes('POST', true, 'com_gridbox', 'store.createOrder'), true);
$check('payment callback (GET)', PageCache::changes('GET', true, 'com_gridbox', 'store.payuplCallback'), true);
$check('comment (guest)', PageCache::changes('POST', true, 'com_gridbox', 'comments.sendCommentMesssage'), true);
$check('add to cart (guest)', PageCache::changes('POST', true, 'com_gridbox', 'store.addProductToCart'), false);

// ---------------------------------------------------------------- Gridbox data requests
$check('items request', PageCache::dataRequest('com_gridbox', 'editor.getItems', ''), 'items');
$check('texts request', PageCache::dataRequest('com_gridbox', 'editor.loadModule', 'gridboxLanguage'), 'language');
$check('other module', PageCache::dataRequest('com_gridbox', 'editor.loadModule', 'shapeDividers'), null);
$check('other component', PageCache::dataRequest('com_content', 'editor.getItems', ''), null);
$check('valid items', PageCache::validData('items', 'var gridboxItems = {}'), true);
$check('error page is not items', PageCache::validData('items', '<!DOCTYPE html>'), false);
$check('valid texts', PageCache::validData('language', 'var gridboxLanguage = {}'), true);

// ---------------------------------------------------------------- files
$dir   = sys_get_temp_dir() . '/mt-pcache-test-' . bin2hex(random_bytes(3));
$cache = new PageCache($dir);
$key   = $cache->key('page', 'https://www.merserwis.pl/');
$check('key differs by kind', $key !== $cache->key('data', 'https://www.merserwis.pl/'), true);
$t0 = microtime(true);
$check('write', $cache->write($key, ['kind' => 'page', 'type' => 'text/html'], $kept, $t0 - 1), true);
$e = $cache->read($key, 600, microtime(true));
$check('read back', [$e['body'] ?? null, $e['meta']['kind'] ?? null], [$kept, 'page']);
$check('expired', $cache->read($key, 600, microtime(true) + 601), null);
$check('stats', array_intersect_key($cache->stats(600, microtime(true)), ['pages' => 0, 'data' => 0]), ['pages' => 1, 'data' => 0]);
usleep(2000);
$cache->purge();
$check('purged: gone', $cache->read($key, 600, microtime(true)), null);
$check('purged: a page begun before is not kept', $cache->write($key, ['kind' => 'page'], 'x', $t0), false);
$fresh = new PageCache($dir);
$check('purged: a page begun after is kept', $fresh->write($key, ['kind' => 'page'], 'y', microtime(true) + 0.001), true);
$check('old entry written before the emptying is not served', (function () use ($dir) {
    $c = new PageCache($dir);
    $k = $c->key('page', 'https://x/old');
    $c->write($k, ['kind' => 'page', 'time' => $c->purgedAt() - 5], 'old', microtime(true));

    return $c->read($k, 600, microtime(true));
})(), null);
$data = $fresh->key('data', 'x');
$fresh->write($data, ['kind' => 'data'], 'var gridboxItems = {}', microtime(true));
$check('stats counts data', $fresh->stats(600, microtime(true))['data'], 1);
foreach (range(1, 5) as $i) {
    $fresh->write($fresh->key('page', "p$i"), ['kind' => 'page'], "p$i", microtime(true));
    touch($dir . '/' . substr($fresh->key('page', "p$i"), 0, 2) . '/' . $fresh->key('page', "p$i") . '.bin', time() - 100 + $i);
}
$fresh->prune(600, 3, microtime(true));
$check('prune keeps the newest up to the limit', count(glob($dir . '/*/*.bin')), 3);
$check('prune drops expired', $fresh->prune(10, 100, microtime(true) + 1000) >= 3, true);
$check('no web listing', is_file($dir . '/index.html'), true);
exec('rm -rf ' . escapeshellarg($dir));

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
