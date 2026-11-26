/**
 * Integration test of Vimeo films loaded only when played (speed_vimeo), on the docker Joomla + Gridbox (the copy of
 * merserwis.pl): a product whose description gets, for the test, a Vimeo frame as Gridbox's Video element writes it.
 *  - on: the picture asked from Vimeo's oEmbed by the site once and kept (mertools-vimeo.json); nothing of Vimeo's player
 *    before a click (only the picture from i.vimeocdn.com), the frame of the same size, picture and play button; a click
 *    opens Vimeo's player in that frame with autoplay=1;
 *  - off: the frame as it was, Vimeo's player loading at once;
 *  - a film Vimeo gives no picture of (no such film): left as it was, asked again only after a day.
 *
 *   node tests/integration/vimeo-facade.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ')}) WHERE ${PLUGIN}`);
// the field of a published product that shows YouTube films (seen on the page, not in a closed tab): the frames go there
const [FIELD, ID] = sql(`SELECT f.id, f.page_id FROM #__gridbox_page_fields f JOIN #__gridbox_pages p ON p.id = f.page_id
  WHERE p.published = 1 AND p.page_category <> 'trashed' AND f.value LIKE '%<iframe%youtube.com/embed/%' ORDER BY f.page_id DESC LIMIT 1`)[0].map(Number);
const OLD = sql(`SELECT value FROM #__gridbox_page_fields WHERE id = ${FIELD}`)[0][0];
const PAGE = URL_ + '/index.php?option=com_gridbox&view=page&id=' + ID;
const FILM = '<p><iframe src="https://player.vimeo.com/video/76979871?autoplay=0&loop=0" frameborder="0" allowfullscreen width="640" height="360"></iframe></p>'
  + '<p><iframe id="none" src="https://player.vimeo.com/video/1" width="320" height="180"></iframe></p>';
const PLAYER = /(^|\.)(player\.vimeo\.com|vimeocdn\.com)$/;
const cache = () => execFileSync('docker', ['exec', C, 'sh', '-c', 'cat /var/www/html/administrator/cache/mertools-vimeo.json /var/www/html/cache/mertools-vimeo.json 2>/dev/null || true'], { encoding: 'utf8' });

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const ctx = admin.context();
  await ctx.addCookies([{ name: 'mertools_consent', value: 'f0t0m0.' + Math.floor(Date.now() / 1000), url: URL_ }]);
  const open = async () => {
    const page = await ctx.newPage();
    const hosts = [];
    page.on('request', (r) => hosts.push(r.url()));
    await page.goto(PAGE + '&mt=' + Date.now(), { waitUntil: 'load', timeout: 90000 });
    await page.waitForTimeout(2500);
    return { page, hosts };
  };
  const box = async (page) => {
    const f = await page.$('iframe[src*="player.vimeo.com/video/76979871"]');
    if (!f) return null;
    await f.scrollIntoViewIfNeeded();
    const r = await f.boundingBox();
    return Math.round(r.width) + 'x' + Math.round(r.height);
  };
  try {
    check('a product with a description: ' + ID, ID > 0);
    sql(`UPDATE #__gridbox_page_fields SET value = ${q(OLD + FILM)} WHERE id = ${FIELD}`);
    execFileSync('docker', ['exec', C, 'sh', '-c', 'rm -f /var/www/html/administrator/cache/mertools-vimeo.json /var/www/html/cache/mertools-vimeo.json']);

    setParams({ speed_vimeo: '0', speed_cache: '0' });
    const off = await open();
    const offBox = await box(off.page);
    check('off: the frame as it was (no srcdoc)', offBox && await off.page.evaluate(() => !document.querySelector('iframe[src*="vimeo"][srcdoc]')), offBox);
    check('off: Vimeo\'s player asked for at once', off.hosts.some((u) => u.includes('player.vimeo.com/video/76979871')));
    await off.page.close();

    setParams({ speed_vimeo: '1', speed_cache: '0' });
    const { page, hosts } = await open();
    check('on: the frame a picture with a button, marked', !!(await page.$('iframe[data-mt-vimeo="76979871"][srcdoc]')));
    // (the frame of a film without a picture, left as it was, loads its player: not this one)
    check('on: nothing of the film\'s player before a click', !hosts.some((u) => u.includes('player.vimeo.com/video/76979871')),
      JSON.stringify(hosts.filter((u) => PLAYER.test(new URL(u).hostname))));
    check('on: the frame of the same size', await box(page) === offBox, (await box(page)) + ' / ' + offBox);
    const frame = await page.$('iframe[data-mt-vimeo]');
    const doc = await frame.contentFrame();
    await page.waitForTimeout(1500);
    const look = await doc.evaluate(() => {
      const img = document.querySelector('img');
      const a = document.querySelector('a');
      return { img: !!img && img.complete && img.naturalWidth > 0, src: img && img.src, label: a && a.getAttribute('aria-label') };
    });
    check('on: the film\'s picture from Vimeo\'s server shown', look.img && /^https:\/\/i\.vimeocdn\.com\//.test(look.src), JSON.stringify(look));
    check('on: a play button with its label', /Vimeo/.test(look.label || ''), JSON.stringify(look));
    check('a film without a picture left as it was', await page.evaluate(() => { const f = document.getElementById('none'); return !!f && !f.hasAttribute('srcdoc'); }));
    const kept = cache();
    check('the pictures kept on the site: the film\'s, the refusal', /"76979871":\["https:\\?\/\\?\/i\.vimeocdn\.com/.test(kept) && /"1":\["",\d+\]/.test(kept), kept.slice(0, 300));
    await doc.click('a');
    await page.waitForTimeout(5000);
    const player = (await frame.contentFrame()).url();
    check('a click: Vimeo\'s player in that frame, autoplay', /^https:\/\/player\.vimeo\.com\/video\/76979871\?.*autoplay=1/.test(player), player);
    check('a click: the frame may play it (allow autoplay)', /(^|;)\s*autoplay\b/.test(await frame.getAttribute('allow')), await frame.getAttribute('allow'));
    await page.close();
  } finally {
    sql(`UPDATE #__gridbox_page_fields SET value = ${q(OLD)} WHERE id = ${FIELD}`);
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
