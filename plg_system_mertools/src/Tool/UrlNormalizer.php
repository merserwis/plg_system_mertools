<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * URL normalization: Gridbox (and the Joomla SEF router behind it) serves a page under any number
 * of slashes in the path — "/oferta", "//oferta" and "//////oferta" all return the same page with
 * status 200. Search engines then see several addresses for one page (duplicate content), and link
 * equity is split. This tool collapses runs of slashes in the request path to a single slash and
 * redirects (301 by default) to the one canonical address, so only one address is ever indexed.
 *
 * Only the path is touched; the query string is left exactly as it is (a "//" there can be a real
 * value, e.g. ?return=https://…). Percent-encoded slashes (%2F) are part of a segment's value and
 * are not touched either. Everything stays on the same host, so this can never redirect off-site.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class UrlNormalizer
{
    /**
     * The canonical request target (path + query) for a request URI, or null when it is already
     * canonical (nothing to redirect).
     *
     * $requestUri is what the server received in $_SERVER['REQUEST_URI']: the path, optionally
     * followed by "?" and the query string (no scheme or host). The base path of a Joomla install
     * in a subdirectory has single slashes, so collapsing every run of slashes is safe there too.
     *
     * @param string $requestUri the raw request URI (path [?query])
     *
     * @return string|null the canonical "path[?query]" when it differs, else null
     */
    public static function canonicalTarget(string $requestUri): ?string
    {
        if ($requestUri === '') {
            return null;
        }

        // keep the query string aside; only the path is normalized
        $mark  = strpos($requestUri, '?');
        $path  = $mark === false ? $requestUri : substr($requestUri, 0, $mark);
        $query = $mark === false ? '' : substr($requestUri, $mark);

        // a path must start with a slash; without one this is not something we normalize
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        $clean = preg_replace('#/{2,}#', '/', $path);

        if ($clean === null || $clean === $path) {
            return null;
        }

        return $clean . $query;
    }
}
