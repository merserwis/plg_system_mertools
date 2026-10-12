<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Images in the size of the screen and a lighter format, made by PHP's GD on the site itself:
 *
 *  - every JPEG and PNG of this site shown on a page gets WebP copies in a few widths (480 … 1920 px,
 *    never wider than the picture); the page offers them in srcset and the browser takes the one
 *    that fits its screen, the original stays the fallback in src;
 *  - a heavy animated GIF gets its first frame as a small WebP: shown at once, the animation is
 *    loaded after the page (see Speedup::images()).
 *
 * Nothing is made while a visitor waits: a page only notes the pictures it lacks (a queue), and
 * the copies are made after the response has gone (onAfterRespond), a few seconds of work at a
 * time. The copies live in media/mertools-img (the originals are never changed); a changed
 * original gets new copies under a new name, so browsers and caches never keep an old one.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ImageOptimizer
{
    /** widths of the copies; the original width is added when it is not wider than MAX_FULL */
    public const WIDTHS = [480, 768, 1080, 1440, 1920];

    public const MAX_FULL = 2560;

    /** larger pictures are left alone (GD would need too much memory) */
    public const MAX_PIXELS = 24000000;

    /** an animated GIF lighter than this is shown as it is */
    public const GIF_MIN_BYTES = 150000;

    public const QUALITY = 80;

    /** smaller JPEG/PNG pictures are not worth copies */
    public const MIN_WIDTH = 300;

    public const MIN_BYTES = 20000;

    /** the folder of the copies, under the site root */
    public const DIR = 'media/mertools-img';

    private const MAX_QUEUE = 500;

    private const MAX_ENTRIES = 20000;

    /** @var array<string, array> relative path => entry */
    private array $manifest = [];

    /** @var array<string, true> relative paths waiting */
    private array $queue = [];

    private bool $dirtyManifest = false;

    private bool $dirtyQueue = false;

    /**
     * @param string $root     site root (JPATH_ROOT)
     * @param string $siteBase "https://www.example.com" (scheme and host, no slash)
     * @param string $sitePath "" or "/subfolder" (Uri::root(true))
     * @param string $cacheDir folder of the manifest and the queue (Joomla cache)
     * @param int    $gifMinBytes an animated GIF lighter than this is shown as it is
     */
    public function __construct(private string $root, private string $siteBase, private string $sitePath, private string $cacheDir,
        private int $gifMinBytes = self::GIF_MIN_BYTES)
    {
        $this->root     = rtrim($root, '/');
        $this->sitePath = rtrim($sitePath, '/');
        $this->manifest = self::readJson($this->manifestFile());
        $this->queue    = array_fill_keys(array_keys(self::readJson($this->queueFile())), true);
    }

    /** GD with WebP: the copies can be made on this server. */
    public static function supported(): bool
    {
        return \function_exists('imagewebp') && \function_exists('imagecreatetruecolor') && (imagetypes() & IMG_WEBP);
    }

    // ---------------------------------------------------------------- pages

    /**
     * What a page may use for the picture at $url: ['srcset' => "url 480w, …", 'frame' => [srcset of
     * the first frame], 'anim' => url of the animation] — or null (not local, not a JPEG/PNG/GIF,
     * not ready yet: then the picture is queued).
     */
    public function lookup(string $url): ?array
    {
        $rel = $this->relative($url);
        if ($rel === null || !preg_match('#\.(jpe?g|png|gif)$#i', $rel)) {
            return null;
        }
        $path = $this->root . '/' . $rel;
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }
        $entry = $this->manifest[$rel] ?? null;
        if (!$entry || $entry['m'] !== (int) $stat['mtime'] || $entry['s'] !== (int) $stat['size']) {
            if (!isset($this->queue[$rel]) && \count($this->queue) < self::MAX_QUEUE) {
                $this->queue[$rel] = true;
                $this->dirtyQueue  = true;
            }

            return null;
        }
        if (($entry['x'] ?? '') !== 'ok') {
            return null;
        }
        $out = [];
        if (!empty($entry['v'])) {
            $out['srcset'] = $this->srcset($entry['v']);
        }
        if (!empty($entry['f'])) {
            $out['frame'] = $this->srcset($entry['f']);
            // the animation itself: a WebP put next to the GIF by the site owner is lighter, else the GIF
            $webp       = preg_replace('#\.gif$#i', '.webp', $rel);
            $out['anim'] = $this->url(is_file($this->root . '/' . $webp) ? $webp : $rel);
        }

        return $out ?: null;
    }

    /** Pictures waiting for their copies. */
    public function pending(): int
    {
        return \count($this->queue);
    }

    public function save(): void
    {
        if ($this->dirtyQueue) {
            self::writeJson($this->queueFile(), array_fill_keys(array_keys($this->queue), 1));
            $this->dirtyQueue = false;
        }
        if ($this->dirtyManifest) {
            if (\count($this->manifest) > self::MAX_ENTRIES) {
                $this->manifest = \array_slice($this->manifest, -self::MAX_ENTRIES, null, true);
            }
            self::writeJson($this->manifestFile(), $this->manifest);
            $this->dirtyManifest = false;
        }
    }

    // ---------------------------------------------------------------- making the copies

    /**
     * Makes the copies of queued pictures for at most $seconds.
     *
     * @return int pictures that got new copies
     */
    public function work(float $seconds): int
    {
        if (!$this->queue || !self::supported()) {
            return 0;
        }
        $lock = @fopen($this->cacheDir . '/mertools-img.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        // another request may have finished some of them meanwhile
        $this->manifest = self::readJson($this->manifestFile());
        $this->queue    = array_fill_keys(array_keys(self::readJson($this->queueFile())), true) + $this->queue;
        $start          = microtime(true);
        $made           = 0;
        try {
            foreach (array_keys($this->queue) as $rel) {
                if (microtime(true) - $start > $seconds) {
                    break;
                }
                unset($this->queue[$rel]);
                $this->dirtyQueue = true;
                $entry            = $this->make((string) $rel);
                if ($entry !== null) {
                    $this->manifest[$rel] = $entry;
                    $this->dirtyManifest  = true;
                    $made += $entry['x'] === 'ok' ? 1 : 0;
                }
                $this->save();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $made;
    }

    /** The copies of one picture: the manifest entry, or null when the file is gone. */
    public function make(string $rel): ?array
    {
        $path = $this->root . '/' . $rel;
        $stat = @stat($path);
        $info = $stat ? @getimagesize($path) : false;
        if ($stat === false) {
            return null;
        }
        $entry = ['m' => (int) $stat['mtime'], 's' => (int) $stat['size'], 'x' => 'skip', 'v' => [], 'f' => [], 't' => time()];
        if (!\is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return $entry;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        $hash = substr(md5($rel . '|' . $entry['m'] . '|' . $entry['s']), 0, 10);
        $name = substr((string) preg_replace('#[^a-z0-9]+#', '-', strtolower(pathinfo($rel, PATHINFO_FILENAME))), 0, 40);
        $dir  = self::DIR . '/' . substr($hash, 0, 2);

        if ($type === IMAGETYPE_GIF) {
            if ($entry['s'] < $this->gifMinBytes || !self::animated($path)) {
                return $entry;
            }
            $img = @imagecreatefromgif($path);
            if (!$img) {
                $entry['x'] = 'fail';

                return $entry;
            }
            $img           = self::trueColor($img, $w, $h, true);
            $entry['f']    = $this->copies($img, $w, $h, $dir, $hash . '-' . $name . '-f', [480], 0);
            $entry['x']    = $entry['f'] ? 'ok' : 'fail';

            return $entry;
        }
        if (($type !== IMAGETYPE_JPEG && $type !== IMAGETYPE_PNG) || $w < self::MIN_WIDTH || $entry['s'] < self::MIN_BYTES) {
            return $entry;
        }
        // memory: about 5 bytes a pixel for the picture and as much for the largest copy
        self::raiseMemory($w * $h * 10 + 32 * 1024 * 1024);
        $img = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (!$img) {
            $entry['x'] = 'fail';

            return $entry;
        }
        $img        = self::trueColor($img, $w, $h, $type === IMAGETYPE_PNG);
        $entry['v'] = $this->copies($img, $w, $h, $dir, $hash . '-' . $name, self::WIDTHS, $entry['s']);
        $entry['x'] = $entry['v'] ? 'ok' : 'skip';

        return $entry;
    }

    /**
     * WebP copies in the given widths (narrower than the picture) and in its own width (when not
     * wider than MAX_FULL). A copy at the full width that is not lighter than the original ($bytes,
     * 0 = no limit) is dropped: the original serves better then.
     *
     * @return array<int, string> width => relative path
     */
    private function copies(\GdImage $img, int $w, int $h, string $dir, string $base, array $widths, int $bytes): array
    {
        if (!is_dir($this->root . '/' . $dir) && !@mkdir($this->root . '/' . $dir, 0755, true) && !is_dir($this->root . '/' . $dir)) {
            return [];
        }
        $out  = [];
        $list = array_values(array_filter($widths, fn ($x) => $x < $w));
        if ($w <= self::MAX_FULL) {
            $list[] = $w;
        }
        foreach ($list as $width) {
            $rel  = $dir . '/' . $base . '-' . $width . '.webp';
            $file = $this->root . '/' . $rel;
            $copy = $width === $w ? $img : @imagescale($img, $width, max(1, (int) round($h * $width / $w)), IMG_BICUBIC);
            if (!$copy) {
                continue;
            }
            if ($copy !== $img) {
                imagealphablending($copy, false);
                imagesavealpha($copy, true);
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            $ok  = @imagewebp($copy, $tmp, self::QUALITY) && @filesize($tmp) > 0;
            clearstatcache(true, $tmp);
            if (!$ok || ($width === $w && $bytes > 0 && (int) @filesize($tmp) >= $bytes)) {
                @unlink($tmp);
                continue;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                continue;
            }
            $out[$width] = $rel;
        }

        return $out;
    }

    // ---------------------------------------------------------------- figures and clean-up

    /** @return array{ok: int, pending: int, skipped: int, failed: int, files: int, bytes: int} */
    public function stats(): array
    {
        $counts = ['ok' => 0, 'skip' => 0, 'fail' => 0];
        foreach ($this->manifest as $e) {
            $counts[$e['x'] ?? 'skip'] = ($counts[$e['x'] ?? 'skip'] ?? 0) + 1;
        }
        [$files, $bytes] = [0, 0];
        $dir = $this->root . '/' . self::DIR;
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.webp')) {
                    $files++;
                    $bytes += $f->getSize();
                }
            }
        }

        return ['ok' => $counts['ok'], 'pending' => \count($this->queue), 'skipped' => $counts['skip'], 'failed' => $counts['fail'],
            'files' => $files, 'bytes' => $bytes];
    }

    /** Removes every copy, the manifest and the queue (the pages fall back to the originals). */
    public function clear(): void
    {
        $dir = $this->root . '/' . self::DIR;
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }
        @unlink($this->manifestFile());
        @unlink($this->queueFile());
        $this->manifest = [];
        $this->queue    = [];
    }

    // ---------------------------------------------------------------- helpers

    /** A GIF with more than one frame (more than one image descriptor after a graphic control block). */
    public static function animated(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $frames = 0;
        $tail   = '';
        while (!feof($fh) && $frames < 2) {
            $chunk  = $tail . fread($fh, 1024 * 100);
            $frames += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);
            $tail   = substr($chunk, -20);
        }
        fclose($fh);

        return $frames > 1;
    }

    /** The path of a local picture relative to the site root, or null. */
    public function relative(string $url): ?string
    {
        $path = ImageSize::pathOf(html_entity_decode($url, ENT_QUOTES), $this->root, $this->siteBase, $this->sitePath);

        return $path === null ? null : ltrim(substr($path, \strlen($this->root)), '/');
    }

    private function url(string $rel): string
    {
        return $this->sitePath . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    /** @param array<int|string, string> $copies */
    private function srcset(array $copies): string
    {
        ksort($copies, SORT_NUMERIC);
        $parts = [];
        foreach ($copies as $width => $rel) {
            $parts[] = $this->url($rel) . ' ' . (int) $width . 'w';
        }

        return implode(', ', $parts);
    }

    private static function trueColor(\GdImage $img, int $w, int $h, bool $alpha): \GdImage
    {
        if (imageistruecolor($img)) {
            if ($alpha) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            return $img;
        }
        $true = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagealphablending($true, false);
            imagesavealpha($true, true);
            imagefill($true, 0, 0, imagecolorallocatealpha($true, 0, 0, 0, 127));
            imagealphablending($true, true);
        }
        imagecopy($true, $img, 0, 0, 0, 0, $w, $h);
        if ($alpha) {
            imagealphablending($true, false);
            imagesavealpha($true, true);
        }

        return $true;
    }

    private static function raiseMemory(int $bytes): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') {
            return;
        }
        $units = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024];
        $value = (int) $limit * ($units[strtolower(substr(trim($limit), -1))] ?? 1);
        $need  = memory_get_usage(true) + $bytes;
        if ($value > 0 && $value < $need) {
            @ini_set('memory_limit', (string) (int) ceil($need / 1048576) . 'M');
        }
    }

    private function manifestFile(): string
    {
        return $this->cacheDir . '/mertools-images.json';
    }

    private function queueFile(): string
    {
        return $this->cacheDir . '/mertools-images-queue.json';
    }

    private static function readJson(string $file): array
    {
        $data = is_file($file) ? @json_decode((string) @file_get_contents($file), true) : null;

        return \is_array($data) ? $data : [];
    }

    private static function writeJson(string $file, array $data): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $file);
        }
    }
}
