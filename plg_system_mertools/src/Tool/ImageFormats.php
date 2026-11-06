<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Copies of the pictures in formats newer than WebP — JPEG XL and AVIF —, made from the copies
 * ImageOptimizer makes, with what this server has:
 *
 *  - AVIF: PHP's GD (built with libavif), Imagick (ImageMagick with an AVIF writer: libheif with an
 *    AV1 encoder) or the program avifenc;
 *  - JPEG XL: Imagick (ImageMagick with libjxl) or the program cjxl.
 *
 * What a server lists is not trusted (ImageMagick lists formats it can only read): each way is tried
 * once on a small picture and the file is read back (its first bytes say AVIF or JPEG XL). What came
 * of it is kept in the cache for a day, or until PHP or ImageMagick changes (encoders()).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ImageFormats
{
    /** the formats and their types, in the order of the <source> elements: the browser takes the first it can show */
    public const TYPES = ['jxl' => 'image/jxl', 'avif' => 'image/avif'];

    /** the key of a format's copies in an entry of ImageOptimizer's manifest */
    public const KEYS = ['jxl' => 'j', 'avif' => 'a'];

    /** the ways tried, best first */
    public const WAYS = ['avif' => ['gd', 'imagick', 'avifenc'], 'jxl' => ['imagick', 'cjxl']];

    /**
     * quality 0–100, chosen on photos of a shop against the WebP copies (quality 80), measured with
     * SSIMULACRA2 at 1080 px: AVIF 64 as good as WebP in the median at about 80 % of its bytes; JPEG
     * XL 78 a little better in the median, lighter for large photos (a format is kept for a picture
     * only when lighter than its WebP copies)
     */
    public const QUALITY = ['avif' => 64, 'jxl' => 78];

    /** AVIF by Imagick (libheif): a little worse than libavif at the same quality, so a little higher */
    public const QUALITY_IMAGICK_AVIF = 66;

    /** AVIF: 0 (slowest, smallest) … 10 (fastest) */
    public const AVIF_SPEED = 6;

    /** JPEG XL: 1 (fastest) … 9 */
    public const JXL_EFFORT = 7;

    private const TTL = 86400;

    /** @var array<string, callable>|null for the tests: format => fn(\GdImage $img, string $file): bool */
    private static ?array $fake = null;

    /**
     * The way this server makes each format: format => 'gd' | 'imagick' | 'avifenc' | 'cjxl', with
     * "-opaque" when it loses transparency (opaqueOnly()); a format it cannot make is not there.
     * $fresh: tried again now (the panel of the pictures).
     *
     * @return array<string, string>
     */
    public static function encoders(string $cacheDir, bool $fresh = false): array
    {
        if (self::$fake !== null) {
            return array_map(fn () => 'fake', array_intersect_key(self::TYPES, self::$fake));
        }
        $file = rtrim($cacheDir, '/') . '/mertools-img-formats.json';
        $key  = self::key();
        if (!$fresh && is_file($file)) {
            $kept = @json_decode((string) @file_get_contents($file), true);
            if (\is_array($kept) && ($kept['key'] ?? '') === $key && (int) ($kept['at'] ?? 0) > time() - self::TTL && \is_array($kept['via'] ?? null)) {
                return array_intersect_key(array_map('strval', $kept['via']), self::TYPES);
            }
        }
        // in the order of the <source> elements
        $via = array_intersect_key(array_replace(self::TYPES, $probed = self::probe()), $probed);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['key' => $key, 'at' => time(), 'via' => $via])) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
        }

        return $via;
    }

    /** For the tests: encoders that write what they like (null: the real ones). */
    public static function fake(?array $encoders): void
    {
        self::$fake = $encoders;
    }

    /**
     * What the ways depend on, cheap to tell on every page: a probe made with another PHP or ImageMagick
     * is not used. A program installed or removed is seen within a day, or when the panel opens.
     */
    private static function key(): string
    {
        $imagick = class_exists('Imagick') ? (string) (\Imagick::getVersion()['versionString'] ?? '') : '';

        return PHP_VERSION . '|' . $imagick . '|' . (ImageCompressor::canExec() ? 'exec' : '-');
    }

    /**
     * Each way tried on a small picture (a gradient, its left side transparent), the first that writes
     * the format wins. A way that loses the transparency (ImageMagick 6 writes AVIF without it), or
     * whose transparency cannot be read back here (Imagick), is "<way>-opaque": used only for
     * pictures without transparency (opaqueOnly()).
     */
    private static function probe(): array
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            return [];
        }
        $img = imagecreatetruecolor(32, 24);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($x = 0; $x < 32; $x++) {
            imageline($img, $x, 0, $x, 23, (int) imagecolorallocatealpha($img, $x * 8, 255 - $x * 8, 120, $x < 8 ? 127 : 0));
        }
        $dir = sys_get_temp_dir();
        $via = [];
        foreach (self::WAYS as $format => $ways) {
            foreach ($ways as $way) {
                $file = $dir . '/mertools-probe-' . getmypid() . '.' . $format;
                $ok   = self::possible($format, $way) && self::write($img, $format, $way, $file, true) && self::is($format, $file);
                $kept = $ok ? self::alphaKept($format, $file) : null;
                @unlink($file);
                if ($ok) {
                    // GD, avifenc and cjxl keep it (where it cannot be read back here, they are trusted)
                    $via[$format] = $way . ($kept === false || ($kept === null && $way === 'imagick') ? '-opaque' : '');
                    break;
                }
            }
        }

        return $via;
    }

    /** Did the transparent side of the probe stay transparent: true, false, or null when this server cannot read the file back. */
    private static function alphaKept(string $format, string $file): ?bool
    {
        if ($format === 'avif' && \function_exists('imagecreatefromavif') && \defined('IMG_AVIF') && (imagetypes() & IMG_AVIF)) {
            $back = @imagecreatefromavif($file);

            return $back ? ((imagecolorat($back, 2, 12) >> 24) & 0x7F) > 120 : null;
        }
        if (class_exists('Imagick') && \in_array(strtoupper($format), \Imagick::queryFormats(strtoupper($format)), true)) {
            try {
                $back = new \Imagick($file);

                return $back->getImageAlphaChannel() && $back->getImagePixelColor(2, 12)->getColorValue(\Imagick::COLOR_ALPHA) < 0.1;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /** A way for pictures without transparency only (probe()). */
    public static function opaqueOnly(string $way): bool
    {
        return str_ends_with($way, '-opaque');
    }

    /**
     * May the picture have transparent parts? A PNG with an alpha channel (colour types 4 and 6, even
     * when every pixel is opaque) or a tRNS chunk before its image data, however much other data comes
     * first (the chunks are walked, their contents skipped); a JPEG never.
     */
    public static function transparent(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        try {
            if (fread($fh, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }
            // at most so many chunks before the image data (a broken file is not read to its end)
            for ($i = 0; $i < 10000; $i++) {
                $head = fread($fh, 8);
                if (!\is_string($head) || \strlen($head) < 8) {
                    return false;
                }
                $length = unpack('N', substr($head, 0, 4))[1];
                $type   = substr($head, 4, 4);
                if ($type === 'IHDR') {
                    $data = (string) fread($fh, $length);
                    if (\in_array(\ord($data[9] ?? "\0"), [4, 6], true)) {
                        return true;
                    }
                    fseek($fh, 4, SEEK_CUR);
                    continue;
                }
                if ($type === 'tRNS') {
                    return true;
                }
                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }
                if (fseek($fh, $length + 4, SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($fh);
        }
    }

    /** Is this way there at all (before it is tried)? */
    private static function possible(string $format, string $way): bool
    {
        return match ($way) {
            'gd'      => $format === 'avif' && \function_exists('imageavif') && \defined('IMG_AVIF') && (imagetypes() & IMG_AVIF),
            'imagick' => class_exists('Imagick') && \in_array(strtoupper($format), \Imagick::queryFormats(strtoupper($format)), true),
            'avifenc', 'cjxl' => ImageCompressor::canExec() && ImageCompressor::program($way) !== '',
            default   => false,
        };
    }

    /**
     * $img written as $format to $file the way $via says (a file of its own beside it while it is
     * written; $file only when the whole file is there). $alpha: the picture has transparency (a PNG).
     */
    public static function write(\GdImage $img, string $format, string $via, string $file, bool $alpha): bool
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        $via = (string) preg_replace('#-opaque$#', '', $via);
        try {
            if ($via === 'fake') {
                $ok = isset(self::$fake[$format]) && (self::$fake[$format])($img, $tmp);
            } elseif ($via === 'gd') {
                $ok = @imageavif($img, $tmp, self::QUALITY['avif'], self::AVIF_SPEED);
            } else {
                $ok = self::throughPng($img, $format, $via, $tmp, $alpha);
            }
            clearstatcache(true, $tmp);
            if ($ok && @filesize($tmp) > 0 && @rename($tmp, $file)) {
                return true;
            }
        } catch (\Throwable $e) {
            // an encoder that fails is no file
        }
        @unlink($tmp);

        return false;
    }

    /** Imagick and the programs read the picture from a PNG (no loss; its transparency only when it has some). */
    private static function throughPng(\GdImage $img, string $format, string $via, string $tmp, bool $alpha): bool
    {
        // a half-written file ends in ".tmp": one left by a PHP stopped meanwhile is removed by the tidying up
        $png = substr($tmp, 0, -4) . '.png.tmp';
        imagesavealpha($img, $alpha);
        $ok = @imagepng($img, $png, 1);
        imagesavealpha($img, true);
        if (!$ok) {
            @unlink($png);

            return false;
        }
        try {
            if ($via === 'imagick') {
                $im = new \Imagick('png:' . $png);
                $im->stripImage();
                $im->setImageFormat(strtoupper($format));
                // ImageMagick 6 takes the quality of AVIF from the settings of the wand, not of the image
                $quality = $format === 'avif' ? self::QUALITY_IMAGICK_AVIF : self::QUALITY[$format];
                $im->setCompressionQuality($quality);
                $im->setImageCompressionQuality($quality);
                $im->setOption($format === 'avif' ? 'heic:speed' : 'jxl:effort', (string) ($format === 'avif' ? self::AVIF_SPEED : self::JXL_EFFORT));
                $ok = $im->writeImage($format . ':' . $tmp);
                $im->clear();

                return (bool) $ok;
            }
            $bin = ImageCompressor::program($via);
            if ($bin === '') {
                return false;
            }
            $cmd = $via === 'cjxl'
                ? escapeshellarg($bin) . ' ' . escapeshellarg($png) . ' ' . escapeshellarg($tmp) . ' -q ' . self::QUALITY['jxl'] . ' -e ' . self::JXL_EFFORT . ' --quiet'
                : escapeshellarg($bin) . ' -q ' . self::QUALITY['avif'] . ' -s ' . self::AVIF_SPEED . ' ' . escapeshellarg($png) . ' ' . escapeshellarg($tmp);
            $out  = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);

            return $code === 0;
        } finally {
            @unlink($png);
        }
    }

    /** Is the file really of this format (its first bytes)? */
    public static function is(string $format, string $file): bool
    {
        $head = (string) @file_get_contents($file, false, null, 0, 32);
        if ($format === 'avif') {
            return substr($head, 4, 4) === 'ftyp' && (substr($head, 8, 4) === 'avif' || substr($head, 8, 4) === 'avis'
                || (substr($head, 8, 4) === 'mif1' && str_contains(substr($head, 16), 'avif')));
        }

        return str_starts_with($head, "\xFF\x0A") || str_starts_with($head, "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A");
    }
}
