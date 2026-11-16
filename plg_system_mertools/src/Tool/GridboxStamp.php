<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox's files with the time of its installation: Gridbox puts only its version in the addresses of
 * its scripts and styles (…/gridboxSave.js?2.20.5.0, sent to be kept a year), so a package of the same
 * version with corrected files (Balbooa's fix of 2.20.5) stays unseen where the old files are kept:
 * Cloudflare and the browsers. Its addresses get the time its files were installed (2.20.5.0.<stamp>),
 * the addresses in the page and the version its scripts load their modules with (gridboxVersion).
 * Modules Gridbox loads without its version (import() of its ES modules, the modules of its
 * administrator lists) are not reached.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class GridboxStamp
{
    /** Gridbox's manifest: the installer writes it anew with every package, its files with it */
    public const MANIFEST = '/components/com_gridbox/gridbox.xml';

    /**
     * The installed Gridbox: ['version' => '2.20.5.0', 'stamp' => 'tk3x9a'] (the stamp: the time of
     * its manifest, base 36), or null without Gridbox.
     */
    public static function installed(string $adminRoot): ?array
    {
        $file = $adminRoot . self::MANIFEST;
        $time = @filemtime($file);
        if ($time === false) {
            return null;
        }
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        if (!preg_match('#<version>\s*([0-9][0-9A-Za-z.\-]*)\s*</version>#', $head, $m)) {
            return null;
        }

        return ['version' => $m[1], 'stamp' => base_convert((string) $time, 10, 36)];
    }

    /**
     * The page with Gridbox's addresses and its gridboxVersion carrying the stamp; the page as it was
     * when a pattern cannot finish (PCRE's limits).
     */
    public static function apply(string $html, string $version, string $stamp): string
    {
        if ($version === '' || $stamp === '' || !str_contains($html, $version)) {
            return $html;
        }
        $v   = preg_quote($version, '#');
        $new = $version . '.' . $stamp;
        // the version the scripts of Gridbox's template load their modules with
        $out = preg_replace('#(\bgridboxVersion\s*=\s*)([\'"])' . $v . '\2#', '${1}${2}' . $new . '${2}', $html);
        if (!\is_string($out)) {
            return $html;
        }
        // src and href of Gridbox's own files and requests, the version as the last part of the query;
        // the value taken whole, never given back (a data: image of megabytes in a src)
        $out = preg_replace_callback('#\b(src|href)=(["\'])([^"\'<>]*+)\2#i', static function (array $m) use ($v, $new): string {
            $url = $m[3];
            if (!preg_match('#com_gridbox|/templates/gridbox/#i', $url)) {
                return $m[0];
            }
            $url = (string) preg_replace('#(\?|&|&amp;)' . $v . '$#', '${1}' . $new, $url);

            return $url === $m[3] ? $m[0] : $m[1] . '=' . $m[2] . $url . $m[2];
        }, $out);

        return \is_string($out) ? $out : $html;
    }
}
