<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox post and product sliders (Recent posts slider, Related posts slider, Recently viewed
 * products) in several rows: Gridbox shows one row of posts and has no setting for more. A slider
 * whose "Class suffix" (element settings in Gridbox) has mt-rows-2 … mt-rows-4 shows that many rows;
 * mt-rows-tablet-N and mt-rows-phone-N set the rows up to 1024 / 768 px screen width (the phone
 * falls back to the tablet value, then to the general one).
 *
 * Before Gridbox starts the slider (its app.initslideset), the posts are grouped: each slide becomes
 * a column of N posts, filled row by row within a page (the first row 1 2 3, the second 4 5 6 for
 * three visible columns). Gridbox's carousel then works on the columns as on single posts — sliding,
 * autoplay, arrows and dots unchanged; the posts keep their markup, so cart and wishlist buttons still
 * work. A change of screen width regroups and restarts the sliders. Nothing changes without the class.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class PostRows
{
    private const SLIDERS = 'ba-item-(?:recent-posts-slider|related-posts-slider|recently-viewed-products)';

    private const CSS = '.slideshow-content .mt-rows{display:flex;flex-direction:column;gap:var(--mt-rows-gap,30px)}.slideshow-content .mt-rows>li{position:relative!important;left:auto!important;top:auto!important;right:auto!important;width:100%!important;height:auto!important;margin:0!important;list-style:none;transform:none!important;visibility:inherit!important}.slideshow-content .mt-rows>li .ba-slideshow-img{visibility:inherit!important}';

    private const JS = '(function(w,d){var a=w.app;if(!a||a.mtRows)return;a.mtRows=1;
var SEL=\'.ba-item-recent-posts-slider,.ba-item-related-posts-slider,.ba-item-recently-viewed-products\';
function pick(c,re){var x=c.match(re);return x?+x[1]:0}
function want(el){var c=\' \'+el.className+\' \',all=pick(c,/\\smt-rows-(\\d)\\s/),tab=pick(c,/\\smt-rows-tablet-(\\d)\\s/),ph=pick(c,/\\smt-rows-phone-(\\d)\\s/),wd=w.innerWidth,r=all||1;
if(!all&&!tab&&!ph)return 1;if(wd<=1024&&tab)r=tab;if(wd<=768)r=ph||tab||r;return Math.max(1,Math.min(4,r))}
function ungroup(box){[].slice.call(box.children).forEach(function(s){if(!s.classList.contains(\'mt-rows-slide\'))return;
[].slice.call(s.querySelectorAll(\'.mt-rows > li\')).forEach(function(p){p.classList.remove(\'mt-rows-post\');p.classList.add(\'item\');box.insertBefore(p,s)});s.remove()})}
function group(el){var box=el.querySelector(\'.slideshow-content\');if(!box)return false;var r=want(el),cur=+(box.getAttribute(\'data-mt-rows\')||1);
if(r===cur)return false;ungroup(box);box.setAttribute(\'data-mt-rows\',r);var it=a.items&&a.items[el.id],gut=!it||!it.desktop||it.desktop.gutter!==false;el.style.setProperty(\'--mt-rows-gap\',gut?\'30px\':\'0px\');if(r<2)return true;
var posts=[].slice.call(box.children).filter(function(p){return p.tagName===\'LI\'&&p.classList.contains(\'item\')}),cols=1,o;
try{o=w.getSlidesetObject?w.getSlidesetObject(el.id):null;cols=Math.max(1,+(o&&o.count)||1)}catch(e){cols=1}
var page=cols*r,groups=[];for(var s0=0;s0<posts.length;s0+=page){for(var j=0;j<cols;j++){var g0=[];for(var k=0;k<r;k++){var p0=posts[s0+j+k*cols];if(p0)g0.push(p0)}if(g0.length)groups.push(g0)}}
groups.forEach(function(gr,i){var s=d.createElement(\'li\'),g=d.createElement(\'div\');s.className=\'item mt-rows-slide\'+(i===0?\' active\':\'\');g.className=\'mt-rows\';s.appendChild(g);box.appendChild(s);
gr.forEach(function(p){p.classList.remove(\'item\',\'active\');p.classList.add(\'mt-rows-post\');g.appendChild(p)})});return true}
var orig=a.initslideset;function wrap(fn){return function(obj,key){var el=d.getElementById(key);if(el&&el.matches(SEL))group(el);return fn.apply(this,arguments)}}
if(typeof orig===\'function\')orig=wrap(orig);
Object.defineProperty(a,\'initslideset\',{configurable:true,get:function(){return orig},set:function(fn){orig=typeof fn===\'function\'?wrap(fn):fn}});
var t;w.addEventListener(\'resize\',function(){clearTimeout(t);t=setTimeout(function(){var ch=false;[].forEach.call(d.querySelectorAll(SEL),function(el){if(group(el))ch=true});if(ch&&a.reloadSlideSet)a.reloadSlideSet()},150)})})(window,document);';

    /** A slider of the page asks for rows (mt-rows-N, mt-rows-tablet-N or mt-rows-phone-N in its classes). */
    public static function wanted(string $html): bool
    {
        if (!str_contains($html, 'mt-rows-')) {
            return false;
        }

        return (bool) preg_match('#\sclass="[^"]*\b' . self::SLIDERS . '\b[^"]*\bmt-rows-(?:tablet-|phone-)?[1-4]\b#i', $html);
    }

    /** The grouping script right after Gridbox's own script, and its few style rules in the head. */
    public static function apply(string $html): string
    {
        if (!self::wanted($html) || str_contains($html, 'a.mtRows')
            || !preg_match('#<script\b[^>]*\bsrc="[^"]*templates/gridbox/js/gridbox\.js[^"]*"[^>]*>\s*</script>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $html = substr_replace($html, '<script>' . self::JS . '</script>', $m[0][1] + \strlen($m[0][0]), 0);
        $head = stripos($html, '</head>');

        return $head === false ? $html : substr_replace($html, '<style id="mertools-rows">' . self::CSS . '</style>', $head, 0);
    }
}
