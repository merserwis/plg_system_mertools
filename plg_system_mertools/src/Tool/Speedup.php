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
     *    GIF at the top ($gif) its first frame at once, the animation after the page has loaded.
     *
     * Tracking pixels, hidden images and images of other sites are left alone.
     *
     * @param string        $html
     * @param string        $siteBase "https://www.merserwis.pl" (scheme and host, no slash)
     * @param callable      $size     fn(string $url): ?array [width, height] of a local image
     * @param callable|null $copies   fn(string $url): ?array ImageOptimizer::lookup()
     * @param bool          $plain    also images Gridbox does not load by script (lazy loading off in Gridbox)
     * @param bool          $gif      a heavy animated GIF at the top: first frame first
     */
    public static function images(string $html, string $siteBase, callable $size, ?callable $copies = null, bool $plain = false, bool $gif = false): string
    {
        $headerStart = stripos($html, '<header');
        $headerEnd   = stripos($html, '</header>');
        $header      = $headerStart !== false && $headerEnd !== false && $headerEnd > $headerStart;
        $zoneEnd     = self::firstSectionEnd($html, $header ? $headerEnd : 0);
        $eagerLeft   = self::HEADER_EAGER;
        $zoneLeft    = self::ZONE_EAGER;
        $priority    = !preg_match('#\sfetchpriority="high"#i', $html);
        $animated    = false;

        $html = (string) preg_replace_callback('#<img\b[^>]*>#i',
            function (array $m) use ($siteBase, $size, $copies, $plain, $gif, $header, $headerStart, $headerEnd, $zoneEnd, &$eagerLeft, &$zoneLeft, &$priority, &$animated, &$html) {
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
                $info = $copies ? $copies($src) : null;
                if ($info && !preg_match('#\ssrcset=#i', $tag)) {
                    if ($gif && $eager && !$inHeader && isset($info['frame'], $info['anim'])) {
                        $tag      = self::addAttr($tag, 'srcset', $info['frame']);
                        $tag      = self::addAttr($tag, 'sizes', self::sizes($html, $offset, false));
                        $tag      = self::addAttr($tag, 'data-mt-anim', $info['anim']);
                        $animated = true;
                    } elseif (isset($info['srcset'])) {
                        $tag = self::addAttr($tag, 'srcset', $info['srcset']);
                        $tag = self::addAttr($tag, 'sizes', self::sizes($html, $offset, !$eager));
                    }
                }
                // the largest picture at the top is usually what the visitor waits for: fetched first
                if ($eager && !$inHeader && $priority && $dims && $dims[0] * $dims[1] >= 40000 && !preg_match('#\sfetchpriority=#i', $tag)) {
                    $tag      = self::addAttr($tag, 'fetchpriority', 'high');
                    $priority = false;
                }

                return $tag;
            }, $html, -1, $count, PREG_OFFSET_CAPTURE);

        if ($animated) {
            $html = self::beforeBodyEnd($html, self::ANIMATION_SCRIPT);
        }

        return $html;
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
     * The sizes attribute of a picture: the whole screen width on phones, else the share of its
     * Gridbox column (ba-col-N of 12, at least a third of the usual 1200 px content width; a full
     * column: the whole screen). Lazy images let the browser measure them ("auto").
     */
    public static function sizes(string $html, int $offset, bool $lazy): string
    {
        $before = substr($html, max(0, $offset - 8000), min($offset, 8000));
        $span   = preg_match_all('#\bba-col-(\d{1,2})\b#', $before, $c) ? (int) end($c[1]) : 12;
        $wide   = $span >= 12 || $span < 1 ? '100vw' : (int) round(max($span, 4) / 12 * 1200) . 'px';
        // a picture of a blog or store list (a grid of posts or products: its columns are set in CSS)
        if (preg_match('#class="ba-blog-post-image\b[^"]*"[^>]*>(?:(?!</div>).){0,400}$#s', $before)) {
            $wide = '400px';
        }

        return ($lazy ? 'auto, ' : '') . '(max-width: 768px) 100vw, ' . $wide;
    }

    /**
     * Background pictures given in the page (style="background-image: url(…)", e.g. the slides of a
     * Gridbox slideshow): a background has no srcset, so the style gets the lighter WebP copy at the
     * picture's own width, and phones (screens up to 768 / 480 px) get a smaller copy through a
     * media rule (data-mt-bg="n"). The same address in data-src (Gridbox loads slides from it) is
     * replaced too. Pictures without copies (yet) and of other sites stay as they are.
     *
     * @param callable $copies   fn(string $url): ?array ImageOptimizer::lookup()
     * @param array    $variants filled: full-width copy => [768 => copy, 480 => copy] (for a preload of the same picture)
     */
    public static function backgrounds(string $html, string $siteBase, callable $copies, array &$variants = []): string
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
                $info = $copies($src);
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

        return $pos === false ? $html : substr_replace($html, '<style id="mertools-bg">' . $css . '</style>', $pos, 0);
    }

    /**
     * Style sheets of icon fonts (Font Awesome, Material Icons, Gridbox's icon libraries…) do not
     * hold back the first paint: they load alongside and apply when they arrive (the icons appear a
     * moment later); a <noscript> copy keeps them for browsers without JavaScript.
     */
    public static function iconStyles(string $html): string
    {
        $head = stripos($html, '</head>');
        if ($head === false) {
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
        string $phoneImage = '', string $phoneColor = '#1a1a1a'): string
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

        return substr_replace($html, $js, $end, 0);
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
    public static function delayPopups(string $html): string
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

        return substr_replace($html, $js, $end, 0);
    }

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
