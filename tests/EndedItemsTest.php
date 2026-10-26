<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/EndedItems.php';

use Merserwis\Plugin\System\MerTools\Tool\EndedItems;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$slide = fn (int $id, string $title) => '<li class="item  active" data-id="' . $id . '"><div class="ba-slideshow-img"><a href="/p/' . $id . '"></a></div>'
    . '<div class="ba-slideshow-caption"><div class="ba-blog-post-title-wrapper"><h3 class="ba-blog-post-title"><a href="/p/' . $id . '">' . $title . '</a></h3></div>'
    . '<div class="ba-blog-post-info-wrapper"><span>1 lipiec 2026</span></div></div></li>' . "\n";
$card = fn (int $id, string $title) => '<div class="ba-blog-post " data-id="' . $id . '"><div class="ba-blog-post-image"><div class="ba-overlay"></div></div>'
    . '<div class="ba-blog-post-content"><div class="ba-blog-post-title-wrapper"><h2 class="ba-blog-post-title"><a href="/p/' . $id . '">' . $title . '</a></h2></div></div></div>' . "\n";
$W = ['[ZAKOŃCZONE]'];

$list = '<ul class="ba-slideset">' . $slide(1, 'Webinar') . $slide(2, '[ZAKOŃCZONE] Promocja Sonel') . $slide(3, 'Nowy Geofon') . '</ul>';
$out  = EndedItems::hide($list, $W);
$check('slider: the ended one is out', str_contains($out, 'Promocja Sonel'), false);
$check('slider: others stay', substr_count($out, '<li class="item'), 2);
$check('slider: the list is intact', $out, '<ul class="ba-slideset">' . $slide(1, 'Webinar') . $slide(3, 'Nowy Geofon') . '</ul>');

$grid = '<div class="ba-blog-posts-wrapper">' . $card(1, 'A') . $card(2, 'Promocja [zakończone]') . $card(3, 'C') . $card(4, '[ZAKOŃCZONE] D') . '</div>';
$out  = EndedItems::hide($grid, $W);
$check('grid: any letter case, two out', substr_count($out, 'class="ba-blog-post "'), 2);
$check('grid: the wrapper intact', $out, '<div class="ba-blog-posts-wrapper">' . $card(1, 'A') . $card(3, 'C') . '</div>');

$check('all out: an empty wrapper', EndedItems::hide('<div class="ba-blog-posts-wrapper">' . $card(1, '[ZAKOŃCZONE] A') . '</div>', $W), '<div class="ba-blog-posts-wrapper"></div>');
$check('no words: untouched', EndedItems::hide($grid, []), $grid);
$check('word not in any title: untouched', EndedItems::hide($grid, ['xyz']), $grid);
$check('two words', substr_count(EndedItems::hide($grid, ['[ZAKOŃCZONE]', 'C']), 'class="ba-blog-post "'), 1);
$check('word in the text, not the title: stays', EndedItems::hide(str_replace('</h2></div></div></div>', '</h2></div><p>[ZAKOŃCZONE]</p></div></div>', $card(1, 'A')), $W), str_replace('</h2></div></div></div>', '</h2></div><p>[ZAKOŃCZONE]</p></div></div>', $card(1, 'A')));
$check('entities and tags in the title', EndedItems::title($card(1, '<b>[ZAKO&#323;CZONE]</b> a &amp; b')), '[ZAKOŃCZONE] a & b');

// not a list: a list item with a data-id and no title, and a wrapper of several cards
$menu = '<ul><li class="item" data-id="5"><a href="/x">[ZAKOŃCZONE] menu</a></li></ul>';
$check('li.item without a post title: untouched', EndedItems::hide($menu . $card(1, 'A'), $W), $menu . $card(1, 'A'));
$wrap = '<div class="ba-blog-post " data-id="9">' . $card(1, '[ZAKOŃCZONE] A') . $card(2, 'B') . '</div>';
$check('a wrapper of two cards is not a card', substr_count(EndedItems::hide($wrap, $W), 'class="ba-blog-post "'), 2);
$check('unclosed card: left alone', EndedItems::hide('<div class="ba-blog-post " data-id="1"><h2 class="ba-blog-post-title">[ZAKOŃCZONE] A</h2>', $W), '<div class="ba-blog-post " data-id="1"><h2 class="ba-blog-post-title">[ZAKOŃCZONE] A</h2>');
$check('words(): lines, trimmed, no empty', EndedItems::words("[ZAKOŃCZONE]\r\n\n  wygasła \n"), ['[ZAKOŃCZONE]', 'wygasła']);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
