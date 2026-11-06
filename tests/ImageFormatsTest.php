<?php

/**
 * Test of ImageFormats with the real encoders of this PHP (GD with libavif, Imagick, cjxl, avifenc), and
 * of ImageOptimizer making the copies in those formats from a large photo (times and sizes printed).
 * The ways expected on this server are given: php tests/ImageFormatsTest.php avif=gd jxl=cjxl
 * (a format not named: none expected). Each way is also tried on its own: imagick:avif=yes …
 *
 *   docker run --rm -v "$PWD":/mt:ro -w /mt mt-img-bookworm php tests/ImageFormatsTest.php avif=gd imagick:avif=yes
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageCompressor.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageFormats.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageOptimizer.php';

use Merserwis\Plugin\System\MerTools\Tool\ImageFormats;
use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$expect = [];
$ways   = [];
foreach (array_slice($argv, 1) as $arg) {
    [$k, $v] = explode('=', $arg, 2) + [1 => ''];
    if (str_contains($k, ':')) {
        $ways[$k] = $v === 'yes';
    } else {
        $expect[$k] = $v;
    }
}

$root  = sys_get_temp_dir() . '/mtfmt-' . getmypid();
$cache = $root . '/cache';
@mkdir($root . '/images', 0777, true);
@mkdir($cache, 0777, true);
echo 'PHP ', PHP_VERSION, class_exists('Imagick') ? ', ' . Imagick::getVersion()['versionString'] : ', no Imagick', "\n";

$via = ImageFormats::encoders($cache);
$check('encoders: the ways expected here', $via, array_intersect_key(array_replace(ImageFormats::TYPES, $expect), $expect));

// each way named on its own: a photo-like picture written and read back
$img = imagecreatetruecolor(640, 400);
for ($i = 0; $i < 1500; $i++) {
    imagefilledellipse($img, mt_rand(0, 640), mt_rand(0, 400), mt_rand(5, 40), mt_rand(5, 40), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
foreach ($ways as $key => $want) {
    [$way, $format] = explode(':', $key);
    $file = $root . '/way.' . $format;
    @unlink($file);
    $ok = ImageFormats::write($img, $format, $way, $file, false) && ImageFormats::is($format, $file);
    $check($key . ': ' . ($want ? 'writes a real ' . $format : 'writes nothing'), $ok, $want);
    if ($ok && $format === 'avif' && function_exists('imagecreatefromavif') && (imagetypes() & IMG_AVIF)) {
        $back = @imagecreatefromavif($file);
        $check($key . ': read back with its size', $back ? [imagesx($back), imagesy($back)] : null, [640, 400]);
    }
}
// transparency kept (a PNG with a transparent corner), for each way expected
$png = imagecreatetruecolor(400, 300);
imagealphablending($png, false);
imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
imagefilledellipse($png, 200, 150, 300, 200, imagecolorallocatealpha($png, 200, 30, 30, 0));
// the ways chosen here: transparency kept, or lost for a way the probe marked "-opaque"
foreach ($via as $format => $way) {
    $file = $root . '/alpha-' . $way . '.' . $format;
    $ok   = ImageFormats::write($png, $format, $way, $file, true);
    $alpha = null;
    // AVIF read back by GD where it can (ImageMagick 6 reads AVIF without its transparency)
    if ($ok && $format === 'avif' && function_exists('imagecreatefromavif') && (imagetypes() & IMG_AVIF)) {
        $b = @imagecreatefromavif($file);
        $alpha = $b ? round(1 - ((imagecolorat($b, 2, 2) >> 24) & 0x7F) / 127, 2) : null;
    } elseif ($ok && class_exists('Imagick')) {
        try {
            $r = new Imagick($file);
            $alpha = $r->getImageAlphaChannel() ? round($r->getImagePixelColor(2, 2)->getColorValue(Imagick::COLOR_ALPHA), 2) : 1.0;
        } catch (Throwable $e) {
            $alpha = null; // ImageMagick here cannot read it back
        }
    }
    $lost = ImageFormats::opaqueOnly($way);
    $check($format . ' (' . $way . '): a transparent corner ' . ($lost ? 'not transparent (marked so)' : 'stays transparent'),
        $alpha === null ? 'not read back' : $alpha < 0.1, $alpha === null ? 'not read back' : !$lost);
}

// the copies of a large photo, as the work makes them: times and sizes against the WebP copies
$photo = imagecreatetruecolor(2560, 1700);
for ($i = 0; $i < 9000; $i++) {
    imagefilledellipse($photo, mt_rand(0, 2560), mt_rand(0, 1700), mt_rand(5, 120), mt_rand(5, 120), imagecolorallocate($photo, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagefilter($photo, IMG_FILTER_GAUSSIAN_BLUR);
imagejpeg($photo, $root . '/images/large.jpg', 90);
$site = 'https://example.com';
$o    = (new ImageOptimizer($root, $site, '', $cache))->formats($via);
$o->lookup('/images/large.jpg');
$o->save();
$t    = microtime(true);
$made = (new ImageOptimizer($root, $site, '', $cache))->formats($via)->work(120.0);
$time = microtime(true) - $t;
$check('a large photo: its copies made', $made, 1);
$entry = (new ImageOptimizer($root, $site, '', $cache))->entries()['images/large.jpg'] ?? [];
$size  = fn (array $copies) => array_sum(array_map(fn ($c) => filesize($root . '/' . $c), $copies));
printf("     2560×1700 photo (JPEG %d KB): WebP %d KB", filesize($root . '/images/large.jpg') / 1024, $size($entry['v'] ?? []) / 1024);
foreach (ImageFormats::KEYS as $format => $key) {
    if (isset($entry[$key])) {
        printf(', %s %s', strtoupper($format), $entry[$key] ? (int) ($size($entry[$key]) / 1024) . ' KB' : 'not lighter');
    }
}
printf(" (%d copies each), %.1f s in all\n", count($entry['v'] ?? []), $time);
foreach ($via as $format => $way) {
    $copies = $entry[ImageFormats::KEYS[$format]] ?? null;
    $check($format . ': tried for the photo', is_array($copies), true);
    foreach ((array) $copies as $copy) {
        if (!ImageFormats::is($format, $root . '/' . $copy)) {
            $check($format . ': every copy a real ' . $format, $copy, 'a real file');
        }
    }
}
$p = (new ImageOptimizer($root, $site, '', $cache))->formats($via)->lookup('/images/large.jpg');
$check('the page: its sources, the newer formats first', array_keys($p['sources'] ?? []), array_values(array_intersect_key(ImageFormats::TYPES, array_filter($via, fn ($w, $f) => !empty($entry[ImageFormats::KEYS[$f]]), ARRAY_FILTER_USE_BOTH))));

// a transparent PNG: no copy of a format whose way here loses transparency, else one that keeps it
$tp = imagecreatetruecolor(1200, 800);
imagealphablending($tp, false);
imagesavealpha($tp, true);
imagefill($tp, 0, 0, imagecolorallocatealpha($tp, 0, 0, 0, 127));
for ($i = 0; $i < 3000; $i++) {
    imagefilledellipse($tp, mt_rand(200, 1000), mt_rand(150, 650), mt_rand(5, 60), mt_rand(5, 60), imagecolorallocatealpha($tp, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0));
}
imagepng($tp, $root . '/images/transparent.png');
$o = (new ImageOptimizer($root, $site, '', $cache))->formats($via);
$o->lookup('/images/transparent.png');
$o->save();
(new ImageOptimizer($root, $site, '', $cache))->formats($via)->work(120.0);
$te = (new ImageOptimizer($root, $site, '', $cache))->entries()['images/transparent.png'] ?? [];
foreach ($via as $format => $way) {
    $copies = $te[ImageFormats::KEYS[$format]] ?? null;
    if (ImageFormats::opaqueOnly($way)) {
        $check($format . ' (' . $way . '): no copy of a transparent PNG', $copies, []);
    } elseif ($copies) {
        $first = $root . '/' . reset($copies);
        $back  = $format === 'avif' && function_exists('imagecreatefromavif') && (imagetypes() & IMG_AVIF) ? @imagecreatefromavif($first) : null;
        $check($format . ' (' . $way . '): a transparent PNG keeps its transparency', $back ? ((imagecolorat($back, 2, 2) >> 24) & 0x7F) >= 120 : 'not read back', $back ? true : 'not read back');
    }
}

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
