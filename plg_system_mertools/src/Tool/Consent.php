<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Consent to cookies, without an outside service: a window asking the visitor (in the middle of the
 * screen by default, the page dimmed behind it), the choice kept in a cookie of the site, and Google's
 * Consent Mode v2 told of it — denied by default before any Google tag runs, updated at once when the
 * visitor chooses, and for a returning visitor from the first line of the head.
 *
 * Scripts of other trackers (Facebook's pixel, Hotjar …) wait in the page as text/plain with
 * data-mertools-consent="<level>" until the visitor allows their level; the rules of the settings mark
 * them, and a script can be marked by hand (TermsFeed's cookie-consent="…" is understood too).
 * Everything is decided in the browser, so a page kept by the page cache is the same for everyone.
 *
 * Levels (TermsFeed's names, so pages marked for it keep working): functionality, tracking (analytics),
 * targeting (advertising). The strictly necessary cookies are always on.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Consent
{
    public const COOKIE = 'mertools_consent';

    public const LEVELS = ['functionality', 'tracking', 'targeting'];

    /** Trackers held until their level is allowed: "level: a piece of their address or code". */
    public const RULES = "targeting: connect.facebook.net\ntargeting: fbq(\ntargeting: facebook.com/tr?\ntargeting: snap.licdn.com\ntargeting: analytics.tiktok.com\n"
        . "tracking: static.hotjar.com\ntracking: mc.yandex.ru";

    public const POSITIONS = ['center', 'bottom', 'corner'];

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('consent_enabled', 0);
    }

    /** @return string[] the levels the visitor chooses from (the necessary cookies are not a choice) */
    public static function levels(Registry $params): array
    {
        $raw  = $params->get('consent_levels', null);
        $list = $raw === null ? self::LEVELS : (\is_array($raw) ? $raw : explode(',', (string) $raw));

        return array_values(array_intersect(self::LEVELS, array_map('strval', $list)));
    }

    /** @return list<array{0: string, 1: string}> [level, pattern] of the scripts held until consent */
    public static function rules(Registry $params): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) $params->get('consent_scripts', self::RULES)) ?: [] as $line) {
            if (preg_match('/^\s*(functionality|tracking|targeting)\s*:\s*(\S.*?)\s*$/i', $line, $m)) {
                $out[] = [strtolower($m[1]), $m[2]];
            }
        }

        return $out;
    }

    public static function days(Registry $params): int
    {
        return max(1, min(395, (int) $params->get('consent_days', 365)));
    }

    /**
     * The first script of the head: Google's Consent Mode with everything but security denied, then
     * the visitor's stored choice (MerTools' cookie, or a choice made in TermsFeed's window before), so a
     * Google tag loaded later already knows it. Microsoft Clarity gets the same through its consent API.
     */
    public static function headScript(Registry $params): string
    {
        $clarity = (int) $params->get('consent_clarity', 1);
        $tf      = (int) $params->get('consent_termsfeed', 1);

        return '(function(w,d){var c=null,k=d.cookie,m=k.match(/(?:^|; )' . self::COOKIE . '=f([01])t([01])m([01])/);'
            . 'if(m){c={functionality:m[1]==="1",tracking:m[2]==="1",targeting:m[3]==="1"}}'
            . ($tf ? 'else if(/(?:^|; )cookie_consent_user_accepted=true/.test(k)){try{var o=JSON.parse(decodeURIComponent('
                . '(k.match(/(?:^|; )cookie_consent_level=([^;]*)/)||[])[1]));c={functionality:!!o.functionality,tracking:!!o.tracking,targeting:!!o.targeting}}catch(e){}}' : '')
            . 'w.mertoolsConsent=c;w.dataLayer=w.dataLayer||[];if(typeof w.gtag!=="function")w.gtag=function(){w.dataLayer.push(arguments)};'
            . 'function g(x){return x?"granted":"denied"}'
            . 'w.gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",'
            . 'functionality_storage:"denied",personalization_storage:"denied",security_storage:"granted",wait_for_update:500});'
            . 'w.gtag("set","ads_data_redaction",true);'
            . 'if(c)w.gtag("consent","update",{ad_storage:g(c.targeting),ad_user_data:g(c.targeting),ad_personalization:g(c.targeting),'
            . 'analytics_storage:g(c.tracking),functionality_storage:g(c.functionality),personalization_storage:g(c.functionality)});'
            . ($clarity ? 'w.clarity=w.clarity||function(){(w.clarity.q=w.clarity.q||[]).push(arguments)};'
                . 'w.clarity("consentv2",{ad_Storage:g(c&&c.targeting),analytics_Storage:g(c&&c.tracking)});' : '')
            . '})(window,document);';
    }

    /** The head script as the very first thing of <head>, before any tag of the page's own code. */
    public static function injectHead(string $html, string $code, string $nonce): string
    {
        if (!preg_match('#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $tag = Speedup::nonced('<script class="exclude-deffer" data-mertools-consent-head>' . $code . '</script>', $nonce);

        return substr_replace($html, $tag, $m[0][1] + \strlen($m[0][0]), 0);
    }

    /**
     * Scripts matching a rule wait as text/plain with their level; <noscript> blocks of the same
     * trackers (a pixel image) are taken out, since without scripts no consent can be given.
     *
     * @param list<array{0: string, 1: string}> $rules
     */
    public static function hold(string $html, array $rules): string
    {
        // also without rules: the scripts marked by hand get their address hidden
        $level = function (string $hay) use ($rules): ?string {
            foreach ($rules as [$lvl, $pattern]) {
                if (stripos($hay, $pattern) !== false) {
                    return $lvl;
                }
            }

            return null;
        };
        $html = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function (array $m) use ($level) {
            [$tag, $attrs, $body] = $m;
            if (stripos($attrs, 'data-mertools-consent') !== false || stripos($attrs, 'cookie-consent=') !== false) {
                // marked by hand: its address hidden as well (see srcOff)
                return preg_match(self::TYPE_ATTR, $attrs, $t) && strtolower(trim(implode('', \array_slice($t, 1)))) === 'text/plain'
                    ? '<script' . self::srcOff($attrs) . '>' . $body . '</script>' : $tag;
            }
            // only classic scripts (and those MerTools delays): data blocks, modules and held ones stay
            if (preg_match(self::TYPE_ATTR, $attrs, $t)
                && !\in_array(strtolower(trim(implode('', \array_slice($t, 1)))), ['text/javascript', 'application/javascript', 'text/mertools-delay', ''], true)) {
                return $tag;
            }
            $lvl = $level($attrs . ' ' . $body);
            if ($lvl === null) {
                return $tag;
            }

            return '<script type="text/plain" data-mertools-consent="' . $lvl . '"' . self::srcOff(preg_replace(self::TYPE_ATTR, '', $attrs)) . '>' . $body . '</script>';
        }, $html);

        return (string) preg_replace_callback('#<noscript\b[^>]*>(.*?)</noscript>#is',
            fn (array $m) => $level($m[1]) !== null ? '' : $m[0], $html);
    }

    /**
     * The address of a held script as data-mertools-src: Gridbox's joining of the site's scripts into one
     * file (compress_js) takes every <script src> of the site whatever its type, and the tracker would
     * run in that file before any consent. The window's script loads it from there once allowed.
     */
    private static function srcOff(string $attrs): string
    {
        return (string) preg_replace('#(\s)src(\s*=)#i', '$1data-mertools-src$2', $attrs, 1);
    }

    /** TermsFeed's window taken out of the page: its loader, its run() and its <noscript>. */
    public static function dropTermsFeed(string $html): string
    {
        $html = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function (array $m) {
            if (preg_match('#\ssrc\s*=\s*["\']?[^"\'\s>]*cookie-consent\.js#i', $m[1]) || str_contains($m[2], 'cookieconsent.run(')) {
                return '';
            }

            return $m[0];
        }, $html);
        $html = (string) preg_replace('#<!--\s*Cookie Consent by TermsFeed.*?-->#is', '', $html);
        $html = (string) preg_replace('#<!--\s*End Cookie Consent by TermsFeed\s*-->#is', '', $html);

        return (string) preg_replace('#<noscript\b[^>]*>(?:(?!</noscript>).)*TermsFeed(?:(?!</noscript>).)*</noscript>#is', '', $html);
    }

    /** a type attribute of a tag: "…", '…' or bare */
    private const TYPE_ATTR = '#\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';

    /** The address of the privacy policy: a full address or one from the site's root, nothing else. */
    public static function policyUrl(Registry $params): string
    {
        $url = trim((string) $params->get('consent_policy', ''));

        return preg_match('#^(https?://|/)[^\s"\'<>]*$#i', $url) ? $url : '';
    }

    private static function colour(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-f]{3,8}$/i', $value) ? $value : '';
    }

    /** @param array<string,string> $texts the window's texts in the site's language */
    public static function jsConfig(Registry $params, array $texts): array
    {
        $pos    = (string) $params->get('consent_position', 'center');
        $reopen = (string) $params->get('consent_reopen', 'left');
        $title  = trim((string) $params->get('consent_title', ''));
        $text   = trim((string) $params->get('consent_text', ''));

        return [
            'cookie'   => self::COOKIE,
            'days'     => self::days($params),
            'levels'   => self::levels($params),
            'pos'      => \in_array($pos, self::POSITIONS, true) ? $pos : 'center',
            'backdrop' => (bool) (int) $params->get('consent_backdrop', 1),
            'reopen'   => \in_array($reopen, ['none', 'left', 'right'], true) ? $reopen : 'left',
            'rx'       => max(0, min(400, (int) $params->get('consent_reopen_x', 18))),
            'ry'       => max(0, min(400, (int) $params->get('consent_reopen_y', 18))),
            'policy'   => self::policyUrl($params),
            'accent'   => self::colour((string) $params->get('consent_accent', '')),
            'clarity'  => (bool) (int) $params->get('consent_clarity', 1),
            'clean'    => (bool) (int) $params->get('consent_clean', 1),
            't'        => ['title' => $title !== '' ? $title : $texts['title'], 'text' => $text !== '' ? $text : $texts['text']] + $texts,
        ];
    }

    /** The window: in the colours of the site (Gridbox's theme variables, so dark mode too). */
    public static function css(): string
    {
        $btn = 'box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 22px;margin:0;'
            . 'border-radius:8px;font:inherit;font-size:16px;font-weight:700;line-height:1.2;cursor:pointer;text-align:center;letter-spacing:normal;text-transform:none;';

        // .mtc .mtc-box .x (0,3,0): Gridbox's theme zeroes `.com_gridbox:not(.modal) h2, … p` (0,2,1) on the body
        return '.mtc{position:fixed;inset:0;z-index:2147483600;display:flex;pointer-events:none;font-family:inherit;font-size:16px;line-height:1.5}'
            . '.mtc[hidden]{display:none}'
            . '.mtc-dim{position:absolute;inset:0;background:rgba(0,0,0,.55);pointer-events:auto;animation:mtc-in .25s ease}'
            . '.mtc-box{position:relative;box-sizing:border-box;pointer-events:auto;overflow:auto;max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);'
            . 'background:var(--bg-primary,#fff);color:var(--text,#333);border-radius:14px;padding:32px;'
            . 'box-shadow:0 24px 64px rgba(0,0,0,.35),0 4px 14px rgba(0,0,0,.2);animation:mtc-up .3s ease;outline:0;text-align:left}'
            . '.mtc .mtc-box:focus,.mtc .mtc-box:focus-visible{outline:0}'
            . '.mtc[data-pos="center"]{align-items:center;justify-content:center;padding:16px}'
            . '.mtc[data-pos="center"] .mtc-box{width:100%;max-width:620px}'
            . '.mtc[data-pos="bottom"]{align-items:flex-end}'
            . '.mtc[data-pos="bottom"] .mtc-box{width:100%;border-radius:14px 14px 0 0;padding:28px max(24px,calc((100vw - 1100px)/2))}'
            . '.mtc[data-pos="corner"]{align-items:flex-end;justify-content:flex-start;padding:16px}'
            . '.mtc[data-pos="corner"] .mtc-box{width:100%;max-width:460px}'
            . '.mtc .mtc-box .mtc-title{margin:0 0 16px;padding:0;font-family:inherit;font-size:24px;line-height:1.25;font-weight:700;color:var(--title,#111);letter-spacing:normal;text-transform:none;text-align:left}'
            . '.mtc .mtc-box .mtc-text{margin:0 0 24px;padding:0;font-size:16px;line-height:1.55;letter-spacing:normal;text-align:left;color:var(--text,#333)}'
            . '.mtc-text a,.mtc-desc a{color:inherit;text-decoration:underline}'
            . '.mtc-btns{display:flex;flex-wrap:wrap;gap:12px;margin-top:4px}'
            . '.mtc-btns button{flex:1 1 160px}.mtc-btns button[hidden]{display:none}'
            . '.mtc-yes{' . $btn . 'border:2px solid var(--mtc-a);background:var(--mtc-a);color:var(--mtc-af)}'
            . '.mtc-no,.mtc-save{' . $btn . 'border:2px solid var(--mtc-a);background:transparent;color:var(--title,#111)}'
            . '.mtc-more{' . $btn . 'border:2px solid transparent;background:transparent;color:var(--title,#111);text-decoration:underline;text-underline-offset:3px}'
            . '.mtc-btns button:hover{filter:brightness(1.08)}'
            . '.mtc-no:hover,.mtc-save:hover,.mtc-more:hover{background:rgba(128,128,128,.12)}'
            . '.mtc button:focus-visible,.mtc a:focus-visible,.mtc input:focus-visible+.mtc-sw{outline:3px solid var(--mtc-a);outline-offset:2px}'
            . '.mtc-prefs{margin:0 0 20px;padding:0;list-style:none;border-top:1px solid rgba(128,128,128,.3)}'
            . '.mtc-prefs[hidden]{display:none}'
            . '.mtc-prefs li{margin:0;padding:14px 0;border-bottom:1px solid rgba(128,128,128,.3)}'
            . '.mtc-prefs li::before{display:none}'
            . '.mtc-row{display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0;font-weight:700;color:var(--title,#111)}'
            . '.mtc-row input{position:absolute;opacity:0;width:1px;height:1px;margin:0}'
            . '.mtc-sw{position:relative;flex:0 0 auto;width:48px;height:28px;border-radius:14px;background:rgba(128,128,128,.45);transition:background-color .2s}'
            . '.mtc-sw::after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s}'
            . '.mtc-row input:checked+.mtc-sw{background:var(--mtc-a)}'
            . '.mtc-row input:checked+.mtc-sw::after{transform:translateX(20px)}'
            . '.mtc-row input:disabled+.mtc-sw{opacity:.6}'
            . '.mtc-row input:disabled{cursor:default}'
            . '.mtc .mtc-box .mtc-desc{margin:6px 0 0;padding:0;font-size:14px;line-height:1.45;letter-spacing:normal;text-align:left;opacity:.85}'
            . '.mtc-re{position:fixed;z-index:2147483500;bottom:18px;box-sizing:border-box;width:44px;height:44px;padding:0;margin:0;border-radius:50%;'
            . 'border:1px solid rgba(128,128,128,.35);background:var(--bg-primary,#fff);color:var(--title,#111);cursor:pointer;display:flex;align-items:center;justify-content:center;'
            . 'box-shadow:0 4px 16px rgba(0,0,0,.25);opacity:.85;transition:opacity .2s,transform .2s}'
            . '.mtc-re[hidden]{display:none}'
            . '.mtc-re:hover,.mtc-re:focus-visible{opacity:1;transform:scale(1.06)}'
            . '.mtc-re:focus-visible{outline:3px solid var(--mtc-a);outline-offset:2px}'
            . '.mtc-re svg{width:24px;height:24px;display:block;pointer-events:none}'
            . '@keyframes mtc-in{from{opacity:0}}@keyframes mtc-up{from{opacity:0;transform:translateY(16px)}}'
            . '@media (max-width:600px){.mtc-box{padding:22px 18px}.mtc[data-pos="bottom"] .mtc-box{padding:22px 18px}.mtc .mtc-box .mtc-title{font-size:21px}.mtc-btns button{flex:1 1 100%}}'
            . '@media (prefers-reduced-motion:reduce){.mtc-dim,.mtc-box{animation:none}.mtc-sw,.mtc-sw::after,.mtc-re{transition:none}}'
            . '@media print{.mtc,.mtc-re{display:none!important}}';
    }
}
