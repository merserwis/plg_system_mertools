<?php

/**
 * Test of ImageOptimizer with PHP's GD (needs gd with WebP).
 * Run: php tests/ImageOptimizerTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageOptimizer.php';

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
$manifest = json_decode(file_get_contents($cache . '/mertools-images.json'), true);
unset($manifest['images/turn-6.jpg']['o'], $manifest['images/turn-1.jpg']['o']);
file_put_contents($cache . '/mertools-images.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
$older = new ImageOptimizer($root, $site, '', $cache, 1000);
$check('before 1.7.0, upright photo: copies still offered', isset($older->lookup('/images/turn-1.jpg')['srcset']), true);
$check('before 1.7.0, photo on its side: made again', [$older->lookup('/images/turn-6.jpg'), $older->pending()], [null, 1]);
$check('new entries say how they were turned', [$manifest['images/turn-8.jpg']['o'] ?? null, $manifest['images/sub/alpha.png']['o'] ?? null], [8, 1]);

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

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
