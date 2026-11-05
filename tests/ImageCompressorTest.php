<?php

/**
 * Test of ImageCompressor and CompressStore with PHP's GD (the lighter originals, their copies).
 * Run: php tests/ImageCompressorTest.php            (GD only; jpegoptim / pngquant, when installed, tested too)
 *      MT_NO_PROGRAMS=1 php tests/ImageCompressorTest.php   (as on a server without them)
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageSize.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ImageCompressor.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/CompressStore.php';

use Merserwis\Plugin\System\MerTools\Tool\CompressStore;
use Merserwis\Plugin\System\MerTools\Tool\ImageCompressor;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-66s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$root = sys_get_temp_dir() . '/mtcmp-' . getmypid();
@mkdir($root . '/images/sub', 0777, true);
@mkdir($root . '/images/skipme', 0777, true);
@mkdir($root . '/images/.hidden', 0777, true);

$exif = function (int $o): string {
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $o) . "\0\0" . pack('V', 0);
    $data = "Exif\0\0" . $tiff;

    return "\xFF\xE1" . pack('n', strlen($data) + 2) . $data;
};
// an ICC profile segment (the bytes are only carried over, not read)
$iccData = "ICC_PROFILE\0\x01\x01" . str_repeat('P', 600);
$icc     = "\xFF\xE2" . pack('n', strlen($iccData) + 2) . $iccData;

// a photo 1200x800, red in its stored top-left corner, saved at quality 95 with EXIF (orientation 6) and ICC
$im = imagecreatetruecolor(1200, 800);
for ($i = 0; $i < 3000; $i++) {
    imagefilledellipse($im, mt_rand(0, 1200), mt_rand(0, 800), mt_rand(5, 80), mt_rand(5, 80), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 255, 0, 0));
ob_start();
imagejpeg($im, null, 95);
$jpeg = (string) ob_get_clean();
// GD writes a JFIF header first: EXIF and ICC after it
$jfifLen = (ord($jpeg[4]) << 8 | ord($jpeg[5])) + 2;
file_put_contents($root . '/images/photo.jpg', substr($jpeg, 0, 2 + $jfifLen) . $exif(1) . $icc . substr($jpeg, 2 + $jfifLen));
file_put_contents($root . '/images/sub/turned.jpg', "\xFF\xD8" . $exif(6) . $icc . substr($jpeg, 2));

// a graphic PNG with transparency: few colours, soft edge
$png = imagecreatetruecolor(800, 500);
imagealphablending($png, false);
imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
for ($r = 240; $r > 0; $r -= 4) {
    imagefilledellipse($png, 400, 250, $r * 3, $r * 2, imagecolorallocatealpha($png, 30 + $r % 200, 90, 200 - $r % 150, (int) (100 * $r / 240)));
}
imagefilledrectangle($png, 20, 20, 140, 90, imagecolorallocatealpha($png, 250, 200, 0, 0));
imagepng($png, $root . '/images/logo.png', 0);
// a PNG of pure noise: a palette would change it too much
$noise = imagecreatetruecolor(400, 300);
for ($y = 0; $y < 300; $y++) {
    for ($x = 0; $x < 400; $x++) {
        imagesetpixel($noise, $x, $y, mt_rand(0, 0xFFFFFF));
    }
}
imagepng($noise, $root . '/images/noise.png', 9);
imagepng(imagecreatetruecolor(30, 30), $root . '/images/tiny.png');
copy($root . '/images/logo.png', $root . '/images/skipme/logo.png');
copy($root . '/images/logo.png', $root . '/images/.hidden/logo.png');
file_put_contents($root . '/images/notes.txt', 'x');
// an animated PNG: an acTL chunk after the header
$bin  = (string) file_get_contents($root . '/images/logo.png');
$actl = pack('N', 8) . 'acTL' . pack('NN', 2, 0);
$actl .= pack('N', crc32(substr($actl, 4)));
file_put_contents($root . '/images/anim.png', substr($bin, 0, 33) . $actl . substr($bin, 33));

$programs = getenv('MT_NO_PROGRAMS') ? [] : null;
ImageCompressor::setPrograms($programs);
$engines = ImageCompressor::engines();
printf("engines: gd=%s exec=%s jpegoptim=%s pngquant=%s\n", var_export($engines['gd'], true), var_export($engines['exec'], true), $engines['jpegoptim'] ?: '-', $engines['pngquant'] ?: '-');
$check('GD with JPEG and PNG', $engines['gd'], true);

// ---------------------------------------------------------------- one file (PHP's GD)
$gd  = new ImageCompressor(['engine' => 'php', 'jpeg' => 80, 'png' => 70, 'min' => 10]);
$out = $root . '/out.jpg';
$r   = $gd->compress($root . '/images/photo.jpg', $out);
$check('JPEG: compressed', [$r['status'], $r['type'], $r['method'], $r['file']], ['ok', 'jpeg', 'gd', true]);
$check('JPEG: at least 10 % lighter', $r['after'] <= $r['before'] * 0.9 && $r['after'] === filesize($out), true);
$check('JPEG: marked', ImageCompressor::marked($out), true);
$check('JPEG: original not marked', ImageCompressor::marked($root . '/images/photo.jpg'), false);
$outBin = (string) file_get_contents($out);
$check('JPEG: colour profile kept', ImageCompressor::jpegIcc($outBin), $icc);
$check('JPEG: no EXIF left', array_values(array_filter(ImageCompressor::jpegSegments($outBin), fn ($s) => $s[0] === 0xE1)), []);
$check('JPEG: JFIF header first, then the mark', [ImageCompressor::jpegSegments($outBin)[0][0], ImageCompressor::jpegSegments($outBin)[1][0]], [0xE0, 0xFE]);
$check('JPEG: still a JPEG of the same size', array_slice(getimagesize($out), 0, 3), [1200, 800, IMAGETYPE_JPEG]);
$check('JPEG: progressive', (bool) preg_match('#\xFF\xC2#', substr($outBin, 0, 4000)), true);

$r = $gd->compress($root . '/images/sub/turned.jpg', $out);
$check('JPEG on its side: turned upright (800x1200)', [$r['status'], array_slice(getimagesize($out), 0, 2)], ['ok', [800, 1200]]);
$t = imagecreatefromjpeg($out);
$c = imagecolorat($t, 750, 50);
$check('JPEG on its side: red corner now top-right', (($c >> 16) & 255) > 150 && (($c >> 8) & 255) < 110, true);

$r = $gd->compress($out, $root . '/again.jpg');
$check('compressed picture never again', [$r['status'], $r['reason'], is_file($root . '/again.jpg')], ['skip', 'marked', false]);

$out = $root . '/out.png';
$r   = $gd->compress($root . '/images/logo.png', $out);
$check('PNG graphic: palette, lighter', [$r['status'], $r['method'], $r['lossy']], ['ok', 'gd', true]);
$check('PNG graphic: marked, still PNG 800x500', [ImageCompressor::marked($out), array_slice(getimagesize($out), 0, 3)], [true, [800, 500, IMAGETYPE_PNG]]);
$p = imagecreatefrompng($out);
$check('PNG graphic: transparent corner stays transparent', imagecolorsforindex($p, imagecolorat($p, 2, 2))['alpha'], 127);
$yel = imagecolorsforindex($p, imagecolorat($p, 60, 50));
$check('PNG graphic: opaque yellow box stays', $yel['alpha'] === 0 && $yel['red'] > 230 && $yel['green'] > 180 && $yel['blue'] < 40, true);
$check('PNG graphic: a palette picture', imageistruecolor($p), false);

$r = $gd->compress($root . '/images/noise.png', $root . '/noise.png');
$check('PNG noise: palette refused, saved without loss — not worth it', [$r['status'], $r['reason'], $r['lossy']], ['skip', 'gain', false]);
$check('PNG noise: the try is still there (for the test window)', $r['file'] && is_file($root . '/noise.png'), true);

$check('tiny picture skipped', $gd->compress($root . '/images/tiny.png', $root . '/x.png')['reason'], 'small');
$check('animated PNG skipped', $gd->compress($root . '/images/anim.png', $root . '/x.png')['reason'], 'animated');
$check('not a picture', $gd->compress($root . '/images/notes.txt', $root . '/x.png')['reason'], 'type');
$check('missing file fails', $gd->compress($root . '/images/none.jpg', $root . '/x.png')[ 'status'], 'fail');
$lossless = new ImageCompressor(['engine' => 'php', 'png' => 100, 'min' => 1]);
$r = $lossless->compress($root . '/images/logo.png', $root . '/ll.png');
$check('PNG quality 100: without loss only', [$r['status'], $r['lossy'], imageistruecolor(imagecreatefrompng($root . '/ll.png'))], ['ok', false, true]);

// the colour chunks of a PNG kept
$srgb = pack('N', 1) . 'sRGB' . "\0";
$srgb .= pack('N', crc32('sRGB' . "\0"));
$withSrgb = substr($bin, 0, 33) . $srgb . substr($bin, 33);
$marked   = ImageCompressor::pngWithMark($withSrgb, ImageCompressor::pngColourChunks($withSrgb), ImageCompressor::MARK . ' x');
$types    = array_map(fn ($c) => $c[0], ImageCompressor::pngChunks($marked));
$check('PNG: header, mark, colour, data', array_slice($types, 0, 3), ['IHDR', 'tEXt', 'sRGB']);
$check('PNG: sRGB once', count(array_keys($types, 'sRGB')), 1);
file_put_contents($root . '/m.png', $marked);
$check('PNG mark read back', ImageCompressor::marked($root . '/m.png'), true);
$check('PNG with the mark still readable', is_array(getimagesize($root . '/m.png')) && imagecreatefrompng($root . '/m.png') !== false, true);

// ---------------------------------------------------------------- the programs (when the server has them)
if ($engines['jpegoptim'] !== '') {
    $auto = new ImageCompressor(['engine' => 'auto', 'jpeg' => 80]);
    $r    = $auto->compress($root . '/images/photo.jpg', $root . '/jo.jpg');
    $jo   = (string) file_get_contents($root . '/jo.jpg');
    $check('jpegoptim: compressed, marked, profile kept', [$r['status'], $r['method'], ImageCompressor::marked($root . '/jo.jpg'), ImageCompressor::jpegIcc($jo) === $icc], ['ok', 'jpegoptim', true, true]);
    $check('jpegoptim: no EXIF left', array_values(array_filter(ImageCompressor::jpegSegments($jo), fn ($s) => $s[0] === 0xE1)), []);
    $r = $auto->compress($root . '/images/sub/turned.jpg', $root . '/jo2.jpg');
    $check('jpegoptim: a photo on its side goes to GD (turned)', [$r['method'], array_slice(getimagesize($root . '/jo2.jpg'), 0, 2)], ['gd', [800, 1200]]);
}
if ($engines['pngquant'] !== '') {
    $auto = new ImageCompressor(['engine' => 'auto', 'png' => 70]);
    $r    = $auto->compress($root . '/images/logo.png', $root . '/pq.png');
    $check('pngquant: compressed, marked', [$r['status'], $r['method'], ImageCompressor::marked($root . '/pq.png')], ['ok', 'pngquant', true]);
    $r = $auto->compress($root . '/images/noise.png', $root . '/pq2.png');
    $check('pngquant: noise refused, GD without loss', [$r['method'], $r['lossy']], ['gd', false]);
}

// ---------------------------------------------------------------- the store: replace, copy, restore
$now   = 1800000000;
$store = new CompressStore($root, 'images', ['skipme', 'images/none'], $now);
$list  = $store->candidates();
$check('candidates: JPEG and PNG, no hidden, no left out folder', $list, ['images/anim.png', 'images/logo.png', 'images/noise.png', 'images/photo.jpg', 'images/sub/turned.jpg', 'images/tiny.png']);
$check('left out folder', [$store->excluded('images/skipme/logo.png'), $store->excluded('images/skipmeNOT/a.png')], [true, false]);

$origLogo = (string) file_get_contents($root . '/images/logo.png');
chmod($root . '/images/logo.png', 0640);
$e = $store->process($gd, 'images/logo.png');
$check('process: replaced', [$e['status'], ImageCompressor::marked($root . '/images/logo.png'), $e['after'] === filesize($root . '/images/logo.png')], ['ok', true, true]);
// the copies: under a random key (a server that does not read .htaccess, nginx, must not serve them at a known address)
$copyOf = function (string $rel) use ($root): string {
    $raw = (string) @file_get_contents($root . '/' . CompressStore::DIR . '/key.php');
    $key = (string) (json_decode(substr($raw, strpos($raw, "\n") + 1), true)['key'] ?? '');

    return $root . '/' . CompressStore::DIR . '/' . $key . '/' . $rel;
};
$check('process: original kept as a copy', file_get_contents($copyOf('images/logo.png')) === $origLogo, true);
$check('process: the copy not at a known address', [is_file($root . '/' . CompressStore::DIR . '/images/logo.png'), (bool) preg_match('#/[0-9a-f]{32}/images/logo\.png$#', $copyOf('images/logo.png'))], [false, true]);
$check('process: file mode kept', fileperms($root . '/images/logo.png') & 0777, 0640);
$check('process: no temporary file left', glob($root . '/images/.mt-*') ?: [], []);
$check('copies folder guarded', is_file($root . '/' . CompressStore::DIR . '/.htaccess'), true);
$check('process again: marked', $store->process($gd, 'images/logo.png')['reason'], 'marked');

$e = $store->process($gd, 'images/noise.png');
$check('process noise: not worth it, file untouched', [$e['reason'], ImageCompressor::marked($root . '/images/noise.png')], ['gain', false]);
$check('process noise again: tried before', $store->process($gd, 'images/noise.png')['reason'], 'tried');
$other = new ImageCompressor(['engine' => 'php', 'png' => 50, 'min' => 10]);
$check('process noise with other settings: tried again', $store->process($other, 'images/noise.png')['reason'], 'gain');

$st = $store->stats();
$check('stats: one compressed, one copy', [$st['compressed'], $st['backups'], $st['backupBytes'] === strlen($origLogo)], [1, 1, true]);
$check('backups list', array_map(fn ($b) => $b['rel'], $store->backups()), ['images/logo.png']);

$check('restore', $store->restore('images/logo.png'), 'ok');
$check('restore: the original back, copy gone', [file_get_contents($root . '/images/logo.png') === $origLogo, is_file($copyOf('images/logo.png'))], [true, false]);
$check('restored picture not compressed again by itself', $store->process($gd, 'images/logo.png')['reason'], 'restored');
$check('restore again: no copy', $store->restore('images/logo.png'), 'gone');
$check('restore: a path out of the folder refused', $store->restore('../etc/passwd'), 'gone');

// a picture uploaded again after it was compressed: its copy is not put back over it
$store->process($gd, 'images/photo.jpg');
copy($root . '/images/sub/turned.jpg', $root . '/images/photo.jpg');
$check('restore over a newer upload refused', $store->restore('images/photo.jpg'), 'changed');
// a newer upload under the name that carries the mark too (a file downloaded from the try of the panel)
$gd->compress($root . '/images/sub/turned.jpg', $root . '/marked-other.jpg');
copy($root . '/marked-other.jpg', $root . '/images/photo.jpg');
$check('restore over a newer upload with the mark refused', [$store->restore('images/photo.jpg'), md5_file($root . '/images/photo.jpg') === md5_file($root . '/marked-other.jpg')], ['changed', true]);
copy($root . '/images/sub/turned.jpg', $root . '/images/photo.jpg');

// expiry
$later = new CompressStore($root, 'images', [], $now + 31 * 86400);
$check('copies older than 30 days removed', [$later->expire(30), $later->stats()['backups']], [1, 0]);
$check('copies folder emptied', glob(dirname($copyOf('images/x')) . '/*') ?: [], []);

// ---------------------------------------------------------------- uploads, compress all
@unlink($root . '/images/sub/turned.jpg');
file_put_contents($root . '/images/sub/new.jpg', substr($jpeg, 0));
touch($root . '/images/sub/new.jpg', $now + 100);
$store = new CompressStore($root, 'images', ['skipme'], $now + 200);
$check('upload: the new picture noted', $store->noteNew($now + 50), 1);
$done = $store->work($gd, 10);
$check('upload: compressed', [count($done), $done[0]['rel'] ?? '', $done[0]['status'] ?? ''], [1, 'images/sub/new.jpg', 'ok']);
$check('upload: nothing waits', $store->stats()['pending'], 0);

// the paths an uploader names in its answer (no look through the folder)
@mkdir($root . '/images/sub/new dir', 0777, true);
foreach (['images/sub/g.jpg', 'images/sub/m.png', 'images/sub/new dir/zażółć ę.jpg', 'images/skipme/x.jpg', 'images/old.jpg', 'images/sub/a.webp'] as $f) {
    file_put_contents($root . '/' . $f, $jpeg);
    touch($root . '/' . $f, $f === 'images/old.jpg' ? $now - 100 : $now + 300);
}
$since = $now + 250;
$check('said: Gridbox (escaped slashes)', $store->pathsIn('{"ext":"jpg","name":"g.jpg","folder":"images\/sub\/","path":"images\/sub\/g.jpg","url":"images\/sub\/g.jpg"}', $since), ['images/sub/g.jpg']);
$check('said: Joomla media manager (adapter, unicode escaped, space)', $store->pathsIn(json_encode(['data' => ['path' => 'local-images:/sub/new dir/zażółć ę.jpg', 'url' => 'http://x/images/sub/new%20dir/za%C5%BC%C3%B3%C5%82%C4%87%20%C4%99.jpg']]), $since), ['images/sub/new dir/zażółć ę.jpg']);
$check('said: gzip and deflate (Joomla gzip)', [$store->pathsIn(gzencode('{"path":"images/sub/m.png"}'), $since), $store->pathsIn(gzcompress('{"path":"images/sub/m.png"}'), $since)], [['images/sub/m.png'], ['images/sub/m.png']]);
$check('said: an address in HTML', $store->pathsIn('<img src="https://site.pl/images/sub/m.png?v=2"> <a href=/images/sub/g.jpg>', $since), ['images/sub/g.jpg', 'images/sub/m.png']);
$check('said: left out, older, missing, other type, outside, hidden, ..', $store->pathsIn('images/skipme/x.jpg images/old.jpg images/sub/none.jpg images/sub/a.webp myimages/sub/g.jpg images/.hidden/a.jpg images/sub/../sub/g.jpg', $since), []);
$check('said: nothing', [$store->pathsIn('', $since), $store->pathsIn('{"success":true}', $since)], [[], []]);
// a name with a space before another ".jpg": the whole name, not the start of it
file_put_contents($root . '/images/sub/g.jpg 2.jpg', $jpeg);
touch($root . '/images/sub/g.jpg 2.jpg', $now + 300);
$check('said: a whole name with a space (JSON, HTML in quotes)', [$store->pathsIn('{"path":"images\/sub\/g.jpg 2.jpg"}', $since), $store->pathsIn('<img src="/images/sub/g.jpg 2.jpg">', $since)], [['images/sub/g.jpg 2.jpg'], ['images/sub/g.jpg 2.jpg']]);
unlink($root . '/images/sub/g.jpg 2.jpg');
// a folder linked to another place: not followed (by the answer nor by process())
@mkdir($root . '/outside', 0777, true);
file_put_contents($root . '/outside/a.jpg', $jpeg);
touch($root . '/outside/a.jpg', $now + 300);
symlink($root . '/outside', $root . '/images/link');
$check('said: a linked folder not followed', [$store->pathsIn('{"path":"images/link/a.jpg"}', $since), $store->process($gd, 'images/link/a.jpg')['reason'], ImageCompressor::marked($root . '/outside/a.jpg')], [[], 'read', false]);
unlink($root . '/images/link');
unlink($root . '/outside/a.jpg');
rmdir($root . '/outside');
$check('said: noted', $store->notePaths(['images/sub/g.jpg', 'images/sub/m.png']), true);
$done = $store->work($gd, 10);
$check('said: both compressed, nothing else', [array_column($done, 'rel'), ImageCompressor::marked($root . '/images/sub/new dir/zażółć ę.jpg')], [['images/sub/g.jpg', 'images/sub/m.png'], false]);

// an answer that names nothing: the look through the folder later, for the time of the upload only
$check('scan: noted, nothing waits yet', [$store->noteScan($now + 290, $now + 310), $store->noteScan($now + 295, $now + 305), $store->stats()['pending'], $store->waiting()], [true, true, 0, true]);
$check('scan: each look counted (one going on keeps a later one)', $store->state()['scan']['n'] ?? 0, 2);
$check('scan: not done when asked not to', [$store->work($gd, 10, false), $store->waiting()], [[], true]);
$done = $store->work($gd, 10);
$check('scan: the picture of that time compressed, older and left out not', array_column($done, 'rel'), ['images/sub/new dir/zażółć ę.jpg']);
$check('scan: nothing waits', $store->waiting(), false);
foreach (['images/sub/g.jpg', 'images/sub/m.png', 'images/sub/new dir/zażółć ę.jpg', 'images/skipme/x.jpg', 'images/old.jpg', 'images/sub/a.webp'] as $f) {
    $store->restore($f);
    @unlink($root . '/' . $f);
}
@rmdir($root . '/images/sub/new dir');

$check('compress all: the list', $store->jobStart(), 6);
$steps   = 0;
$reasons = [];
do {
    $run = $store->jobRun($gd, 0.0);
    foreach ($run['entries'] as $e) {
        $reasons[$e['rel']] = $e['status'] . '/' . $e['reason'];
    }
    $steps++;
} while (!$run['done'] && $steps < 20);
$check('compress all: a picture a step at the least, then done', [$steps, $run['pos'], $run['total']], [6, 6, 6]);
$check('compress all: run removed when done', $store->jobStatus(), null);
ksort($reasons);
$check('compress all: what each gave', $reasons, [
    'images/anim.png' => 'skip/animated', 'images/logo.png' => 'skip/restored', 'images/noise.png' => 'skip/gain',
    'images/photo.jpg' => 'ok/', 'images/sub/new.jpg' => 'skip/marked', 'images/tiny.png' => 'skip/small',
]);
$check('compress all: sums', [$run['sum']['ok'], $run['sum']['skip']], [1, 5]);
$store->jobStart();
$run = $store->jobRun($gd, 30);
$check('a second run: the animated PNG noted, the photo marked', array_column(array_map(fn ($e) => [$e['rel'], $e['reason']], $run['entries']), 1, 0)
    + [], ['images/anim.png' => 'tried', 'images/logo.png' => 'restored', 'images/noise.png' => 'tried', 'images/photo.jpg' => 'marked', 'images/sub/new.jpg' => 'marked', 'images/tiny.png' => 'small']);
$check('a run stopped half-way goes on', (function () use ($store, $gd) {
    $store->jobStart();
    $store->jobRun($gd, 0.0);
    $mid = $store->jobStatus();

    return $mid !== null && $mid['pos'] === 1 && $mid['total'] === 6;
})(), true);
$store->jobCancel();
$all = $store->restoreAll(10);
$check('restore all', array_diff_key($all, ['entries' => 1]), ['restored' => 2, 'changed' => 0, 'failed' => 0, 'left' => 0]);
$check('…each picture in the entries', array_column($all['entries'], 'result', 'rel'), ['images/sub/new.jpg' => 'ok', 'images/photo.jpg' => 'ok']);

// the notes cannot be written (a full disk: here state.php is a folder): the picture gets its original back at once
$fresh = $root . '/images/sub/fresh.jpg';
file_put_contents($fresh, $jpeg);
$freshMd5 = md5_file($fresh);
$state = $root . '/' . CompressStore::DIR . '/state.php';
$saved = (string) file_get_contents($state);
unlink($state);
mkdir($state);
$e = (new CompressStore($root, 'images', [], $now + 300))->process($gd, 'images/sub/fresh.jpg');
$check('notes not written: a failure, the original back', [$e['status'], $e['reason'], md5_file($fresh) === $freshMd5, glob(dirname($fresh) . '/.mt-*') ?: []], ['fail', 'state', true, []]);
$check('…and no copy left behind', is_file($copyOf('images/sub/fresh.jpg')), false);
rmdir($state);
file_put_contents($state, $saved);

// restore all with a picture that cannot be written (its path taken by a folder): counted as failed, its copy stays
$s2 = new CompressStore($root, 'images', ['skipme'], $now + 400);
$check('fresh picture compressed', $s2->process($gd, 'images/sub/fresh.jpg')['status'], 'ok');
file_put_contents($root . '/images/sub/later.jpg', $jpeg);
$check('…and one after it', $s2->process($gd, 'images/sub/later.jpg')['status'], 'ok');
unlink($fresh);
mkdir($fresh);
touch($fresh . '/x');
// a part out of time still takes one picture; the failed one stays at the front, skip passes over it
$check('restore all: a failure counted', $s2->restoreAll(0.0), ['restored' => 0, 'changed' => 0, 'failed' => 1, 'left' => 1,
    'entries' => [['rel' => 'images/sub/fresh.jpg', 'result' => 'fail']]]);
$check('…the next part passes over it', $s2->restoreAll(0.0, 1), ['restored' => 1, 'changed' => 0, 'failed' => 0, 'left' => 0,
    'entries' => [['rel' => 'images/sub/later.jpg', 'result' => 'ok']]]);
$check('…its copy stays', is_file($copyOf('images/sub/fresh.jpg')), true);
unlink($fresh . '/x');
rmdir($fresh);

// ---------------------------------------------------------------- size on upload: made to fit a frame
$check('fit: a photo into a square frame', ImageCompressor::fit(4000, 3000, [1600, 1600]), [1600, 1200]);
$check('fit: a smaller picture stays', ImageCompressor::fit(1000, 800, [1600, 1600]), null);
$check('fit: only the height limited', ImageCompressor::fit(3000, 4000, [0, 1000]), [750, 1000]);
$check('fit: no limits', ImageCompressor::fit(4000, 3000, [0, 0]), null);
$check('fit: exactly the frame', ImageCompressor::fit(1600, 900, [1600, 1600]), null);
$check('fit: never a side over its limit', ImageCompressor::fit(1001, 333, [500, 0]), [500, 166]);
@mkdir($root . '/images/rsz', 0777, true);
file_put_contents($root . '/images/rsz/photo.jpg', substr($jpeg, 0, 2 + $jfifLen) . $exif(1) . $icc . substr($jpeg, 2 + $jfifLen));
file_put_contents($root . '/images/rsz/turned.jpg', "\xFF\xD8" . $exif(6) . $icc . substr($jpeg, 2));
imagepng($png, $root . '/images/rsz/logo.png');
$r = $gd->compress($root . '/images/rsz/photo.jpg', $out = $root . '/r1.jpg', [600, 600]);
$check('resize JPEG: made smaller, kept its proportions', [$r['status'], $r['resized'], array_slice(getimagesize($out), 0, 3)], ['ok', [1200, 800, 600, 400], [600, 400, IMAGETYPE_JPEG]]);
$check('resize JPEG: marked, colour profile kept, no EXIF', [ImageCompressor::marked($out), ImageCompressor::jpegIcc((string) file_get_contents($out)) === $icc,
    array_values(array_filter(ImageCompressor::jpegSegments((string) file_get_contents($out)), fn ($s) => $s[0] === 0xE1))], [true, true, []]);
$r = $gd->compress($root . '/images/rsz/turned.jpg', $out = $root . '/r2.jpg', [600, 600]);
$check('resize JPEG on its side: the frame counts it as shown (800x1200 → 400x600)', [$r['resized'], array_slice(getimagesize($out), 0, 2)], [[800, 1200, 400, 600], [400, 600]]);
$c = imagecolorat(imagecreatefromjpeg($out), 390, 8);
$check('resize JPEG on its side: turned upright, red corner top-right', (($c >> 16) & 255) > 150 && (($c >> 8) & 255) < 110, true);
$r = $gd->compress($root . '/images/rsz/logo.png', $out = $root . '/r3.png', [400, 0]);
$p = imagecreatefrompng($out);
$check('resize PNG: 800x500 → 400x250, transparency kept', [$r['status'], $r['resized'], array_slice(getimagesize($out), 0, 2), imagecolorsforindex($p, imagecolorat($p, 1, 1))['alpha']],
    ['ok', [800, 500, 400, 250], [400, 250], 127]);
$check('resize: a picture that fits, only resizing: left as it is', [$gd->compress($root . '/images/rsz/logo.png', $root . '/r4.png', [2000, 2000], false)['reason'], is_file($root . '/r4.png')], ['fits', false]);
$check('resize: a picture that fits, with compression: compressed as before', $gd->compress($root . '/images/rsz/logo.png', $root . '/r4.png', [2000, 2000])['status'], 'ok');
$check('resize: a marked picture larger than the frame made smaller', [$gd->compress($root . '/r1.jpg', $root . '/r5.jpg', [300, 300])['status'], array_slice(getimagesize($root . '/r5.jpg'), 0, 2)], ['ok', [300, 200]]);
$flat = imagecreatetruecolor(900, 900);
imagefill($flat, 0, 0, imagecolorallocate($flat, 20, 120, 220));
imagepng($flat, $root . '/images/rsz/flat.png');
$check('resize: a light picture (under 5 kB) larger than the frame made smaller too', [filesize($root . '/images/rsz/flat.png') < ImageCompressor::MIN_BYTES,
    $gd->compress($root . '/images/rsz/flat.png', $root . '/r6.png', [300, 300])['resized']], [true, [900, 900, 300, 300]]);

// the store: the place of an upload noted with it, its frame used; only resizing leaves the rest as it is
$s3 = new CompressStore($root, 'images', [], $now + 500);
$check('upload places noted', [$s3->notePaths(['images/rsz/photo.jpg'], 'shop'), $s3->notePaths(['images/rsz/logo.png'], 'blog'), $s3->state()['pending']],
    [true, true, ['images/rsz/photo.jpg' => 'shop', 'images/rsz/logo.png' => 'blog']]);
$logoMd5 = md5_file($root . '/images/rsz/logo.png');
$done = $s3->work($gd, 10, true, ['shop' => [600, 600], 'other' => [100, 100]], false);
$check('work, only resizing: the shop picture to its frame, the blog one (no frame) untouched', [array_column($done, 'resized', 'rel'),
    array_slice(getimagesize($root . '/images/rsz/photo.jpg'), 0, 2), md5_file($root . '/images/rsz/logo.png') === $logoMd5],
    [['images/rsz/photo.jpg' => [1200, 800, 600, 400]], [600, 400], true]);
$check('…nothing waits', $s3->waiting(), false);
$b = array_column($s3->backups('rsz'), 'r', 'rel');
$check('…the copy lists the sizes', $b, ['images/rsz/photo.jpg' => [1200, 800, 600, 400]]);
$check('…restore brings the large original back', [$s3->restore('images/rsz/photo.jpg'), array_slice(getimagesize($root . '/images/rsz/photo.jpg'), 0, 2)], ['ok', [1200, 800]]);
// a place not known (a look through the folder, an older version): the frame of other
file_put_contents($root . '/images/rsz/new.jpg', $jpeg);
touch($root . '/images/rsz/new.jpg', $now + 600);
$s3->noteScan($now + 590, $now + 610);
$done = $s3->work($gd, 10, true, ['other' => [300, 0]], true);
$check('work: a picture found by a look gets the frame of other', array_column($done, 'resized', 'rel'), ['images/rsz/new.jpg' => [1200, 800, 300, 200]]);

// a picture MerTools made smaller found again (a look through the folder of another upload, a smaller frame):
// left as it is, its copy of the large original kept (review: the copy was overwritten by the smaller picture)
file_put_contents($root . '/images/rsz/big.jpg', $jpeg);
touch($root . '/images/rsz/big.jpg', $now + 700);
$s4 = new CompressStore($root, 'images', [], $now + 800);
$s4->notePaths(['images/rsz/big.jpg'], 'shop');
$s4->work($gd, 10, true, ['shop' => [600, 600], 'other' => [300, 300]], false);
$made = md5_file($root . '/images/rsz/big.jpg');
clearstatcache();
$t = filemtime($root . '/images/rsz/big.jpg');
$s4->noteScan($t - 1, $t + 1);
$done = $s4->work($gd, 10, true, ['shop' => [600, 600], 'other' => [300, 300]], false);
$check('found again by a look: left as it is (marked), not made smaller again', [array_column($done, 'reason', 'rel')['images/rsz/big.jpg'] ?? 'not tried',
    array_slice(getimagesize($root . '/images/rsz/big.jpg'), 0, 2), md5_file($root . '/images/rsz/big.jpg') === $made], ['marked', [600, 400], true]);
$check('…its copy still the large original', [$s4->restore('images/rsz/big.jpg'), array_slice(getimagesize($root . '/images/rsz/big.jpg'), 0, 2)], ['ok', [1200, 800]]);

// a look through the folder keeps the place an upload already noted (review: it turned "blog" into "other")
file_put_contents($root . '/images/rsz/post.jpg', $jpeg);
touch($root . '/images/rsz/post.jpg', $now + 900);
$s5 = new CompressStore($root, 'images', [], $now + 1000);
$s5->notePaths(['images/rsz/post.jpg'], 'blog');
$s5->noteScan($now + 890, $now + 910);
$done = $s5->work($gd, 10, true, ['other' => [300, 300]], false);
$check('a look keeps the place noted: the blog (no frame) picture untouched', [array_column($done, 'rel'), array_slice(getimagesize($root . '/images/rsz/post.jpg'), 0, 2)], [[], [1200, 800]]);

// cleanup
$rm = function (string $dir) use (&$rm) {
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        is_dir("$dir/$n") && !is_link("$dir/$n") ? $rm("$dir/$n") : unlink("$dir/$n");
    }
    rmdir($dir);
};
$rm($root);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
