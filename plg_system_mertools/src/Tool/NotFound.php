<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page not found (404): instead of the error page, the visitor is redirected to a chosen page — the
 * home page by default, a menu item or any address. This used to be done by editing the template's
 * error.php, which every Gridbox update overwrites; here it is a setting that survives updates.
 *
 * Runs on Joomla's onError event, after the System - Redirect plugin, so redirects set for single
 * addresses in Joomla's Redirects component still win; a redirect typed in MerTools' log of missing
 * addresses and a moved page (Redirects) come before the target. Only pages are redirected (GET or HEAD of an
 * HTML page): not form posts, AJAX or JSON requests, and not the target page itself, so a missing
 * target can never loop.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class NotFound
{
    /**
     * The target as an absolute URL, or null when it cannot be used.
     *
     * @param string $target  the target from the settings: an absolute http(s) address or a path ("/kontakt")
     * @param string $siteUrl the site's base, e.g. "https://www.merserwis.pl/" (Uri::root())
     *
     * @return string|null
     */
    public static function absoluteTarget(string $target, string $siteUrl): ?string
    {
        $target = trim($target);
        if ($target === '' || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $target)) {
            return null;
        }
        if (preg_match('#^https?://[^/]+#i', $target)) {
            return $target;
        }
        // "//host/…" would leave the site without saying so; only real addresses or paths
        if (str_starts_with($target, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
            return null;
        }
        $base = preg_replace('#^(https?://[^/]+).*$#i', '$1', rtrim($siteUrl, '/'));
        if ($target[0] === '/') {
            return $base . $target;
        }

        return rtrim($siteUrl, '/') . '/' . $target;
    }

    /**
     * Whether a missing page is redirected to the target.
     *
     * @param string   $requestUri the request path with its query ("/oferta/stara?x=1")
     * @param string   $target     the absolute target URL
     * @param string   $siteHost   the host of the request
     * @param string[] $excludes   path beginnings that keep the normal 404 ("/api/", "/images/")
     *
     * @return bool
     */
    public static function shouldRedirect(string $requestUri, string $target, string $siteHost, array $excludes): bool
    {
        if (self::excluded($requestUri, $excludes)) {
            return false;
        }
        // the target itself is missing: show the error page, never loop
        $targetHost = strtolower((string) parse_url($target, PHP_URL_HOST));
        if ($targetHost === strtolower($siteHost) && self::page($target) === self::page($requestUri)) {
            return false;
        }

        return true;
    }

    /**
     * What tells a page apart: its path, and with index.php (or the bare root) also its query, as in the log
     * of missing addresses (a site without SEF: index.php?option=…&id=42 and …&id=99 are two pages). The
     * parameters of the query compared in any order.
     */
    private static function page(string $url): string
    {
        $path  = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '' || !preg_match('#(?:^/|/index\.php)$#i', $path)) {
            return rtrim($path, '/');
        }
        parse_str($query, $vars);
        ksort($vars);

        return rtrim($path, '/') . '?' . http_build_query($vars);
    }

    /**
     * Whether a request path begins with one of the excluded beginnings (it keeps the normal 404 and is
     * not logged).
     *
     * @param string[] $excludes
     */
    public static function excluded(string $requestUri, array $excludes): bool
    {
        $path = strtolower((string) parse_url($requestUri, PHP_URL_PATH));
        foreach ($excludes as $exclude) {
            $exclude = trim($exclude);
            if ($exclude !== '' && str_starts_with($path, strtolower($exclude))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The excluded path beginnings from the settings (one per line or separated by commas).
     *
     * @return string[]
     */
    public static function excludes(string $setting): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $setting) ?: [])));
    }
}
