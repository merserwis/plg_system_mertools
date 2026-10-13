<?php

/**
 * Test of ImageOptimizer with PHP's GD (needs gd with WebP).
 * Run: php tests/ImageOptimizerTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageOptimizer.php';

use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;

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

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
