<?php

/**
 * Standalone test of Speedup and ImageSize::pathOf (no Joomla needed).
 * Run: php tests/SpeedupTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Speedup.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/PostRows.php';

use Merserwis\Plugin\System\MerTools\Tool\ImageSize;
use Merserwis\Plugin\System\MerTools\Tool\Speedup;
use Merserwis\Plugin\System\MerTools\Tool\PostRows;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$has = fn (string $h, string $needle) => str_contains($h, $needle);

$site = 'https://www.merserwis.pl';
$ph   = 'https://www.merserwis.pl/components/com_gridbox/assets/images/default-lazy-load.webp';
$size = fn (string $url) => str_contains($url, 'logo') ? [240, 60] : (str_contains($url, 'known') ? [800, 600] : null);

// ---------------------------------------------------------------- images
$html = '<body><header class="header"><img src="' . $ph . '" alt="Merserwis logo" width="100" height="100" data-gridbox-lazyload-src="https://www.merserwis.pl/images/logo.png" class="lazy-load-image"></header>'
    . '<div><img src="' . $ph . '" alt="" width="100" height="100" data-gridbox-lazyload-src="/images/known.jpg" class="lazy-load-image foo">'
    . '<img src="' . $ph . '" alt="x" width="100" height="100" data-gridbox-lazyload-src="/images/mystery.jpg" class="lazy-load-image">'
    . '<img height="1" width="1" style="display:none" src="' . $ph . '" data-gridbox-lazyload-src="https://www.facebook.com/tr?id=1&amp;ev=PageView">'
    . '<img src="' . $ph . '" width="100" height="100" data-gridbox-lazyload-src="https://cdn.example.com/a.jpg" class="lazy-load-image"></div></body>';
$out = Speedup::images($html, $site, $size);
$check('logo in header: eager, real src, Gridbox size kept, proportions', $has($out, '<img decoding="async" loading="eager" style="aspect-ratio:240/60" src="https://www.merserwis.pl/images/logo.png" alt="Merserwis logo" width="100" height="100">'), true);
$check('logo: no lazy class left (class attribute gone)', $has($out, 'logo.png" alt="Merserwis logo" title') || !preg_match('#logo\.png"[^>]*lazy-load-image#', $out), true);
$check('below the header: lazy, proportions, other classes kept', $has($out, '<img decoding="async" loading="lazy" style="aspect-ratio:800/600" src="/images/known.jpg" alt="" width="100" height="100" class="foo">'), true);
$check('unknown size: Gridbox size kept, no proportions', $has($out, '<img decoding="async" loading="lazy" src="/images/mystery.jpg" alt="x" width="100" height="100">'), true);
$check('existing style kept, proportions added', $has(Speedup::images('<img src="' . $ph . '" style="border:0" width="100" height="100" data-gridbox-lazyload-src="/images/known.jpg">', $site, $size), 'style="border:0;aspect-ratio:800/600"'), true);
$check('empty style filled', $has(Speedup::images('<img src="' . $ph . '" style="" data-gridbox-lazyload-src="/images/known.jpg">', $site, $size), 'style="aspect-ratio:800/600"'), true);
$check('tracking pixel untouched', $has($out, '<img height="1" width="1" style="display:none" src="' . $ph . '" data-gridbox-lazyload-src="https://www.facebook.com/tr?id=1&amp;ev=PageView">'), true);
$check('other site untouched', $has($out, 'data-gridbox-lazyload-src="https://cdn.example.com/a.jpg" class="lazy-load-image"'), true);
$check('no placeholder left for local images', substr_count($out, 'src="' . $ph . '"'), 2);
$check('Gridbox 100x100 kept everywhere', substr_count($out, 'width="100" height="100"'), 4);
$check('images: idempotent', Speedup::images($out, $site, $size), $out);
$hdr = '<header><img src="P" width="100" height="100" data-gridbox-lazyload-src="/a.jpg" class="lazy-load-image"><img src="P" width="100" height="100" data-gridbox-lazyload-src="/b.jpg" class="lazy-load-image">'
    . '<img src="P" width="100" height="100" data-gridbox-lazyload-src="/menu-promo.jpg" class="lazy-load-image"></header>';
$ho  = Speedup::images($hdr, $site, $size);
$check('header: first two at once', substr_count($ho, 'loading="eager"'), 2);
$check('header: menu pictures left to Gridbox', $has($ho, '<img src="P" width="100" height="100" data-gridbox-lazyload-src="/menu-promo.jpg" class="lazy-load-image">'), true);
$check('srcset carried over', $has(Speedup::images('<img src="' . $ph . '" data-gridbox-lazyload-src="/a.jpg" data-gridbox-lazyload-srcset="/a.jpg 1x, /a2.jpg 2x">', $site, $size), 'srcset="/a.jpg 1x, /a2.jpg 2x"'), true);

// ---------------------------------------------------------------- 0.0.29: plain images, first section, copies, GIF
$dimsOf = fn (string $u) => str_contains($u, 'hero') ? [770, 744] : (str_contains($u, 'logo') ? [240, 60] : (str_contains($u, 'photo') ? [1200, 705] : null));
$copies = function (string $u) {
    if (str_contains($u, 'hero.gif')) {
        return ['frame' => '/media/mertools-img/ab/x-hero-f-480.webp 480w, /media/mertools-img/ab/x-hero-f-770.webp 770w', 'anim' => '/images/hero.webp'];
    }
    return str_contains($u, 'photo') ? ['srcset' => '/media/mertools-img/cd/y-photo-480.webp 480w, /media/mertools-img/cd/y-photo-1200.webp 1200w'] : null;
};
$page = '<html><head><link rel="stylesheet" href="/templates/gridbox/library/icons/fontawesome/fontawesome.css"><link rel="stylesheet" href="/templates/gridbox/css/gridbox.css"></head><body>'
    . '<header class="header"><div class="ba-section row-fluid" id="h"><img src="https://www.merserwis.pl/images/logo.svg" alt="" width="100" height="100"></div></header>'
    . '<div class="ba-section row-fluid" id="s1"><div class="ba-grid-column-wrapper ba-col-6 ba-sm-la-12"><img src="https://www.merserwis.pl/images/hero.gif" alt="" width="100" height="100"></div>'
    . '<div class="ba-grid-column-wrapper ba-col-6"><img src="/images/photo-a.jpg" alt="" width="100" height="100"></div></div>'
    . '<div class="ba-section row-fluid" id="s2"><div class="ba-grid-column-wrapper ba-col-12"><img src="/images/photo-b.jpg" alt="" width="100" height="100"><img src="https://cdn.example.com/x.jpg"></div></div>'
    . '</body></html>';
$po = Speedup::images($page, $site, $dimsOf, $copies, true, true);
$check('plain: first section picture not lazy', (bool) preg_match('#<img[^>]*hero\.gif[^>]*>#', $po, $hm) && !str_contains($hm[0], 'loading="lazy"'), true);
$check('plain: GIF at the top shows its first frame (srcset of frames)', str_contains($hm[0] ?? '', 'srcset="/media/mertools-img/ab/x-hero-f-480.webp 480w, /media/mertools-img/ab/x-hero-f-770.webp 770w"'), true);
$check('plain: GIF animation address kept for later', str_contains($hm[0] ?? '', 'data-mt-anim="/images/hero.webp"'), true);
$check('plain: GIF at the top fetched first', str_contains($hm[0] ?? '', 'fetchpriority="high"'), true);
$check('plain: GIF proportions', str_contains($hm[0] ?? '', 'style="aspect-ratio:770/744"'), true);
$check('plain: GIF sizes = its own width (or the screen when narrower)', str_contains($hm[0] ?? '', 'sizes="(max-width: 770px) 100vw, 770px"'), true);
$check('plain: only one fetchpriority', substr_count($po, 'fetchpriority="high"'), 1);
$check('plain: animation script added once', substr_count($po, 'img[data-mt-anim]'), 1);
preg_match('#<img[^>]*photo-a\.jpg[^>]*>#', $po, $am);
$check('plain: second picture of the first section not lazy, copies offered', !str_contains($am[0], 'loading=') && str_contains($am[0], 'srcset="/media/mertools-img/cd/y-photo-480.webp 480w'), true);
$check('plain: eager sizes = own width', str_contains($am[0], 'sizes="(max-width: 1200px) 100vw, 1200px"'), true);
preg_match('#<img[^>]*photo-b\.jpg[^>]*>#', $po, $bm);
$check('plain: further down lazy + async', str_contains($bm[0], 'loading="lazy"') && str_contains($bm[0], 'decoding="async"'), true);
$check('plain: lazy sizes never auto (a picture without CSS width would get 300 px)', str_contains($bm[0], 'sizes="(max-width: 1200px) 100vw, 1200px"') && !str_contains($po, 'auto,'), true);
$check('plain: logo in header gets proportions, not lazy, no priority', str_contains($po, '<img style="aspect-ratio:240/60" src="https://www.merserwis.pl/images/logo.svg" alt="" width="100" height="100">'), true);
$check('plain: other site untouched', str_contains($po, '<img src="https://cdn.example.com/x.jpg">'), true);
$check('plain: idempotent', Speedup::images($po, $site, $dimsOf, $copies, true, true), $po);
$check('plain off: plain images untouched', Speedup::images($page, $site, $dimsOf, $copies, false, true), $page);
$noGif = Speedup::images($page, $site, $dimsOf, $copies, true, false);
$check('gif off: no frame, no script', !str_contains($noGif, 'data-mt-anim') && !str_contains($noGif, 'img[data-mt-anim]'), true);
$check('existing fetchpriority on the page: none added', substr_count(Speedup::images(str_replace('<head>', '<head><link rel="preload" as="image" href="/p.jpg" fetchpriority="high">', $page), $site, $dimsOf, $copies, true, true), 'fetchpriority="high"'), 1);
$many = '<header></header><div class="ba-section" id="a">' . str_repeat('<img src="/images/photo-z.jpg" width="100" height="100">', 6) . '</div><div class="ba-section" id="b"></div>';
$check('first section: at most ZONE_EAGER at once', substr_count(Speedup::images($many, $site, $dimsOf, null, true), 'loading="lazy"'), 2);
$real = '<header></header><div class="ba-wrapper"><div class="ba-section row-fluid" id="a"><div class="ba-overlay"></div><div class="ba-section-items"><img src="/x.jpg"></div></div></div>'
    . '<div class="ba-wrapper"><div class="ba-section row-fluid" id="b"><div class="ba-section-items"></div></div></div>';
$check('first section: inner ba-section-items is not a section', Speedup::firstSectionEnd($real, 17), strpos($real, '<div class="ba-section row-fluid" id="b"'));
$check('first section: class not first in the list', Speedup::firstSectionEnd('<header></header><div class="x ba-section"></div><div class="y ba-section"></div>', 17), 49);
$check('no sections: nothing counts as first section', Speedup::firstSectionEnd('<header></header><div>x</div>', 17), 0);
$check('one section: all of it', Speedup::firstSectionEnd('<header></header><div class="ba-section">x</div>', 17), 48);
$check('sizes: picture of a blog/store list = a grid cell, at most 400 px', Speedup::sizes('<div class="ba-grid-column-wrapper ba-col-12"><div class="ba-blog-post-image"><a href="/p">', 90, 960), '(max-width: 400px) 100vw, 400px');
$check('sizes: small list picture keeps its width', Speedup::sizes('<div class="ba-blog-post-image"><a href="/p">', 46, 300), '(max-width: 300px) 100vw, 300px');
$check('sizes: unknown width = no copies', Speedup::sizes('<div>', 5, 0), null);
$check('sizes: after a list picture, own width again', Speedup::sizes('<div class="ba-col-12"><div class="ba-blog-post-image"><a href="/p"><img></a></div></div><div class="x">', 104, 602), '(max-width: 602px) 100vw, 602px');
$check('srcset escaped', str_contains(Speedup::images('<header></header><div class="ba-section"></div><div class="ba-section"><img src="/images/photo-q.jpg"></div>', $site, $dimsOf,
    fn ($u) => ['srcset' => '/a b.webp 480w"x'], true), 'srcset="/a b.webp 480w&quot;x"'), true);
$ic = Speedup::iconStyles($page);
$check('icons: icon style sheet loads alongside', str_contains($ic, '<link rel="stylesheet" href="/templates/gridbox/library/icons/fontawesome/fontawesome.css" media="print" onload="this.media=\'all\'"><noscript><link rel="stylesheet" href="/templates/gridbox/library/icons/fontawesome/fontawesome.css"></noscript>'), true);
$check('icons: other style sheets unchanged', str_contains($ic, '<link rel="stylesheet" href="/templates/gridbox/css/gridbox.css">'), true);
$check('icons: idempotent', Speedup::iconStyles($ic), $ic);
$check('icons: body links untouched', Speedup::iconStyles('<body><link rel="stylesheet" href="/x/fontawesome.css"></body>'), '<body><link rel="stylesheet" href="/x/fontawesome.css"></body>');

// ---------------------------------------------------------------- 0.0.29: background pictures
$bgCopies = fn (string $u) => str_contains($u, 'slide') ? ['srcset' => '/m/s-480.webp 480w, /m/s-768.webp 768w, /m/s-1080.webp 1080w, /m/s-1500.webp 1500w'] : null;
$bg = '<html><head></head><body><div class="ba-slideshow-img" data-src="https://www.merserwis.pl/images/slide.jpg" style="background-image: url(https://www.merserwis.pl/images/slide.jpg);"></div>'
    . '<div class="x" style="background-image: url(&quot;/images/other.jpg&quot;)"></div><div style="background-image: url(https://cdn.example.com/slide.jpg)"></div></body></html>';
$bo = Speedup::backgrounds($bg, $site, $bgCopies);
$vars = [];
$bo = Speedup::backgrounds($bg, $site, $bgCopies, $vars);
$check('bg: style gets the full-width copy, marker at the end', str_contains($bo, '<div class="ba-slideshow-img" data-src="/m/s-1500.webp" style="background-image: url(/m/s-1500.webp);" data-mt-bg="1">'), true);
$check('bg: variants for a preload', $vars, ['/m/s-1500.webp' => [768 => '/m/s-768.webp', 480 => '/m/s-480.webp']]);
$pl = Speedup::preloadImage('<title>T</title>', '/m/s-1500.webp', $vars['/m/s-1500.webp']);
$check('bg: preload per screen', $pl, '<title>T</title><link rel="preload" as="image" href="/m/s-1500.webp" media="(min-width:769px)" fetchpriority="high"><link rel="preload" as="image" href="/m/s-768.webp" media="(min-width:481px) and (max-width:768px)" fetchpriority="high"><link rel="preload" as="image" href="/m/s-480.webp" media="(max-width:480px)" fetchpriority="high">');
$mp = '<title>T</title></head><body><div class="slideshow-content lazy-load-image"> <li class="item active"><div class="ba-slideshow-img" data-src="/images/slide.jpg" style="background-image: url(/images/slide.jpg);"></div></li>';
[$mpo, $mph] = Speedup::mainPhoto(Speedup::backgrounds($mp, $site, $bgCopies));
$check('bg + main photo: main photo still found, now the copy', $mph, '/m/s-1500.webp');
$check('bg: phone rules', str_contains($bo, '<style id="mertools-bg">@media (max-width:768px){[data-mt-bg="1"]{background-image:url("/m/s-768.webp")!important}}@media (max-width:480px){[data-mt-bg="1"]{background-image:url("/m/s-480.webp")!important}}</style></head>'), true);
$check('bg: without copies unchanged', str_contains($bo, '<div class="x" style="background-image: url(&quot;/images/other.jpg&quot;)"></div>'), true);
$check('bg: other site unchanged', str_contains($bo, '<div style="background-image: url(https://cdn.example.com/slide.jpg)"></div>'), true);
$check('bg: idempotent', Speedup::backgrounds($bo, $site, $bgCopies), $bo);
$check('bg: nothing to do = same page', Speedup::backgrounds('<head></head><div style="color:red"></div>', $site, $bgCopies), '<head></head><div style="color:red"></div>');
$check('bg: quoted url', str_contains(Speedup::backgrounds('<head></head><div style="background-image: url(\'/images/slide.png\')"></div>', $site, $bgCopies), "url('/m/s-1500.webp')"), true);

// ---------------------------------------------------------------- 0.0.30: popups after the first interaction
$pg = '<body><div class="ba-lightbox-backdrop" data-id="item-1"></div><script src="/templates/gridbox/js/gridbox.js?2.20.4.0" nonce="x"></script><script src="/other.js"></script></body>';
$po2 = Speedup::delayPopups($pg);
$check('popups: wrapper right after gridbox.js', str_contains($po2, 'gridbox.js?2.20.4.0" nonce="x"></script><script>(function(w){var o=w.initLightbox;'), true);
$check('popups: only time-delay, not cookies', str_contains($po2, 'it.type!=="cookies"&&it.trigger&&it.trigger.type==="time-delay"'), true);
$check('popups: idempotent', Speedup::delayPopups($po2), $po2);
$check('popups: page without popups unchanged', Speedup::delayPopups('<script src="/templates/gridbox/js/gridbox.js"></script>'), '<script src="/templates/gridbox/js/gridbox.js"></script>');
$check('popups: without gridbox.js unchanged', Speedup::delayPopups('<div class="ba-lightbox-backdrop"></div>'), '<div class="ba-lightbox-backdrop"></div>');

// ---------------------------------------------------------------- 0.0.31: post sliders in rows
$sl = '<html><head></head><body><div class="ba-item-recent-posts-slider ba-item mt-rows-2 mt-rows-phone-1" id="item-1"></div><script src="/templates/gridbox/js/gridbox.js?2.20.4.0"></script></body></html>';
$so = PostRows::apply($sl);
$check('rows: script right after gridbox.js', str_contains($so, 'gridbox.js?2.20.4.0"></script><script>(function(w,d){var a;') && str_contains($so, 'a.mtRows=1'), true);
$check('rows: style in head', str_contains($so, '<style id="mertools-rows">.slideshow-content .mt-rows{'), true);
$check('rows: idempotent', PostRows::apply($so), $so);
$check('rows: slider without class unchanged', PostRows::apply(str_replace(' mt-rows-2 mt-rows-phone-1', '', $sl)), str_replace(' mt-rows-2 mt-rows-phone-1', '', $sl));
$check('rows: only phone class counts', PostRows::wanted('<div class="ba-item-related-posts-slider ba-item mt-rows-phone-2">'), true);
$check('rows: other element with the class ignored', PostRows::wanted('<div class="ba-item-text ba-item mt-rows-2">'), false);
$check('rows: recently viewed products', PostRows::wanted('<div class="ba-item-recently-viewed-products ba-item mt-rows-3">'), true);
$check('rows: no gridbox.js = unchanged', PostRows::apply('<div class="ba-item-recent-posts-slider mt-rows-2"></div>'), '<div class="ba-item-recent-posts-slider mt-rows-2"></div>');

// ---------------------------------------------------------------- header backgrounds
$h = '<div class="lazy-load-image x"></div><header class="header"><div class="ba-section row-fluid lazy-load-image" id="a"></div><div class="lazy-load-image"></div></header><div class="lazy-load-image"></div>';
$check('header backgrounds shown, others kept', Speedup::headerBackgrounds($h),
    '<div class="lazy-load-image x"></div><header class="header"><div class="ba-section row-fluid" id="a"></div><div></div></header><div class="lazy-load-image"></div>');
$check('header pictures keep their class (Gridbox loads them)', Speedup::headerBackgrounds('<header><img src="P" class="lazy-load-image" data-gridbox-lazyload-src="/m.jpg"></header>'),
    '<header><img src="P" class="lazy-load-image" data-gridbox-lazyload-src="/m.jpg"></header>');

// ---------------------------------------------------------------- main photo
$p = '<title>T</title></head><body><div class="slideshow-content ba-field-content lightbox-enabled lazy-load-image" tabindex="0"> <li class="item active"><div class="ba-slideshow-img" data-src="https://www.merserwis.pl/images/p.jpg" style="background-image: url(https://www.merserwis.pl/images/p.jpg);"></div></li>'
    . '<div class="slideshow-content lazy-load-image"> <li class="item active"><div class="ba-slideshow-img" data-src="/second.jpg"></div></li>';
[$o, $photo] = Speedup::mainPhoto($p);
$check('main photo address', $photo, 'https://www.merserwis.pl/images/p.jpg');
$check('first slideshow shown at once', $has($o, '<div class="slideshow-content ba-field-content lightbox-enabled" tabindex="0">'), true);
$check('second slideshow left lazy', $has($o, '<div class="slideshow-content lazy-load-image">'), true);
$check('preload after the title', $has(Speedup::preloadImage($o, $photo), '<title>T</title><link rel="preload" as="image" href="https://www.merserwis.pl/images/p.jpg" fetchpriority="high"></head>'), true);
$check('no slideshow: nothing', Speedup::mainPhoto('<p>x</p>'), ['<p>x</p>', null]);

// ---------------------------------------------------------------- page shown at once (Gridbox deferred loading)
$dl = '<html><head><title>T</title><style type="text/css">.a{}</style><script class="exclude-deffer">x()</script></head>'
    . '<body class="com_gridbox page " style="opacity: 0; overflow: hidden; margin: 0;"><div class="body">C</div>'
    . '<link rel="preload" href="/f.woff" as="font" type="font/woff" crossorigin="anonymous">'
    . '<link href="/a.css?1" rel="preload" nonce="N" as="style">' . "\n"
    . '<link href="//fonts.googleapis.com/css?family=Roboto&amp;display=swap" rel="preload" as="style">'
    . '<link rel="preload" as="image" href="/p.jpg"><script src="/g.js"></script></body></html>';
$sa = Speedup::showAtOnce($dl);
$check('show at once: style sheets back in the head, in order, after the inline styles',
    $has($sa, '<script class="exclude-deffer">x()</script><link href="/a.css?1" rel="stylesheet" nonce="N"><link href="//fonts.googleapis.com/css?family=Roboto&amp;display=swap" rel="stylesheet"></head>'), true);
$check('show at once: body visible, its class kept', $has($sa, '<body class="com_gridbox page "><div class="body">C</div>'), true);
$check('show at once: font and image preloads stay', $has($sa, '<link rel="preload" href="/f.woff" as="font"') && $has($sa, '<link rel="preload" as="image" href="/p.jpg">'), true);
$check('show at once: no style preload left in the body', preg_match('#<body.*as="style"#s', $sa), 0);
$check('show at once: scripts stay at the end', $has($sa, '<script src="/g.js"></script></body>'), true);
$check('show at once: idempotent', Speedup::showAtOnce($sa), $sa);
$check('show at once: deferred loading off = nothing', Speedup::showAtOnce('<head></head><body class="x"><link href="/a.css" rel="preload" as="style"></body>'),
    '<head></head><body class="x"><link href="/a.css" rel="preload" as="style"></body>');
$check('show at once: other body styles kept', $has(Speedup::showAtOnce('<head></head><body style="color:red; opacity:0; overflow:hidden; margin:0"></body>'), '<body style="color:red;"></body>'), true);
$check('show at once: compressed HTML (one line)', $has(Speedup::showAtOnce(str_replace("\n", '', $dl)), '<link href="/a.css?1" rel="stylesheet" nonce="N"><link href="//fonts'), true);

// ---------------------------------------------------------------- video background
$g = '<script src="/templates/gridbox/js/gridbox.js?2.20.4.0"></script><script src="/x.js"></script>';
$v = Speedup::delayVideoBackground($g, 3);
$check('video delay right after gridbox.js', str_starts_with($v, '<script src="/templates/gridbox/js/gridbox.js?2.20.4.0"></script><script>(function(w,d){var a=w.app;'), true);
$check('video delay: 3000 ms', $has($v, 'setTimeout(run,3000)'), true);
$check('video delay: before the next script', $has($v, '})(window,document);</script><script src="/x.js"></script>'), true);
$check('no gridbox.js: nothing', Speedup::delayVideoBackground('<p>x</p>', 3), '<p>x</p>');
$check('video as on computers: no phone code', $has($v, 'matchMedia'), false);
$n = Speedup::delayVideoBackground($g, 3, 'none', 768, '', '#1a1a1a');
$check('phones without video: width and colour', $has($n, 'matchMedia("(max-width:768px)")') && $has($n, 'var col="#1a1a1a",img="",pic=0;'), true);
$check('phones without video: colour at DOMContentLoaded, no start', $has($n, 'if(ph){d.readyState==="loading"?d.addEventListener("DOMContentLoaded",paint):paint()}') && $has($n, 'if(ph){paint();return}'), true);
$check('phones without video: elsewhere still delayed', $has($n, 'setTimeout(run,3000)'), true);
$i = Speedup::delayVideoBackground($g, 3, 'image', 600, '/images/hero "x".webp', '#000');
$check('phone picture: chosen, quotes escaped', $has($i, 'img="/images/hero \\u0022x\\u0022.webp",pic=1') && $has($i, 'max-width:600px'), true);
$check('phone picture: YouTube thumbnail fallback', $has($i, 'https://i.ytimg.com/vi/"+v.id+"/hqdefault.jpg'), true);
$check('bad colour dropped', $has(Speedup::delayVideoBackground($g, 3, 'none', 768, '', 'red;}</script>'), 'var col="",'), true);
$check('unknown phone mode = video', Speedup::delayVideoBackground($g, 3, 'x'), $v);
$check('width clamped', $has(Speedup::delayVideoBackground($g, 3, 'none', 5), 'max-width:320px'), true);

// ---------------------------------------------------------------- marketing scripts
$s = '<head><script type="application/json" class="joomla-script-options new">{"googletagmanager.com":1}</script>'
    . '<script type="application/ld+json">{"x":"fbq("}</script></head><body>'
    . "<script>(function(w,d,s,l,i){w[l]=w[l]||[];j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-X');</script>"
    . '<script>!function(f,b,e,v,n,t,s){fbq("init","1")}</script>'
    . '<script src="/assets/js/cookie-consent.js"></script>'
    . "<script>document.addEventListener('DOMContentLoaded', function () { cookieconsent.run({}); gtag('consent','default') });</script>"
    . '<script src="https://elfsightcdn.com/platform.js" async></script>'
    . '<script type="module" src="/x.js"></script><script src="/app.js"></script></body>';
$d = Speedup::delayScripts($s, ['googletagmanager.com', 'fbq(', 'elfsightcdn.com', 'gtag('], ['cookieconsent', 'cookie-consent'], 0);
$check('3 scripts delayed', substr_count($d, '<script type="text/mertools-delay"'), 3);
$check('JSON blocks untouched', $has($d, '<script type="application/json" class="joomla-script-options new">{"googletagmanager.com":1}</script><script type="application/ld+json">'), true);
$check('cookie consent kept (also with gtag in it)', $has($d, "<script>document.addEventListener('DOMContentLoaded', function () { cookieconsent.run({});"), true);
$check('external script keeps its attributes', $has($d, '<script type="text/mertools-delay" src="https://elfsightcdn.com/platform.js" async></script>'), true);
$check('module untouched', $has($d, '<script type="module" src="/x.js"></script>'), true);
$check('loader before </body>, no timer at 0', $has($d, '})(window,document);</script></body>') && !$has($d, 'setTimeout(run'), true);
$check('timer when set', $has(Speedup::delayScripts($s, ['fbq('], [], 10), 'setTimeout(run,10000)'), true);
$check('no patterns: nothing', Speedup::delayScripts($s, ['', ' '], [], 0), $s);
$check('nothing matching: nothing', Speedup::delayScripts('<script>x()</script></body>', ['fbq('], [], 0), '<script>x()</script></body>');
// 1.7.0: the type in any quoting HTML allows: inert blocks and modules are never made classic scripts
$q = "<script type='text/plain'>window.runTracker();//fbq(</script><script type=module>import 'fbq(';</script>"
    . "<script type = 'text/plain' >fbq()</script><script type=TEXT/PLAIN>fbq()</script></body>";
$check('single-quoted, bare and spaced data types untouched', Speedup::delayScripts($q, ['fbq('], [], 0), $q);
$dq = Speedup::delayScripts("<script type='text/javascript' async>fbq()</script><script type=application/javascript>fbq(2)</script></body>", ['fbq('], [], 0);
$check('single-quoted and bare JavaScript delayed, its type gone', $has($dq, '<script type="text/mertools-delay" async>fbq()</script><script type="text/mertools-delay">fbq(2)</script>'), true);
$check('data-type is not the type', substr_count(Speedup::delayScripts('<script data-type="x">fbq()</script></body>', ['fbq('], [], 0), 'text/mertools-delay"'), 2);

// 1.7.0: a Content Security Policy with nonces
$check('nonce: on a script added', Speedup::nonced('<script>x()</script>', 'TkVX+/='), '<script nonce="TkVX+/=">x()</script>');
$check('nonce: on a style added', Speedup::nonced('<style id="a">b{}</style>', 'N1'), '<style nonce="N1" id="a">b{}</style>');
$check('nonce: none = unchanged', Speedup::nonced('<script>x()</script>', ''), '<script>x()</script>');
$check('nonce: quotes cannot break out', Speedup::nonced('<script>x()</script>', 'a"b'), '<script nonce="a&quot;b">x()</script>');
$dn = Speedup::delayScripts('<script nonce="N1">fbq()</script></body>', ['fbq('], [], 0, 'N1');
$check('nonce: delay loader carries it and passes the property on', $has($dn, '<script nonce="N1">(function(w,d){var go=0;') && $has($dn, 'if(o.nonce)s.nonce=o.nonce;')
    && $has($dn, 'a.name!=="nonce"'), true);
$check('nonce: the delayed script keeps its own', $has($dn, '<script type="text/mertools-delay" nonce="N1">fbq()</script>'), true);
$check('nonce: popups wrapper', $has(Speedup::delayPopups($pg, 'N1'), '<script nonce="N1">(function(w){var o=w.initLightbox;'), true);
$check('nonce: video delay', $has(Speedup::delayVideoBackground($g, 3, 'video', 768, '', '#000', 'N1'), '<script nonce="N1">(function(w,d){var a=w.app;'), true);
$check('nonce: background style', $has(Speedup::backgrounds($bg, $site, $bgCopies, $vars, 'N1'), '<style nonce="N1" id="mertools-bg">'), true);
$check('nonce: icon styles left blocking (an onload would not run)', Speedup::iconStyles($page, 'N1'), $page);

// ---------------------------------------------------------------- local addresses and files
$check('local: path', Speedup::isLocal('/images/a.jpg', $site), true);
$check('local: own host', Speedup::isLocal('https://www.merserwis.pl/images/a.jpg', $site), true);
$check('local: other host', Speedup::isLocal('https://www.merserwis.pl.evil.com/a.jpg', $site), false);
$check('local: protocol-relative', Speedup::isLocal('//cdn.x/a.jpg', $site), false);
$check('path: own host', ImageSize::pathOf('https://www.merserwis.pl/images/a%20b.jpg?x=1', '/var/www/html', $site, ''), '/var/www/html/images/a b.jpg');
$check('path: site in folder', ImageSize::pathOf('/joomla/images/a.png', '/var/www/html', 'https://x.pl', '/joomla'), '/var/www/html/images/a.png');
$check('path: no traversal', ImageSize::pathOf('/images/../configuration.php', '/var/www/html', $site, ''), null);
$check('path: picture types only', ImageSize::pathOf('/images/a.svg', '/var/www/html', $site, ''), null);
$check('path: other host', ImageSize::pathOf('https://evil.com/a.jpg', '/var/www/html', $site, ''), null);

// an address with "$1" or a backslash (from the page) is put in as it is, not as a back-reference
$check('images: $ in the address kept', $has(Speedup::images('<img src="' . $ph . '" data-gridbox-lazyload-src="/images/a$1b.jpg">', $site, $size), 'src="/images/a$1b.jpg"'), true);
$check('images: backslash in the address kept', $has(Speedup::images('<img src="' . $ph . '" data-gridbox-lazyload-src="/images/a\\b.jpg">', $site, $size), 'src="/images/a\\b.jpg"'), true);
$icons = '<html><head><link rel="stylesheet" href="/media/font-awesome/css/all.css"><link rel="stylesheet" href="/media/materialize.min.css"><link rel="stylesheet" href="/media/material-icons.css"></head><body></body></html>';
$iconsOut = Speedup::iconStyles($icons);
$check('icons: font awesome async', $has($iconsOut, 'href="/media/font-awesome/css/all.css" media="print"'), true);
$check('icons: material icons async', $has($iconsOut, 'href="/media/material-icons.css" media="print"'), true);
$check('icons: materialize (a framework) untouched', $has($iconsOut, '<link rel="stylesheet" href="/media/materialize.min.css">'), true);

// ---------------------------------------------------------------- product photos as pictures
$pp = '<html><head><title>Metrel A 1715 5M - Merserwis</title><meta property="og:title" content="OG name"></head><body>'
    . '<div class="ba-item-product-slideshow ba-item" id="item-1"><div class="slideshow-wrapper"><ul class="ba-slideshow"><div class="slideshow-content ba-field-content lightbox-enabled">'
    . '<li class="item active"><div class="ba-slideshow-img" data-src="/m/s-1500.webp" style="background-image: url(/m/s-1500.webp);" data-mt-bg="1"></div></li>'
    . '<li class="item"><div class="ba-slideshow-img" data-src="https://www.merserwis.pl/images/b&amp;w.jpg" style="background-image: url(&quot;https://www.merserwis.pl/images/b&amp;w.jpg&quot;);"></div></li>'
    . '<li class="item"><div class="ba-slideshow-img" data-src="/images/lazy.jpg"></div></li>'
    . '</div><div class="ba-slideshow-dots thumbnails-dots" style="--thumbnails-dots-image-0: url(/images/a.jpg);"></div></ul></div></div>'
    . '<div class="ba-item-post-intro ba-item"><h1 class="intro-post-title">Metrel A&nbsp;1715 <b>5M</b> &amp; co</h1></div>'
    . '<div class="ba-item-field-slideshow ba-item"><li class="item active"><div class="ba-slideshow-img" style="background-image: url(/images/field.jpg);"></div></li></div>'
    . '</body></html>';
$name = Speedup::productName($pp);
$check('name: Gridbox title, tags out, entities decoded, hard space plain', $name, 'Metrel A 1715 5M & co');
$check('name: og:title when no title of a post', Speedup::productName('<meta property="og:title" content="Kane AQA &amp; co">'), 'Kane AQA & co');
$check('name: <title> last', Speedup::productName('<title> Sonel MPI-530 </title>'), 'Sonel MPI-530');
$check('name: nothing', Speedup::productName('<p>x</p>'), '');
$si = Speedup::slideImages($pp, 'Metrel A 1715', '%1$s – zdjęcie %2$d', ['/m/s-1500.webp' => [768 => '/m/s-768.webp', 480 => '/m/s-480.webp']]);
$check('slides: active one a <picture> with the copies of the background, fetched first', $has($si, '<div class="ba-slideshow-img" data-src="/m/s-1500.webp" style="background-image: url(/m/s-1500.webp);" data-mt-bg="1"><picture><source media="(max-width:480px)" srcset="/m/s-480.webp"><source media="(max-width:768px)" srcset="/m/s-768.webp"><img class="mt-slide-img" src="/m/s-1500.webp" alt="Metrel A 1715" decoding="async" draggable="false" fetchpriority="high"></picture></div>'), true);
$check('slides: second one plain <img>, quoted url, &amp; kept once, lazy, numbered text', $has($si, '<img class="mt-slide-img" src="https://www.merserwis.pl/images/b&amp;w.jpg" alt="Metrel A 1715 – zdjęcie 2" decoding="async" draggable="false" loading="lazy"></div>'), true);
$check('slides: no style = the address of data-src', $has($si, '<div class="ba-slideshow-img" data-src="/images/lazy.jpg"><img class="mt-slide-img" src="/images/lazy.jpg" alt="Metrel A 1715 – zdjęcie 3"'), true);
$check('slides: background and Gridbox markup kept', substr_count($si, 'class="ba-slideshow-img"'), 4);
$check('slides: thumbnails untouched', $has($si, '<div class="ba-slideshow-dots thumbnails-dots" style="--thumbnails-dots-image-0: url(/images/a.jpg);"></div>'), true);
$check('slides: a field slideshow (not a product) untouched', $has($si, '<div class="ba-slideshow-img" style="background-image: url(/images/field.jpg);"></div>'), true);
$check('slides: three pictures', substr_count($si, 'class="mt-slide-img"'), 3);
$check('slides: style in the head, script before </body>', $has($si, '</style></head>') && $has($si, '<style id="mertools-slide-img">') && $has($si, '</script></body>'), true);
$nv = str_replace('<li class="item active">', '<li class="item">', $pp);
$nvo = Speedup::slideImages($nv, 'X', '%1$s – zdjęcie %2$d');
$check('slides: no active slide (a variation chosen) = the first fetched first, the rest lazy', substr_count($nvo, 'fetchpriority="high"') . '/' . substr_count($nvo, 'loading="lazy"'), '1/2');
$paren = '<head></head><div class="ba-item-product-slideshow ba-item"><li class="item active"><div class="ba-slideshow-img" data-src="/images/foto%20(1).jpg" style="background-image: url(/images/foto%20(1).jpg);"></div></li></div>';
$check('slides: ")" in the file name = the whole address (data-src)', $has(Speedup::slideImages($paren, 'X', '%1$s %2$d'), 'src="/images/foto%20(1).jpg"'), true);
$check('slides: a text override with other placeholders = name and number, no error', $has(Speedup::slideImages($pp, 'X', '%1$s %2$d %3$s'), 'alt="X 2"'), true);
$check('slides: the script also fits a slide whose background comes later', $has($si, 'backgroundImage'), false);
$check('slides: the script waits for the load and follows a resize', $has($si, 'addEventListener("load",f)') && $has($si, 'addEventListener("resize"'), true);
$check('slides: done twice = once', Speedup::slideImages($si, 'X', '%1$s %2$d'), $si);
$check('slides: name with quotes escaped', $has(Speedup::slideImages($pp, 'Miernik "Pro" <5>', '%1$s %2$d'), 'alt="Miernik &quot;Pro&quot; &lt;5&gt;"'), true);
$check('slides: no name = empty text on all', substr_count(Speedup::slideImages($pp, '', '%1$s %2$d'), 'alt=""'), 3);
$check('slides: page without a product slideshow unchanged', Speedup::slideImages('<head></head><div class="ba-item-field-slideshow">x</div>', 'X', '%1$s %2$d'), '<head></head><div class="ba-item-field-slideshow">x</div>');
$check('slides: copy equal to the full one = no <picture>', $has(Speedup::slideImages($pp, 'X', '%1$s %2$d', ['/m/s-1500.webp' => [768 => '/m/s-1500.webp', 480 => '/m/s-1500.webp']]), '<picture>'), false);
$two = str_replace('</body>', '<div class="ba-item-product-slideshow ba-item"><li class="item active"><div class="ba-slideshow-img" style="background-image: url(/images/two.jpg);"></div></li></div></body>', $pp);
$check('slides: a second product slideshow too, one style', substr_count(Speedup::slideImages($two, 'X', '%1$s %2$d'), 'class="mt-slide-img"') . '/' . substr_count(Speedup::slideImages($two, 'X', '%1$s %2$d'), 'id="mertools-slide-img"'), '4/1');

$raw = '<html><head><title>T</title></head><body><div class="ba-item-product-slideshow ba-item" id="item-1"><div class="slideshow-wrapper"><ul class="ba-slideshow">'
    . '<div class="slideshow-content ba-field-content lazy-load-image lightbox-enabled"><li class="item active"><div class="ba-slideshow-img" data-src="https://www.merserwis.pl/images/slide.jpg" style="background-image: url(https://www.merserwis.pl/images/slide.jpg);"></div></li></div>'
    . '<div class="ba-slideshow-dots"></div></ul></div></div><h1 class="intro-post-title">Sonel MPI-530</h1></body></html>';
$chainVars = [];
$chain = Speedup::backgrounds($raw, $site, $bgCopies, $chainVars);
[$chain, $chainPhoto] = Speedup::mainPhoto($chain);
$chain = Speedup::slideImages($chain, Speedup::productName($chain), '%1$s – zdjęcie %2$d', $chainVars);
$check('chain as in the plugin: copies, main photo, picture with the same copies', $has($chain, '<li class="item active"><div class="ba-slideshow-img" data-src="/m/s-1500.webp" style="background-image: url(/m/s-1500.webp);" data-mt-bg="1"><picture><source media="(max-width:480px)" srcset="/m/s-480.webp"><source media="(max-width:768px)" srcset="/m/s-768.webp"><img class="mt-slide-img" src="/m/s-1500.webp" alt="Sonel MPI-530" decoding="async" draggable="false" fetchpriority="high"></picture></div></li>'), true);
$check('chain: main photo still found', $chainPhoto, '/m/s-1500.webp');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
