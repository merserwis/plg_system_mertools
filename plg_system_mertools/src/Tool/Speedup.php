<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Faster first view of Gridbox pages, done on the finished HTML (onAfterRender), never in the
 * Gridbox files:
 *
 *  - images: Gridbox's lazy loading gives every image a 100×100 placeholder and loads the real one
 *    by script, also the logo and other images at the top. Here each image of this site gets its
 *    real address at once and its real proportions (aspect-ratio; Gridbox's own width/height stay,
 *    its CSS sizes images by them), with the browser's own lazy loading for the ones further down
 *    and normal loading for those in the header;
 *  - the main product photo: the first product slideshow shows its picture at once (Gridbox keeps
 *    it hidden until its script runs) and the browser fetches it first;
 *  - a YouTube video in a section background starts after the first interaction or a few seconds
 *    after the page has loaded, not while the page is still loading; on phones it can be left out
 *    or replaced by a still picture;
 *  - a YouTube film in a frame loads nothing of YouTube until it is played: its picture and a play button;
 *  - marketing scripts (Tag Manager, Facebook, Clarity…) start at the first interaction.
 *
 * Every function takes the HTML and returns it changed; nothing else is touched.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Speedup
{
    /** the first images of the header load at once (logo, badges); later ones are often hidden menu pictures */
    public const HEADER_EAGER = 2;

    /** what counts as an interaction (the moment a visitor really uses the page) */
    private const EVENTS = '["pointerdown","keydown","scroll","touchstart","mousemove","wheel"]';

    // ---------------------------------------------------------------- images

    /**
     * The images of this site: shown without waiting and without the page jumping.
     *
     *  - Gridbox's script-loaded images (lazy loading on in Gridbox) get their real address at once;
     *  - every image gets its real proportions (aspect-ratio; Gridbox's own width="100" height="100"
     *    stay, its CSS sizes images by them), so nothing below moves when it arrives;
     *  - the header (its first HEADER_EAGER images) and the first section of the page (its first
     *    ZONE_EAGER images) load at once, the largest of them first (fetchpriority); every image
     *    further down waits until the visitor scrolls near it (the browser's own lazy loading);
     *  - with $copies: lighter copies in the size of the screen (srcset), and for a heavy animated
     *    GIF at the top ($gif) its first frame at once, the animation after the page has loaded;
     *    copies in newer formats (JPEG XL, AVIF) in a <picture> around the image (picture()).
     *
     * Tracking pixels, hidden images and images of other sites are left alone.
     *
     * @param string        $html
     * @param string        $siteBase "https://www.merserwis.pl" (scheme and host, no slash)
     * @param callable      $size     fn(string $url): ?array [width, height] of a local image
     * @param callable|null $copies   fn(string $url, bool $newer): ?array ImageOptimizer::lookup() ($newer: it may be in a <picture>)
     * @param bool          $plain    also images Gridbox does not load by script (lazy loading off in Gridbox)
     * @param bool          $gif      a heavy animated GIF at the top: first frame first
     * @param string        $nonce    the page's CSP nonce (nonced())
     */
    public static function images(string $html, string $siteBase, callable $size, ?callable $copies = null, bool $plain = false, bool $gif = false,
        string $nonce = ''): string
    {
        $headerStart = stripos($html, '<header');
        $headerEnd   = stripos($html, '</header>');
        $header      = $headerStart !== false && $headerEnd !== false && $headerEnd > $headerStart;
        $zoneEnd     = self::firstSectionEnd($html, $header ? $headerEnd : 0);
        $eagerLeft   = self::HEADER_EAGER;
        $zoneLeft    = self::ZONE_EAGER;
        $priority    = !preg_match('#\sfetchpriority="high"#i', $html);
        $animated    = false;
        $spans       = null;

        $html = (string) preg_replace_callback('#<img\b[^>]*>#i',
            function (array $m) use ($siteBase, $size, $copies, $plain, $gif, $header, $headerStart, $headerEnd, $zoneEnd, &$eagerLeft, &$zoneLeft, &$priority, &$animated, &$html, &$spans) {
                [$tag, $offset] = $m[0];
                $lazySrc = preg_match('#\sdata-gridbox-lazyload-src="([^"]+)"#i', $tag, $ls) ? $ls[1] : null;
                if ($lazySrc === null && (!$plain || !preg_match('#\ssrc="([^"]+)"#i', $tag, $ps))) {
                    return $tag;
                }
                $raw = $lazySrc ?? $ps[1];
                $src = html_entity_decode($raw, ENT_QUOTES);
                if (str_starts_with($src, 'data:') || preg_match('#display\s*:\s*none|\bwidth="1"|\bheight="1"#i', $tag) || !self::isLocal($src, $siteBase)) {
                    return $tag;
                }
                $inHeader = $header && $offset > $headerStart && $offset < $headerEnd;
                if ($inHeader) {
                    // the header: its first pictures (logo, badges) at once; the rest are mostly menu pictures,
                    // hidden until the menu opens, which Gridbox loads at the right moment: left to Gridbox
                    if ($eagerLeft-- <= 0) {
                        return $tag;
                    }
                    $eager = true;
                } else {
                    $eager = $zoneEnd > 0 && $offset < $zoneEnd && $zoneLeft-- > 0;
                }

                if ($lazySrc !== null) {
                    // the address comes from the page: "$1" or "\\" in it would be read as a back-reference
                    $tag = preg_replace('#\ssrc="[^"]*"#i', ' src="' . strtr($raw, ['\\' => '\\\\', '$' => '\\$']) . '"', $tag, 1);
                    $tag = preg_replace('#\sdata-gridbox-lazyload-src="[^"]*"#i', '', $tag);
                    $tag = preg_replace('#\sdata-gridbox-lazyload-srcset="([^"]*)"#i', ' srcset="$1"', $tag);
                    $tag = self::removeClass($tag, 'lazy-load-image');
                }

                // Gridbox's width="100" height="100" stay: its CSS (img[width="100"][height="100"] {height: auto;
                // width: auto}) sizes the picture by them, other sizes would stretch it. The real proportions
                // go in aspect-ratio, which only counts while one side is automatic: the same look once loaded,
                // the right space reserved before.
                $dims = $size($src);
                if ($dims && $dims[0] > 0 && $dims[1] > 0 && !preg_match('#aspect-ratio#i', $tag)) {
                    $ratio = 'aspect-ratio:' . (int) $dims[0] . '/' . (int) $dims[1];
                    if (preg_match('#\sstyle="([^"]*)"#i', $tag, $st)) {
                        $css = trim($st[1]);
                        $tag = str_replace($st[0], ' style="' . ($css === '' ? '' : rtrim($css, ';') . ';') . $ratio . '"', $tag);
                    } else {
                        $tag = preg_replace('#^<img\b#i', '<img style="' . $ratio . '"', $tag);
                    }
                }
                if ($lazySrc !== null) {
                    if (!preg_match('#\sloading=#i', $tag)) {
                        $tag = preg_replace('#^<img\b#i', '<img loading="' . ($eager ? 'eager' : 'lazy') . '"', $tag);
                    }
                    if (!preg_match('#\sdecoding=#i', $tag)) {
                        $tag = preg_replace('#^<img\b#i', '<img decoding="async"', $tag);
                    }
                } elseif (!$eager && !preg_match('#\sloading=#i', $tag)) {
                    $tag = self::addAttr($tag, 'loading', 'lazy');
                    if (!preg_match('#\sdecoding=#i', $tag)) {
                        $tag = self::addAttr($tag, 'decoding', 'async');
                    }
                }

                // lighter copies; the first frame of a heavy animated GIF at the top
                // only a picture that can be put in a <picture> asks for the newer formats
                $wrap    = $copies && self::wrappable($html, $offset, $tag, $spans ??= self::enclosures($html));
                $info    = $copies ? $copies($src, $wrap) : null;
                $sources = [];
                $sizes = $info ? self::sizes($html, $offset, $dims ? (int) $dims[0] : 0) : null;
                if ($info && $sizes !== null && !preg_match('#\ssrcset=#i', $tag)) {
                    if ($gif && $eager && !$inHeader && isset($info['frame'], $info['anim'])) {
                        $tag      = self::addAttr($tag, 'srcset', $info['frame']);
                        $tag      = self::addAttr($tag, 'sizes', $sizes);
                        $tag      = self::addAttr($tag, 'data-mt-anim', $info['anim']);
                        $animated = true;
                    } elseif (isset($info['srcset'])) {
                        $tag = self::addAttr($tag, 'srcset', $info['srcset']);
                        $tag = self::addAttr($tag, 'sizes', $sizes);
                        // the newer formats (JPEG XL, AVIF): the browser takes the first it can show
                        if (!empty($info['sources']) && $wrap) {
                            $sources = $info['sources'];
                        }
                    }
                }
                // the largest picture at the top is usually what the visitor waits for: fetched first
                if ($eager && !$inHeader && $priority && $dims && $dims[0] * $dims[1] >= 40000 && !preg_match('#\sfetchpriority=#i', $tag)) {
                    $tag      = self::addAttr($tag, 'fetchpriority', 'high');
                    $priority = false;
                }

                // last: every attribute above belongs to the <img>
                return $sources ? self::picture($tag, $sources, (string) $sizes) : $tag;
            }, $html, -1, $count, PREG_OFFSET_CAPTURE);

        if ($animated) {
            $html = self::beforeBodyEnd($html, self::nonced(self::ANIMATION_SCRIPT, $nonce));
        }

        return $html;
    }

    /**
     * May a picture be put in a <picture> with its newer formats? Not one whose parent is a link
     * (Gridbox's lightbox opens only for a picture whose parent is not a link), not one in a <picture>
     * or a <noscript> already, not a picture of Gridbox's before/after slider (its CSS: img + img).
     */
    public static function wrappable(string $html, int $offset, string $tag, ?array $spans = null): bool
    {
        if (preg_match('#\sclass="[^"]*\bba-(?:before|after)-img\b#i', $tag)) {
            return false;
        }
        $before = substr($html, max(0, $offset - 4000), min($offset, 4000));
        if (preg_match('#<a\b[^>]*>\s*$#i', $before)) {
            return false;
        }
        foreach ($spans ?? self::enclosures($html) as [$start, $end]) {
            if ($offset > $start && $offset < $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where the <picture> and <noscript> elements of a page are, [start, end] of each — however long
     * what they hold (a data: address of thousands of characters). One left open runs to the end.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function enclosures(string $html): array
    {
        $spans = [];
        foreach (['picture', 'noscript'] as $name) {
            if (!preg_match_all('#<(/?)' . $name . '\b#i', $html, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = null;
            foreach ($m[0] as $i => [, $at]) {
                if ($m[1][$i][0] === '') {
                    $open ??= $at;
                } elseif ($open !== null) {
                    $spans[] = [$open, $at];
                    $open    = null;
                }
            }
            if ($open !== null) {
                $spans[] = [$open, \strlen($html)];
            }
        }

        return $spans;
    }

    /**
     * The <img> in a <picture> with a <source> for each newer format (type => srcset, the first the
     * browser can show wins; the <img> with its WebP srcset and the original serves the others). The
     * <picture> makes no box of its own (display: contents): the picture is laid out as before.
     */
    public static function picture(string $img, array $sources, string $sizes): string
    {
        $out = '<picture style="display:contents">';
        foreach ($sources as $type => $srcset) {
            $out .= '<source type="' . htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8', false) . '" srcset="'
                . htmlspecialchars((string) $srcset, ENT_QUOTES, 'UTF-8', false) . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8', false) . '">';
        }

        return $out . $img . '</picture>';
    }

    /** the first section of the page: so many of its images load at once */
    public const ZONE_EAGER = 4;

    /** a heavy GIF shows its first frame first; its animation is loaded when the page has loaded and the browser is idle */
    private const ANIMATION_SCRIPT = '<script data-cfasync="false">(function(w,d){function go(){d.querySelectorAll("img[data-mt-anim]").forEach(function(i){'
        . 'var u=i.getAttribute("data-mt-anim"),n=new Image();i.removeAttribute("data-mt-anim");'
        . 'n.onload=function(){i.removeAttribute("srcset");i.removeAttribute("sizes");i.src=u};n.src=u})}'
        . 'function later(){w.requestIdleCallback?w.requestIdleCallback(go,{timeout:2000}):setTimeout(go,200)}'
        . 'd.readyState==="complete"?later():w.addEventListener("load",later)})(window,document);</script>';

    /**
     * Where the first section of the page content ends (the start of the second Gridbox section
     * after $from, the end of the header); 0 when the page has no Gridbox sections there. A page
     * with a single section counts as one long first section (ZONE_EAGER still limits it).
     */
    public static function firstSectionEnd(string $html, int $from): int
    {
        // "ba-section" as a whole class (not ba-section-items, the inner wrapper of every section)
        if (!preg_match_all('#<div\b[^>]*\bclass="(?:[^"]*\s)?ba-section(?=[\s"])#i', $html, $sec, PREG_OFFSET_CAPTURE, $from)) {
            return 0;
        }

        return isset($sec[0][1]) ? (int) $sec[0][1][1] : \strlen($html);
    }

    /**
     * The sizes attribute of a picture of $natural px. Gridbox sizes most pictures by their own width
     * (width/height "auto"), and with a srcset that width comes from sizes — so sizes says exactly that
     * width: the picture's own, or the whole screen when the screen is narrower. Never "auto": for a
     * picture without a CSS width the browser takes 300 px and the picture shrank (1.0.1). A picture
     * of a blog or store list (a grid cell, sized by its CSS) asks for at most 400 px. Unknown width:
     * null, the picture keeps its single address.
     */
    public static function sizes(string $html, int $offset, int $natural): ?string
    {
        if ($natural <= 0) {
            return null;
        }
        $before = substr($html, max(0, $offset - 8000), min($offset, 8000));
        if (preg_match('#class="ba-blog-post-image\b[^"]*"[^>]*>(?:(?!</div>).){0,400}$#s', $before)) {
            $natural = min($natural, 400);
        }

        return '(max-width: ' . $natural . 'px) 100vw, ' . $natural . 'px';
    }

    /**
     * Background pictures given in the page (style="background-image: url(…)", e.g. the slides of a
     * Gridbox slideshow): a background has no srcset, so the style gets the lighter WebP copy at the
     * picture's own width, and phones (screens up to 768 / 480 px) get a smaller copy through a
     * media rule (data-mt-bg="n"). The same address in data-src (Gridbox loads slides from it) is
     * replaced too. Pictures without copies (yet) and of other sites stay as they are.
     *
     * @param callable $copies   fn(string $url, bool $newer): ?array ImageOptimizer::lookup() (called with $newer false)
     * @param array    $variants filled: full-width copy => [768 => copy, 480 => copy] (for a preload of the same picture)
     * @param string   $nonce    the page's CSP nonce (nonced())
     */
    public static function backgrounds(string $html, string $siteBase, callable $copies, array &$variants = [], string $nonce = ''): string
    {
        $rules = [];
        $n     = 0;
        $html  = (string) preg_replace_callback('#<[a-z][a-z0-9-]*\b[^>]*\sstyle="[^"]*background-image:\s*url\(([^)]+)\)[^"]*"[^>]*>#i',
            function (array $m) use ($siteBase, $copies, &$rules, &$n, &$variants) {
                $tag = $m[0];
                if (str_contains($tag, 'data-mt-bg=')) {
                    return $tag;
                }
                $raw = trim($m[1], " \t'\"");
                $raw = (string) preg_replace('#^(&quot;|&\#0?39;)|(&quot;|&\#0?39;)$#', '', $raw);
                $src = html_entity_decode($raw, ENT_QUOTES);
                if (!self::isLocal($src, $siteBase) || !preg_match('#\.(jpe?g|png)(?:[?\#]|$)#i', $src)) {
                    return $tag;
                }
                // a background has no <picture>: its WebP copies only, no newer formats asked for
                $info = $copies($src, false);
                if (!$info || empty($info['srcset']) || !preg_match_all('#(\S+) (\d+)w#', $info['srcset'], $c, PREG_SET_ORDER)) {
                    return $tag;
                }
                $byWidth = [];
                foreach ($c as $x) {
                    $byWidth[(int) $x[2]] = $x[1];
                }
                ksort($byWidth);
                $pick = function (int $min) use ($byWidth): string {
                    foreach ($byWidth as $w => $u) {
                        if ($w >= $min) {
                            return $u;
                        }
                    }

                    return end($byWidth);
                };
                $full = end($byWidth);
                $id   = (string) ++$n;
                $tag  = str_replace('url(' . $m[1] . ')', 'url(' . str_replace($raw, $full, $m[1]) . ')', $tag);
                $tag  = preg_replace('#\sdata-src="' . preg_quote($raw, '#') . '"#', ' data-src="' . $full . '"', $tag);
                // at the end of the tag: Gridbox's own markup stays as other tools expect it
                $tag = self::addAttr($tag, 'data-mt-bg', $id);
                foreach ([768 => 768, 480 => 480] as $screen => $min) {
                    $u                       = $pick($min);
                    $variants[$full][$screen] = $u;
                    if ($u !== $full) {
                        $rules[$screen][] = '[data-mt-bg="' . $id . '"]{background-image:url("' . str_replace('"', '%22', $u) . '")!important}';
                    }
                }

                return $tag;
            }, $html);
        if (!$rules) {
            return $html;
        }
        $css = '';
        foreach ([768, 480] as $screen) {
            if (!empty($rules[$screen])) {
                $css .= '@media (max-width:' . $screen . 'px){' . implode('', $rules[$screen]) . '}';
            }
        }
        $pos = stripos($html, '</head>');

        return $pos === false ? $html : substr_replace($html, self::nonced('<style id="mertools-bg">' . $css . '</style>', $nonce), $pos, 0);
    }

    /**
     * Style sheets of icon fonts (Font Awesome, Material Icons, Gridbox's icon libraries…) do not
     * hold back the first paint: they load alongside and apply when they arrive (the icons appear a
     * moment later); a <noscript> copy keeps them for browsers without JavaScript. Not under a
     * Content Security Policy with nonces ($nonce): it stops the onload that switches them on.
     */
    public static function iconStyles(string $html, string $nonce = ''): string
    {
        $head = stripos($html, '</head>');
        if ($head === false || $nonce !== '') {
            return $html;
        }
        $head = substr($html, 0, $head);
        $part = (string) preg_replace_callback('#<link\b[^>]*>#i', function (array $m) use ($head) {
            [$tag, $at] = $m[0];
            // the <noscript> copy of an earlier pass stays as it is
            if (str_ends_with(substr($head, max(0, $at - 10), min($at, 10)), '<noscript>')) {
                return $tag;
            }
            if (!preg_match('#\srel="stylesheet"#i', $tag) || preg_match('#\smedia=|\sonload=#i', $tag)
                || !preg_match('#\shref="([^"]*(?:/icons?/|font-?awesome|material-?(?:icons|design-?icons)|icomoon|line-?awesome|bootstrap-icons)[^"]*)"#i', $tag)) {
                return $tag;
            }
            $async = preg_replace('#\s*/?>$#', '', $tag) . ' media="print" onload="this.media=\'all\'">';

            return $async . '<noscript>' . $tag . '</noscript>';
        }, $head, -1, $count, PREG_OFFSET_CAPTURE);

        return $part . substr($html, \strlen($head));
    }

    /**
     * Backgrounds in the header shown at once (Gridbox hides them until its script runs). Only
     * sections, rows and columns: pictures (<img>) keep their class, Gridbox still loads those
     * that images() leaves to it (menu pictures).
     */
    public static function headerBackgrounds(string $html): string
    {
        $start = stripos($html, '<header');
        $end   = stripos($html, '</header>');
        if ($start === false || $end === false || $end < $start) {
            return $html;
        }
        $header = (string) preg_replace_callback('#<(?!img\b)[a-z][a-z0-9-]*\b[^>]*\sclass="[^"]*\blazy-load-image\b[^"]*"[^>]*>#i',
            fn (array $m) => self::removeClass($m[0], 'lazy-load-image'), substr($html, $start, $end - $start));

        return substr($html, 0, $start) . $header . substr($html, $end);
    }

    /**
     * The first product slideshow: its first picture shown at once and fetched first.
     *
     * @return array{0: string, 1: ?string} the HTML and the address of that picture (to preload)
     */
    public static function mainPhoto(string $html): array
    {
        if (!preg_match('#<div class="(slideshow-content[^"]*\blazy-load-image\b[^"]*)"[^>]*>\s*<li class="item active"><div class="ba-slideshow-img" data-src="([^"]+)"#i',
            $html, $m, PREG_OFFSET_CAPTURE)) {
            return [$html, null];
        }
        $whole = $m[0][0];
        $fixed = str_replace('class="' . $m[1][0] . '"', 'class="' . trim(preg_replace('#\s*\blazy-load-image\b#', '', $m[1][0])) . '"', $whole);

        return [substr_replace($html, $fixed, $m[0][1], \strlen($whole)), html_entity_decode($m[2][0], ENT_QUOTES)];
    }

    /** the picture put into a product slide: on the background, looking the same, never in the way of a click or a swipe */
    private const SLIDE_CSS = '<style id="mertools-slide-img">.ba-slideshow-img>picture>.mt-slide-img,.ba-slideshow-img>.mt-slide-img{position:absolute;top:0;left:0;'
        . 'width:100%;height:100%;max-width:none;object-fit:cover;object-position:50% 50%;pointer-events:none;user-select:none;-webkit-user-drag:none}</style>';

    /**
     * a slideshow set to show its pictures whole or at their size: the pictures follow. Read once the page
     * has loaded (Gridbox's deferred loading switches its style sheets on at DOMContentLoaded, they may not
     * apply yet then; till the load the style above, cover, the usual setting) and again on a resize (a
     * setting per screen size). The size is the slide's own style, so also a slide whose background comes
     * later (loaded when shown) gets it at once.
     */
    private const SLIDE_JS = '<script>(function(w){function f(){document.querySelectorAll(".mt-slide-img").forEach(function(i){'
        . 'var d=i.closest(".ba-slideshow-img");if(!d)return;var s=getComputedStyle(d),z=s.backgroundSize;'
        . 'i.style.objectFit=z==="contain"?"contain":/^auto( auto)?$/.test(z)?"none":"cover";i.style.objectPosition=s.backgroundPosition})}'
        . 'document.readyState==="complete"?f():w.addEventListener("load",f);var t;w.addEventListener("resize",function(){clearTimeout(t);t=setTimeout(f,200)})})(window);</script>';

    /**
     * The name of the product (or post) of a page, for the text of its pictures: Gridbox's title of the
     * post, else og:title, else <title>. Plain text, entities decoded.
     */
    public static function productName(string $html): string
    {
        foreach (['#<h1\b[^>]*\bclass="[^"]*\bintro-post-title\b[^"]*"[^>]*>(.*?)</h1>#is', '#<meta\s+property="og:title"\s+content="([^"]*)"#i', '#<title>(.*?)</title>#is'] as $re) {
            if (preg_match($re, $html, $m)) {
                $name = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * Product photos for search engines: Gridbox shows the pictures of a product slideshow as CSS
     * backgrounds (no <img>, no alt, so not in image search, and found late by the browser). Into every
     * slide goes a real picture of the same address, with the product's name as its text, laid exactly
     * on the background: same look (cover, centred, as the background; a slideshow set otherwise is
     * followed by a line of script), clicks and swipes still go to Gridbox's slide (pointer-events none).
     * The background stays: Gridbox's lightbox reads it, and a slideshow Gridbox builds anew (another
     * variation of the product) goes back to backgrounds only. With the phone copies of backgrounds()
     * ($variants) the picture is a <picture> with the same media rules, so each screen fetches one
     * address, the one of the background. The active slide's picture is fetched first, the others lazily.
     *
     * @param array  $variants full-width copy => [768 => copy, 480 => copy] (backgrounds())
     * @param string $more     the text of the second and further pictures (sprintf: name, number)
     * @param string $nonce    the page's CSP nonce (nonced())
     */
    public static function slideImages(string $html, string $name, string $more, array $variants = [], string $nonce = ''): string
    {
        if (stripos($html, 'ba-item-product-slideshow') === false || str_contains($html, 'id="mertools-slide-img"')) {
            return $html;
        }
        $out  = '';
        $done = 0;
        $at   = 0;
        while (preg_match('#<div class="ba-item-product-slideshow\b[^"]*"#i', $html, $m, PREG_OFFSET_CAPTURE, $at)) {
            $start = $m[0][1];
            // the slides: up to the dots (thumbnails) of this slideshow, or up to the next item
            $next  = preg_match('#<div class="ba-item-[a-z-]+\b[^"]*"#i', $html, $n, PREG_OFFSET_CAPTURE, $start + 1) ? $n[0][1] : \strlen($html);
            $dots  = stripos($html, 'ba-slideshow-dots', $start);
            $end   = $dots !== false && $dots < $next ? $dots : $next;
            $part  = substr($html, $start, $end - $start);
            $count = 0;
            // a product with a variation chosen beforehand: Gridbox marks no slide active (its script makes the first one so)
            $first = !preg_match('#<li class="item\b[^"]*\bactive\b#i', $part);
            $part  = (string) preg_replace_callback('#<li class="(item\b[^"]*)"><div class="ba-slideshow-img"[^>]*>(?!\s*<(?:picture|img)\b)#i',
                function (array $s) use ($name, $more, $variants, &$count, $first): string {
                    $tag = $s[0];
                    // data-src first: the same address as the background (backgrounds() changes both), and whole even
                    // with a ")" in the file name, which ends an unquoted url( ) of the style
                    $url = preg_match('#\sdata-src="([^"]+)"#i', $tag, $d) ? $d[1]
                        : (preg_match('#background-image:\s*url\(\s*(?:&quot;|&\#0?39;|["\'])?(.*?)(?:&quot;|&\#0?39;|["\'])?\s*\)#i', $tag, $u) ? $u[1] : '');
                    if ($url === '' || str_starts_with($url, 'data:')) {
                        return $tag;
                    }
                    $number = ++$count;
                    $alt    = $name;
                    if ($number > 1 && $name !== '') {
                        try {
                            $alt = sprintf($more, $name, $number);
                        } catch (\ValueError|\ArgumentCountError $e) {
                            // a text override with other placeholders
                            $alt = $name . ' ' . $number;
                        }
                    }
                    $active = (bool) preg_match('#\bactive\b#', $s[1]) || ($first && $number === 1);
                    $esc    = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8', false);
                    $img    = '<img class="mt-slide-img" src="' . $esc($url) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" decoding="async" draggable="false"'
                        . ($active ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
                    $plain  = html_entity_decode($url, ENT_QUOTES);
                    $source = '';
                    // the narrower screen first: the first source that fits is taken, as the later media rule wins for the background
                    foreach ([480, 768] as $screen) {
                        $copy = $variants[$plain][$screen] ?? null;
                        if ($copy !== null && $copy !== $plain) {
                            $source .= '<source media="(max-width:' . $screen . 'px)" srcset="' . $esc($copy) . '">';
                        }
                    }

                    return $tag . ($source === '' ? $img : '<picture>' . $source . $img . '</picture>');
                }, $part);
            $done += $count;
            $out  .= substr($html, $at, $start - $at) . $part;
            $at    = $end;
        }
        if ($done === 0) {
            return $html;
        }
        $html = $out . substr($html, $at);
        $head = stripos($html, '</head>');
        $html = $head === false ? $html : substr_replace($html, self::nonced(self::SLIDE_CSS, $nonce), $head, 0);

        return self::beforeBodyEnd($html, self::nonced(self::SLIDE_JS, $nonce));
    }

    /**
     * A preload of the main picture, right after <title> (or at the end of the head). With $phone
     * ([768 => copy, 480 => copy], see backgrounds()) each screen preloads the copy it will show.
     */
    public static function preloadImage(string $html, string $url, array $phone = []): string
    {
        $esc  = fn (string $u) => htmlspecialchars($u, ENT_QUOTES);
        if (isset($phone[768], $phone[480])) {
            $link = '<link rel="preload" as="image" href="' . $esc($url) . '" media="(min-width:769px)" fetchpriority="high">'
                . '<link rel="preload" as="image" href="' . $esc($phone[768]) . '" media="(min-width:481px) and (max-width:768px)" fetchpriority="high">'
                . '<link rel="preload" as="image" href="' . $esc($phone[480]) . '" media="(max-width:480px)" fetchpriority="high">';
        } else {
            $link = '<link rel="preload" as="image" href="' . $esc($url) . '" fetchpriority="high">';
        }
        $pos  = stripos($html, '</title>');
        if ($pos !== false) {
            return substr_replace($html, $link, $pos + 8, 0);
        }
        $pos = stripos($html, '</head>');

        return $pos === false ? $html : substr_replace($html, $link, $pos, 0);
    }

    // ---------------------------------------------------------------- page shown at once

    /** what Gridbox's deferred loading puts on <body>: the page stays invisible until its scripts have run */
    private const HIDDEN_BODY = '#opacity:\s*0;\s*overflow:\s*hidden;\s*margin:\s*0;?#i';

    /**
     * Gridbox's deferred loading moves the style sheets to the end of the page as preloads and hides the
     * whole page (body opacity 0) until DOMContentLoaded, that is until every script at the end has been
     * fetched and run, Gridbox's getItems request included: nothing is painted before that, the main
     * product photo neither. Here the page is shown at once: the style sheets go back into the head as
     * normal style sheets (same order, after the inline styles, so the cascade is the one Gridbox's script
     * made at DOMContentLoaded) and the hiding style is taken off <body>. The scripts stay at the end.
     * Pages without that hiding style (deferred loading off) are left alone.
     */
    public static function showAtOnce(string $html): string
    {
        if (!preg_match('#<body\b[^>]*>#i', $html, $b, PREG_OFFSET_CAPTURE)
            || !preg_match('#\sstyle="([^"]*)"#i', $b[0][0], $st) || !preg_match(self::HIDDEN_BODY, $st[1])) {
            return $html;
        }
        [$bodyTag, $bodyPos] = $b[0];
        $headEnd = strripos(substr($html, 0, $bodyPos), '</head>');
        if ($headEnd === false) {
            return $html;
        }

        $links = [];
        $body  = (string) preg_replace_callback('#<link\b[^>]*>#i', function (array $m) use (&$links) {
            $tag = $m[0];
            if (!preg_match('#\srel="preload"#i', $tag) || !preg_match('#\sas="style"#i', $tag)) {
                return $tag;
            }
            $links[] = preg_replace(['#\srel="preload"#i', '#\sas="style"#i'], [' rel="stylesheet"', ''], $tag, 1);

            return '';
        }, substr($html, $bodyPos + \strlen($bodyTag)));

        $css     = trim((string) preg_replace(self::HIDDEN_BODY, '', $st[1]));
        $bodyTag = str_replace($st[0], $css === '' ? '' : ' style="' . $css . '"', $bodyTag);

        return substr($html, 0, $headEnd) . implode('', $links) . substr($html, $headEnd, $bodyPos - $headEnd) . $bodyTag . $body;
    }

    // ---------------------------------------------------------------- YouTube background

    /**
     * Video backgrounds start after the first interaction, or $seconds after the page has loaded
     * (Gridbox starts them while the page is still loading). Wraps app.checkVideoBackground right
     * after Gridbox's own script; the Gridbox file is not changed.
     *
     * On phones (screens up to $phoneWidth px, or with the browser's data saver on) $phone decides:
     * 'video' the same as elsewhere, 'none' no video, 'image' a still picture instead ($phoneImage,
     * else the video's own poster or YouTube thumbnail). Without the video the section gets $phoneColor
     * under its overlay (the light colour behind a video would leave white text unreadable), set when
     * Gridbox shows the page (DOMContentLoaded, its items are known by then), so nothing flashes.
     */
    public static function delayVideoBackground(string $html, int $seconds, string $phone = 'video', int $phoneWidth = 768,
        string $phoneImage = '', string $phoneColor = '#1a1a1a', string $nonce = ''): string
    {
        if (!preg_match('#<script\b[^>]*\bsrc="[^"]*templates/gridbox/js/gridbox\.js[^"]*"[^>]*>\s*</script>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $ms    = max(0, min(60, $seconds)) * 1000;
        $phone = \in_array($phone, ['none', 'image'], true) ? $phone : 'video';
        $json  = fn (string $v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $js    = '<script>(function(w,d){var a=w.app;if(!a||!a.checkVideoBackground||a.mtVideoDelay)return;a.mtVideoDelay=1;'
            . 'var o=a.checkVideoBackground,go=0,args=[];'
            . 'function run(){if(go)return;go=1;o.apply(a,args)}';
        if ($phone !== 'video') {
            $width = max(320, min(2000, $phoneWidth));
            $color = preg_match('/^#[0-9a-f]{3,8}$|^rgba?\([0-9.,\s%]+\)$/i', trim($phoneColor)) ? trim($phoneColor) : '';
            $js .= 'var c=navigator.connection,ph=(w.matchMedia&&w.matchMedia("(max-width:' . $width . 'px)").matches)||!!(c&&c.saveData);'
                . 'var col=' . $json($color) . ',img=' . $json($phone === 'image' ? $phoneImage : '') . ',pic=' . ($phone === 'image' ? '1' : '0') . ';'
                // every section, row or column with a video background (Gridbox's items, or the page data before Gridbox has read it)
                . 'function vids(){var g=w.gridboxItems||{},seen={},out=[];[a.items,g.page,g.header,g.footer].forEach(function(s){if(!s)return;'
                . 'Object.keys(s).forEach(function(id){var dk=s[id]&&s[id].desktop,e;if(seen[id]||!dk||!dk.background||dk.background.type!=="video")return;'
                . 'seen[id]=1;e=d.getElementById(id);if(e)out.push([e,dk])})});return out}'
                . 'function paint(){vids().forEach(function(p){var e=p[0],v=p[1].video||p[1].background.video||{},u;if(col)e.style.backgroundColor=col;if(!pic)return;'
                . 'u=img||v.image||(v.type==="youtube"&&/^[\w-]{6,20}$/.test(v.id||"")?"https://i.ytimg.com/vi/"+v.id+"/hqdefault.jpg":"");'
                . 'if(u){e.style.backgroundImage="url(\""+u.replace(/["\\\\]/g,"")+"\")";e.style.backgroundSize="cover";e.style.backgroundPosition="center"}})}'
                . 'if(ph){d.readyState==="loading"?d.addEventListener("DOMContentLoaded",paint):paint()}'
                . 'a.checkVideoBackground=function(){args=arguments;if(go)return o.apply(a,arguments);if(ph){paint();return}';
        } else {
            $js .= 'a.checkVideoBackground=function(){args=arguments;if(go)return o.apply(a,arguments);';
        }
        $js .= self::EVENTS . '.forEach(function(e){w.addEventListener(e,run,{once:true,passive:true})});'
            . 'var t=function(){setTimeout(run,' . $ms . ')};d.readyState==="complete"?t():w.addEventListener("load",t)}})(window,document);</script>';

        $end = $m[0][1] + \strlen($m[0][0]);

        return substr_replace($html, self::nonced($js, $nonce), $end, 0);
    }

    /**
     * A background video fills its section like a picture with "cover": Gridbox sizes the frame 100vw × 56.25vw
     * (16:9 of the window's width), so a section taller than that — a narrower screen, a tall section — shows
     * bands above and below. The frame now takes the larger of the section's width and 16/9 of its height,
     * centred and cut at the edges; a video file gets object-fit:cover. Container units (cqw/cqh); a browser
     * without them keeps Gridbox's size. Only on a Gridbox page: its gridbox.js, or with Gridbox's compress_js the
     * one file of templates/gridbox/js/min/ that replaces it.
     */
    public static function videoCover(string $html, string $nonce = ''): string
    {
        $pos = stripos($html, '</head>');
        if ($pos === false || stripos($html, 'templates/gridbox/js/') === false || str_contains($html, 'id="mertools-vcover"')) {
            return $html;
        }

        return substr_replace($html, self::nonced('<style id="mertools-vcover">' . self::VIDEO_COVER_CSS . '</style>', $nonce), $pos, 0);
    }

    private const VIDEO_COVER_CSS = '@supports (width:1cqw){div.ba-video-background{container-type:size}'
        . 'div.ba-video-background iframe{width:max(100cqw,177.78cqh);height:max(56.25cqw,100cqh);left:50%;top:50%;transform:translate(-50%,-50%)}'
        . 'div.ba-video-background video{width:100%;height:100%;left:0;top:0;object-fit:cover}}';

    // ---------------------------------------------------------------- YouTube films

    /** the look of a film before it is played: its picture and a play button, in the frame's own document */
    private const YT_CSS = '*{margin:0;padding:0}html,body{height:100%;overflow:hidden;background:#000}'
        . 'a{position:absolute;inset:0;display:block;cursor:pointer}img{width:100%;height:100%;object-fit:cover}'
        . 'b{position:absolute;left:50%;top:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border-radius:14px;'
        . 'background:rgba(23,23,23,.8);transition:background .2s}'
        . 'b::after{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 17px;'
        . 'border-color:transparent transparent transparent #fff}'
        . 'a:hover b,a:focus-visible b{background:#f00}a:focus-visible{outline:3px solid #fff;outline-offset:-3px}';

    /**
     * A YouTube film in a frame (one pasted in a text or a product's description, Gridbox's Video element)
     * loads nothing of YouTube until it is played: the frame shows the film's picture and a play button
     * (srcdoc: a document of its own, so the frame keeps its place, size and the site's CSS for frames);
     * a click opens the player in that same frame, playing at once. An embedded film otherwise costs about
     * 1 MB and 10 requests of YouTube's scripts each, also when nobody plays it.
     *
     * A playlist given with its first film (/embed/ID?list=…) the same: that film's picture, the player with the list
     * on a click. Left as they are: frames a script drives (enablejsapi=1: Gridbox's video backgrounds), films that
     * play by themselves (autoplay=1), a playlist without a film (/embed/videoseries?list=…: no picture to show), a live
     * stream of a channel (/embed/live_stream), sandboxed frames and frames that already have a srcdoc.
     * The player keeps the host of the frame's address (youtube-nocookie.com when Consent changed it). The frame's
     * document has the page's Content Security Policy: its style carries the nonce (the kept page's is put back for
     * each visitor, see PageCache). A frame written in a script, a style or a textarea is their text: not touched.
     */
    public static function youtubeFacade(string $html, string $label = 'Play', string $nonce = ''): string
    {
        if (stripos($html, '/embed/') === false || stripos($html, '<iframe') === false) {
            return $html;
        }
        $parts = preg_split('#(<(script|style|textarea)\b[^>]*>.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!\is_array($parts)) {
            return $html;
        }
        $out = '';
        // [text, a whole script/style/textarea, its tag name, text, …]
        for ($i = 0, $n = \count($parts); $i < $n; $i++) {
            if ($i % 3 === 0) {
                $out .= self::facadeFrames($parts[$i], $label, $nonce);
            } elseif ($i % 3 === 1) {
                $out .= $parts[$i];
            }
        }

        return $out;
    }

    private static function facadeFrames(string $html, string $label, string $nonce): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }

        // the tag to its real end: a ">" inside a quoted value (title="A > B") is not it
        return (string) preg_replace_callback('#<iframe\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i', function (array $m) use ($label, $nonce): string {
            $tag   = $m[0];
            $attrs = self::attributes($tag);
            if (isset($attrs['srcdoc']) || isset($attrs['sandbox']) || !isset($attrs['src'])) {
                return $tag;
            }
            $src = html_entity_decode($attrs['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('#^\s*(?:https?:)?//(?:www\.)?(youtube(?:-nocookie)?\.com)/embed/([A-Za-z0-9_-]{11})(?:\?([^\#]*))?(?:\#.*)?\s*$#i', $src, $u)) {
                return $tag;
            }
            // these two are not a film's id, though of its length
            if (\in_array(strtolower($u[2]), ['videoseries', 'live_stream'], true)) {
                return $tag;
            }
            parse_str($u[3] ?? '', $query);
            if (!empty($query['enablejsapi']) || !empty($query['autoplay'])) {
                return $tag;
            }
            unset($query['autoplay']);
            $query['autoplay'] = 1;
            $play  = 'https://www.' . strtolower($u[1]) . '/embed/' . $u[2] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $thumb = 'https://i.ytimg.com/vi/' . $u[2] . '/hqdefault.jpg';
            $doc   = '<!DOCTYPE html><meta charset="utf-8">' . self::nonced('<style>' . self::YT_CSS . '</style>', $nonce)
                . '<a href="' . self::h($play) . '" aria-label="' . self::h($label) . '"><img src="' . self::h($thumb) . '" alt="" decoding="async"><b></b></a>';

            $add = ' srcdoc="' . self::h($doc) . '" data-mt-yt="' . $u[2] . '"';
            if (!isset($attrs['loading'])) {
                $add .= ' loading="lazy"';
            }
            if (!isset($attrs['title']) || trim($attrs['title']) === '') {
                $add .= ' title="' . self::h($label) . '"';
            }
            // the player starts on the click in the frame: the frame may play it
            if (!isset($attrs['allow'])) {
                $add .= ' allow="autoplay; encrypted-media; picture-in-picture; fullscreen"';
            } elseif (!preg_match('#(^|;)\s*autoplay\b#i', html_entity_decode($attrs['allow'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                $tag = (string) preg_replace_callback('#(\sallow\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i',
                    fn (array $a) => $a[1] . '"' . self::h(rtrim(html_entity_decode($a[2] . ($a[3] ?? '') . ($a[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '; ') . '; autoplay') . '"', $tag, 1);
            }

            return (string) preg_replace('#\s*/?>$#', $add . '>', $tag, 1);
        }, $html);
    }

    /**
     * Gridbox's Video element with lazy loading shows its own picture of the film (.video-lazy-load-thumbnail) and
     * writes the frame of YouTube on a click (initvideo.js, jQuery's replaceWith) — with autoplay=0 but in an overlay
     * section or a lightbox, so on a page the click only loads the player and the film needs a second one. The frame
     * written for that picture gets autoplay=1: the click that asked for the film plays it. The wrap goes on as soon
     * as jQuery is there, at the latest when the first click reaches the document (before Gridbox's handler of the
     * picture); the flag on $.fn keeps it one, also when Consent's YouTube without cookies wraps replaceWith too.
     * Only on a page with such a picture.
     */
    public static function youtubeLazyPlay(string $html, string $nonce = ''): string
    {
        if (!preg_match('#\sclass="[^"]*\bvideo-lazy-load-thumbnail\b#i', $html) || str_contains($html, 'mtLazyPlay')) {
            return $html;
        }

        return self::beforeBodyEnd($html, self::nonced('<script>' . self::LAZY_PLAY_JS . '</script>', $nonce));
    }

    private const LAZY_PLAY_JS = '(function(w,d){function hook(){var $=w.$g||w.jQuery;if(!$||!$.fn||!$.fn.replaceWith||$.fn.mtLazyPlay)return;'
        . '$.fn.mtLazyPlay=1;var o=$.fn.replaceWith;$.fn.replaceWith=function(v){'
        . 'if(typeof v==="string"&&/\/embed\//.test(v)&&this.is&&this.is(".video-lazy-load-thumbnail")){'
        . 'arguments[0]=v.replace(/(<iframe\b[^>]*?\ssrc=")([^"]*)"/i,function(a,b,u){var r=/([?&])autoplay=[^&#]*/;'
        . 'return b+(r.test(u)?u.replace(r,"$1autoplay=1"):u+(u.indexOf("?")<0?"?":"&")+"autoplay=1")+\'"\'})}'
        . 'return o.apply(this,arguments)}}'
        . 'hook();d.addEventListener("DOMContentLoaded",hook);w.addEventListener("load",hook);d.addEventListener("click",hook,true)})(window,document);';

    /** the attributes of a tag: lower-case name => value as written (entities not decoded); a bare one => '' */
    private static function attributes(string $tag): array
    {
        preg_match_all('#\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#', preg_replace('#^<\w+|/?>$#', '', $tag), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $a) {
            $name = strtolower($a[1]);
            if (!isset($out[$name])) {
                $out[$name] = ($a[2] ?? '') . ($a[3] ?? '') . ($a[4] ?? '');
            }
        }

        return $out;
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---------------------------------------------------------------- marketing scripts

    /**
     * Gridbox popups (lightboxes) opened by a timer ("time delay") open at their time, but not before
     * the visitor has first used the page (scroll, touch, mouse, key): a big popup opened while the
     * page is still being looked at becomes the largest element of the page and Google measures the
     * load time (LCP) by it, and a popup before any interaction is an intrusive interstitial on
     * phones. Popups opened on scrolling or on leaving the page, and Gridbox's cookie notice, stay as
     * they are. Wraps Gridbox's global initLightbox right after its script; the file is not changed.
     */
    public static function delayPopups(string $html, string $nonce = ''): string
    {
        if (stripos($html, 'ba-lightbox-backdrop') === false
            || !preg_match('#<script\b[^>]*\bsrc="[^"]*templates/gridbox/js/gridbox\.js[^"]*"[^>]*>\s*</script>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        if (str_contains($html, 'mtPopup')) {
            return $html;
        }
        $js = '<script>(function(w){var o=w.initLightbox;if(typeof o!=="function"||o.mtPopup)return;var go=0,q=[];'
            . 'function run(){if(go)return;go=1;q.splice(0).forEach(function(f){f()})}'
            . self::EVENTS . '.forEach(function(e){w.addEventListener(e,run,{once:true,passive:true})});'
            . 'var f=function(el){var a=w.app,it=a&&a.items&&el&&el.dataset?a.items[el.dataset.id]:null;'
            . 'if(it&&it.type!=="cookies"&&it.trigger&&it.trigger.type==="time-delay"&&typeof w.showLightbox==="function"){'
            . 'setTimeout(function(){go?w.showLightbox(el):q.push(function(){w.showLightbox(el)})},+it.trigger.time||0);return}'
            . 'return o.apply(this,arguments)};f.mtPopup=1;w.initLightbox=f})(window);</script>';
        $end = $m[0][1] + \strlen($m[0][0]);

        return substr_replace($html, self::nonced($js, $nonce), $end, 0);
    }

    /**
     * Scripts whose address or code contains one of $patterns run at the first interaction
     * (and, if $seconds > 0, at the latest that long after loading). Their order is kept. Scripts
     * containing one of $keep (e.g. the cookie consent) are never delayed.
     *
     * @param string[] $patterns
     * @param string[] $keep
     */
    public static function delayScripts(string $html, array $patterns, array $keep, int $seconds, string $nonce = ''): string
    {
        $patterns = array_values(array_filter(array_map('trim', $patterns)));
        if (!$patterns) {
            return $html;
        }
        $count = 0;
        $html  = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function (array $m) use ($patterns, $keep, &$count) {
            [$tag, $attrs, $body] = $m;
            // only classic scripts: JSON and other data blocks, modules and scripts already delayed stay as they are
            // (the type written in any way HTML allows: "…", '…' or bare)
            if (preg_match(self::TYPE_ATTR, $attrs, $t) && !\in_array(strtolower(trim(implode('', \array_slice($t, 1)))), ['text/javascript', 'application/javascript', ''], true)) {
                return $tag;
            }
            $hay = $attrs . ' ' . $body;
            foreach ($keep as $k) {
                if ($k !== '' && stripos($hay, $k) !== false) {
                    return $tag;
                }
            }
            foreach ($patterns as $p) {
                if (stripos($hay, $p) !== false) {
                    $count++;
                    $attrs = preg_replace(self::TYPE_ATTR, '', $attrs);

                    return '<script type="text/mertools-delay"' . $attrs . '>' . $body . '</script>';
                }
            }

            return $tag;
        }, $html);
        if (!$count) {
            return $html;
        }

        // the nonce of a script is not readable as an attribute once the page is shown: it is passed on as the property
        $ms     = max(0, min(120, $seconds)) * 1000;
        $loader = self::nonced('<script>(function(w,d){var go=0;function run(){if(go)return;go=1;'
            . 'var list=d.querySelectorAll(\'script[type="text/mertools-delay"]\'),i=0;'
            . 'function next(){if(i>=list.length)return;var o=list[i++],s=d.createElement("script");'
            . '[].forEach.call(o.attributes,function(a){if(a.name!=="type"&&a.name!=="nonce")s.setAttribute(a.name,a.value)});if(o.nonce)s.nonce=o.nonce;'
            . 'if(o.src){s.async=false;s.onload=s.onerror=next;s.src=o.src;o.replaceWith(s)}else{s.text=o.text;o.replaceWith(s);next()}}next()}'
            . self::EVENTS . '.forEach(function(e){w.addEventListener(e,run,{once:true,passive:true})});'
            . ($ms ? 'var t=function(){setTimeout(run,' . $ms . ')};d.readyState==="complete"?t():w.addEventListener("load",t);' : '')
            . '})(window,document);</script>', $nonce);
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $loader : substr_replace($html, $loader, $pos, 0);
    }

    /** a type attribute of a tag: "…", '…' or bare */
    private const TYPE_ATTR = '#\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';

    /**
     * The nonce of the page's Content Security Policy (Joomla's csp_nonce) on a <script> or <style>
     * MerTools adds: under a policy with nonces nothing without one runs.
     */
    public static function nonced(string $code, string $nonce): string
    {
        if ($nonce === '' || !preg_match('#^<(script|style)\b#i', $code, $m)) {
            return $code;
        }

        return '<' . $m[1] . ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' . substr($code, \strlen($m[0]));
    }

    // ---------------------------------------------------------------- helpers

    /** An address on this site: a path ("/images/…") or this scheme and host. */
    public static function isLocal(string $url, string $siteBase): bool
    {
        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }
        if ($url[0] === '/') {
            return true;
        }

        return stripos($url, rtrim($siteBase, '/') . '/') === 0;
    }

    /** An attribute added at the end of a tag (the value is HTML-escaped). */
    private static function addAttr(string $tag, string $name, string $value): string
    {
        $attr = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false) . '"';

        return (string) preg_replace('#\s*(/?)>$#', $attr . '$1>', $tag, 1);
    }

    private static function beforeBodyEnd(string $html, string $code): string
    {
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $code : substr_replace($html, $code, $pos, 0);
    }

    private static function removeClass(string $tag, string $class): string
    {
        return (string) preg_replace_callback('#\sclass="([^"]*)"#i', function ($m) use ($class) {
            $classes = trim(preg_replace('#\s*\b' . preg_quote($class, '#') . '\b#', '', $m[1]));

            return $classes === '' ? '' : ' class="' . $classes . '"';
        }, $tag, 1);
    }

}
