<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Images in the size of the screen and a lighter format, made by PHP's GD on the site itself:
 *
 *  - every JPEG and PNG of this site shown on a page to a guest gets WebP copies in a few widths
 *    (480 … 1920 px, never wider than the picture, turned upright as its EXIF data says); the page
 *    offers them in srcset and the browser takes the one that fits its screen, the original stays the
 *    fallback in src. Pictures in a folder the web server guards (.htaccess) get none;
 *  - a heavy animated GIF gets its first frame as a small WebP: shown at once, the animation is
 *    loaded after the page (see Speedup::images()).
 *
 * Nothing is made while a visitor waits: a page only notes the pictures it lacks (a queue), and
 * the copies are made after the response has gone (onAfterRespond), a few seconds of work at a
 * time. The copies live in media/mertools-img (the originals are never changed); a changed
 * original gets new copies under a new name, so browsers and caches never keep an old one.
 *
 * What was made is noted in a manifest kept in 256 parts by the path of the picture (a page reads
 * only the parts of its own pictures, the work writes only the parts it changed). Once a day the
 * entries of pictures that are gone are dropped, and copies no entry names any more (the older
 * copies of a changed picture, those of a removed one) are removed a week after (sweep()).
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

    /** the entries a part of the manifest keeps at most (about 40 000 pictures in all) */
    private const MAX_PART = 160;

    /** a copy no entry names is removed this long after it was first found unused (pages kept before may show it) */
    public const GRACE = 604800;

    /** @var array<string, array<string, array>> part => relative path => entry (the parts read so far) */
    private array $parts = [];

    /** @var array<string, true> parts to write */
    private array $dirtyParts = [];

    /** the manifest of one file (before 1.11) while a worker holds the lock its move into parts needs */
    private ?array $legacy = null;

    /** @var array<string, true> relative paths waiting */
    private array $queue = [];

    private bool $dirtyQueue = false;

    /** @var array<string, true> paths this request queued (merged with the file on save, see save()) */
    private array $added = [];

    /** inside work(): the queue in memory is the truth (removals), not the file */
    private bool $working = false;

    /** @var array<string, string> the newer formats wanted, and how this server makes them (ImageFormats::encoders()) */
    private array $via = [];

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
        $this->queue    = array_fill_keys(array_keys(self::readJson($this->queueFile())), true);
        if (is_file($this->legacyFile())) {
            $this->migrate();
        }
    }

    /**
     * The formats newer than WebP the pages offer and the work makes (format => way, from
     * ImageFormats::encoders()); none by default.
     */
    public function formats(array $via): self
    {
        $this->via = array_intersect_key(array_replace(ImageFormats::TYPES, $via), $via);

        return $this;
    }

    /** GD with WebP: the copies can be made on this server. */
    public static function supported(): bool
    {
        return \function_exists('imagewebp') && \function_exists('imagecreatetruecolor') && (imagetypes() & IMG_WEBP);
    }

    // ---------------------------------------------------------------- pages

    /**
     * What a page may use for the picture at $url: ['srcset' => "url 480w, …", 'sources' => [type =>
     * srcset] of the newer formats (formats()), 'frame' => [srcset of the first frame], 'anim' => url of
     * the animation] — or null (not local, not a JPEG/PNG/GIF, not ready yet: then the picture is
     * queued, if $queue: a page only a logged-in user sees does not queue its pictures, their copies
     * would be public). A picture lacking only a newer format is offered without it and queued — when
     * $newer: a picture shown where it cannot be in a <picture> (a background, a link) does not ask for them.
     */
    public function lookup(string $url, bool $queue = true, bool $newer = true): ?array
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
        $entry = $this->entry($rel);
        if (!$entry || !$this->fresh($rel, $entry, $stat)) {
            if ($queue) {
                $this->enqueue($rel);
            }

            return null;
        }
        if (($entry['x'] ?? '') !== 'ok') {
            return null;
        }
        $out = [];
        if (!empty($entry['v'])) {
            $out['srcset'] = $this->srcset($entry['v']);
            $sources       = [];
            foreach ($this->via as $format => $way) {
                $copies = $entry[ImageFormats::KEYS[$format]] ?? null;
                if (\is_array($copies) && $copies && $this->filesThere($copies)) {
                    $sources[ImageFormats::TYPES[$format]] = $this->srcset($copies);
                }
            }
            if ($sources) {
                $out['sources'] = $sources;
            }
            if ($queue && $newer && $this->missing($entry)) {
                $this->enqueue($rel);
            }
        }
        if (!empty($entry['f'])) {
            $out['frame'] = $this->srcset($entry['f']);
            // the animation itself: a WebP put next to the GIF by the site owner is lighter, else the GIF
            $webp       = preg_replace('#\.gif$#i', '.webp', $rel);
            $out['anim'] = $this->url(is_file($this->root . '/' . $webp) ? $webp : $rel);
        }

        return $out ?: null;
    }

    /**
     * Is the entry the one of the picture as it is now? Made again: a changed picture, a WebP copy that
     * is gone (removed by hand, a site copied without media/mertools-img), and the copies of a photo
     * stored on its side made before 1.7.0 (sideways; their entry has no "o").
     */
    private function fresh(string $rel, array $entry, array $stat): bool
    {
        return ($entry['m'] ?? null) === (int) $stat['mtime'] && ($entry['s'] ?? null) === (int) $stat['size']
            && (($entry['x'] ?? '') !== 'ok' || ($this->copiesThere($entry)
                && (isset($entry['o']) || !preg_match('#\.jpe?g$#i', $rel) || ImageSize::orientation($this->root . '/' . $rel) <= 1)));
    }

    /** The entry of a picture whose WebP copies are made and there (only newer formats may be missing), or null. */
    private function current(string $rel): ?array
    {
        $entry = $this->entry($rel);
        $stat  = @stat($this->root . '/' . $rel);

        return $entry && $stat !== false && ($entry['x'] ?? '') === 'ok' && $this->fresh($rel, $entry, $stat) ? $entry : null;
    }

    /**
     * The newer formats (formats()) an entry with WebP copies still lacks: never tried, or a copy gone.
     * A format tried without a lighter result ([]) is not tried again for the same picture.
     *
     * @return string[]
     */
    private function missing(array $entry): array
    {
        if (($entry['x'] ?? '') !== 'ok' || empty($entry['v'])) {
            return [];
        }
        $out = [];
        foreach ($this->via as $format => $way) {
            $copies = $entry[ImageFormats::KEYS[$format]] ?? null;
            if (!\is_array($copies) || ($copies && !$this->filesThere($copies))) {
                $out[] = $format;
            }
        }

        return $out;
    }

    private function enqueue(string $rel): void
    {
        if (!isset($this->queue[$rel]) && \count($this->queue) < self::MAX_QUEUE) {
            $this->queue[$rel] = true;
            $this->added[$rel] = true;
            $this->dirtyQueue  = true;
        }
    }

    /** Are all the WebP files of the copies of an entry there? */
    private function copiesThere(array $entry): bool
    {
        return $this->filesThere(array_merge(array_values((array) ($entry['v'] ?? [])), array_values((array) ($entry['f'] ?? []))));
    }

    /** @param array<int|string, string> $copies relative paths */
    private function filesThere(array $copies): bool
    {
        foreach ($copies as $copy) {
            if (!is_file($this->root . '/' . $copy)) {
                return false;
            }
        }

        return true;
    }

    /** Pictures waiting for their copies. */
    public function pending(): int
    {
        return \count($this->queue);
    }

    /**
     * A page adds its new pictures to the queue file as it is now (the worker of another request
     * may have taken pictures out meanwhile; writing the page's older copy would put them back and
     * make them again); the worker itself writes the queue as it has it. $manifest false: the queue
     * only (the worker writes the parts of the manifest less often, see work()).
     */
    public function save(bool $manifest = true): void
    {
        if ($this->dirtyQueue) {
            if ($this->working) {
                $queue = $this->queue;
            } else {
                $queue = array_fill_keys(array_keys(self::readJson($this->queueFile())), true) + $this->added;
                if (\count($queue) > self::MAX_QUEUE) {
                    $queue = \array_slice($queue, 0, self::MAX_QUEUE, true);
                }
            }
            self::writeJson($this->queueFile(), array_fill_keys(array_keys($queue), 1));
            $this->added      = [];
            $this->dirtyQueue = false;
        }
        if ($manifest && $this->dirtyParts) {
            foreach (array_keys($this->dirtyParts) as $part) {
                self::writeJson($this->partFile($part), self::capped($this->parts[$part] ?? []));
            }
            $this->dirtyParts = [];
        }
    }

    // ---------------------------------------------------------------- making the copies

    /**
     * Makes the copies of queued pictures for at most $seconds (a picture begun is finished). $extra:
     * also the copies in the newer formats (formats()) — slow (AVIF: seconds for a large picture), so
     * never while a visitor waits; one begun after $seconds is left for a later round.
     *
     * @return int pictures that got new copies
     */
    public function work(float $seconds, int $maxPixels = self::MAX_PIXELS, bool $extra = true): int
    {
        if (!$this->queue || !self::supported()) {
            return 0;
        }
        $lock = @fopen($this->cacheDir . '/mertools-img.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        // another request may have finished some of them meanwhile: the parts are read again
        if (is_file($this->legacyFile())) {
            $this->migrateLocked();
        }
        $this->parts      = [];
        $this->dirtyParts = [];
        $this->queue      = array_fill_keys(array_keys(self::readJson($this->queueFile())), true) + $this->queue;
        $this->added    = [];
        $this->working  = true;
        $start          = microtime(true);
        $saved          = $start;
        $made           = 0;
        try {
            foreach (array_keys($this->queue) as $rel) {
                if (microtime(true) - $start > $seconds) {
                    break;
                }
                // taken out of the queue before the work: a picture that makes PHP die (memory) must
                // not come first again with every page, blocking the rest of the queue
                unset($this->queue[$rel]);
                $this->dirtyQueue = true;
                $this->save(false);
                $rel   = (string) $rel;
                $entry = $this->current($rel);
                $new   = false;
                if ($entry === null) {
                    $entry = $this->make($rel, $maxPixels);
                    if ($entry !== null) {
                        $this->put($rel, $entry);
                        $new = $entry['x'] === 'ok';
                    }
                }
                $missing = $entry !== null && $extra && microtime(true) - $start <= $seconds ? $this->missing($entry) : [];
                if ($missing) {
                    // noted as tried before the work: a picture whose encoding makes PHP die (memory, time)
                    // is not tried again with every page
                    foreach ($missing as $format) {
                        $entry[ImageFormats::KEYS[$format]] = [];
                    }
                    $this->put($rel, $entry);
                    $this->save();
                    $entry = $this->extras($rel, $entry, $missing);
                    $this->put($rel, $entry);
                    foreach ($missing as $format) {
                        $new = $new || !empty($entry[ImageFormats::KEYS[$format]]);
                    }
                }
                $made += $new ? 1 : 0;
                // the parts of the manifest at most once a second and at the end, not after every
                // picture; a round cut short loses a second of work at most (made again)
                $flush = microtime(true) - $saved >= 1.0;
                $this->save($flush);
                $saved = $flush ? microtime(true) : $saved;
            }
            $this->save();
        } finally {
            $this->working = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $made;
    }

    /** The copies of one picture: the manifest entry, or null when the file is gone. */
    public function make(string $rel, int $maxPixels = self::MAX_PIXELS): ?array
    {
        $path = $this->root . '/' . $rel;
        $stat = @stat($path);
        $info = $stat ? @getimagesize($path) : false;
        if ($stat === false) {
            return null;
        }
        // o: the EXIF orientation the copies were made upright from (1.7.0)
        $entry = ['m' => (int) $stat['mtime'], 's' => (int) $stat['size'], 'x' => 'skip', 'v' => [], 'f' => [], 't' => time(), 'o' => 1];
        if (!\is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS || self::guarded($this->root, $rel)) {
            return $entry;
        }
        if ($info[0] * $info[1] > $maxPixels) {
            // too large for this moment (a visitor waits): left in the queue for a later, free moment
            $this->queue[$rel] = true;
            $this->dirtyQueue  = true;

            return null;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        // a photo of a camera or phone may be stored on its side, with the way to turn it in its EXIF
        // data (browsers turn it): the copies are made upright, under other names than copies made before
        $turn       = $type === IMAGETYPE_JPEG ? ImageSize::orientation($path) : 1;
        $entry['o'] = $turn;
        // memory: about 5 bytes a pixel for the picture and as much for the largest copy (and the turned
        // picture); a limit that cannot be raised enough makes the picture a skip instead of a fatal error
        if (!self::raiseMemory($w * $h * ($turn > 1 ? 15 : 10) + 32 * 1024 * 1024)) {
            return $entry;
        }
        $hash = substr(md5($rel . '|' . $entry['m'] . '|' . $entry['s'] . ($turn > 1 ? '|' . $turn : '')), 0, 10);
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
        if (($type !== IMAGETYPE_JPEG && $type !== IMAGETYPE_PNG) || ($turn >= 5 ? $h : $w) < self::MIN_WIDTH || $entry['s'] < self::MIN_BYTES) {
            return $entry;
        }
        $img = self::open($path, $type, $w, $h, $turn);
        if (!$img) {
            $entry['x'] = 'fail';

            return $entry;
        }
        [$w, $h]    = [imagesx($img), imagesy($img)];
        $entry['v'] = $this->copies($img, $w, $h, $dir, $hash . '-' . $name, self::WIDTHS, $entry['s']);
        $entry['x'] = $entry['v'] ? 'ok' : 'skip';

        return $entry;
    }

    /**
     * A JPEG or PNG read as true colour (its transparency kept) and turned upright as its EXIF data
     * says (a copy on its side would be worse than none): null when GD cannot do it.
     */
    private static function open(string $path, int $type, int $w, int $h, int $turn): ?\GdImage
    {
        $img = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (!$img) {
            return null;
        }
        $img = self::trueColor($img, $w, $h, $type === IMAGETYPE_PNG);

        return $turn > 1 ? self::upright($img, $turn) : $img;
    }

    /**
     * The copies in the newer formats $formats of a picture with WebP copies: in the widths of its WebP
     * copies, named as they are. A format is kept only when its copies together are lighter than the
     * WebP ones (the browser takes the newer format whenever it can show it); else, and when it could
     * not be written, the entry notes it as tried ([]).
     */
    private function extras(string $rel, array $entry, array $formats): array
    {
        $path = $this->root . '/' . $rel;
        $info = @getimagesize($path);
        if (!\is_array($info) || !\in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return $entry;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        $turn           = (int) ($entry['o'] ?? 1);
        if (!self::raiseMemory($w * $h * ($turn > 1 ? 15 : 10) + 32 * 1024 * 1024)) {
            return $entry;
        }
        $img = self::open($path, $type, $w, $h, $turn);
        if (!$img) {
            return $entry;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $alpha   = $type === IMAGETYPE_PNG && ImageFormats::transparent($path);
        $this->mimeTypes();
        $webp    = 0;
        foreach ($entry['v'] as $copy) {
            $webp += (int) @filesize($this->root . '/' . $copy);
        }
        foreach ($formats as $format) {
            $copies = [];
            $bytes  = 0;
            // a way that loses transparency: the WebP copies serve such a picture
            if ($alpha && ImageFormats::opaqueOnly($this->via[$format])) {
                $entry[ImageFormats::KEYS[$format]] = [];
                continue;
            }
            foreach ($entry['v'] as $width => $name) {
                $width = (int) $width;
                $out   = (string) preg_replace('#\.webp$#', '.' . $format, (string) $name);
                $copy  = $out === $name ? null : ($width >= $w ? $img : @imagescale($img, $width, max(1, (int) round($h * $width / $w)), IMG_BICUBIC));
                if ($copy && $copy !== $img) {
                    imagealphablending($copy, false);
                    imagesavealpha($copy, true);
                }
                if (!$copy || !ImageFormats::write($copy, $format, $this->via[$format], $this->root . '/' . $out, $alpha)) {
                    $bytes = PHP_INT_MAX;
                    break;
                }
                $copies[$width] = $out;
                clearstatcache(true, $this->root . '/' . $out);
                $bytes += (int) @filesize($this->root . '/' . $out);
            }
            if ($bytes >= $webp) {
                foreach ($copies as $made) {
                    @unlink($this->root . '/' . $made);
                }
                $copies = [];
            }
            $entry[ImageFormats::KEYS[$format]] = $copies;
        }

        return $entry;
    }

    /**
     * Apache and LiteSpeed send the newer formats with their types: an older mime.types may not know
     * them, and with "nosniff" (MerTools' security headers) a wrong type can keep them from showing.
     */
    private function mimeTypes(): void
    {
        $file = $this->root . '/' . self::DIR . '/.htaccess';
        if (!is_file($file) && (is_dir(\dirname($file)) || @mkdir(\dirname($file), 0755, true))) {
            @file_put_contents($file, "<IfModule mod_mime.c>\nAddType image/avif .avif\nAddType image/jxl .jxl\n</IfModule>\n");
        }
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

    /**
     * @return array{ok: int, pending: int, skipped: int, failed: int, files: int, bytes: int, unused: int, unusedBytes: int,
     *         formats: array<string, array{files: int, bytes: int}>}
     *         unused: copies no entry names any more, removed a week after they were found so (sweep());
     *         formats: the copies of each format (webp, avif, jxl)
     */
    public function stats(): array
    {
        $counts = ['ok' => 0, 'skip' => 0, 'fail' => 0];
        $used   = [];
        foreach ($this->entries() as $e) {
            $counts[$e['x'] ?? 'skip'] = ($counts[$e['x'] ?? 'skip'] ?? 0) + 1;
            foreach (self::copiesOf($e) as $copy) {
                $used[$copy] = true;
            }
        }
        [$files, $bytes, $unused, $unusedBytes] = [0, 0, 0, 0];
        $formats = array_fill_keys(self::EXTENSIONS, ['files' => 0, 'bytes' => 0]);
        $dir     = $this->root . '/' . self::DIR;
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $format = $f->isFile() ? self::copyFormat($f->getFilename()) : '';
                if ($format !== '') {
                    $files++;
                    $bytes += $f->getSize();
                    $formats[$format]['files']++;
                    $formats[$format]['bytes'] += $f->getSize();
                    if (!isset($used[$this->relativeCopy($f->getPathname())])) {
                        $unused++;
                        $unusedBytes += $f->getSize();
                    }
                }
            }
        }

        return ['ok' => $counts['ok'], 'pending' => \count($this->queue), 'skipped' => $counts['skip'], 'failed' => $counts['fail'],
            'files' => $files, 'bytes' => $bytes, 'unused' => $unused, 'unusedBytes' => $unusedBytes, 'formats' => $formats];
    }

    /**
     * The daily tidying up, under the lock of the work: the entries of pictures that are gone are
     * dropped, so are the copies of the newer formats not in formats() (switched off), and a copy no
     * entry names any more (an older copy of a changed picture, a copy of a removed one) is removed once
     * it has been unused for GRACE: a page kept before (by MerTools, a proxy or a browser) may still show
     * it. Copies a worker left half written go after an hour.
     *
     * @return array{dropped: int, removed: int, unused: int} entries dropped, copies removed, copies waiting
     */
    public function sweep(?int $now = null): array
    {
        $now  ??= time();
        $done = ['dropped' => 0, 'removed' => 0, 'unused' => 0];
        $lock = @fopen($this->cacheDir . '/mertools-img.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }

            return $done;
        }
        try {
            if (is_file($this->legacyFile())) {
                $this->migrateLocked();
            }
            $this->parts      = [];
            $this->dirtyParts = [];
            $used             = [];
            foreach ($this->partFiles() as $part => $file) {
                $entries = self::readJson($file);
                $kept    = [];
                $changed = false;
                foreach ($entries as $rel => $entry) {
                    if (!\is_array($entry) || !is_file($this->root . '/' . $rel)) {
                        $done['dropped']++;
                        continue;
                    }
                    // a newer format switched off (or no longer made here): its copies become unused, removed a week later
                    foreach (ImageFormats::KEYS as $format => $key) {
                        if (isset($entry[$key]) && !isset($this->via[$format])) {
                            unset($entry[$key]);
                            $changed = true;
                        }
                    }
                    $kept[$rel] = $entry;
                    foreach (self::copiesOf($entry) as $copy) {
                        $used[$copy] = true;
                    }
                }
                if ($changed || \count($kept) !== \count($entries)) {
                    self::writeJson($file, $kept);
                }
            }
            $seen = self::readJson($this->unusedFile());
            $next = [];
            $dir  = $this->root . '/' . self::DIR;
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                    $path = $f->getPathname();
                    if ($f->isDir()) {
                        // only an empty folder goes
                        @rmdir($path);
                        continue;
                    }
                    $name = $f->getFilename();
                    if (str_ends_with($name, '.tmp')) {
                        if ($f->getMTime() < $now - 3600) {
                            @unlink($path);
                        }
                        continue;
                    }
                    $rel = $this->relativeCopy($path);
                    if (self::copyFormat($name) === '' || isset($used[$rel])) {
                        continue;
                    }
                    $since = (int) ($seen[$rel] ?? $now);
                    if ($since <= $now - self::GRACE && @unlink($path)) {
                        $done['removed']++;
                        continue;
                    }
                    $next[$rel] = $since;
                }
            }
            self::writeJson($this->unusedFile(), $next);
            $done['unused'] = \count($next);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $done;
    }

    /**
     * Removes every copy, the manifest and the queue (the pages fall back to the originals). Waits for
     * a worker making copies right now (its lock): it would write a manifest naming removed files.
     */
    public function clear(): void
    {
        $lock = @fopen($this->cacheDir . '/mertools-img.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $dir = $this->root . '/' . self::DIR;
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
            }
            foreach ($this->partFiles() as $file) {
                @unlink($file);
            }
            @unlink($this->legacyFile());
            @unlink($this->unusedFile());
            @unlink($this->queueFile());
            $this->parts      = [];
            $this->dirtyParts = [];
            $this->legacy     = null;
            $this->queue      = [];
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
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

    /** the folders whose pictures get public copies (a copy would make a protected folder's picture public) */
    public const FOLDERS = ['images', 'media', 'templates', 'modules', 'components', 'plugins', 'uploads', 'upload', 'files'];

    /**
     * The path of a local picture relative to the site root, or null: only pictures of the public
     * asset folders (FOLDERS), in no folder or file whose name starts with a dot.
     */
    public function relative(string $url): ?string
    {
        $path = ImageSize::pathOf(html_entity_decode($url, ENT_QUOTES), $this->root, $this->siteBase, $this->sitePath);
        if ($path === null) {
            return null;
        }
        $rel = ltrim(substr($path, \strlen($this->root)), '/');

        // a name that is not UTF-8 (an old upload, "%E9" in the address) could not be kept in the manifest
        return self::allowed($rel) && preg_match('//u', $rel) ? $rel : null;
    }

    /**
     * Is the picture in a folder the web server guards: an Apache .htaccess in its folder or one above
     * it (below the site root) that asks for a password or limits who may see the files? A copy would
     * be public, so none is made. Rules for some files only (<Files…>, e.g. "no PHP here") do not count.
     */
    public static function guarded(string $root, string $rel): bool
    {
        $dir = \dirname(str_replace('\\', '/', $rel));
        while ($dir !== '.' && $dir !== '' && $dir !== '/') {
            $file = rtrim($root, '/') . '/' . $dir . '/.htaccess';
            if (is_file($file)) {
                $rules = (string) preg_replace('#<Files(?:Match)?\b.*?</Files(?:Match)?\s*>#is', '', (string) @file_get_contents($file, false, null, 0, 65536));
                if (preg_match('/^\s*(?:Auth(?:Type|Name|UserFile|GroupFile|BasicProvider)\b|Require\s+(?:valid-user|user|group|ip|host|all\s+denied)\b|Deny\s+from\b)/im', $rules)) {
                    return true;
                }
            }
            $dir = \dirname($dir);
        }

        return false;
    }

    /** Is this relative path in one of the public asset folders, with no hidden part? */
    public static function allowed(string $rel): bool
    {
        $parts = explode('/', str_replace('\\', '/', $rel));
        if (\count($parts) < 2 || !\in_array(strtolower($parts[0]), self::FOLDERS, true)) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === '' || $part[0] === '.') {
                return false;
            }
        }

        return true;
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

    /**
     * The picture as its EXIF orientation (2–8, ImageSize::orientation()) says it is shown: mirrored
     * (2, 4), half a turn (3), a quarter turn clockwise (6) or anticlockwise (8), or a quarter turn and
     * mirrored (5, 7). Null when GD could not do it.
     */
    private static function upright(\GdImage $img, int $orientation): ?\GdImage
    {
        // imagerotate() turns anticlockwise
        $angle = [3 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($angle) {
            $turned = @imagerotate($img, $angle, 0);
            if (!$turned) {
                return null;
            }
            $img = $turned;
        }
        if (\in_array($orientation, [2, 5, 7], true) && !@imageflip($img, IMG_FLIP_HORIZONTAL)) {
            return null;
        }
        if ($orientation === 4 && !@imageflip($img, IMG_FLIP_VERTICAL)) {
            return null;
        }

        return $img;
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

    /** Enough memory for $bytes more: raised when needed; false when the limit cannot be raised that far. */
    private static function raiseMemory(int $bytes): bool
    {
        $need = memory_get_usage(true) + $bytes;
        if (self::limitBytes() < 0 || self::limitBytes() >= $need) {
            return true;
        }
        @ini_set('memory_limit', (string) (int) ceil($need / 1048576) . 'M');

        return self::limitBytes() < 0 || self::limitBytes() >= $need;
    }

    /** memory_limit in bytes (-1: no limit). */
    private static function limitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1' || $limit === '') {
            return -1;
        }
        $units = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024];

        return (int) $limit * ($units[strtolower(substr($limit, -1))] ?? 1);
    }

    // ---------------------------------------------------------------- the manifest

    /** The entry of a picture (its part of the manifest read when first needed), or null. */
    private function entry(string $rel): ?array
    {
        $part = self::partOf($rel);
        $this->parts[$part] ??= self::readJson($this->partFile($part));
        $entry = $this->parts[$part][$rel] ?? $this->legacy[$rel] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    private function put(string $rel, array $entry): void
    {
        $part = self::partOf($rel);
        $this->parts[$part] ??= self::readJson($this->partFile($part));
        // a new entry last: when a part is full, the oldest go first
        unset($this->parts[$part][$rel]);
        $this->parts[$part][$rel] = $entry;
        $this->dirtyParts[$part]  = true;
    }

    /** @return array<string, array> every entry (the figures, the tests) */
    public function entries(): array
    {
        $all = [];
        foreach ($this->partFiles() as $file) {
            $all += self::readJson($file);
        }

        return \array_filter($all + ($this->legacy ?? []), 'is_array');
    }

    /** The part of the manifest of a picture: 00 … ff. */
    private static function partOf(string $rel): string
    {
        return substr(md5($rel), 0, 2);
    }

    /**
     * Files of the cache folder itself, not a folder of their own: Joomla's Clear Cache empties every
     * folder of the cache as a group of its own (the manifest would be lost and every copy made again).
     */
    private function partFile(string $part): string
    {
        return $this->cacheDir . '/mertools-images-' . $part . '.json';
    }

    /** @return array<string, string> part => file, of the parts written so far */
    private function partFiles(): array
    {
        $files = [];
        foreach (glob($this->cacheDir . '/mertools-images-??.json') ?: [] as $file) {
            if (preg_match('#mertools-images-([0-9a-f]{2})\.json$#', $file, $m)) {
                $files[$m[1]] = $file;
            }
        }

        return $files;
    }

    /** At most MAX_PART entries, the newest. */
    private static function capped(array $entries): array
    {
        return \count($entries) > self::MAX_PART ? \array_slice($entries, -self::MAX_PART, null, true) : $entries;
    }

    /** @return string[] the files of the copies of an entry (every format), relative to the site root */
    private static function copiesOf(array $entry): array
    {
        $all = [];
        foreach (array_merge(['v', 'f'], array_values(ImageFormats::KEYS)) as $key) {
            $all = array_merge($all, array_values((array) ($entry[$key] ?? [])));
        }

        return array_map('strval', $all);
    }

    /** the file types of the copies: what the figures count and the tidying up may remove */
    public const EXTENSIONS = ['webp', 'avif', 'jxl'];

    /** The format of a copy by its name ('webp', 'avif', 'jxl'), '' for another file. */
    private static function copyFormat(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return \in_array($ext, self::EXTENSIONS, true) ? $ext : '';
    }

    /** A file of the copies folder as the manifest names it (media/mertools-img/ab/….webp, .avif, .jxl). */
    private function relativeCopy(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, \strlen($this->root))), '/');
    }

    /**
     * The manifest of one file (MerTools before 1.11) moved into its parts, once, under the lock of the
     * work; while a worker holds that lock, this request reads the old file as it is.
     */
    private function migrate(): void
    {
        $lock = @fopen($this->cacheDir . '/mertools-img.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            $this->legacy = self::readJson($this->legacyFile());

            return;
        }
        try {
            $this->migrateLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function migrateLocked(): void
    {
        $old   = self::readJson($this->legacyFile());
        $parts = [];
        foreach ($old as $rel => $entry) {
            if (\is_array($entry)) {
                $parts[self::partOf((string) $rel)][(string) $rel] = $entry;
            }
        }
        foreach ($parts as $part => $entries) {
            // a part written already (a move cut short, or the work since) is newer than the old file
            if (!self::writeJson($this->partFile($part), self::capped(self::readJson($this->partFile($part)) + $entries))) {
                // the old file stays, read as it is, until the parts can be written
                $this->legacy = $old;

                return;
            }
        }
        @unlink($this->legacyFile());
        $this->legacy = null;
        $this->parts  = [];
    }

    private function legacyFile(): string
    {
        return $this->cacheDir . '/mertools-images.json';
    }

    private function unusedFile(): string
    {
        return $this->cacheDir . '/mertools-images-unused.json';
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

    private static function writeJson(string $file, array $data): bool
    {
        // what cannot be written as JSON never replaces the file (an empty one would lose every entry)
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $file)) {
            return true;
        }
        @unlink($tmp);

        return false;
    }
}
