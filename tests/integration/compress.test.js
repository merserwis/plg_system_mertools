/**
 * Integration test of the compressed originals (Images → Compressed originals, MerTools 1.12.0) on a
 * real Joomla + Gridbox in docker (the test copy gbx-sitemap-test): a temporary Super User
 *  - uploads a JPEG through Gridbox's media manager and one through Joomla's media manager: both are
 *    compressed on the server right after the upload, their originals kept as copies — found in what
 *    the uploaders answer, not by a look through the folder (a picture changed by hand is left as it is);
 *  - tries the compression on a picture from the disk in the panel (with an unsaved quality): the
 *    result is shown next to the original, nothing stays on the server;
 *  - compresses all pictures in the window with the log, restores one from the window of the copies,
 *    lets one copy pass its days (removed when the panel opens) and restores all the others;
 *  - sends a JPEG to a page that does not say where it went: the look through the folder waits for the
 *    panel (mod_php: not while the request waits) and takes only the pictures of the time of that request.
 * The pictures, the copies and the settings are put back as they were at the end.
 *
 *   node tests/integration/compress.test.js            the MerTools installed on the copy
 *   node tests/integration/compress.test.js --install  first installs pkg_mertools-<version>.zip (./build.sh)
 *
 * Env: MT_CONTAINER (gbx-sitemap-test), MT_URL (http://127.0.0.1:18096), PLAYWRIGHT (path of playwright-core).
 */
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { C, ADMIN, check, sql, run, install } = require('./lib');

const DIR = 'images/mt-cmp-test';
const WWW = '/var/www/html/';
const sh = (cmd) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'sh', '-c', cmd], { encoding: 'utf8' });
const php = (code) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'php', '-r', code], { encoding: 'utf8' });
const marked = (rel) => execFileSync('docker', ['exec', C, 'head', '-c', '600', WWW + rel]).includes('MerTools compressed');
const md5 = (rel) => sh('md5sum ' + WWW + rel + ' 2>/dev/null | cut -c1-32').trim();
const size = (rel) => Number(sh('stat -c %s ' + WWW + rel).trim());
const exists = (rel) => sh('[ -e ' + WWW + rel + ' ] && echo y || echo n').trim() === 'y';
/** the copy of a picture: under the random key of the copies folder */
const state = () => JSON.parse(php(`$r = @file_get_contents('${WWW}media/mertools-originals/state.php');
  echo $r ? substr($r, strpos($r, "\n") + 1) : '{}';`) || '{}');
const copyOf = (rel) => 'media/mertools-originals/' + php(`$r = @file_get_contents('${WWW}media/mertools-originals/key.php');
  echo $r ? (json_decode(substr($r, strpos($r, "\n") + 1), true)['key'] ?? '') : '';`).trim() + '/' + rel;

// the pictures, made with GD in the container: a photo at quality 95, a graphic PNG, a PNG of noise
const make = (to, kind) => php(`
  $f = '${to}';
  if ('${kind}' === 'photo') { $im = imagecreatetruecolor(1400, 900);
    for ($i = 0; $i < 3500; $i++) imagefilledellipse($im, mt_rand(0, 1400), mt_rand(0, 900), mt_rand(5, 90), mt_rand(5, 90), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    imagejpeg($im, $f, 95); }
  if ('${kind}' === 'logo') { $im = imagecreatetruecolor(900, 500); imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    for ($r = 240; $r > 0; $r -= 3) imagefilledellipse($im, 450, 250, $r * 3, $r * 2, imagecolorallocatealpha($im, 30 + $r % 200, 90, 200 - $r % 150, (int) (100 * $r / 240)));
    imagepng($im, $f, 0); }
  if ('${kind}' === 'noise') { $im = imagecreatetruecolor(300, 200);
    for ($y = 0; $y < 200; $y++) for ($x = 0; $x < 300; $x++) imagesetpixel($im, $x, $y, mt_rand(0, 0xFFFFFF));
    imagepng($im, $f, 9); }`);

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const hadCopies = exists('media/mertools-originals');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'mtcmp-'));

