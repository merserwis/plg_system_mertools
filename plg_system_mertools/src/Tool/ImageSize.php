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

    /** @var array<string, array{0:int,1:int,2:int,3:int}> path => [width, height, mtime, 1 (EXIF orientation counted)] */
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
        // (an entry without its fourth value was read before the EXIF orientation counted: read again)
        if ($hit && $hit[2] === $mtime && isset($hit[3])) {
            return $hit[0] > 0 ? [$hit[0], $hit[1]] : null;
        }
        $info = @getimagesize($path);
        $w    = \is_array($info) ? (int) $info[0] : 0;
        $h    = \is_array($info) ? (int) $info[1] : 0;
        // a photo stored on its side (EXIF orientation 5–8) is shown turned: its width is its height
        if (\is_array($info) && (int) $info[2] === IMAGETYPE_JPEG && self::orientation($path) >= 5) {
            [$w, $h] = [$h, $w];
        }
        if (\count($this->cache) >= self::MAX_ENTRIES) {
            // the oldest half goes, not everything (every picture would be read again otherwise)
            $this->cache = \array_slice($this->cache, (int) (self::MAX_ENTRIES / 2), null, true);
        }
        $this->cache[$path] = [$w, $h, $mtime, 1];
        $this->dirty        = true;

        return $w > 0 && $h > 0 ? [$w, $h] : null;
    }

    /** Keep what was read for the next page views (written only when something new was read). */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        // (a file name that is not UTF-8 must not make the whole file unwritable)
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($this->cache, JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $this->cacheFile);
        }
        $this->dirty = false;
    }

    /**
     * The EXIF orientation of a JPEG, 1–8 (1: as stored, also when it has none): how the camera or
     * phone that stored the picture on its side says to turn it, as browsers do. Read from the file's
     * first segments (no EXIF extension needed).
     */
    public static function orientation(string $path): int
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return 1;
        }
        $out = 1;
        if (fread($fh, 2) === "\xFF\xD8") {
            // the segments before the picture data: APP1 "Exif" holds the orientation
            for ($i = 0; $i < 64; $i++) {
                $head = (string) fread($fh, 4);
                if (\strlen($head) < 4 || $head[0] !== "\xFF") {
                    break;
                }
                $type = \ord($head[1]);
                $len  = (int) unpack('n', substr($head, 2))[1];
                if ($type === 0xDA || $type === 0xD9 || $len < 2) {
                    break;
                }
                if ($type === 0xE1) {
                    // the first directory, with the orientation, is at its start (the rest is not read)
                    $data = (string) fread($fh, min($len - 2, 16384));
                    if (str_starts_with($data, "Exif\0\0")) {
                        $out = self::exifOrientation(substr($data, 6));
                        break;
                    }
                    if (fseek($fh, $len - 2 - \strlen($data), SEEK_CUR) !== 0) {
                        break;
                    }
                    continue;
                }
                if (fseek($fh, $len - 2, SEEK_CUR) !== 0) {
                    break;
                }
            }
        }
        fclose($fh);

        return $out;
    }

    /** The orientation tag (0x0112) of the first directory of EXIF data (TIFF, either byte order); 1 when none. */
    private static function exifOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (\strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }
        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $ifd = (int) unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd < 8 || $ifd + 2 > \strlen($tiff)) {
            return 1;
        }
        $count = (int) unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count && $ifd + 14 + $i * 12 <= \strlen($tiff); $i++) {
            $at = $ifd + 2 + $i * 12;
            if ((int) unpack($short, substr($tiff, $at, 2))[1] === 0x0112) {
                $value = (int) unpack($short, substr($tiff, $at + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
