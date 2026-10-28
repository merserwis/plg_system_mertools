<?php

/*
 * Post sliders in several rows: the grouping script goes right after Gridbox's own script (also a
 * deferred one, then it starts once the page has been read), only once, and only on pages that ask
 * for rows.
 */

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/PostRows.php';

use Merserwis\Plugin\System\MerTools\Tool\PostRows;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$slider = '<div class="ba-item-recent-posts-slider ba-item mt-rows-2" id="item-1"><ul class="ba-slideset"></ul></div>';
$page   = fn (string $body, string $gb = '<script src="/templates/gridbox/js/gridbox.js?1.2"></script>')
    => '<html><head><title>x</title></head><body>' . $body . $gb . '<script>other()</script></body></html>';

$check('wanted: rows class on a posts slider', PostRows::wanted($slider), true);
$check('wanted: tablet / phone class', PostRows::wanted(str_replace('mt-rows-2', 'mt-rows-phone-3', $slider)), true);
$check('wanted: no rows class', PostRows::wanted(str_replace(' mt-rows-2', '', $slider)), false);
$check('wanted: rows class on another item', PostRows::wanted(str_replace('ba-item-recent-posts-slider', 'ba-item-text', $slider)), false);
$check('wanted: five rows is not offered', PostRows::wanted(str_replace('mt-rows-2', 'mt-rows-5', $slider)), false);

$html = PostRows::apply($page($slider));
$gb   = '<script src="/templates/gridbox/js/gridbox.js?1.2"></script>';
$check('script right after gridbox.js', str_contains($html, $gb . '<script>(function(w,d){'), true);
$check('style in the head', str_contains($html, '<style id="mertools-rows">') && strpos($html, 'mertools-rows') < strpos($html, '</head>'), true);
$check('only once', PostRows::apply($html), $html);
$check('page without rows unchanged', PostRows::apply($page(str_replace(' mt-rows-2', '', $slider))), $page(str_replace(' mt-rows-2', '', $slider)));
$check('page without gridbox.js unchanged', PostRows::apply($page($slider, '')), $page($slider, ''));

// gridbox.js deferred by another optimiser: the script is still added, and waits for Gridbox
$deferred = '<script defer src="/templates/gridbox/js/gridbox.js"></script>';
$html     = PostRows::apply($page($slider, $deferred));
$check('deferred gridbox.js: script after it', str_contains($html, $deferred . '<script>(function(w,d){'), true);
$check('deferred gridbox.js: starts when the page is read', str_contains($html, "d.addEventListener('DOMContentLoaded',late)"), true);
$check('deferred gridbox.js: or when it has loaded', str_contains($html, "w.addEventListener('load',late)"), true);
$check('late start regroups the started sliders', str_contains($html, 'if(hook()&&a.reloadSlideSet)all()'), true);
// regrouping goes back to the first order of the posts
$check('posts keep their first order', str_contains($html, 'p.mtI=i') && str_contains($html, 'return x.mtI-y.mtI'), true);

// a site whose Content-Security-Policy uses a nonce: the script and the style carry it
$html = PostRows::apply($page($slider), 'n0nce"x');
$check('nonce on the script', str_contains($html, $gb . '<script nonce="n0nce&quot;x">(function(w,d){'), true);
$check('nonce on the style', str_contains($html, '<style id="mertools-rows" nonce="n0nce&quot;x">'), true);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
