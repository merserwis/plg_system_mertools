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
 *    after the page has loaded, not while the page is still loading;
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
     * Gridbox's script-loaded images of this site: real address and size now, the browser's own
     * lazy loading below the header. Tracking pixels, hidden images and other sites are left alone.
     *
     * @param string   $html
     * @param string   $siteBase  "https://www.merserwis.pl" (scheme and host, no slash)
     * @param callable $size      fn(string $url): ?array [width, height] of a local image
     */
    public static function images(string $html, string $siteBase, callable $size): string
    {
        $headerStart = stripos($html, '<header');
        $headerEnd   = stripos($html, '</header>');
        $eagerLeft   = self::HEADER_EAGER;

        return (string) preg_replace_callback('#<img\b[^>]*\bdata-gridbox-lazyload-src="([^"]+)"[^>]*>#i',
            function (array $m) use ($siteBase, $size, $headerStart, $headerEnd, &$eagerLeft) {
                [$tag, $src] = [$m[0][0], html_entity_decode($m[1][0], ENT_QUOTES)];
                $offset = $m[0][1];
                if (preg_match('#display\s*:\s*none|\bwidth="1"|\bheight="1"#i', $tag) || !self::isLocal($src, $siteBase)) {
                    return $tag;
                }
                $inHeader = $headerStart !== false && $headerEnd !== false && $offset > $headerStart && $offset < $headerEnd;
                // the header: its first pictures (logo, badges) at once; the rest are mostly menu pictures,
                // hidden until the menu opens, which Gridbox loads at the right moment: left to Gridbox
                if ($inHeader && $eagerLeft-- <= 0) {
                    return $tag;
                }
                $eager = $inHeader;

                $tag = preg_replace('#\ssrc="[^"]*"#i', ' src="' . $m[1][0] . '"', $tag, 1);
                $tag = preg_replace('#\sdata-gridbox-lazyload-src="[^"]*"#i', '', $tag);
                $tag = preg_replace('#\sdata-gridbox-lazyload-srcset="([^"]*)"#i', ' srcset="$1"', $tag);
                $tag = self::removeClass($tag, 'lazy-load-image');

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
                if (!preg_match('#\sloading=#i', $tag)) {
                    $tag = preg_replace('#^<img\b#i', '<img loading="' . ($eager ? 'eager' : 'lazy') . '"', $tag);
                }
                if (!preg_match('#\sdecoding=#i', $tag)) {
                    $tag = preg_replace('#^<img\b#i', '<img decoding="async"', $tag);
                }

                return $tag;
            }, $html, -1, $count, PREG_OFFSET_CAPTURE);
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

    /** A preload of the main picture, right after <title> (or at the end of the head). */
    public static function preloadImage(string $html, string $url): string
    {
        $link = '<link rel="preload" as="image" href="' . htmlspecialchars($url, ENT_QUOTES) . '" fetchpriority="high">';
        $pos  = stripos($html, '</title>');
        if ($pos !== false) {
            return substr_replace($html, $link, $pos + 8, 0);
        }
        $pos = stripos($html, '</head>');

        return $pos === false ? $html : substr_replace($html, $link, $pos, 0);
    }

    // ---------------------------------------------------------------- YouTube background

    /**
     * Video backgrounds start after the first interaction, or $seconds after the page has loaded
     * (Gridbox starts them while the page is still loading). Wraps app.checkVideoBackground right
     * after Gridbox's own script; the Gridbox file is not changed.
     */
    public static function delayVideoBackground(string $html, int $seconds): string
    {
        if (!preg_match('#<script\b[^>]*\bsrc="[^"]*templates/gridbox/js/gridbox\.js[^"]*"[^>]*>\s*</script>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $ms = max(0, min(60, $seconds)) * 1000;
        $js = '<script>(function(w,d){var a=w.app;if(!a||!a.checkVideoBackground||a.mtVideoDelay)return;a.mtVideoDelay=1;'
            . 'var o=a.checkVideoBackground,go=0,args=[];'
            . 'function run(){if(go)return;go=1;o.apply(a,args)}'
            . 'a.checkVideoBackground=function(){args=arguments;if(go)return o.apply(a,arguments);'
            . self::EVENTS . '.forEach(function(e){w.addEventListener(e,run,{once:true,passive:true})});'
            . 'var t=function(){setTimeout(run,' . $ms . ')};d.readyState==="complete"?t():w.addEventListener("load",t)}})(window,document);</script>';

        $end = $m[0][1] + \strlen($m[0][0]);

        return substr_replace($html, $js, $end, 0);
    }

    // ---------------------------------------------------------------- marketing scripts

    /**
     * Scripts whose address or code contains one of $patterns run at the first interaction
     * (and, if $seconds > 0, at the latest that long after loading). Their order is kept. Scripts
     * containing one of $keep (e.g. the cookie consent) are never delayed.
     *
     * @param string[] $patterns
     * @param string[] $keep
     */
    public static function delayScripts(string $html, array $patterns, array $keep, int $seconds): string
    {
        $patterns = array_values(array_filter(array_map('trim', $patterns)));
        if (!$patterns) {
            return $html;
        }
        $count = 0;
        $html  = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function (array $m) use ($patterns, $keep, &$count) {
            [$tag, $attrs, $body] = $m;
            // only classic scripts: JSON and other data blocks, modules and scripts already delayed stay as they are
            if (preg_match('#\stype="([^"]*)"#i', $attrs, $t) && !\in_array(strtolower(trim($t[1])), ['text/javascript', 'application/javascript', ''], true)) {
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
                    $attrs = preg_replace('#\stype="[^"]*"#i', '', $attrs);

                    return '<script type="text/mertools-delay"' . $attrs . '>' . $body . '</script>';
                }
            }

            return $tag;
        }, $html);
        if (!$count) {
            return $html;
        }

        $ms     = max(0, min(120, $seconds)) * 1000;
        $loader = '<script>(function(w,d){var go=0;function run(){if(go)return;go=1;'
            . 'var list=d.querySelectorAll(\'script[type="text/mertools-delay"]\'),i=0;'
            . 'function next(){if(i>=list.length)return;var o=list[i++],s=d.createElement("script");'
            . '[].forEach.call(o.attributes,function(a){if(a.name!=="type")s.setAttribute(a.name,a.value)});'
            . 'if(o.src){s.async=false;s.onload=s.onerror=next;s.src=o.src;o.replaceWith(s)}else{s.text=o.text;o.replaceWith(s);next()}}next()}'
            . self::EVENTS . '.forEach(function(e){w.addEventListener(e,run,{once:true,passive:true})});'
            . ($ms ? 'var t=function(){setTimeout(run,' . $ms . ')};d.readyState==="complete"?t():w.addEventListener("load",t);' : '')
            . '})(window,document);</script>';
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $loader : substr_replace($html, $loader, $pos, 0);
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

    private static function removeClass(string $tag, string $class): string
    {
        return (string) preg_replace_callback('#\sclass="([^"]*)"#i', function ($m) use ($class) {
            $classes = trim(preg_replace('#\s*\b' . preg_quote($class, '#') . '\b#', '', $m[1]));

            return $classes === '' ? '' : ' class="' . $classes . '"';
        }, $tag, 1);
    }

}
