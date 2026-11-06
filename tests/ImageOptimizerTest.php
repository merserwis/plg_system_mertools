<?php

/**
 * Test of ImageOptimizer with PHP's GD (needs gd with WebP).
 * Run: php tests/ImageOptimizerTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageOptimizer.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageFormats.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageCompressor.php';

use Merserwis\Plugin\System\MerTools\Tool\ImageFormats;
use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;
use Merserwis\Plugin\System\MerTools\Tool\ImageSize;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-62s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$root  = sys_get_temp_dir() . '/mtimg-' . getmypid();
$cache = $root . '/cache';
@mkdir($root . '/images/sub', 0777, true);
@mkdir($cache, 0777, true);

// a photo 1600x900 (noise, so the JPEG is not tiny), a PNG with transparency 1000x600, a tiny PNG, a static GIF, an animated GIF
$im = imagecreatetruecolor(1600, 900);
for ($i = 0; $i < 4000; $i++) {
    imagefilledellipse($im, mt_rand(0, 1600), mt_rand(0, 900), mt_rand(5, 60), mt_rand(5, 60), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagejpeg($im, $root . '/images/photo one.jpg', 92);
$png = imagecreatetruecolor(1000, 600);
imagealphablending($png, false);
imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
imagefilledellipse($png, 500, 300, 700, 400, imagecolorallocatealpha($png, 200, 30, 30, 20));
imagealphablending($png, true);
for ($i = 0; $i < 3000; $i++) {
    imagesetpixel($png, mt_rand(150, 850), mt_rand(100, 500), imagecolorallocatealpha($png, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0));
}
imagealphablending($png, false);
imagepng($png, $root . '/images/sub/alpha.png');
imagepng(imagecreatetruecolor(40, 40), $root . '/images/tiny.png');
imagegif(imagecreatetruecolor(300, 200), $root . '/images/static.gif');
copy(__DIR__ . '/fixtures/anim.gif', $root . '/images/anim.gif');

$check('GD with WebP available', ImageOptimizer::supported(), true);
$check('animated GIF detected', ImageOptimizer::animated($root . '/images/anim.gif'), true);
$check('static GIF not animated', ImageOptimizer::animated($root . '/images/static.gif'), false);

$site = 'https://example.com';
$opt  = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('first look: not ready (queued)', $opt->lookup('https://example.com/images/photo%20one.jpg'), null);
$check('relative path, query ignored', $opt->lookup('/images/sub/alpha.png?x=1'), null);
$opt->lookup('/images/tiny.png');
$opt->lookup('/images/static.gif');
$opt->lookup('/images/anim.gif');
$check('other site never queued', $opt->lookup('https://cdn.example.org/images/a.jpg'), null);
$check('svg never queued', $opt->lookup('/images/logo.svg'), null);
$check('missing file never queued', $opt->lookup('/images/nope.jpg'), null);
$check('queue: 5 pictures', $opt->pending(), 5);
$opt->save();

$worker = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('queue read back', $worker->pending(), 5);
$made = $worker->work(60.0);
$check('made: photo, alpha png, animated gif', $made, 3);
$check('queue empty after work', $worker->pending(), 0);

$pages = new ImageOptimizer($root, $site, '', $cache, 1000);
$p = $pages->lookup('/images/photo one.jpg');
$check('photo: srcset 480 768 1080 1440 1600', preg_replace('#/media/mertools-img/[0-9a-f]{2}/[0-9a-f]{10}-#', '', $p['srcset'] ?? ''),
    'photo-one-480.webp 480w, photo-one-768.webp 768w, photo-one-1080.webp 1080w, photo-one-1440.webp 1440w, photo-one-1600.webp 1600w');
preg_match_all('#(/media/mertools-img/\S+\.webp) (\d+)w#', $p['srcset'] ?? '', $files, PREG_SET_ORDER);
$okFiles = true;
foreach ($files as $f) {
    $info = @getimagesize($root . $f[1]);
    $okFiles = $okFiles && $info && $info[0] === (int) $f[2] && $info[2] === IMAGETYPE_WEBP;
}
$check('photo: every copy is a WebP of its width', $okFiles && count($files) === 5, true);
$check('photo: largest copy lighter than the original', filesize($root . end($files)[1]) < filesize($root . '/images/photo one.jpg'), true);
$a = $pages->lookup('/images/sub/alpha.png');
preg_match('#(/media/mertools-img/\S+-480\.webp)#', $a['srcset'] ?? '', $af);
$ai = $af ? imagecreatefromwebp($root . $af[1]) : null;
$check('png: transparency kept in the copy', $ai ? (imagecolorat($ai, 2, 2) >> 24) & 0x7F : -1, 127);
$check('tiny png: no copies (nothing lighter)', $pages->lookup('/images/tiny.png'), null);
$check('static gif: shown as it is', $pages->lookup('/images/static.gif'), null);
$g = $pages->lookup('/images/anim.gif');
$check('animated gif: first frame copy at its width', (bool) preg_match('#^/media/mertools-img/[0-9a-f]{2}/[0-9a-f]{10}-anim-f-320\.webp 320w$#', $g['frame'] ?? ''), true);
$check('animated gif: animation = the gif', $g['anim'] ?? '', '/images/anim.gif');
file_put_contents($root . '/images/anim.webp', 'x');
$check('animated gif: a WebP next to it is used for the animation', $pages->lookup('/images/anim.gif')['anim'] ?? '', '/images/anim.webp');
$check('url-encoded name', str_contains($pages->lookup('/images/photo%20one.jpg')['srcset'] ?? '', 'photo-one-480.webp'), true);

// a changed original: new copies under a new name
touch($root . '/images/photo one.jpg', time() + 5);
clearstatcache();
$changed = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('changed original: not ready again (queued)', $changed->lookup('/images/photo one.jpg'), null);
$changed->save();
$changed->work(60.0);
$again = (new ImageOptimizer($root, $site, '', $cache, 1000))->lookup('/images/photo one.jpg');
$check('changed original: new names', ($again['srcset'] ?? '') !== '' && ($again['srcset'] ?? '') !== $p['srcset'], true);

$sub = new ImageOptimizer($root, 'https://example.com', '/joomla', $cache, 1000);
$check('site in a subfolder: path without the folder', $sub->relative('/joomla/images/x.jpg'), 'images/x.jpg');

$st = $pages->stats();
$check('stats: files counted', $st['files'] >= 7, true);
$pages->clear();
$check('clear: folder gone', is_dir($root . '/media/mertools-img') && (new FilesystemIterator($root . '/media/mertools-img'))->valid(), false);
$check('clear: nothing ready', (new ImageOptimizer($root, $site, '', $cache, 1000))->lookup('/images/sub/alpha.png'), null);

$check('allowed: images folder', ImageOptimizer::allowed('images/a/b.jpg'), true);
$check('allowed: media folder', ImageOptimizer::allowed('media/x.png'), true);
$check('allowed: administrator refused', ImageOptimizer::allowed('administrator/x.jpg'), false);
$check('allowed: cache refused', ImageOptimizer::allowed('cache/x.jpg'), false);
$check('allowed: root file refused', ImageOptimizer::allowed('x.jpg'), false);
$check('allowed: hidden folder refused', ImageOptimizer::allowed('images/.private/x.jpg'), false);
$check('allowed: hidden file refused', ImageOptimizer::allowed('images/.x.jpg'), false);
$check('relative: protected folder gives null', (new ImageOptimizer($root, $site, '', $cache))->relative('/administrator/x.jpg'), null);
$check('relative: public folder', (new ImageOptimizer($root, $site, '', $cache))->relative('/images/sub/alpha.png'), 'images/sub/alpha.png');
$check('relative: a name that is not UTF-8 is left alone', (new ImageOptimizer($root, $site, '', $cache))->relative('/images/caf%E9.jpg'), null);
$check('relative: a UTF-8 name', (new ImageOptimizer($root, $site, '', $cache))->relative('/images/caf%C3%A9.jpg'), "images/caf\u{e9}.jpg");

// ---------------------------------------------------------------- 1.7.0: copies never more public than the picture
$fresh = new ImageOptimizer($root, $site, '', $cache, 1000);
$fresh->lookup('/images/sub/alpha.png', false);
$check('a page only logged-in users see queues nothing', $fresh->pending(), 0);
@mkdir($root . '/images/members/deep', 0777, true);
@mkdir($root . '/images/hardened', 0777, true);
@mkdir($root . '/images/old', 0777, true);
file_put_contents($root . '/images/members/.htaccess', "AuthType Basic\nAuthName \"Members\"\nAuthUserFile /home/x/.htpasswd\nRequire valid-user\n");
file_put_contents($root . '/images/hardened/.htaccess', "<FilesMatch \"\\.(php|phtml)$\">\n  Require all denied\n</FilesMatch>\nOptions -Indexes\n");
file_put_contents($root . '/images/old/.htaccess', "Order deny,allow\nDeny from all\n");
$check('guarded: password', ImageOptimizer::guarded($root, 'images/members/a.jpg'), true);
$check('guarded: a folder below', ImageOptimizer::guarded($root, 'images/members/deep/a.jpg'), true);
$check('guarded: everybody refused', ImageOptimizer::guarded($root, 'images/old/a.jpg'), true);
$check('not guarded: rules for PHP files only', ImageOptimizer::guarded($root, 'images/hardened/a.jpg'), false);
$check('not guarded: no .htaccess', ImageOptimizer::guarded($root, 'images/sub/alpha.png'), false);
copy($root . '/images/photo one.jpg', $root . '/images/members/deep/photo.jpg');
$check('guarded picture: no copies made', $fresh->make('images/members/deep/photo.jpg')['x'] ?? null, 'skip');

// ---------------------------------------------------------------- 1.7.0: a copy gone is made again
$gone = new ImageOptimizer($root, $site, '', $cache, 1000);
$gone->lookup('/images/sub/alpha.png');
$gone->save();
$gone->work(60.0);
$ready = (new ImageOptimizer($root, $site, '', $cache, 1000))->lookup('/images/sub/alpha.png');
preg_match('#(/media/mertools-img/\S+-480\.webp)#', $ready['srcset'] ?? '', $one);
@unlink($root . ($one[1] ?? '/none'));
$lost = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('copy removed: not offered, queued again', [$lost->lookup('/images/sub/alpha.png'), $lost->pending()], [null, 1]);
$lost->save();
$lost->work(60.0);
$check('copy removed: made again under the same name', is_file($root . ($one[1] ?? '/none')), true);

// ---------------------------------------------------------------- 1.7.0: EXIF orientation
// a 480x360 photo, red in its stored top-left corner, green in its stored top-right corner
$exif = function (int $o, bool $motorola = false): string {
    $tiff = $motorola
        ? 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $o) . "\0\0" . pack('N', 0)
        : 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $o) . "\0\0" . pack('V', 0);
    $data = "Exif\0\0" . $tiff;

    return "\xFF\xE1" . pack('n', strlen($data) + 2) . $data;
};
$im = imagecreatetruecolor(480, 360);
for ($i = 0; $i < 2500; $i++) {
    imagefilledellipse($im, mt_rand(0, 480), mt_rand(0, 360), mt_rand(5, 40), mt_rand(5, 40), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagefilledrectangle($im, 0, 0, 79, 79, imagecolorallocate($im, 255, 0, 0));
imagefilledrectangle($im, 400, 0, 479, 79, imagecolorallocate($im, 0, 255, 0));
ob_start();
imagejpeg($im, null, 92);
$jpeg = (string) ob_get_clean();
$check('test photo heavy enough for copies', strlen($jpeg) > ImageOptimizer::MIN_BYTES, true);
$color = function (\GdImage $img, int $x, int $y): string {
    $c = imagecolorat($img, $x, $y);
    [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];

    return $r > 150 && $g < 110 && $b < 110 ? 'red' : ($g > 150 && $r < 110 && $b < 110 ? 'green' : 'other');
};
// where red and green are seen (TL, TR, BR, BL) once the browser has turned the photo
$seen = [1 => ['TL', 'TR'], 2 => ['TR', 'TL'], 3 => ['BR', 'BL'], 4 => ['BL', 'BR'], 5 => ['TL', 'BL'], 6 => ['TR', 'BR'], 7 => ['BR', 'TR'], 8 => ['BL', 'TL']];
$turnOpt = new ImageOptimizer($root, $site, '', $cache, 1000);
foreach (range(1, 8) as $o) {
    file_put_contents($root . "/images/turn-$o.jpg", "\xFF\xD8" . $exif($o) . substr($jpeg, 2));
    $turnOpt->lookup("/images/turn-$o.jpg");
}
file_put_contents($root . '/images/turn-mm.jpg', "\xFF\xD8" . $exif(6, true) . substr($jpeg, 2));
$check('orientation: none', ImageSize::orientation($root . '/images/photo one.jpg'), 1);
$check('orientation: Intel byte order', ImageSize::orientation($root . '/images/turn-6.jpg'), 6);
$check('orientation: Motorola byte order', ImageSize::orientation($root . '/images/turn-mm.jpg'), 6);
$check('orientation: not a JPEG', ImageSize::orientation($root . '/images/sub/alpha.png'), 1);
$sizes = new ImageSize($root, $site, '', $cache . '/sizes.json');
$check('size: stored on its side = shown turned', [$sizes->get('/images/turn-6.jpg'), $sizes->get('/images/turn-3.jpg')], [[360, 480], [480, 360]]);
file_put_contents($cache . '/sizes-old.json', json_encode([$root . '/images/turn-8.jpg' => [480, 360, filemtime($root . '/images/turn-8.jpg')]]));
$check('size: an entry read before 1.7.0 is read again', (new ImageSize($root, $site, '', $cache . '/sizes-old.json'))->get('/images/turn-8.jpg'), [360, 480]);
$turnOpt->save();
$turnOpt->work(60.0);
$turned = new ImageOptimizer($root, $site, '', $cache, 1000);
$corners = [];
foreach (range(1, 8) as $o) {
    $srcset = $turned->lookup("/images/turn-$o.jpg")['srcset'] ?? '';
    preg_match('#^(\S+) (\d+)w$#', $srcset, $c);
    $copy = $c ? @imagecreatefromwebp($root . $c[1]) : false;
    if (!$copy) {
        $corners[$o] = 'no copy: ' . $srcset;
        continue;
    }
    [$w, $h] = [imagesx($copy), imagesy($copy)];
    $at      = ['TL' => [30, 30], 'TR' => [$w - 31, 30], 'BR' => [$w - 31, $h - 31], 'BL' => [30, $h - 31]];
    $corners[$o] = [$w . 'x' . $h, $color($copy, ...$at[$seen[$o][0]]), $color($copy, ...$at[$seen[$o][1]])];
}
$expect = [];
foreach (range(1, 8) as $o) {
    $expect[$o] = [$o >= 5 ? '360x480' : '480x360', 'red', 'green'];
}
$check('copies upright for all 8 orientations', $corners, $expect);
$oldName = fn (int $o) => substr(md5("images/turn-$o.jpg|" . filemtime($root . "/images/turn-$o.jpg") . '|' . filesize($root . "/images/turn-$o.jpg")), 0, 10);
$check('turned copies get other names than copies made before 1.7.0, upright ones keep theirs',
    [str_contains($turned->lookup('/images/turn-6.jpg')['srcset'] ?? '', $oldName(6)), str_contains($turned->lookup('/images/turn-1.jpg')['srcset'] ?? '', $oldName(1))], [false, true]);

// entries of copies made before 1.7.0 (no "o"): a photo stored on its side is made again, others stay as they are
// (written as the manifest of one file of MerTools before 1.11: moved into its parts on the way)
$manifest = (new ImageOptimizer($root, $site, '', $cache, 1000))->entries();
unset($manifest['images/turn-6.jpg']['o'], $manifest['images/turn-1.jpg']['o']);
array_map('unlink', glob($cache . '/mertools-images-??.json'));
file_put_contents($cache . '/mertools-images.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
$older = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('1.11: the manifest of one file moved into parts', [is_file($cache . '/mertools-images.json'), count(glob($cache . '/mertools-images-??.json')) > 3,
    count($older->entries())], [false, true, count($manifest)]);
$check('before 1.7.0, upright photo: copies still offered', isset($older->lookup('/images/turn-1.jpg')['srcset']), true);
$check('before 1.7.0, photo on its side: made again', [$older->lookup('/images/turn-6.jpg'), $older->pending()], [null, 1]);
$check('new entries say how they were turned', [$manifest['images/turn-8.jpg']['o'] ?? null, $manifest['images/sub/alpha.png']['o'] ?? null], [8, 1]);

// ---------------------------------------------------------------- 1.11: the manifest in parts
$parts = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('a part holds only the pictures of its part', array_sum(array_map(fn ($f) => count(json_decode(file_get_contents($f), true)), glob($cache . '/mertools-images-??.json'))),
    count($parts->entries()));
// a worker busy with the old manifest (its lock): a page reads the old file, which stays until the move
file_put_contents($cache . '/mertools-images.json', json_encode(['images/sub/alpha.png' => $parts->entries()['images/sub/alpha.png']], JSON_UNESCAPED_SLASHES));
array_map('unlink', glob($cache . '/mertools-images-??.json'));
$code  = '$f=fopen(' . var_export($cache . '/mertools-img.lock', true) . ',"c");flock($f,LOCK_EX);echo "L\n";usleep(800000);flock($f,LOCK_UN);';
$child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
fgets($pipes[1]);
$busy = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('old manifest, worker busy: read as it is', [isset($busy->lookup('/images/sub/alpha.png')['srcset']), is_file($cache . '/mertools-images.json')], [true, true]);
proc_close($child);
new ImageOptimizer($root, $site, '', $cache, 1000);
$check('…moved by the next request', [is_file($cache . '/mertools-images.json'), count(glob($cache . '/mertools-images-??.json'))], [false, 1]);
// all made again for the sweep
foreach (['/images/photo one.jpg', '/images/sub/alpha.png', '/images/anim.gif'] as $u) {
    $parts->lookup($u);
}
$parts->save();
(new ImageOptimizer($root, $site, '', $cache, 1000))->work(30.0);

// ---------------------------------------------------------------- 1.11: the sweep of copies no longer used
$sw   = new ImageOptimizer($root, $site, '', $cache, 1000);
$now  = time();
$used = fn () => array_map(fn ($u) => $u, $sw->entries()['images/photo one.jpg']['v'] ?? []);
$oldCopies = array_values($used());
// the copies the tests above made unused (photos stored on their side, made again since 1.7.0) are found
$check('sweep: copies unused before found, kept for now', [$sw->sweep($now)['unused'] > 0, $sw->sweep($now + ImageOptimizer::GRACE + 1)['removed'] > 0], [true, true]);
$check('sweep: nothing to do on a tidy site', $sw->sweep($now), ['dropped' => 0, 'removed' => 0, 'unused' => 0]);
// the photo changed: new copies under new names, the old ones unused
touch($root . '/images/photo one.jpg', $now + 5);
clearstatcache();
$sw->lookup('/images/photo one.jpg');
$sw->save();
(new ImageOptimizer($root, $site, '', $cache, 1000))->work(30.0);
$sw = new ImageOptimizer($root, $site, '', $cache, 1000);
$newCopies = array_values($sw->entries()['images/photo one.jpg']['v'] ?? []);
$check('a changed photo: new copies under other names', $newCopies && !array_intersect($newCopies, $oldCopies), true);
// a removed picture: its entry goes, its copies are unused
$gifCopies = array_values($sw->entries()['images/anim.gif']['f'] ?? []);
rename($root . '/images/anim.gif', $root . '/anim.gif.away');
file_put_contents($root . '/media/mertools-img/half.webp.123.tmp', 'x');
touch($root . '/media/mertools-img/half.webp.123.tmp', $now - 7200);
$r = $sw->sweep($now);
$check('sweep: the entry of a removed picture dropped', [$r['dropped'], isset($sw->entries()['images/anim.gif'])], [1, false]);
$check('…the old copies are unused, kept for now', [$r['removed'], $r['unused'], $sw->stats()['unused']], [0, count($oldCopies) + count($gifCopies), count($oldCopies) + count($gifCopies)]);
$check('…a copy left half written an hour ago removed', is_file($root . '/media/mertools-img/half.webp.123.tmp'), false);
$check('a day later: still kept (a page kept before may show them)', $sw->sweep($now + 86400)['removed'], 0);
$r = $sw->sweep($now + ImageOptimizer::GRACE + 1);
$check('a week after: removed', [$r['removed'], array_filter(array_merge($oldCopies, $gifCopies), fn ($c) => is_file($root . '/' . $c))], [count($oldCopies) + count($gifCopies), []]);
$check('…the copies in use stay', array_filter($newCopies, fn ($c) => !is_file($root . '/' . $c)), []);
$check('…and the photo still has them', isset((new ImageOptimizer($root, $site, '', $cache, 1000))->lookup('/images/photo one.jpg')['srcset']), true);
// a copy unused and then named again (the same picture made again) is not removed
rename($root . '/anim.gif.away', $root . '/images/anim.gif');
$sw->sweep($now);
$check('nothing left over', $sw->stats()['unused'], 0);
$code  = '$f=fopen(' . var_export($cache . '/mertools-img.lock', true) . ',"c");flock($f,LOCK_EX);echo "L\n";usleep(600000);flock($f,LOCK_UN);';
$child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
fgets($pipes[1]);
$check('sweep: never while a worker makes copies', $sw->sweep($now), ['dropped' => 0, 'removed' => 0, 'unused' => 0]);
proc_close($child);

// ---------------------------------------------------------------- 1.7.0: clearing waits for a worker
$lockFile = $cache . '/mertools-img.lock';
$code     = '$f=fopen(' . var_export($lockFile, true) . ',"c");flock($f,LOCK_EX);echo "L\n";usleep(1200000);flock($f,LOCK_UN);';
$child    = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
fgets($pipes[1]);
$t = microtime(true);
(new ImageOptimizer($root, $site, '', $cache, 1000))->clear();
$waited = microtime(true) - $t;
proc_close($child);
$check('clear: waits until the worker has finished', $waited > 0.8, true);
$check('clear: then everything gone', is_dir($root . '/media/mertools-img') && (new FilesystemIterator($root . '/media/mertools-img'))->valid(), false);
$check('clear: the parts of the manifest gone too', glob($cache . '/mertools-images*.json'), []);

// ---------------------------------------------------------------- 1.14.0: the newer formats (AVIF, JPEG XL) in <picture>
$check('is: AVIF by its first bytes', [ImageFormats::is('avif', (function () use ($root) { file_put_contents($root . '/t.avif', "\0\0\0\x1cftypavif\0\0\0\0"); return $root . '/t.avif'; })()),
    ImageFormats::is('avif', (function () use ($root) { file_put_contents($root . '/t.heic', "\0\0\0\x18ftypheic\0\0\0\0"); return $root . '/t.heic'; })())], [true, false]);
$check('is: JPEG XL, a code stream and a container', [ImageFormats::is('jxl', (function () use ($root) { file_put_contents($root . '/t.jxl', "\xFF\x0A..."); return $root . '/t.jxl'; })()),
    ImageFormats::is('jxl', (function () use ($root) { file_put_contents($root . '/t2.jxl', "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A"); return $root . '/t2.jxl'; })()),
    ImageFormats::is('jxl', $root . '/t.avif')], [true, true, false]);
// the real ways of this PHP: probed once, kept with what they depend on
$real = ImageFormats::encoders($cache);
$check('encoders: what this server can make (kept in the cache)', [is_file($cache . '/mertools-img-formats.json'), array_diff(array_keys($real), ['avif', 'jxl'])], [true, []]);
$kept = json_decode((string) file_get_contents($cache . '/mertools-img-formats.json'), true);
file_put_contents($cache . '/mertools-img-formats.json', json_encode(['key' => $kept['key'], 'at' => time(), 'via' => ['avif' => 'kept']]));
$check('encoders: read from the cache while fresh', ImageFormats::encoders($cache), ['avif' => 'kept']);
file_put_contents($cache . '/mertools-img-formats.json', json_encode(['key' => 'other PHP', 'at' => time(), 'via' => ['avif' => 'kept']]));
$check('encoders: probed again for another PHP or ImageMagick', ImageFormats::encoders($cache), $real);
$check('encoders: probed again when asked (the panel)', ImageFormats::encoders($cache, true), $real);

// fake encoders: AVIF small, JPEG XL heavier than the WebP copies
$fakeWrites = [];
ImageFormats::fake([
    'avif' => function (GdImage $img, string $file) use (&$fakeWrites) { $fakeWrites[] = imagesx($img); return file_put_contents($file, "\0\0\0\x1cftypavif" . str_repeat('a', 40)) > 0; },
    'jxl'  => fn (GdImage $img, string $file) => file_put_contents($file, "\xFF\x0A" . str_repeat('j', 4000000)) > 0,
]);
$via = ImageFormats::encoders($cache);
$check('formats: JPEG XL first, then AVIF (the order of <source>)', $via, ['jxl' => 'fake', 'avif' => 'fake']);
$pg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via);
$check('a new picture: queued', $pg->lookup('/images/photo one.jpg'), null);
$pg->lookup('/images/sub/alpha.png');
$pg->save();
$check('work: WebP and the newer formats in one round', (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via)->work(60.0), 2);
$pg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via);
$p  = $pg->lookup('/images/photo one.jpg');
$strip = fn (string $s) => preg_replace('#/media/mertools-img/[0-9a-f]{2}/[0-9a-f]{10}-#', '', $s);
$check('photo: AVIF offered in the widths of its WebP copies', $strip($p['sources']['image/avif'] ?? ''), str_replace('.webp', '.avif', $strip($p['srcset'] ?? '')));
$check('…made from copies of those widths', $fakeWrites, [480, 768, 1080, 1440, 1600, 480, 768, 1000]);
$check('…JPEG XL not lighter than the WebP copies: not offered, its files removed', [array_keys($p['sources'] ?? []), glob($root . '/media/mertools-img/*/*.jxl')], [['image/avif'], []]);
$e = $pg->entries()['images/photo one.jpg'];
$check('…noted as tried: not made again', [$e['j'] ?? null, $pg->pending()], [[], 0]);
$check('the copies folder tells Apache the types of AVIF and JPEG XL', (string) @file_get_contents($root . '/media/mertools-img/.htaccess'),
    "<IfModule mod_mime.c>\nAddType image/avif .avif\nAddType image/jxl .jxl\n</IfModule>\n");