run(async ({ login }) => {
  sh(`rm -rf ${WWW}${DIR} && mkdir -p ${WWW}${DIR}/all`);
  make(WWW + DIR + '/all/photo.jpg', 'photo');
  make(WWW + DIR + '/all/logo.png', 'logo');
  make(WWW + DIR + '/all/noise.png', 'noise');
  make('/tmp/mt-upload.jpg', 'photo');
  execFileSync('docker', ['cp', C + ':/tmp/mt-upload.jpg', path.join(tmp, 'upload.jpg')]);
  const orig = { photo: md5(DIR + '/all/photo.jpg'), logo: md5(DIR + '/all/logo.png'), noise: md5(DIR + '/all/noise.png') };
  const uploadB64 = fs.readFileSync(path.join(tmp, 'upload.jpg')).toString('base64');
  const uploadMd5 = require('crypto').createHash('md5').update(fs.readFileSync(path.join(tmp, 'upload.jpg'))).digest('hex');

  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  // compression on upload on; every other picture of the copy left out, so "compress all" touches only the
  // pictures of this test (the settings put back at the end)
  const others = sh(`ls -A ${WWW}images`).split('\n').map((n) => n.trim()).filter((n) => n && n !== 'mt-cmp-test').map((n) => 'images/' + n);
  sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.cmp_upload', '1', '$.cmp_keep', '30', '$.cmp_exclude', '${others.join('\\n').replace(/'/g, "''")}') WHERE ${PLUGIN}`);
  check('the other pictures of the copy left out: ' + others.length, true);
  page.on('dialog', (d) => d.accept());

  // ---------------------------------------------------------------- uploads
  // a picture changed "during" the uploads (its time in the future) that no uploader names
  make(WWW + DIR + '/late.jpg', 'photo');
  sh(`touch -d '+10 min' ${WWW}${DIR}/late.jpg`);
  await page.goto(ADMIN + 'index.php?option=com_mertools&view=page&page=images&tab=compress_panel');
  const token = await page.evaluate(() => Joomla.getOptions('csrf.token'));
  const gridbox = await page.evaluate(async ({ b64, token, dir }) => {
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const body = new FormData();
    body.append('file', new File([bin], 'gridbox-upload.jpg', { type: 'image/jpeg' }));
    body.append(token, '1');
    const r = await fetch('index.php?option=com_gridbox&task=uploader.executeAction&action=uploadFile&path=' + encodeURIComponent(dir), { method: 'POST', body });
    return r.text();
  }, { b64: uploadB64, token, dir: DIR });
  let gbx = '';
  try { gbx = (JSON.parse(gridbox).path || JSON.parse(gridbox).url || ''); } catch (e) { /* shown below */ }
  check('Gridbox upload answered', /gridbox-upload/.test(gridbox), gridbox.slice(0, 300));
  const media = await page.evaluate(async ({ b64, token }) => {
    const r = await fetch('index.php?option=com_media&task=api.files&format=json&path=local-images:/mt-cmp-test', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ [token]: '1', name: 'media-upload.jpg', content: b64, override: false })
    });
    return r.status;
  }, { b64: uploadB64, token });
  check('Joomla media manager upload answered', media === 200, 'HTTP ' + media);
  const gbxRel = DIR + '/' + (sh(`ls ${WWW}${DIR} | grep gridbox-upload`).trim().split('\n')[0] || 'gridbox-upload.jpg');
  let waited = 0;
  while ((!marked(gbxRel) || !marked(DIR + '/media-upload.jpg')) && waited < 20) {
    await page.waitForTimeout(500);
    waited++;
  }
  check('Gridbox upload compressed on the server', marked(gbxRel), gbx);
  check('…lighter than uploaded', size(gbxRel) < fs.statSync(path.join(tmp, 'upload.jpg')).size * 0.9);
  check('…its original kept as a copy', md5(copyOf(gbxRel)) === uploadMd5);
  check('media manager upload compressed', marked(DIR + '/media-upload.jpg'));
  check('…its original kept as a copy', md5(copyOf(DIR + '/media-upload.jpg')) === uploadMd5);
  check('pictures put in by hand not touched by an upload', md5(DIR + '/all/photo.jpg') === orig.photo);
  check('…nor one changed during them that the uploaders do not name', !marked(DIR + '/late.jpg'));
  const after = state();
  check('the uploads found in the answers: no look through the folder left', !(after.scan && after.scan.since) && Object.keys(after.pending || {}).length === 0, JSON.stringify(after.scan));
  sh(`rm -f ${WWW}${DIR}/late.jpg`);
  check('copies folder closed to the web', exists('media/mertools-originals/.htaccess'));

  // ---------------------------------------------------------------- the panel and a try
  await page.reload();
  await page.waitForSelector('.mt-cmp-figures dt');
  const figures = await page.textContent('.mt-cmp-figures');
  check('panel: what the server uses', /PHP \(GD\)|jpegoptim/.test(figures), figures);
  check('panel: compressed so far and the copies', /2 ·/.test(figures), figures);
  // the field is on the tab of the settings: changed as a user would there
  const setField = (value) => page.evaluate((v) => {
    const f = document.querySelector('[name="jform[params][cmp_jpeg]"]');
    f.value = v;
    f.dispatchEvent(new Event('input', { bubbles: true }));
  }, value);
  await setField('60');
  check('panel: the try names the unsaved quality', /JPEG 60/.test(await page.textContent('.mt-cmp-try-settings')) && /niezapisane|unsaved/.test(await page.textContent('.mt-cmp-try-settings')));
  await page.setInputFiles('[data-mt-cmp-file]', path.join(tmp, 'upload.jpg'));
  await page.click('[data-mt-cmp-try]');
  await page.waitForSelector('.mt-cmp-compare img + .mt-cmp-clip img', { timeout: 60000 });
  const tryText = await page.textContent('.mt-cmp-try-out');
  check('try: sizes before and after shown', /→/.test(tryText) && /−\d+ %/.test(tryText), tryText);
  check('try: would be replaced', !!(await page.$('.mt-cmp-try-out .alert-success')), tryText);
  check('try: the compressed picture can be downloaded', /^data:image\/jpeg;base64,/.test(await page.getAttribute('.mt-cmp-try-out a[download]', 'href') || ''));
  check('try: nothing left on the server', sh(`find ${WWW} -name 'mertools-cmp-try-*' | wc -l`).trim() === '0');
  await setField('82');

  // ---------------------------------------------------------------- compress all
  await page.click('[data-mt-cmp-run]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] .mt-cmp-note.text-success', { timeout: 120000 });
  const log = await page.$$eval('dialog.mt-cmp-dlg .mt-cmp-log li', (lis) => lis.map((li) => li.className + ' ' + li.textContent));
  const line = (name) => log.find((l) => l.includes(name)) || '';
  check('log: the photo compressed', /mt-cmp-ok/.test(line('all/photo.jpg')), line('all/photo.jpg'));
  check('log: the graphic PNG compressed', /mt-cmp-ok/.test(line('all/logo.png')), line('all/logo.png'));
  check('log: the noise PNG skipped (saving too small)', /mt-cmp-skip/.test(line('all/noise.png')), line('all/noise.png'));
  check('log: the uploads skipped (compressed already)', /mt-cmp-skip/.test(line('media-upload.jpg')), line('media-upload.jpg'));
  check('log: skipped lines hidden at first', !(await page.isVisible('dialog.mt-cmp-dlg li.mt-cmp-skip')));
  await page.check('#mt-cmp-skips');
  check('log: shown with the box', await page.isVisible('dialog.mt-cmp-dlg li.mt-cmp-skip'));
  const counts = await page.textContent('dialog.mt-cmp-dlg .progress + p');
  check('counts: 5 of 5, 2 compressed', /5\D+5/.test(counts) && /: 2 ·/.test(counts), counts);
  check('photo replaced, noise untouched', marked(DIR + '/all/photo.jpg') && md5(DIR + '/all/noise.png') === orig.noise);
  await page.click('dialog.mt-cmp-dlg .btn-close');

  // ---------------------------------------------------------------- the copies: one back, one past its days, the rest back
  await page.waitForFunction(() => !document.querySelector('[data-mt-cmp-list]').disabled);
  await page.click('[data-mt-cmp-list]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] [data-rel$="all/photo.jpg"]');
  check('copies window: four copies', (await page.$$('dialog.mt-cmp-dlg[open] [data-rel]')).length === 4);
  await page.click('dialog.mt-cmp-dlg[open] [data-rel$="all/photo.jpg"]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] .text-success');
  check('restore one: the original back', md5(DIR + '/all/photo.jpg') === orig.photo);
  check('…its copy gone', !exists(copyOf(DIR + '/all/photo.jpg')));
  await page.click('dialog.mt-cmp-dlg[open] .btn-close');

  // the copy of the logo made 40 days ago: removed when the panel opens (30 days kept)
  php(`$f = '${WWW}media/mertools-originals/state.php'; $raw = file_get_contents($f); $g = "<?php exit; ?>\\n";
    $s = json_decode(substr($raw, strlen($g)), true); $s['backups']['${DIR}/all/logo.png']['t'] -= 40 * 86400; file_put_contents($f, $g . json_encode($s, JSON_UNESCAPED_SLASHES));`);
  await page.reload();
  await page.waitForSelector('.mt-cmp-figures dt');
  check('a copy past its days removed', !exists(copyOf(DIR + '/all/logo.png')));
  check('…the logo stays compressed', marked(DIR + '/all/logo.png'));
  // Stop while a part is on its way (answered only afterwards, with more left): no next part; the window closed
  // meanwhile and the compression asked for start nothing until that answer is in
  let parts = 0, release;
  const held = new Promise((r) => { release = r; });
  const started = [];
  const onRequest = (r) => { if (/mertools_action=cmp_(start|run)\b/.test(r.url())) { started.push(r.url()); } };
  page.on('request', onRequest);
  await page.route(/mertools_action=cmp_restore_all/, async (route) => {
    parts++;
    await held;
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
      restore: { restored: 1, changed: 0, failed: 0, left: 3, entries: [{ rel: 'images/not-real.jpg', result: 'ok' }] } }) });
  });
  await page.click('[data-mt-cmp-restore-all]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] .mt-cmp-foot .btn-secondary:not([hidden])');
  await page.click('dialog.mt-cmp-dlg[open] .mt-cmp-foot .btn-secondary');
  await page.click('dialog.mt-cmp-dlg[open] .btn-close');
  await page.click('[data-mt-cmp-run]');
  const openedMeanwhile = await page.evaluate(() => !!document.querySelector('dialog.mt-cmp-dlg[open]'));
  release();
  await page.waitForSelector('.mt-cmp-result .alert-warning');
  await page.waitForTimeout(1500);
  check('restore all: Stop asks for no next part', parts === 1, String(parts));
  check('…said on the panel', /^(Zatrzymane|Stopped)\b.*: 1\./.test(await page.textContent('.mt-cmp-result')), await page.textContent('.mt-cmp-result'));
  check('…the compression not started while the part was on its way', !openedMeanwhile && started.length === 0, JSON.stringify(started));
  await page.unroute(/mertools_action=cmp_restore_all/);
  page.off('request', onRequest);

  await page.click('[data-mt-cmp-restore-all]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] .mt-cmp-note.text-success');
  const restoreWin = await page.evaluate(() => {
    const d = document.querySelector('dialog.mt-cmp-dlg[open]');
    return { title: d.querySelector('h3').textContent, ok: [...d.querySelectorAll('li.mt-cmp-ok code')].map((c) => c.textContent).sort(),
      bar: d.querySelector('.progress-bar').textContent, skips: d.querySelector('#mt-cmp-skips').closest('.form-check').hidden,
      go: d.querySelector('.mt-cmp-foot .btn-primary').hidden, stop: d.querySelector('.mt-cmp-foot .btn-secondary').hidden };
  });
  check('restore all: in a window with a bar', restoreWin.bar === '100 %' && restoreWin.skips && restoreWin.go && restoreWin.stop && restoreWin.title !== '', JSON.stringify(restoreWin));
  check('…a line for each picture back', restoreWin.ok.length === 2 && restoreWin.ok.every((r) => r.endsWith('.jpg')), JSON.stringify(restoreWin.ok));
  check('restore all: two back', /: 2\./.test(await page.textContent('.mt-cmp-result')), await page.textContent('.mt-cmp-result'));
  await page.click('dialog.mt-cmp-dlg[open] .btn-close');
  check('…the uploads as they were uploaded', md5(DIR + '/media-upload.jpg') === uploadMd5 && md5(gbxRel) === uploadMd5);
  // a picture brought back is not compressed again by the next run
  await page.click('[data-mt-cmp-run]');
  await page.waitForSelector('dialog.mt-cmp-dlg[open] .mt-cmp-note.text-success', { timeout: 120000 });
  check('next run: the restored pictures left as they are', md5(DIR + '/all/photo.jpg') === orig.photo && md5(DIR + '/media-upload.jpg') === uploadMd5);
  await page.click('dialog.mt-cmp-dlg .btn-close');

  // ---------------------------------------------------------------- an uploader that does not say where the picture went
  make(WWW + DIR + '/late.jpg', 'photo');
  sh(`touch -d '+10 min' ${WWW}${DIR}/late.jpg`);
  const other = await page.evaluate(async ({ b64, token }) => {
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const body = new FormData();
    body.append('file', new File([bin], 'other-upload.jpg', { type: 'image/jpeg' }));
    body.append(token, '1');
    const t = Date.now();
    const r = await fetch('index.php?option=com_cpanel&view=cpanel', { method: 'POST', body });
    await r.text();
    return Date.now() - t;
  }, { b64: uploadB64, token });
  const waiting = state();
  check('other uploader: the look through the folder noted', !!(waiting.scan && waiting.scan.since), JSON.stringify(waiting.scan) + ' ' + other + ' ms');
  check('…not done while the request waited (mod_php)', !marked(DIR + '/late.jpg') && Object.keys(waiting.pending || {}).length === 0);
  // a picture of the time of that request (as if the page had written it), and late.jpg after it
  make(WWW + DIR + '/scan-me.jpg', 'photo');
  sh(`touch -d @${waiting.scan.since} ${WWW}${DIR}/scan-me.jpg`);
  await page.reload();
  await page.waitForSelector('.mt-cmp-figures dt');
  check('panel opened: the picture of that time compressed', marked(DIR + '/scan-me.jpg'));
  check('…one changed after it left as it is', !marked(DIR + '/late.jpg'));
  check('…nothing waits', !(state().scan || {}).since);
});

process.on('exit', () => {
  try {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    sh(`rm -rf ${WWW}${DIR} /tmp/mt-upload.jpg` + (hadCopies ? '' : ` ${WWW}media/mertools-originals`));
    fs.rmSync(tmp, { recursive: true, force: true });
  } catch (e) {
    console.log('cleanup failed: ' + e.message);
  }
});
