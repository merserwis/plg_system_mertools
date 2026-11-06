<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Lighter originals of JPEG and PNG pictures, made on the site's own server (the pictures never leave
 * it), the way TinyJPG / TinyPNG do it:
 *
 *  - a JPEG is saved again at the quality chosen (progressive), without its EXIF, XMP, IPTC and comment
 *    data (camera, GPS); its colour profile (ICC) is kept, a photo stored on its side is turned upright.
 *    With jpegoptim on the server it does the work: it never saves a picture again at a higher quality
 *    than it has (then it only optimises it without loss);
 *  - a PNG gets a palette of at most 256 colours (with transparency), as pngquant does; with pngquant on
 *    the server it does the work, else PHP's GD, whose palette is checked against the picture (a
 *    palette that would change it too much is not used: the PNG is then only saved again without loss).
 *    Its colour data (iCCP, sRGB, gAMA, cHRM) is kept.
 *
 * A result is used only when it is lighter by the share chosen; the picture gets a mark (a JPEG
 * comment, a PNG text "Comment: MerTools …"), so it is never compressed a second time — also when the
 * list of what was done is lost. Animated PNGs, CMYK JPEGs and very large pictures are left alone.
 *
 * With a frame (an upload, Images → Size on upload) a picture larger than it is made smaller to fit
 * in it first (its proportions kept, never made larger; a photo on its side counted as it is shown),
 * by PHP's GD, then saved with the same settings — the smaller picture is used also when the saving
 * is less than the share chosen, and also when it had the mark already.
 *
 * The class only makes the lighter file; what to compress, the copies of the originals and the
 * replacing of the files are in CompressStore.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ImageCompressor
{
    /** the start of the mark in a compressed picture */
    public const MARK = 'MerTools compressed';

    /** pictures lighter than this are not worth it */
    public const MIN_BYTES = 5000;

    /** heavier files are left alone (a PNG of 40 MB is no picture for a page) */
    public const MAX_BYTES = 41943040;

    /** larger pictures are left alone (GD would need too much memory) */
    public const MAX_PIXELS = 24000000;

    /** a picture made smaller to fit a frame may be larger (a photo of a phone): read once, written much smaller */
    public const MAX_RESIZE_PIXELS = 52000000;

    /** the points of a PNG compared with its palette version */
    private const SAMPLES = 20000;

    /** @var array{jpeg:int, png:int, min:int, engine:string} */
    private array $o;

    /** @var array<string, string>|null the programs found (name => path) */
    private static ?array $programs = null;

    /**
     * @param array $options jpeg: quality of a JPEG (40–100), png: quality of a PNG palette (0–100; 100 =
     *                       only without loss), min: the smallest saving in % (1–90), engine: "auto" (the
     *                       programs of the server when there are) or "php" (always PHP's GD)
     */
    public function __construct(array $options = [])
    {
        $this->o = [
            'jpeg'   => max(40, min(100, (int) ($options['jpeg'] ?? 82))),
            'png'    => max(0, min(100, (int) ($options['png'] ?? 70))),
            'min'    => max(1, min(90, (int) ($options['min'] ?? 10))),
            'engine' => ($options['engine'] ?? 'auto') === 'php' ? 'php' : 'auto',
        ];
    }

    /** The settings that decide the result, as a short text (a picture tried with other settings may be tried again). */
    public function signature(): string
    {
        return 'j' . $this->o['jpeg'] . 'p' . $this->o['png'] . 'm' . $this->o['min'] . $this->o['engine'];
    }

    /** PHP's GD with JPEG and PNG (without it nothing is compressed). */
    public static function gd(): bool
    {
        return \function_exists('imagecreatefromjpeg') && \function_exists('imagepng') && \function_exists('imagetruecolortopalette');
    }

    /** What this server can use: ['gd' => GD with JPEG and PNG, 'exec' => programs may run, 'jpegoptim' => path, 'pngquant' => path]. */
    public static function engines(): array
    {
        return [
            'gd'        => self::gd(),
            'exec'      => self::canExec(),
            'jpegoptim' => self::programs()['jpegoptim'] ?? '',
            'pngquant'  => self::programs()['pngquant'] ?? '',
        ];
    }

    /** May PHP run programs (exec() there and not disabled)? */
    public static function canExec(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $off = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return !\in_array('exec', $off, true);
    }

    /** The path of a program of the server MerTools can use (jpegoptim, pngquant, cjxl, avifenc), '' when none. */
    public static function program(string $name): string
    {
        return self::programs()[$name] ?? '';
    }

    /** @return array<string, string> the programs of the server MerTools uses, name => path */
    private static function programs(): array
    {
        if (self::$programs !== null) {
            return self::$programs;
        }
        self::$programs = [];
        if (!self::canExec()) {
            return self::$programs;
        }
        foreach (['jpegoptim', 'pngquant', 'cjxl', 'avifenc'] as $name) {
            $out  = [];
            $code = 1;
            @exec('command -v ' . $name . ' 2>/dev/null', $out, $code);
            $path = $code === 0 ? trim((string) ($out[0] ?? '')) : '';
            if ($path === '' || $path[0] !== '/') {
                foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) {
                    if (@is_executable($dir . $name)) {
                        $path = $dir . $name;
                        break;
                    }
                }
            }
            if ($path !== '' && $path[0] === '/') {
                self::$programs[$name] = $path;
            }
        }

        return self::$programs;
    }

    /** For the tests: the programs as they are now (another PATH) or none ([]). */
    public static function setPrograms(?array $programs): void
    {
        self::$programs = $programs;
    }

    /**
     * The size [width, height] a picture of $w × $h shown gets to fit in the frame $box [width, height]
     * (0: that side not limited), its proportions kept; null when it fits as it is.
     */
    public static function fit(int $w, int $h, array $box): ?array
    {
        $bw    = max(0, (int) ($box[0] ?? 0));
        $bh    = max(0, (int) ($box[1] ?? 0));
        $ratio = min($bw > 0 ? $bw / max(1, $w) : INF, $bh > 0 ? $bh / max(1, $h) : INF);
        if ($ratio >= 1 || $w < 1 || $h < 1) {
            return null;
        }
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        // rounding must not make a side larger than its limit
        $nw = $bw > 0 ? min($nw, $bw) : $nw;
        $nh = $bh > 0 ? min($nh, $bh) : $nh;

        return $nw === $w && $nh === $h ? null : [$nw, $nh];
    }

    /**
     * A lighter version of the picture $src written to $dest (a new file, $src stays as it is); with
     * $box [width, height] (0: that side not limited) a picture larger than it made smaller to fit
     * first; with $squeeze false a picture that fits the frame is left as it is (reason fits).
     *
     * @return array{status:string, reason:string, type:string, before:int, after:int, method:string, lossy:bool, file:bool, resized:int[]}
     *   status: ok (lighter by the share chosen, or made smaller), skip (not worth it or not for this
     *   picture), fail; reason (of a skip or fail): small, large, type, marked, animated, cmyk, memory,
     *   gain, read, write, fits; file: $dest has the result (also with reason gain: what it would have
     *   been, for a try); resized: [width, height, new width, new height] as shown, [] when not made smaller
     */
    public function compress(string $src, string $dest, ?array $box = null, bool $squeeze = true): array
    {
        $before = (int) @filesize($src);
        $res    = ['status' => 'skip', 'reason' => '', 'type' => '', 'before' => $before, 'after' => $before, 'method' => '', 'lossy' => false, 'file' => false,
            'resized' => []];
        $info   = @getimagesize($src);
        if (!\is_array($info)) {
            return ['reason' => is_file($src) ? 'type' : 'read', 'status' => is_file($src) ? 'skip' : 'fail'] + $res;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        $res['type']    = $type === IMAGETYPE_JPEG ? 'jpeg' : ($type === IMAGETYPE_PNG ? 'png' : '');
        if ($res['type'] === '') {
            return ['reason' => 'type'] + $res;
        }
        // the frame counts the picture as it is shown: a photo stored on its side has its sides swapped
        $turn = $type === IMAGETYPE_JPEG ? ImageSize::orientation($src) : 1;
        $fit  = $box ? ($turn >= 5 ? self::fit($h, $w, $box) : self::fit($w, $h, $box)) : null;
        if ($fit === null && !$squeeze) {
            return ['reason' => 'fits'] + $res;
        }
        if ($fit === null && $before < self::MIN_BYTES) {
            return ['reason' => 'small'] + $res;
        }
        if ($before > self::MAX_BYTES || $w * $h > ($fit ? self::MAX_RESIZE_PIXELS : self::MAX_PIXELS) || $w < 1 || $h < 1) {
            return ['reason' => 'large'] + $res;
        }
        if ($fit === null && self::marked($src)) {
            return ['reason' => 'marked'] + $res;
        }
        if ($type === IMAGETYPE_JPEG && (int) ($info['channels'] ?? 3) === 4) {
            // CMYK (print): GD would turn its colours wrong
            return ['reason' => 'cmyk'] + $res;
        }
        $bin = (string) @file_get_contents($src);
        if ($bin === '') {
            return ['status' => 'fail', 'reason' => 'read'] + $res;
        }
        if ($type === IMAGETYPE_PNG && self::pngAnimated($bin)) {
            return ['reason' => 'animated'] + $res;
        }
        @unlink($dest);
        try {
            $made = $type === IMAGETYPE_JPEG ? $this->jpeg($src, $dest, $bin, $w, $h, $turn, $fit) : $this->png($src, $dest, $bin, $w, $h, $fit);
        } catch (\Throwable $e) {
            $made = ['fail' => 'read'];
        }
        if (isset($made['fail'])) {
            @unlink($dest);

            return ['status' => $made['fail'] === 'memory' ? 'skip' : 'fail', 'reason' => $made['fail']] + $res;
        }
        clearstatcache(true, $dest);
        $after = (int) @filesize($dest);
        if ($after < 1 || !\is_array(@getimagesize($dest))) {
            @unlink($dest);

            return ['status' => 'fail', 'reason' => 'write'] + $res;
        }
        $res = ['after' => $after, 'method' => $made['method'], 'lossy' => $made['lossy'], 'file' => true] + $res;
        if ($fit !== null) {
            // made smaller: what was asked for, whatever it saved
            $shown = $turn >= 5 ? [$h, $w] : [$w, $h];

            return ['status' => 'ok', 'resized' => [$shown[0], $shown[1], $fit[0], $fit[1]]] + $res;
        }
        if ($after > $before * (100 - $this->o['min']) / 100) {
            return ['reason' => 'gain'] + $res;
        }

        return ['status' => 'ok'] + $res;
    }

    // ---------------------------------------------------------------- JPEG

    /** @return array{method?:string, lossy?:bool, fail?:string} */
    private function jpeg(string $src, string $dest, string $bin, int $w, int $h, int $turn, ?array $fit = null): array
    {
        $icc  = self::jpegIcc($bin);
        // jpegoptim cannot make a picture smaller: GD does it
        $tool = $this->o['engine'] === 'auto' && $fit === null ? (self::programs()['jpegoptim'] ?? '') : '';
        // jpegoptim keeps the pixels as they are: a photo on its side (EXIF orientation) is turned by GD
        if ($tool !== '' && $turn <= 1 && @copy($src, $dest)) {
            $out  = [];
            $code = 1;
            @exec(escapeshellarg($tool) . ' -q -o --strip-all --all-progressive -m' . $this->o['jpeg'] . ' ' . escapeshellarg($dest) . ' 2>&1', $out, $code);
            clearstatcache(true, $dest);
            if ($code === 0 && (int) @filesize($dest) > 0) {
                $data = (string) @file_get_contents($dest);
                if ($data !== '' && @file_put_contents($dest, self::jpegWithMark($data, $icc, $this->mark())) !== false) {
                    return ['method' => 'jpegoptim', 'lossy' => true];
                }
            }
            @unlink($dest);
        }
        if (!self::raiseMemory($w * $h * ($turn > 1 ? 12 : 6) + ($fit ? $fit[0] * $fit[1] * 6 : 0) + 16 * 1024 * 1024)) {
            return ['fail' => 'memory'];
        }
        $img = @imagecreatefromstring($bin);
        if (!$img) {
            return ['fail' => 'read'];
        }
        if ($turn > 1) {
            $img = self::upright($img, $turn);
            if (!$img) {
                return ['fail' => 'read'];
            }
        }
        if ($fit !== null) {
            $img = self::scaled($img, $fit[0], $fit[1], false);
            if (!$img) {
                return ['fail' => 'memory'];
            }
        }
        imageinterlace($img, true);
        ob_start();
        $ok   = @imagejpeg($img, null, $this->o['jpeg']);
        $data = (string) ob_get_clean();
        if (!$ok || $data === '') {
            return ['fail' => 'write'];
        }

        return @file_put_contents($dest, self::jpegWithMark($data, $icc, $this->mark())) !== false
            ? ['method' => 'gd', 'lossy' => true] : ['fail' => 'write'];
    }

    /**
     * The segments of a JPEG before its picture data: [marker byte, offset, length with the 4 bytes of
     * marker and length]. Stops at the start of the scan (SOS) or at a broken segment.
     *
     * @return array<int, array{0:int, 1:int, 2:int}>
     */
    public static function jpegSegments(string $bin, int $limit = PHP_INT_MAX): array
    {
        $out = [];
        $len = min(\strlen($bin), $limit);
        if ($len < 4 || $bin[0] !== "\xFF" || $bin[1] !== "\xD8") {
            return $out;
        }
        $pos = 2;
        while ($pos + 4 <= $len && $bin[$pos] === "\xFF") {
            $marker = \ord($bin[$pos + 1]);
            if ($marker === 0xFF) {
                // fill byte
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size  = (\ord($bin[$pos + 2]) << 8) | \ord($bin[$pos + 3]);
            if ($size < 2) {
                break;
            }
            $out[] = [$marker, $pos, $size + 2];
            $pos  += $size + 2;
        }

        return $out;
    }

    /** The ICC colour profile of a JPEG: its APP2 "ICC_PROFILE" segments as they are. */
    public static function jpegIcc(string $bin): string
    {
        $icc = '';
        foreach (self::jpegSegments($bin) as [$marker, $pos, $len]) {
            if ($marker === 0xE2 && substr($bin, $pos + 4, 12) === "ICC_PROFILE\0") {
                $icc .= substr($bin, $pos, $len);
            }
        }

        return $icc;
    }

    /**
     * A JPEG with the mark (a comment segment) and the colour profile $icc: after its JFIF header (APP0,
     * which must come first), in place of its own comments and profile.
     */
    public static function jpegWithMark(string $bin, string $icc, string $mark): string
    {
        $segments = self::jpegSegments($bin);
        $head     = '';
        $start    = 2;
        $keep     = '';
        foreach ($segments as $i => [$marker, $pos, $len]) {
            $part = substr($bin, $pos, $len);
            if ($i === 0 && $marker === 0xE0) {
                $head  = $part;
                $start = $pos + $len;
                continue;
            }
            // own comments and profile are left out (the original's profile comes in their place)
            if ($marker === 0xFE || ($marker === 0xE2 && substr($part, 4, 12) === "ICC_PROFILE\0")) {
                continue;
            }
            $keep .= $part;
        }
        $last = $segments ? end($segments) : null;
        $rest = $last ? substr($bin, $last[1] + $last[2]) : substr($bin, $start);
        $com  = "\xFF\xFE" . pack('n', \strlen($mark) + 2) . $mark;

        return "\xFF\xD8" . $head . $com . $icc . $keep . $rest;
    }

    // ---------------------------------------------------------------- PNG

    /** @return array{method?:string, lossy?:bool, fail?:string} */
    private function png(string $src, string $dest, string $bin, int $w, int $h, ?array $fit = null): array
    {
        $colour = self::pngColourChunks($bin);
        // pngquant cannot make a picture smaller: GD does it
        $tool   = $this->o['engine'] === 'auto' && $fit === null ? (self::programs()['pngquant'] ?? '') : '';
        if ($tool !== '' && $this->o['png'] < 100) {
            $out  = [];
            $code = 1;
            $q    = $this->o['png'];
            @exec(escapeshellarg($tool) . ' --quality=' . $q . '-' . min(100, $q + 20) . ' --speed 3 --strip --force --output '
                . escapeshellarg($dest) . ' -- ' . escapeshellarg($src) . ' 2>&1', $out, $code);
            clearstatcache(true, $dest);
            // 98: the quality asked for cannot be reached, 99: not lighter — then saved without loss below
            if ($code === 0 && (int) @filesize($dest) > 0) {
                $data = (string) @file_get_contents($dest);
                if ($data !== '' && @file_put_contents($dest, self::pngWithMark($data, $colour, $this->mark())) !== false) {
                    return ['method' => 'pngquant', 'lossy' => true];
                }
            }
            @unlink($dest);
        }
        if (!self::raiseMemory($w * $h * ($fit ? 6 : 12) + ($fit ? $fit[0] * $fit[1] * 12 : 0) + 16 * 1024 * 1024)) {
            return ['fail' => 'memory'];
        }
        $img = @imagecreatefromstring($bin);
        if (!$img) {
            return ['fail' => 'read'];
        }
        if ($fit !== null) {
            $img = self::scaled($img, $fit[0], $fit[1], true);
            if (!$img) {
                return ['fail' => 'memory'];
            }
            [$w, $h] = $fit;
        }
        $data  = '';
        $lossy = false;
        if ($this->o['png'] < 100 && imageistruecolor($img)) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $pal = imagecreatetruecolor($w, $h);
            imagealphablending($pal, false);
            imagesavealpha($pal, true);
            imagecopy($pal, $img, 0, 0, 0, 0, $w, $h);
            if (@imagetruecolortopalette($pal, false, 256)) {
                @imagecolormatch($img, $pal);
                if (self::psnr($img, $pal, $w, $h) >= $this->minPsnr()) {
                    $data  = self::pngData($pal);
                    $lossy = true;
                }
            }
        }
        if ($data === '') {
            if (imageistruecolor($img)) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
            $data = self::pngData($img);
        }
        if ($data === '') {
            return ['fail' => 'write'];
        }

        return @file_put_contents($dest, self::pngWithMark($data, $colour, $this->mark())) !== false
            ? ['method' => 'gd', 'lossy' => $lossy] : ['fail' => 'write'];
    }

    /** The closeness a GD palette must keep (dB): from 26 at quality 0 to 40 at 100. */
    private function minPsnr(): float
    {
        return 26 + $this->o['png'] * 0.14;
    }

    private static function pngData(\GdImage $img): string
    {
        ob_start();
        $ok   = @imagepng($img, null, 9);
        $data = (string) ob_get_clean();

        return $ok ? $data : '';
    }

    /**
     * How close the palette version is to the picture: the peak signal-to-noise ratio (dB) of up to
     * SAMPLES points on a grid, the colours weighed by their opacity (a transparent point's colour is
     * not seen), the opacity itself counted too. 99 = the same.
     */
    public static function psnr(\GdImage $true, \GdImage $pal, int $w, int $h): float
    {
        $step = max(1, (int) floor(sqrt($w * $h / self::SAMPLES)));
        $sum  = 0.0;
        $n    = 0;
        for ($y = (int) floor($step / 2); $y < $h; $y += $step) {
            for ($x = (int) floor($step / 2); $x < $w; $x += $step) {
                $a = imagecolorat($true, $x, $y);
                $b = imagecolorsforindex($pal, imagecolorat($pal, $x, $y));
                // GD: alpha 0 opaque … 127 transparent
                $oa  = (127 - (($a >> 24) & 0x7F)) / 127;
                $ob  = (127 - $b['alpha']) / 127;
                $sum += (((($a >> 16) & 0xFF) * $oa - $b['red'] * $ob) ** 2 + ((($a >> 8) & 0xFF) * $oa - $b['green'] * $ob) ** 2
                    + (($a & 0xFF) * $oa - $b['blue'] * $ob) ** 2 + (($oa - $ob) * 255) ** 2) / 4;
                $n++;
            }
        }
        if (!$n || $sum <= 0) {
            return 99.0;
        }

        return 10 * log10(255 * 255 / ($sum / $n));
    }

    /**
     * The chunks of a PNG: [type, offset, length of the data]. Stops at IEND or at a broken chunk.
     *
     * @return array<int, array{0:string, 1:int, 2:int}>
     */
    public static function pngChunks(string $bin, bool $untilData = false): array
    {
        $out = [];
        if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return $out;
        }
        $pos = 8;
        $len = \strlen($bin);
        while ($pos + 12 <= $len) {
            $size  = unpack('N', substr($bin, $pos, 4))[1];
            $type  = substr($bin, $pos + 4, 4);
            $out[] = [$type, $pos, $size];
            if ($type === 'IEND' || ($untilData && $type === 'IDAT')) {
                break;
            }
            $pos += 12 + $size;
        }

        return $out;
    }

    /** An APNG: an animation control chunk (acTL) before the picture data. */
    public static function pngAnimated(string $bin): bool
    {
        foreach (self::pngChunks($bin, true) as [$type]) {
            if ($type === 'acTL') {
                return true;
            }
        }

        return false;
    }

    /** The colour chunks of a PNG (profile, sRGB, gamma, primaries) as they are. */
    public static function pngColourChunks(string $bin): string
    {
        $out = '';
        foreach (self::pngChunks($bin, true) as [$type, $pos, $size]) {
            if (\in_array($type, ['iCCP', 'sRGB', 'gAMA', 'cHRM'], true)) {
                $out .= substr($bin, $pos, 12 + $size);
            }
        }

        return $out;
    }

    /** A PNG with the mark (a text chunk) and the colour chunks $colour right after its header, in place of its own. */
    public static function pngWithMark(string $bin, string $colour, string $mark): string
    {
        $chunks = self::pngChunks($bin);
        if (!$chunks || $chunks[0][0] !== 'IHDR') {
            return $bin;
        }
        $text = 'Comment' . "\0" . $mark;
        $out  = substr($bin, 0, 8) . substr($bin, $chunks[0][1], 12 + $chunks[0][2])
            . pack('N', \strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text)) . $colour;
        foreach (\array_slice($chunks, 1) as [$type, $pos, $size]) {
            if (\in_array($type, ['iCCP', 'sRGB', 'gAMA', 'cHRM'], true) && $colour !== '') {
                continue;
            }
            if ($type === 'tEXt' && str_starts_with(substr($bin, $pos + 8, $size), 'Comment' . "\0" . self::MARK)) {
                continue;
            }
            $out .= substr($bin, $pos, 12 + $size);
        }

        return $out;
    }

    // ---------------------------------------------------------------- the mark

    private function mark(): string
    {
        return self::MARK . ' ' . $this->signature();
    }

    /** Was the picture compressed by MerTools (its mark at the start of the file)? */
    public static function marked(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        if (str_starts_with($head, "\xFF\xD8")) {
            foreach (self::jpegSegments($head) as [$marker, $pos, $len]) {
                if ($marker === 0xFE) {
                    return str_starts_with(substr($head, $pos + 4, $len - 4), self::MARK);
                }
            }

            return false;
        }
        $chunks = self::pngChunks($head, true);
        foreach (\array_slice($chunks, 1, 1) as [$type, $pos, $size]) {
            return $type === 'tEXt' && str_starts_with(substr($head, $pos + 8, $size), 'Comment' . "\0" . self::MARK);
        }

        return false;
    }

    // ---------------------------------------------------------------- helpers

    /** The picture turned as its EXIF orientation (2–8) says it is shown; null when GD could not do it. */
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

    /** The picture made smaller to $w × $h (GD's resampling); with $alpha its transparency kept. */
    private static function scaled(\GdImage $img, int $w, int $h, bool $alpha): ?\GdImage
    {
        $out = @imagecreatetruecolor($w, $h);
        if (!$out) {
            return null;
        }
        if ($alpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        if (!@imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img))) {
            return null;
        }

        return $out;
    }

    /** Enough memory for $bytes more: raised when needed; false when the limit cannot be raised that far. */
    private static function raiseMemory(int $bytes): bool
    {
        $limit = static function (): int {
            $v = trim((string) ini_get('memory_limit'));
            if ($v === '-1' || $v === '') {
                return -1;
            }

            return (int) $v * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($v, -1))] ?? 1);
        };
        $need = memory_get_usage(true) + $bytes;
        if ($limit() < 0 || $limit() >= $need) {
            return true;
        }
        @ini_set('memory_limit', (string) (int) ceil($need / 1048576) . 'M');

        return $limit() < 0 || $limit() >= $need;
    }
}