$check('png: AVIF too', isset($pg->lookup('/images/sub/alpha.png')['sources']['image/avif']), true);
$check('setting off (no formats): WebP only', array_key_exists('sources', (new ImageOptimizer($root, $site, '', $cache, 1000))->lookup('/images/photo one.jpg') ?? []), false);
$st = $pg->stats();
$check('stats: the copies of each format', [$st['formats']['avif']['files'], $st['formats']['jxl']['files'], $st['formats']['webp']['files'] > 0], [8, 0, true]);
// an AVIF copy removed by hand: offered without it, made again
unlink($root . '/media/mertools-img/' . explode('/media/mertools-img/', explode(' ', $p['sources']['image/avif'])[0])[1]);
$pg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via);
$q  = $pg->lookup('/images/photo one.jpg');
$check('an AVIF copy gone: WebP still offered, the picture queued', [isset($q['srcset']), isset($q['sources']), $pg->pending()], [true, false, 1]);
$pg->save();
$webpBefore = $pg->entries()['images/photo one.jpg']['v'];
$mtimes = array_map(fn ($c) => filemtime($root . '/' . $c), $webpBefore);
clearstatcache();
$fakeWrites = [];
(new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via)->work(60.0);
$check('…only the newer format made again (the WebP copies kept)', [count($fakeWrites), array_map(fn ($c) => filemtime($root . '/' . $c), $webpBefore) === $mtimes],
    [5, true]);
