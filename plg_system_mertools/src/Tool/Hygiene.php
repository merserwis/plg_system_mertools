<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

/**
 * Small things an audit of a site keeps finding: the campaign tags in the address of og:url, a language code
 * written twice (pl-pl), cookies without Secure and SameSite, a Content-Security-Policy that protects nothing.
 * Every function takes what it works on and returns the result; nothing here reads the request.
 */
final class Hygiene
{
    /** a policy that cannot break a page: no plug-ins, no foreign <base>, insecure sub-resources upgraded */
    public const CSP_DEFAULT = "upgrade-insecure-requests; object-src 'none'; base-uri 'self'";

    /**
     * og:url and twitter:url without the campaign tags (?srsltid=, ?utm_*, ?gclid…). A page the cache kept for a
     * visitor who came with such a tag is served to all the others, so the tag would stay in the page for good.
     */
    public static function cleanUrls(string $html): string
    {
        if (stripos($html, 'og:url') === false && stripos($html, 'twitter:url') === false) {
            return $html;
        }

        return (string) preg_replace_callback('#<meta\b[^>]*>#i', function (array $m): string {
            if (!preg_match('#\b(?:property|name)\s*=\s*["\'](?:og|twitter):url["\']#i', $m[0])) {
                return $m[0];
            }

            return (string) preg_replace_callback('#(\bcontent\s*=\s*)(["\'])(.*?)\2#is', fn (array $c): string => $c[1] . $c[2] . self::cleanUrl($c[3]) . $c[2], $m[0], 1);
        }, $html);
    }

    /** One address as it stands in an HTML attribute (&amp;); returned as it was when it has no campaign tag. */
    public static function cleanUrl(string $attribute): string
    {
        if (!str_contains($attribute, '?')) {
            return $attribute;
        }
        $url  = html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hash = '';
        if (($pos = strpos($url, '#')) !== false) {
            $hash = substr($url, $pos);
            $url  = substr($url, 0, $pos);
        }
        [$base, $query] = explode('?', $url, 2) + [1 => ''];
        $keep = [];
        foreach (explode('&', $query) as $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if ($pair !== '' && !\in_array($name, PageCache::IGNORED_PARAMS, true) && !str_starts_with($name, 'utm_')) {
                $keep[] = $pair;
            }
        }
        $clean = $base . ($keep ? '?' . implode('&', $keep) : '') . $hash;

        return $clean === $url . $hash ? $attribute : htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * lang="pl-pl" of the <html> tag as lang="pl": a language and a region that repeat it (pl-pl, de-de, cs-cz)
     * say the language twice. pt-br, en-gb and the like stay as they are.
     */
    public static function shortLang(string $html): string
    {
        return (string) preg_replace_callback('#<html\b[^>]*>#i', fn (array $tag): string => (string) preg_replace_callback(
            '#\b((?:xml:)?lang\s*=\s*)(["\'])([a-z]{2,3})-([a-z]{2,3})\2#i',
            fn (array $l): string => strtolower($l[3]) === strtolower($l[4]) ? $l[1] . $l[2] . strtolower($l[3]) . $l[2] : $l[0],
            $tag[0]
        ), $html, 1);
    }

    /**
     * The Set-Cookie lines of a response with Secure (on HTTPS) and SameSite=Lax added where they are missing.
     *
     * @param  string[] $headers  headers_list()
     * @return string[]|null      the lines to send instead of all the Set-Cookie headers; null when nothing changes
     */
    public static function cookies(array $headers, bool $https): ?array
    {
        $out     = [];
        $changed = false;
        foreach ($headers as $header) {
            if (!preg_match('/^set-cookie:\s*(.+)$/i', $header, $m)) {
                continue;
            }
            $line = trim($m[1]);
            $new  = $line;
            if ($https && !preg_match('/;\s*secure\s*(;|$)/i', $new)) {
                $new .= '; Secure';
            }
            if (!preg_match('/;\s*samesite\s*=/i', $new)) {
                $new .= '; SameSite=Lax';
            }
            $changed = $changed || $new !== $line;
            $out[]   = $new;
        }

        return $changed ? $out : null;
    }

    /**
     * The Content-Security-Policy header to add: [name, value]; null when it is off or the policy is not one
     * (a character a header cannot carry). Report-only collects the violations in the browser's console without
     * blocking anything. Another policy of the site (Joomla's own) stays: browsers apply all of them.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function csp(string $mode, string $policy): ?array
    {
        if ($mode !== 'report' && $mode !== 'enforce') {
            return null;
        }
        $policy = trim((string) preg_replace('/\s+/', ' ', $policy));
        if ($policy === '') {
            $policy = self::CSP_DEFAULT;
        }
        if (preg_match('/[^\x20-\x7e]/', $policy)) {
            return null;
        }

        return [$mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy];
    }
}
