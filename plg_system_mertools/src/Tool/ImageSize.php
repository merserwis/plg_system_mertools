<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Width and height of the site's own images, for the width/height attributes that stop the page
 * from jumping while pictures load. Read from the file header (getimagesize, no decoding) and kept
 * in a small cache file next to Joomla's cache, so a page view usually reads no image at all.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ImageSize
{
    private const MAX_ENTRIES = 20000;

    /** @var array<string, array{0:int,1:int,2:int}> path => [width, height, mtime] */
    private array $cache = [];

    private bool $dirty = false;

    public function __construct(private string $root, private string $siteBase, private string $sitePath, private string $cacheFile)
    {
        $data = is_file($cacheFile) ? @json_decode((string) @file_get_contents($cacheFile), true) : null;
        $this->cache = \is_array($data) ? $data : [];
    }

    /**
     * The file of a local image address, or null: only below the site root, only picture types.
     */
    public static function pathOf(string $url, string $root, string $siteBase, string $sitePath): ?string
    {
        $url = preg_replace('#[?\#].*$#', '', $url);
        if (stripos($url, rtrim($siteBase, '/') . '/') === 0) {
            $url = substr($url, \strlen(rtrim($siteBase, '/')));
        }
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return null;
        }
        // the site may live in a folder: "/joomla/images/x.jpg" -> "images/x.jpg"
        $sitePath = '/' . trim($sitePath, '/');
        $relative = $sitePath !== '/' && stripos($url, $sitePath . '/') === 0 ? substr($url, \strlen($sitePath) + 1) : ltrim($url, '/');
        $relative = rawurldecode($relative);
        if (!preg_match('#\.(jpe?g|png|gif|webp|avif|bmp)$#i', $relative) || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        return rtrim($root, '/') . '/' . $relative;
    }

    /** [width, height] of a local image, or null when unknown. */
    public function get(string $url): ?array
    {
        $path = self::pathOf($url, $this->root, $this->siteBase, $this->sitePath);
        if ($path === null) {
            return null;
        }
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        $hit = $this->cache[$path] ?? null;
        if ($hit && $hit[2] === $mtime) {
            return $hit[0] > 0 ? [$hit[0], $hit[1]] : null;
        }
        $info = @getimagesize($path);
        $w    = \is_array($info) ? (int) $info[0] : 0;
        $h    = \is_array($info) ? (int) $info[1] : 0;
        if (\count($this->cache) >= self::MAX_ENTRIES) {
            $this->cache = [];
        }
        $this->cache[$path] = [$w, $h, $mtime];
        $this->dirty        = true;

        return $w > 0 && $h > 0 ? [$w, $h] : null;
    }

    /** Keep what was read for the next page views (written only when something new was read). */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($this->cache)) !== false) {
            @rename($tmp, $this->cacheFile);
        }
        $this->dirty = false;
    }
}