$check('…and offered again', isset((new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via)->lookup('/images/photo one.jpg')['sources']['image/avif']), true);
// while a visitor waits ($extra false): WebP only, the newer formats left for a later round
$im2 = imagecreatetruecolor(900, 600);
for ($i = 0; $i < 2500; $i++) {
    imagefilledellipse($im2, mt_rand(0, 900), mt_rand(0, 600), mt_rand(5, 50), mt_rand(5, 50), imagecolorallocate($im2, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagejpeg($im2, $root . '/images/second.jpg', 92);
$pg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via);
$pg->lookup('/images/second.jpg');
$pg->save();
$fakeWrites = [];
(new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via)->work(60.0, ImageOptimizer::MAX_PIXELS, false);
$pg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats($via);
$r2 = $pg->lookup('/images/second.jpg');
$check('no newer formats while a visitor waits: WebP offered, queued again for them', [count($fakeWrites), isset($r2['srcset']), isset($r2['sources']), $pg->pending()], [0, true, false, 1]);
// an encoder that fails: noted as tried, WebP stays
ImageFormats::fake(['avif' => fn () => false]);
$pg->save();
$w = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(ImageFormats::encoders($cache));
$check('an encoder that fails: nothing new', $w->work(60.0), 0);
$e2 = (new ImageOptimizer($root, $site, '', $cache, 1000))->entries()['images/second.jpg'];
$check('…tried, WebP copies kept, no file left over', [$e2['a'] ?? null, count($e2['v']) > 0, glob($root . '/media/mertools-img/*/*second*.avif'), glob($root . '/media/mertools-img/*/*.tmp')], [[], true, [], []]);
// a way that loses transparency (ImageMagick 6 and AVIF): pictures with transparency keep their WebP copies only
ImageFormats::fake(['avif' => fn (GdImage $img, string $file) => file_put_contents($file, "\0\0\0\x1cftypavif" . str_repeat('a', 40)) > 0]);
$check('transparent: a PNG with an alpha channel, not a JPEG', [ImageFormats::transparent($root . '/images/sub/alpha.png'), ImageFormats::transparent($root . '/images/second.jpg')], [true, false]);
$opaquePng = imagecreatetruecolor(800, 500);
for ($i = 0; $i < 2500; $i++) {
    imagefilledellipse($opaquePng, mt_rand(0, 800), mt_rand(0, 500), mt_rand(5, 50), mt_rand(5, 50), imagecolorallocate($opaquePng, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagepng($opaquePng, $root . '/images/opaque.png');
$check('transparent: a PNG without alpha channel or tRNS', ImageFormats::transparent($root . '/images/opaque.png'), false);
// a palette PNG whose tRNS comes after 100 KB of text: found by walking the chunks
$chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$pal   = imagecreate(400, 300);
imagecolorallocate($pal, 255, 255, 255);
imagecolortransparent($pal, imagecolorallocate($pal, 0, 0, 0));
ob_start();
imagepng($pal);
$bin  = (string) ob_get_clean();
$plte = strpos($bin, 'PLTE') - 4;
$plteEnd = $plte + 12 + unpack('N', substr($bin, $plte, 4))[1];
$check('…the palette PNG made by GD has a tRNS', str_contains($bin, 'tRNS'), true);
file_put_contents($root . '/images/late-trns.png', substr($bin, 0, $plte) . $chunk('tEXt', 'Comment' . "\0" . str_repeat('z', 100000)) . substr($bin, $plte));
$check('transparent: a tRNS after 100 KB of other chunks', ImageFormats::transparent($root . '/images/late-trns.png'), true);
file_put_contents($root . '/images/text-trns.png', substr($bin, 0, $plte) . $chunk('tEXt', 'Comment' . "\0" . 'tRNS in a text') . substr(str_replace('tRNS', 'tRNx', substr($bin, $plte, 200000)), 0));
$check('transparent: the word tRNS inside another chunk does not count', ImageFormats::transparent($root . '/images/text-trns.png'), false);
@unlink($root . '/images/late-trns.png');
@unlink($root . '/images/text-trns.png');
foreach (['images/sub/alpha.png', 'images/opaque.png'] as $u) {
    $op = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake-opaque']);
    $op->lookup('/' . $u);
    $op->save();
}
// alpha.png has its AVIF already: removed to be made again with the opaque way
$ae = (new ImageOptimizer($root, $site, '', $cache, 1000))->entries()['images/sub/alpha.png']['a'] ?? [];
foreach ($ae as $c) {
    @unlink($root . '/' . $c);
}
$op = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake-opaque']);
$op->lookup('/images/sub/alpha.png');
$op->save();
(new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake-opaque'])->work(60.0);
$oe = (new ImageOptimizer($root, $site, '', $cache, 1000))->entries();
$check('a way losing transparency: none for a transparent PNG, made for an opaque one', [$oe['images/sub/alpha.png']['a'] ?? null, count($oe['images/opaque.png']['a'] ?? [])], [[], 3]);

// a picture that cannot be in a <picture> (a background, a link) does not ask for the newer formats
$third = imagecreatetruecolor(700, 500);
for ($i = 0; $i < 2000; $i++) {
    imagefilledellipse($third, mt_rand(0, 700), mt_rand(0, 500), mt_rand(5, 50), mt_rand(5, 50), imagecolorallocate($third, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagejpeg($third, $root . '/images/background.jpg', 92);
$bg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake']);
$bg->lookup('/images/background.jpg', true, false);
$bg->save();
(new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake'])->work(60.0, ImageOptimizer::MAX_PIXELS, false);
$bg = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake']);
$bgSeen = $bg->lookup('/images/background.jpg', true, false);
$check('a background: WebP copies, not queued again for AVIF', [isset($bgSeen['srcset']), $bg->pending()], [true, 0]);
$bg->lookup('/images/background.jpg');
$check('…the same picture as an <img>: queued for AVIF', $bg->pending(), 1);
$bg->save();
(new ImageOptimizer($root, $site, '', $cache, 1000))->work(1.0);

// a PNG left by an encoder PHP stopped (*.png.tmp) goes with the other half-written files
file_put_contents($root . '/media/mertools-img/x.webp.1.png.tmp', 'x');
touch($root . '/media/mertools-img/x.webp.1.png.tmp', $now - 7200);
(new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake'])->sweep($now);
$check('sweep: a PNG an encoder left behind removed after an hour', is_file($root . '/media/mertools-img/x.webp.1.png.tmp'), false);

// the sweep removes AVIF copies no picture uses any more
file_put_contents($root . '/media/mertools-img/stray.avif', 'x');
$sw2 = (new ImageOptimizer($root, $site, '', $cache, 1000))->formats(['avif' => 'fake']);
$check('sweep: an AVIF copy no entry names is unused, removed a week after', [$sw2->sweep($now)['unused'], $sw2->sweep($now + ImageOptimizer::GRACE + 1)['removed'], is_file($root . '/media/mertools-img/stray.avif')], [1, 1, false]);
$avifNow = count(glob($root . '/media/mertools-img/*/*.avif'));
$sw3 = new ImageOptimizer($root, $site, '', $cache, 1000);
$r3  = $sw3->sweep($now);
$check('sweep with AVIF switched off: its copies unused, the entries without them', [$r3['unused'], isset($sw3->entries()['images/photo one.jpg']['a']), $avifNow > 0], [$avifNow, false, true]);
$check('…removed a week later, the WebP copies kept', [$sw3->sweep($now + ImageOptimizer::GRACE + 1)['removed'], count(glob($root . '/media/mertools-img/*/*.avif')), isset($sw3->lookup('/images/photo one.jpg')['srcset'])], [$avifNow, 0, true]);
ImageFormats::fake(null);
(new ImageOptimizer($root, $site, '', $cache, 1000))->clear();
$check('clear: the AVIF copies gone too', glob($root . '/media/mertools-img/*/*.avif'), []);

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
