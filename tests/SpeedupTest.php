<?php

/**
 * Standalone test of Speedup and ImageSize::pathOf (no Joomla needed).
 * Run: php tests/SpeedupTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Speedup.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';

use Merserwis\Plugin\System\MerTools\Tool\ImageSize;
use Merserwis\Plugin\System\MerTools\Tool\Speedup;

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

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
