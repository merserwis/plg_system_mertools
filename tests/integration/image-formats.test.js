/**
 * Integration test of the newer formats of the lighter copies (1.14.0), on the docker Joomla + Gridbox
 * served by PHP-FPM (the work after the response; with mod_php the formats are not made): a page with a
 * photo, a PNG with transparency and a photo that is a link of its own. AVIF (GD) and JPEG XL (cjxl) are
 * made after the response; the page puts the photo and the PNG in a <picture> (JPEG XL first, AVIF, the
 * <img> with WebP and the original), the linked photo keeps WebP only (Gridbox's lightbox); the copies
 * are served with their types; a browser shows one of the newer formats, laid out as without them; the
 * panel says what the server makes them with. Switched off: the page as before. On a server that
 * cannot send the page before the work (mod_php, the copy gbx-sitemap-test) the panel says so and the
 * pages get WebP only.
 *
 *   MT_CONTAINER=gbx-fpm-test MT_URL=http://127.0.0.1:18097 node tests/integration/image-formats.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, URL_, ADMIN, check, sql, run, install } = require('./lib');

const DIR = 'images/mt-fmt-test';
const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
// a published single page of the copy, open to guests; its first section gets the test pictures
const PAGE = Number(sql(`SELECT p.id FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'single'
  AND p.published = 1 AND p.page_access = 1 AND p.page_category <> 'trashed' AND p.params LIKE '%<div class="ba-section row-fluid" id="item-%' ORDER BY LENGTH(p.params), p.id LIMIT 1`)[0][0]);
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const sh = (cmd) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'sh', '-c', cmd], { encoding: 'utf8' });
const set = (values) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(values).map(([k, v]) => `'$.${k}', '${v}'`).join(', ')}) WHERE ${PLUGIN}`);
const pageUrl = URL_ + '/index.php?option=com_gridbox&view=page&id=' + PAGE;
const guest = async () => (await fetch(pageUrl, { headers: { 'User-Agent': 'Mozilla/5.0 (MerTools test)' } })).text();
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
/** the <picture> around the img of a test picture, or null; its source types */
const pictureOf = (html, name) => {
  const m = new RegExp('<picture style="display:contents">((?:<source [^>]*>)+)<img [^>]*' + DIR + '/' + name + '[^>]*></picture>').exec(html);
  return m ? { sources: m[1], types: [...m[1].matchAll(/type="([^"]+)"/g)].map((x) => x[1]) } : null;
};
/** the entry of a picture in the manifest of the copies (the parts in Joomla's cache), or null */
const manifest = (rel) => JSON.parse(sh(`php -r '$d = getenv("C") ?: "/var/www/html/administrator/cache"; $e = null;
  foreach (glob($d . "/mertools-images-??.json") as $f) { $p = json_decode(file_get_contents($f), true); if (isset($p[$argv[1]])) { $e = $p[$argv[1]]; } }
  echo json_encode($e);' ${JSON.stringify(rel)}`));
const imgOf = (html, name) => (new RegExp('<img [^>]*' + DIR + '/' + name + '[^>]*>').exec(html) || [''])[0];

run(async ({ login }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  sql(`DROP TABLE IF EXISTS mt_fmt_page_backup`);
  sql(`SET SESSION sql_mode = ''; CREATE TABLE mt_fmt_page_backup AS SELECT id, params FROM #__gridbox_pages WHERE id = ${PAGE}`);
  try {
    // the test pictures, made here: a photo-like JPEG, the same as a link, a PNG with transparency
    sh(`mkdir -p /var/www/html/${DIR} && php -r '$p = imagecreatetruecolor(1600, 1000); mt_srand(3);
      for ($i = 0; $i < 6000; $i++) { imagefilledellipse($p, mt_rand(0, 1600), mt_rand(0, 1000), mt_rand(5, 90), mt_rand(5, 90), imagecolorallocate($p, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))); }
      imagefilter($p, IMG_FILTER_GAUSSIAN_BLUR); imagejpeg($p, "/var/www/html/${DIR}/photo.jpg", 90);' && cp /var/www/html/${DIR}/photo.jpg /var/www/html/${DIR}/linked.jpg`);
    sh(`php -r '$p = imagecreatetruecolor(900, 600); imagealphablending($p, false); imagesavealpha($p, true);
      imagefill($p, 0, 0, imagecolorallocatealpha($p, 0, 0, 0, 127)); mt_srand(7);
      for ($i = 0; $i < 4000; $i++) { imagefilledellipse($p, mt_rand(150, 750), mt_rand(100, 500), mt_rand(5, 50), mt_rand(5, 50), imagecolorallocatealpha($p, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0)); }
      imagepng($p, "/var/www/html/${DIR}/clear.png");'`);
    const block = `<div class="mt-fmt-test" style="width:100%"><img src="/${DIR}/photo.jpg" alt="photo" class="mt-fmt-photo">`
      + `<img src="/${DIR}/clear.png" alt="clear"><a href="/"><img src="/${DIR}/linked.jpg" alt="linked"></a></div>`;
    sql(`UPDATE #__gridbox_pages SET params = REGEXP_REPLACE(params, '(<div class="ba-section row-fluid" id="item-[0-9]+">)', '$1${block}', 1, 1) WHERE id = ${PAGE}`);
    set({ speed_images: 1, speed_webp: 1, speed_avif: 1, speed_jxl: 1, speed_cache: 0, speed_cache_data: 0 });

    // what the panel says this server can do (asked before the visits: the work after the response or not)
    const panel = async () => {
      await page.goto(ADMIN + 'index.php');
      return page.evaluate(async () => {
        const body = new FormData();
        body.append(Joomla.getOptions('csrf.token'), '1');
        const r = await fetch('index.php?option=com_plugins&mertools_action=img_stats', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        return (await r.json()).stats;
      });
    };
    const before = await panel();
    let html = await guest();
    check('the test pictures on the page, in the first section', /<div class="ba-section[^"]*" id="item-\d+"[^>]*>\s*<div class="mt-fmt-test"/.test(html) && html.includes(`/${DIR}/photo.jpg`) && html.includes(`/${DIR}/clear.png`) && html.includes(`/${DIR}/linked.jpg`));
    check('first visit: no copies yet, no <picture>', !pictureOf(html, 'photo.jpg') && !imgOf(html, 'photo.jpg').includes('srcset='), imgOf(html, 'photo.jpg'));
    if (!before.newer.avif.finish) {
      // mod_php: WebP copies (made while a visitor waits, small ones), never the newer formats
      check('mod_php: the panel says the newer formats are not made here', before.newer.avif.on && !before.newer.avif.finish && !before.newer.jxl.finish, JSON.stringify(before.newer));
      const start = Date.now();
      while (Date.now() - start < 120000 && !/srcset="[^"]*\.webp/.test(imgOf(html, 'clear.png'))) {
        await wait(4000);
        html = await guest();
      }
      check('mod_php: WebP copies offered, no <picture>', /srcset="[^"]*\.webp/.test(imgOf(html, 'clear.png')) && !html.includes('<picture style="display:contents">'), imgOf(html, 'clear.png'));
      const entry = manifest(`${DIR}/clear.png`);
      check('mod_php: no AVIF or JPEG XL made for the test pictures', !!entry && entry.x === 'ok' && !('a' in entry) && !('j' in entry), JSON.stringify(entry));
      return;
    }
    // after the response (PHP-FPM) the work makes them: visits until they are there
    const start = Date.now();
    while (Date.now() - start < 150000 && !(pictureOf(html, 'photo.jpg') && pictureOf(html, 'clear.png'))) {
      await wait(4000);
      html = await guest();
    }
    const photo = pictureOf(html, 'photo.jpg');
    const clear = pictureOf(html, 'clear.png');
    console.log('     made after the visits in ' + Math.round((Date.now() - start) / 1000) + ' s; photo: ' + (photo ? photo.types.join(', ') : '-') + '; PNG: ' + (clear ? clear.types.join(', ') : '-'));
    check('the photo in a <picture> with AVIF', !!photo && photo.types.includes('image/avif'), imgOf(html, 'photo.jpg'));
    check('…JPEG XL (when lighter than WebP) before AVIF', !!photo && (!photo.types.includes('image/jxl') || photo.types.indexOf('image/jxl') < photo.types.indexOf('image/avif')), photo && photo.types.join());
    check('…the img keeps its WebP srcset and the original', /srcset="[^"]*\.webp \d+w/.test(imgOf(html, 'photo.jpg')) && imgOf(html, 'photo.jpg').includes(`src="/${DIR}/photo.jpg"`));
    check('the PNG with transparency: AVIF too (GD keeps transparency)', !!clear && clear.types.includes('image/avif'), imgOf(html, 'clear.png'));
    check('the photo that is a link of its own: WebP only, no <picture>', !pictureOf(html, 'linked.jpg') && /srcset="[^"]*\.webp/.test(imgOf(html, 'linked.jpg')), imgOf(html, 'linked.jpg'));
    // the copies, as a browser fetches them: their types
    for (const [type, ext] of [['image/avif', 'avif'], ['image/jxl', 'jxl']]) {
      const m = photo && new RegExp('<source type="' + type + '" srcset="([^" ]+)').exec(photo.sources);
      if (!m) continue;
      const r = await fetch(URL_ + m[1]);
      check(`${ext} copy served as ${type}`, r.status === 200 && r.headers.get('content-type') === type, r.status + ' ' + r.headers.get('content-type'));
    }
    check('the copies folder names the types for Apache', sh(`cat /var/www/html/media/mertools-img/.htaccess`).includes('AddType image/jxl .jxl'));

    // a browser: one of the newer formats shown, laid out as without the <picture>
    const ctx = await page.context().browser().newContext({ viewport: { width: 1440, height: 900 } });
    const view = await ctx.newPage();
    const shown = async () => {
      await view.goto(pageUrl, { waitUntil: 'load' });
      await view.locator('img.mt-fmt-photo').scrollIntoViewIfNeeded();
      await view.waitForFunction(() => { const i = document.querySelector('img.mt-fmt-photo'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 30000 });
      return view.evaluate(() => { const i = document.querySelector('img.mt-fmt-photo'), b = i.getBoundingClientRect();
        return { src: i.currentSrc, w: Math.round(b.width), h: Math.round(b.height), parent: i.parentElement.localName, natural: i.naturalWidth }; });
    };
    const on = await shown();
    check('the browser shows a newer format (' + on.src.split('.').pop() + ')', /\.(avif|jxl)$/.test(on.src) && on.natural > 0, JSON.stringify(on));
    set({ speed_avif: 0, speed_jxl: 0 });
    const off = await shown();
    check('switched off: no <picture>, WebP as before', off.parent !== 'picture' && /\.webp$/.test(off.src), JSON.stringify(off));
    check('…the picture laid out the same with and without the <picture>', on.w === off.w && on.h === off.h, JSON.stringify([on, off]));
    await ctx.close();

    // the panel: what this server makes them with
    set({ speed_avif: 1, speed_jxl: 1 });
    const stats = await panel();
    check('panel: AVIF on, made by GD; JPEG XL by cjxl; the work after the response', stats && stats.newer.avif.on && stats.newer.avif.via === 'gd' && stats.newer.jxl.via === 'cjxl' && stats.newer.avif.finish,
      JSON.stringify(stats && stats.newer));
    check('panel: AVIF copies counted', stats && stats.formats.avif.files > 0 && stats.formats.avif.bytes > 0, JSON.stringify(stats && stats.formats));
  } finally {
    sql(`UPDATE #__gridbox_pages p JOIN mt_fmt_page_backup b ON b.id = p.id SET p.params = b.params`);
    sql(`DROP TABLE IF EXISTS mt_fmt_page_backup`);
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    sh(`rm -rf /var/www/html/${DIR}`);
  }
});
