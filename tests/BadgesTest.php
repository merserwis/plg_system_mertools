<?php

/**
 * Test of Badges: the badges of a product as Gridbox's tiles show them, the wrapper put into the
 * pictures of a product page, the stylesheet of the look chosen.
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Badges.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Badges;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-66s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// ---------------------------------------------------------------- settings
$check('off by default', Badges::enabled(new Registry()), false);
$o = Badges::options(new Registry());
$check('defaults: Gridbox\'s look, top left, 20 px, product page and lists', [$o['style'], $o['colours'], $o['corner'], $o['size'], $o['layout'], $o['offset'], $o['product'], $o['lists']],
    ['gridbox', 'gridbox', 'tl', 's', 'column', 20, true, true]);
$o = Badges::options(new Registry(['badge_style' => 'x;}body{', 'badge_corner' => 'mid', 'badge_offset' => '500', 'badge_color' => 'red;}']));
$check('unknown values fall back, offset kept to 80, a colour not safe for CSS refused', [$o['style'], $o['corner'], $o['offset'], $o['one']], ['gridbox', 'tl', 80, '#1f2328']);
$check('colours safe for CSS', [Badges::colour('#F64231'), Badges::colour('rgba(50, 219, 44, 0.55)'), Badges::colour('#abc'), Badges::colour('url(x)'), Badges::colour('red'), Badges::colour('rgb(1,2,3);x:y')],
    ['#f64231', 'rgba(50, 219, 44, 0.55)', '#abc', null, null, null]);

// ---------------------------------------------------------------- the badges of a product
$rows = [['title' => '', 'color' => '#f64231', 'type' => 'sale'], ['title' => 'Bestseller', 'color' => '#ffc700', 'type' => ''], ['title' => '', 'color' => '#000', 'type' => '']];
$in   = ['price' => '9758', 'sale_price' => '7806.4', 'stock' => '', 'min' => ''];
$check('sale as "- n%", the others as they are, an empty one left out', Badges::forProduct($rows, $in, false, 'Out of Stock'),
    [['title' => '- 20%', 'color' => '#f64231', 'out' => false], ['title' => 'Bestseller', 'color' => '#ffc700', 'out' => false]]);
$check('sale without a sale price: - 0% (as Gridbox)', Badges::forProduct([$rows[0]], ['sale_price' => ''] + $in, false, 'x')[0]['title'], '- 0%');
$check('sale of a free product: - 0%', Badges::forProduct([$rows[0]], ['price' => '0'] + $in, false, 'x')[0]['title'], '- 0%');
$check('stock below the minimum: only out of stock', Badges::forProduct($rows, ['stock' => '1', 'min' => '2'] + $in, false, 'Brak'), [['title' => 'Brak', 'color' => '', 'out' => true]]);
$check('stock 0: out of stock', count(Badges::forProduct($rows, ['stock' => '0'] + $in, false, 'Brak')), 1);
$check('stock enough: its badges', count(Badges::forProduct($rows, ['stock' => '3', 'min' => '2'] + $in, false, 'Brak')), 2);
$check('with variations the stock is not looked at', count(Badges::forProduct($rows, ['stock' => '0'] + $in, true, 'Brak')), 2);
$check('no badges, in stock: none', Badges::forProduct([], $in, false, 'x'), []);

$html = Badges::html([['title' => 'A&B <i>', 'color' => 'rgba(1, 2, 3, 1)', 'out' => false], ['title' => 'Brak', 'color' => '', 'out' => true], ['title' => 'X', 'color' => 'red;}', 'out' => false]]);
$check('wrapper: Gridbox\'s classes, colour as Gridbox writes it, text escaped, unsafe colour left out', $html,
    '<div class="ba-blog-post-badge-wrapper mt-badges-page" data-mt-badges><span class="ba-blog-post-badge" style="--badge-color:rgba(1, 2, 3, 1);">A&amp;B &lt;i&gt;</span>'
    . '<span class="ba-blog-post-badge out-of-stock-badge">Brak</span><span class="ba-blog-post-badge">X</span></div>');

// ---------------------------------------------------------------- into the pictures of the page
$w = '<div data-mt-badges>B</div>';
$page = '<div class="ba-item-product-slideshow ba-item" id="item-1"><div class="slideshow-wrapper dots-position-outside"><ul class="ba-slideshow ba-fade-in">'
    . '<div class="slideshow-content ba-field-content lightbox-enabled" tabindex="0"><li class="item active"><div class="ba-slideshow-img"></div></li></div>'
    . '<div class="ba-slideshow-dots"></div></ul></div></div>';
$check('slideshow: first in the box of the slides', Badges::inject($page, $w),
    str_replace('tabindex="0">', 'tabindex="0">' . $w, $page));
$gallery = '<div class="ba-item-product-gallery ba-item" id="item-2"><div class="ba-gridbox-gallery-image" tabindex="0" style="background-image: url(a.jpg);"><img src="a.jpg"></div>'
    . '<div class="ba-gridbox-gallery-image" tabindex="0"><img src="b.jpg"></div></div>';
$check('gallery: on its first picture only, which holds it', Badges::inject($gallery, $w),
    '<div class="ba-item-product-gallery ba-item" id="item-2"><div class="ba-gridbox-gallery-image mt-badge-host" tabindex="0" style="background-image: url(a.jpg);">' . $w
    . '<img src="a.jpg"></div><div class="ba-gridbox-gallery-image" tabindex="0"><img src="b.jpg"></div></div>');
$check('two slideshows: both', substr_count(Badges::inject($page . $page, $w), $w), 2);
$check('a page that has them already: as it is', Badges::inject($page . $w, $w), $page . $w);
$empty = '<div class="ba-item-product-slideshow ba-item" id="item-3"><div class="empty-list"></div></div><div class="ba-item-slideshow ba-item" id="item-4"><div class="slideshow-content"></div></div>';
$check('an empty product slideshow: nothing taken from the next element', Badges::inject($empty, $w), $empty);
$check('no product pictures: as it is', Badges::inject('<div class="ba-item-text">x</div>', $w), '<div class="ba-item-text">x</div>');

// ---------------------------------------------------------------- the stylesheet
$css = Badges::css(Badges::options(new Registry(['badge_style' => 'tag', 'badge_corner' => 'br', 'badge_size' => 'l', 'badge_offset' => '12', 'badge_layout' => 'row'])));
$check('site: the lists\' wrappers, above Gridbox\'s own rules', str_contains($css, 'body .ba-blog-post-badge-wrapper{position:absolute'), true);
$check('site: the product page\'s above the picture of the slide', str_contains($css, 'body .mt-badges-page{display:flex;z-index:5}'), true);
$check('site: only the style chosen', [str_contains($css, 'clip-path'), str_contains($css, 'backdrop-filter'), str_contains($css, '999px')], [true, false, false]);
$check('site: bottom right, the arrow of the tag turned', str_contains($css, 'bottom:var(--mt-off,20px);top:auto;right:var(--mt-off,20px);left:auto;--mt-align:flex-end;--mt-flip:-1'), true);
$check('site: the size, the row, the offset', [str_contains($css, '--mt-fs:1rem'), str_contains($css, 'flex-direction:row'), str_contains($css, '--mt-off:12px')], [true, true, true]);
$check('site: Gridbox\'s colours, out of stock as Gridbox, text by the colour where the browser can', [str_contains($css, '--mt-c:var(--badge-color'), str_contains($css, 'out-of-stock-badge{--mt-c:#ff5c00}'), str_contains($css, '@supports (color:oklch(from red l c h))')],
    [true, true, true]);
$css = Badges::css(Badges::options(new Registry(['badge_lists' => '0', 'badge_corner' => 'tr'])));
$check('lists off: only the product page\'s badges', [str_contains($css, 'body .mt-badges-page{position:absolute'), str_contains($css, 'body .ba-blog-post-badge-wrapper{')], [true, false]);
$check('top right without lists: no room made for a heart', str_contains($css, 'wishlist'), false);
$check('top right in the lists: below the heart', str_contains(Badges::css(Badges::options(new Registry(['badge_corner' => 'tr']))), ':has(~ .ba-blog-post-wishlist-wrapper)'), true);
$check('one colour: the colour chosen', str_contains(Badges::css(Badges::options(new Registry(['badge_colors' => 'one', 'badge_color' => '#123456']))), '--mt-one:#123456'), true);
$preview = Badges::previewCss();
$check('preview: every value of every part under its class', array_map(fn ($c) => str_contains($preview, '.' . $c . ' .ba-blog-post-badge-wrapper'),
    ['mt-bp-s-gradient', 'mt-bp-s-dot', 'mt-bp-c-light', 'mt-bp-k-bl', 'mt-bp-z-m', 'mt-bp-l-row']), [true, true, true, true, true, true]);
$check('stylesheet: balanced braces', substr_count($preview, '{') === substr_count($preview, '}') && substr_count($css, '{') === substr_count($css, '}'), true);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
