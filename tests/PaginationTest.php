<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Pagination.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Pagination;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$check('off by default', Pagination::enabled(new Registry()), false);
$check('page numbers', array_map([Pagination::class, 'page'], [null, '2', '9', '1000', '1', '0', '01', 'abc', '', '-2', '2.5', '99999999999']),
    [1, 2, 9, 1000, null, null, null, null, null, null, null, null]);
$check('without page, others kept', Pagination::withoutPage('/oferta/x', 'sort-by=price&page=0&query=a'), '/oferta/x?sort-by=price&query=a');
$check('without page, none left', Pagination::withoutPage('/blog', 'page=abc'), '/blog');
// every form PHP reads as the parameter goes (one left in: refused again after the redirect, for ever)
$check('without page: array forms', Pagination::withoutPage('/blog', 'page[]=2&x=1&page%5B%5D=3&page[a]=4'), '/blog?x=1');
$check('without page: an encoded name', Pagination::withoutPage('/blog', 'p%61ge=0&x=1'), '/blog?x=1');
$check('without page: a bare name', Pagination::withoutPage('/blog', 'page&x=1'), '/blog?x=1');
$check('without page: other names that begin the same stay', Pagination::withoutPage('/blog', 'pages=2&page_x=1&pagex=3'), '/blog?pages=2&page_x=1&pagex=3');
$check('without sort-by: scalar and array, others as they are', Pagination::without('/a', 'sort-by[]=x&q=%C5%BC+a&sort-by=bad&sort%2Dby=y', 'sort-by'), '/a?q=%C5%BC+a');

$head = "<head><title>Multimetry</title>\n\t<link href=\"https://x.pl/oferta/multimetry\" rel=\"canonical\"><meta name=\"description\" content=\"Multimetry cyfrowe\"></head>";
$check('canonical of page 3', Pagination::canonical($head, 3), str_replace('/multimetry"', '/multimetry?page=3"', $head));
$check('canonical with a query', Pagination::canonical('<link rel="canonical" href="https://x.pl/a?sort-by=price">', 2), '<link rel="canonical" href="https://x.pl/a?sort-by=price&page=2">');
$check('canonical: an old page parameter replaced', Pagination::canonical('<link href="https://x.pl/a?page=1" rel="canonical">', 4), '<link href="https://x.pl/a?page=4" rel="canonical">');
$t = Pagination::texts($head, 2, '%1$s – strona %2$d');
$check('title with the page', preg_match('#<title>Multimetry – strona 2</title>#', $t), 1);
$check('description with the page', str_contains($t, 'content="Multimetry cyfrowe – strona 2"'), true);
$check('empty description stays empty', Pagination::texts('<title>A</title><meta name="description" content="">', 2, '%1$s – %2$d'), '<title>A – 2</title><meta name="description" content="">');

$list  = '<div class="ba-item-blog-posts ba-item" id="item-1"><div class="ba-blog-posts-wrapper ba-masonry-layout"><div class="ba-blog-post " data-id="1"></div></div></div>';
$empty = "<div class=\"ba-item-blog-posts ba-item\" id=\"item-1\"><div class=\"ba-blog-posts-wrapper ba-masonry-layout\">\n</div><div class=\"ba-blog-posts-pagination-wrapper\"></div></div>";
$check('a list with posts is not empty', Pagination::emptyList($list), false);
$check('an empty list', Pagination::emptyList($empty), true);
$check('no list on the page', Pagination::emptyList('<div class="ba-item-recent-posts"></div>'), false);
$check('two lists, one with posts', Pagination::emptyList($empty . $list), false);
$check('noindex replaces the robots', Pagination::noindex('<title>a</title><meta name="robots" content="index, follow">'), '<title>a</title><meta name="robots" content="noindex, follow">');
$check('noindex added after the title', Pagination::noindex('<title>a</title>'), "<title>a</title>\n\t<meta name=\"robots\" content=\"noindex, follow\">");

// the guard of the sorting
$check('guard on by default', Pagination::guarded(new Registry()), true);
$check('sorting Gridbox offers passes', array_map(fn ($v) => Pagination::badSort($v, ''), ['price-low-high', 'newest', 'order_list', '', null]), [false, false, false, false, false]);
$check('any other sorting is refused', array_map(fn ($v) => Pagination::badSort($v, ''), ['price', 'title ASC', 'id,(select 1)', 'NEWEST', 'newest ']), [true, true, true, true, true]);
$check('comments and reviews left alone', [Pagination::badSort('oldest', 'comments.getComments'), Pagination::badSort('recent', 'Reviews.getReviews')], [false, false]);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
