/**
 * Integration test of 1.13.0 on a real Joomla + Gridbox in docker (the test copy gbx-sitemap-test):
 *
 *  - Size on upload (only resizing, compression off): a picture uploaded through Gridbox's media manager
 *    from the editor of a product (mt_ref, as the media manager adds it) is made to fit the frame of the
 *    shop; one uploaded from the panel of the blog (the Referer) keeps its size (the blog has no frame
 *    here); one from Joomla's media manager gets the frame of "other"; the copies list the sizes and
 *    bring the originals back; the media manager opened in a page adds that page's address to its uploads;
 *  - badges: on the picture of a product page (in the box of the slides, at the corner chosen, in the
 *    style and colours chosen), still there once over the new picture after a variation with pictures of
 *    its own is chosen in Gridbox's options (an option given a picture for the test); the tiles of a
 *    category in the same look, or as Gridbox draws them with the lists off; the preview of the
 *    settings (a click on a card chooses the style, on a corner the corner).
 *
 *   node tests/integration/badges-resize.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, URL_, ADMIN, check, sql, run, install } = require('./lib');

const DIR = 'images/mt-rsz-test';
const WWW = '/var/www/html/';
const sh = (cmd) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'sh', '-c', cmd], { encoding: 'utf8' });
const php = (code) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'php', '-r', code], { encoding: 'utf8' });
const dims = (rel) => sh(`php -r 'echo implode("x", array_slice(getimagesize("${WWW}${rel}") ?: [0, 0], 0, 2));'`).trim();
const exists = (rel) => sh('[ -e ' + WWW + rel + ' ] && echo y || echo n').trim() === 'y';
const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const hadCopies = exists('media/mertools-originals');
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', '${v}'`).join(', ')}) WHERE ${PLUGIN}`);
const state = () => JSON.parse(php(`$r = @file_get_contents('${WWW}media/mertools-originals/state.php'); echo $r ? substr($r, strpos($r, "\n") + 1) : '{}';`) || '{}');

// a product with two badges of its own (no sale badge), in stock, with variations (the first is taken), and a category it is listed in
const PRODUCT = Number(sql(`SELECT bm.product_id FROM #__gridbox_store_badges_map bm JOIN #__gridbox_pages p ON p.id = bm.product_id
  JOIN #__gridbox_store_product_data d ON d.product_id = p.id
  WHERE p.published = 1 AND p.intro_image <> '' AND d.stock = '' GROUP BY bm.product_id HAVING COUNT(*) = 2 AND MIN(bm.badge_id) > 1
  ORDER BY EXISTS (SELECT 1 FROM #__gridbox_store_product_variations_map v WHERE v.product_id = bm.product_id) DESC, bm.product_id LIMIT 1`)[0][0]);
// an option of it given a picture of its own for the test (put back at the end)
const OPTION = (sql(`SELECT id, option_key, images FROM #__gridbox_store_product_variations_map WHERE product_id = ${PRODUCT} ORDER BY id LIMIT 1`)[0] || null);
const BADGES = sql(`SELECT b.title, b.color FROM #__gridbox_store_badges_map bm JOIN #__gridbox_store_badges b ON b.id = bm.badge_id WHERE bm.product_id = ${PRODUCT} ORDER BY bm.order_list, bm.id`);
const BLOG_APP = Number(sql("SELECT id FROM #__gridbox_app WHERE type = 'blog' ORDER BY id LIMIT 1")[0][0]);

run(async ({ login }) => {
  sh(`rm -rf ${WWW}${DIR} && mkdir -p ${WWW}${DIR}`);
  php(`$im = imagecreatetruecolor(1600, 1000); for ($i = 0; $i < 2000; $i++) imagefilledellipse($im, mt_rand(0, 1600), mt_rand(0, 1000), mt_rand(5, 90), mt_rand(5, 90),
    imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))); imagejpeg($im, '/tmp/mt-rsz.jpg', 92);`);
  const b64 = execFileSync('docker', ['exec', C, 'base64', '-w0', '/tmp/mt-rsz.jpg'], { encoding: 'utf8' });

  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  // only resizing (compression off); the shop 600×600, the blog without a frame, other 400 wide; no kept pages, nor kept answers of Gridbox (its items read for the variation)
  setParams({ cmp_upload: '0', rsz_upload: '1', rsz_shop_w: '600', rsz_shop_h: '600', rsz_blog_w: '0', rsz_blog_h: '0', rsz_other_w: '400', rsz_other_h: '0', speed_cache: '0', speed_cache_data: '0' });
  page.on('dialog', (d) => d.accept());

  // ---------------------------------------------------------------- size on upload
  await page.goto(ADMIN + 'index.php?option=com_gridbox&view=apps&id=' + BLOG_APP);
  const token = await page.evaluate(() => Joomla.getOptions('csrf.token'));
  const upload = (name, ref) => page.evaluate(async ({ b64, token, dir, name, ref }) => {
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const body = new FormData();
    body.append('file', new File([bin], name, { type: 'image/jpeg' }));
    body.append(token, '1');
    const r = await fetch('index.php?option=com_gridbox&task=uploader.executeAction&action=uploadFile&path=' + encodeURIComponent(dir)
      + (ref ? '&mt_ref=' + encodeURIComponent(ref) : ''), { method: 'POST', body });
    return r.text();
  }, { b64, token, dir: DIR, name, ref });
  // from the editor of the product (the address the media manager adds)
  const shop = await upload('shop.jpg', '/index.php?option=com_gridbox&view=editor&tmpl=component&id=' + PRODUCT);
  check('upload from the product editor answered', /shop\.jpg/.test(shop), shop.slice(0, 200));
  // from the panel of the blog app, no address added: the Referer (this page) says it
  await upload('blog.jpg', '');
  // Joomla's media manager, from its own page (the Referer: not Gridbox's)
  await page.goto(ADMIN + 'index.php?option=com_media');
  const media = await page.evaluate(async ({ b64, token }) => {
    const r = await fetch('index.php?option=com_media&task=api.files&format=json&path=local-images:/mt-rsz-test', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify({ [token]: '1', name: 'media.jpg', content: b64, override: false })
    });
    return r.status;
  }, { b64, token });
  check('Joomla media manager upload answered', media === 200, 'HTTP ' + media);
  const file = (n) => DIR + '/' + (sh(`ls ${WWW}${DIR} | grep '^${n}'`).trim().split('\n')[0] || n);
  check('shop upload made to fit 600×600 (1600×1000 → 600×375)', dims(file('shop')) === '600x375', dims(file('shop')));
  check('blog upload (Referer: the blog\'s panel, no frame) kept its size', dims(file('blog')) === '1600x1000', dims(file('blog')));
  check('media manager upload: the frame of other (400 wide)', dims(file('media')) === '400x250', dims(file('media')));
  const backups = state().backups || {};
  check('copies of the originals kept, with the sizes', JSON.stringify((backups[file('shop')] || {}).r) === '[1600,1000,600,375]'
    && JSON.stringify((backups[file('media')] || {}).r) === '[1600,1000,400,250]' && !backups[file('blog')], JSON.stringify(backups));

  // the media manager opened in a page adds that page's address to its uploads
  await page.goto(ADMIN + 'index.php?option=com_gridbox&view=apps&id=' + BLOG_APP);
  const action = await page.evaluate(() => new Promise((resolve) => {
    const f = document.createElement('iframe');
    f.src = 'index.php?option=com_gridbox&view=uploader&tmpl=component';
    f.onload = () => setTimeout(() => resolve(String(f.contentWindow.mediaManager && f.contentWindow.mediaManager.action)), 300);
    document.body.append(f);
  }));
  check('media manager: its uploads say the page it is open in', action.includes('mt_ref=' + encodeURIComponent('/administrator/index.php?option=com_gridbox&view=apps&id=' + BLOG_APP)), action);

  // the panel: the frames, the copy list with the sizes, restore
  await page.goto(ADMIN + 'index.php?option=com_mertools&view=page&page=images&tab=compress_panel');
  await page.waitForSelector('.mt-cmp-figures dt');
  const figures = await page.textContent('.mt-cmp-figures');
  check('panel: the frames of the places', /600 × 600/.test(figures) && /400 × /.test(figures), figures);
  await page.click('[data-mt-cmp-list]');
  await page.waitForSelector('dialog[open] li code');
  const listed = await page.textContent('dialog[open]');
  check('copies list: 1600×1000 → 600×375', listed.includes('1600×1000 → 600×375'), listed.slice(0, 300));
  await page.keyboard.press('Escape');
  const back = php(`define('_JEXEC', 1); require '${WWW}plugins/system/mertools/src/Tool/ImageSize.php'; require '${WWW}plugins/system/mertools/src/Tool/ImageCompressor.php';
    require '${WWW}plugins/system/mertools/src/Tool/CompressStore.php';
    $s = new Merserwis\\Plugin\\System\\MerTools\\Tool\\CompressStore('${WWW.slice(0, -1)}', 'images');
    echo $s->restore('${file('shop')}'), ' ', $s->restore('${file('media')}');`);
  check('restore: the large originals back', back === 'ok ok' && dims(file('shop')) === '1600x1000' && dims(file('media')) === '1600x1000', back + ' ' + dims(file('shop')));

  // ---------------------------------------------------------------- badges on the product page
  setParams({ badge_on: '1', badge_style: 'pill', badge_colors: 'gridbox', badge_corner: 'br', badge_offset: '12', badge_size: 'm', badge_layout: 'column' });
  const guest = await page.context().browser().newPage({ viewport: { width: 1440, height: 900 } });
  // (the copy's own scripts may fail on their own: only MerTools' are looked at)
  guest.on('pageerror', (e) => { if (/mertools/i.test(String(e.stack))) check('no script error of MerTools on the site', false, e.message); });
  await guest.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT, { waitUntil: 'load' });
  const onPage = await guest.evaluate(() => {
    const w = document.querySelector('.slideshow-content > [data-mt-badges], .ba-gridbox-gallery-image > [data-mt-badges]');
    if (!w) return null;
    const host = w.parentElement.getBoundingClientRect();
    const r = w.getBoundingClientRect();
    const b = w.querySelector('.ba-blog-post-badge');
    const cs = getComputedStyle(b);
    const events = getComputedStyle(w).pointerEvents;
    // on top of the picture: what is seen at the badge's middle (clicks let in for the look only)
    w.style.pointerEvents = 'auto';
    const br = b.getBoundingClientRect();
    const seen = document.elementFromPoint(br.x + br.width / 2, br.y + br.height / 2);
    w.style.pointerEvents = '';
    return { titles: [...w.querySelectorAll('.ba-blog-post-badge')].map((s) => s.textContent), right: Math.round(host.right - r.right), bottom: Math.round(host.bottom - r.bottom),
      radius: cs.borderTopLeftRadius, bg: cs.backgroundColor, color: cs.color, size: cs.fontSize, events, onTop: !!seen && w.contains(seen) };
  });
  check('product page: the badges on its picture', !!onPage && JSON.stringify(onPage.titles) === JSON.stringify(BADGES.map((b) => b[0])), JSON.stringify(onPage) + ' want ' + JSON.stringify(BADGES));
  check('…at the bottom right corner, 12 px from the edges', !!onPage && onPage.right === 12 && onPage.bottom === 12, JSON.stringify(onPage));
  check('…a pill (round ends), medium size', !!onPage && parseFloat(onPage.radius) > 100 && onPage.size === '14px', JSON.stringify(onPage));
  const want = (c) => { const m = /rgba?\(([^)]+)\)/.exec(c); if (m) { const p = m[1].split(',').map((x) => x.trim()); return 'rgb(' + p.slice(0, 3).join(', ') + ')'; }
    const h = c.replace('#', ''); return 'rgb(' + [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(', ') + ')'; };
  check('…in the badge\'s colour from Gridbox', !!onPage && onPage.bg.replace(/rgba\(([^,]+,[^,]+,[^,]+), 1\)/, 'rgb($1)') === want(BADGES[0][1]), onPage && onPage.bg + ' want ' + want(BADGES[0][1]));
  check('…seen over the picture of the slide', !!onPage && onPage.onTop, JSON.stringify(onPage));
  check('…clicks go through to the picture', !!onPage && onPage.events === 'none');
  // a variation with pictures of its own chosen in Gridbox's options: Gridbox writes the slides anew
  if (!OPTION) {
    check('a product with variations to choose (none on this copy)', false);
  } else {
    sql(`UPDATE #__gridbox_store_product_variations_map SET images = '["${DIR}/variant.jpg"]' WHERE id = ${OPTION[0]}`);
    const errors = [];
    guest.on('pageerror', (e) => errors.push(e.message));
    await guest.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT, { waitUntil: 'load' });
    await guest.waitForTimeout(800);
    errors.length = 0;
    const pick = guest.locator(`.ba-add-to-cart-variations li[data-value="${OPTION[1]}"]`).first();
    await pick.evaluate((li) => li.closest('.ba-custom-select').querySelector('input[type="text"]').click());
    await pick.click();
    await guest.waitForFunction(() => /variant\.jpg/.test((document.querySelector('.ba-item-product-slideshow .slideshow-content') || {}).innerHTML || ''), null, { timeout: 15000 })
      .catch(() => {});
    await guest.waitForTimeout(300);
    const swapped = await guest.evaluate(() => {
      const box = document.querySelector('.ba-item-product-slideshow .slideshow-content');
      const w = box && box.querySelector(':scope > [data-mt-badges]');
      let onTop = false;
      if (w) {
        const b = w.querySelector('.ba-blog-post-badge').getBoundingClientRect();
        w.style.pointerEvents = 'auto';
        const seen = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
        w.style.pointerEvents = '';
        onTop = !!seen && w.contains(seen);
      }
      return { picture: /variant\.jpg/.test(box ? box.innerHTML : ''), sets: box ? box.querySelectorAll('[data-mt-badges]').length : -1, onTop };
    });
    check('a variation with its own picture chosen in Gridbox\'s options: the slide changed', swapped.picture, JSON.stringify(swapped));
    check('…the badges on it once, over the picture', swapped.sets === 1 && swapped.onTop, JSON.stringify(swapped));
    check('…no script error while Gridbox switched', errors.length === 0, errors.join(' | '));
  }
  await guest.screenshot({ path: '/tmp/mt-badges-product.png' });

  // ---------------------------------------------------------------- badges on the tiles of a category
  const category = await guest.evaluate(() => (document.querySelector('.intro-post-category a') || {}).href || '');
  const tile = async () => {
    await guest.goto(category, { waitUntil: 'load' });
    return guest.evaluate((titles) => {
      const w = [...document.querySelectorAll('.ba-blog-post-badge-wrapper')].find((x) => x.textContent.includes(titles[0]));
      if (!w) return null;
      const host = w.parentElement.getBoundingClientRect();
      const r = w.getBoundingClientRect();
      return { right: Math.round(host.right - r.right), left: Math.round(r.left - host.left), top: Math.round(r.top - host.top), radius: getComputedStyle(w.firstElementChild).borderTopLeftRadius };
    }, BADGES.map((b) => b[0]));
  };
  const listed1 = await tile();
  check('category: the tile in the same look (bottom right, pill)', !!listed1 && listed1.right === 12 && parseFloat(listed1.radius) > 100, JSON.stringify(listed1) + ' ' + category);
  setParams({ badge_lists: '0' });
  const listed2 = await tile();
  check('lists off: the tile as Gridbox draws it (top left, 20 px, 3 px corners)', !!listed2 && listed2.left === 20 && listed2.top === 20 && listed2.radius === '3px', JSON.stringify(listed2));

  // ---------------------------------------------------------------- the preview of the settings
  await page.goto(ADMIN + 'index.php?option=com_mertools&view=page&page=badges');
  await page.waitForSelector('[data-mt-badges-preview]');
  const cards = await page.$$eval('[data-mt-style]', (b) => b.length);
  check('preview: a card for each of the 9 styles', cards === 9, String(cards));
  await page.click('[data-mt-style="glass"]');
  await page.click('[data-mt-corner="tl"]');
  const chosen = await page.evaluate(() => ({ style: document.querySelector('[name="jform[params][badge_style]"]').value, corner: document.querySelector('[name="jform[params][badge_corner]"]').value,
    big: document.querySelector('.mt-bp-big').className, pressed: document.querySelector('[data-mt-style="glass"]').getAttribute('aria-pressed') }));
  check('a click on a card chooses the style, on a corner the corner; the preview follows',
    chosen.style === 'glass' && chosen.corner === 'tl' && /mt-bp-s-glass/.test(chosen.big) && /mt-bp-k-tl/.test(chosen.big) && chosen.pressed === 'true', JSON.stringify(chosen));
  await page.selectOption('[name="jform[params][badge_colors]"]', 'dark');
  const dark = await page.$eval('.mt-bp-big .ba-blog-post-badge', (b) => getComputedStyle(b).color);
  check('colours chosen in the form: the preview at once (dark: white text)', dark === 'rgb(255, 255, 255)', dark);
  await page.screenshot({ path: '/tmp/mt-badges-settings.png', fullPage: true });
  await guest.close();
});

process.on('exit', () => {
  try {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    if (OPTION) {
      sql(`UPDATE #__gridbox_store_product_variations_map SET images = CONVERT(UNHEX('${Buffer.from(String(OPTION[2]), 'utf8').toString('hex')}') USING utf8mb4) WHERE id = ${OPTION[0]}`);
    }
    sh(`rm -rf ${WWW}${DIR}` + (hadCopies ? '' : ` ${WWW}media/mertools-originals`));
    execFileSync('docker', ['exec', C, 'rm', '-f', '/tmp/mt-rsz.jpg']);
  } catch (e) {
    console.log('cleanup: ' + e.message);
  }
});
