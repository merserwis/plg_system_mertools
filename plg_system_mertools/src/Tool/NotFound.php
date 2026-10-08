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
 * addresses in Joomla's Redirects component still win. Only pages are redirected (GET or HEAD of an
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
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        foreach ($excludes as $exclude) {
            $exclude = trim($exclude);
            if ($exclude !== '' && str_starts_with(strtolower($path), strtolower($exclude))) {
                return false;
            }
        }

        // the target itself is missing: show the error page, never loop
        $targetHost = strtolower((string) parse_url($target, PHP_URL_HOST));
        if ($targetHost === strtolower($siteHost)) {
            $targetPath = rtrim((string) parse_url($target, PHP_URL_PATH), '/');
            if ($targetPath === rtrim($path, '/')) {
                return false;
            }
        }

        return true;
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
