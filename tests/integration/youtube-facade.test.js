/**
 * Integration test of YouTube films loaded only when played (speed_youtube), on the docker Joomla + Gridbox
 * (the copy of merserwis.pl): a product whose description has YouTube frames pasted in it.
 *  - on: nothing of YouTube is asked for before a click (only the films' pictures from i.ytimg.com), each frame
 *    of the same size as without it, its picture and play button there; a click opens YouTube's player in that
 *    frame with autoplay=1 (whether it then plays needs a real browser: see below); the next frame still not loaded;
 *  - off: the frames as they were, YouTube's player loading at once;
 *  - a playlist given with its first film (/embed/ID?list=…): that film's picture, nothing of YouTube before a click,
 *    the player with the list on a click; a playlist without a film (videoseries) loading as before;
 *  - a page with a Content Security Policy of style nonces: the frame's own document (it has the page's policy) styled
 *    with the nonce, and its style refused without it (the check sees the difference).
 *
 *   node tests/integration/youtube-facade.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ')}) WHERE ${PLUGIN}`);
// a published product with two or more films pasted in its description
const ID = Number(sql(`SELECT f.page_id FROM #__gridbox_page_fields f JOIN #__gridbox_pages p ON p.id = f.page_id
  WHERE p.published = 1 AND p.page_category <> 'trashed' AND f.value LIKE '%<iframe%youtube.com/embed/%' GROUP BY f.page_id
  HAVING SUM((LENGTH(f.value) - LENGTH(REPLACE(f.value, 'youtube.com/embed/', ''))) / 18) >= 2 ORDER BY f.page_id DESC LIMIT 1`)[0][0]);
const PAGE = URL_ + '/index.php?option=com_gridbox&view=page&id=' + ID;
const YT = /(^|\.)(youtube(-nocookie)?\.com|googlevideo\.com|ggpht\.com)$/;
// a film's frame as Speedup makes it, with the page's nonce (the plugin's own code, in the container)
const facade = (nonce, input = '<iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/yiqbq_-v0RM"></iframe>') => execFileSync('docker', ['exec', '-i', C, 'php', '-r', 'define("_JEXEC", 1); require "/var/www/html/plugins/system/mertools/src/Tool/Speedup.php";'
  + ' echo Merserwis\\Plugin\\System\\MerTools\\Tool\\Speedup::youtubeFacade(stream_get_contents(STDIN), "Play", $argv[1]);', '--', nonce],
  { input, encoding: 'utf8' });

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const ctx = admin.context();
  const open = async () => {
    const page = await ctx.newPage();
    const hosts = [];
    page.on('request', (r) => hosts.push(new URL(r.url()).hostname));
    await page.goto(PAGE + '&mt=' + Date.now(), { waitUntil: 'load', timeout: 90000 });
    await page.waitForTimeout(2500);
    return { page, hosts };
  };
  const sizes = (page) => page.evaluate(() => [...document.querySelectorAll('iframe[src*="youtube"]')].map((f) => {
    const r = f.getBoundingClientRect();
    return Math.round(r.width) + 'x' + Math.round(r.height);
  }));
  try {
    check('a product with films: ' + ID, ID > 0);
    setParams({ speed_youtube: '0', speed_cache: '0' });
    const off = await open();
    const offSizes = await sizes(off.page);
    check('off: the frames as they were (no srcdoc)', await off.page.evaluate(() => !document.querySelector('iframe[srcdoc]')) && offSizes.length >= 2, JSON.stringify(offSizes));
    check('off: YouTube\'s player asked for at once', off.hosts.some((h) => YT.test(h)));
    await off.page.close();

    setParams({ speed_youtube: '1', speed_cache: '0' });
    const { page, hosts } = await open();
    check('on: nothing of YouTube before a click', !hosts.some((h) => YT.test(h)), JSON.stringify([...new Set(hosts.filter((h) => YT.test(h)))]));
    check('on: every frame of the same size as before', JSON.stringify(await sizes(page)) === JSON.stringify(offSizes), JSON.stringify([await sizes(page), offSizes]));
    const frames = await page.$$('iframe[data-mt-yt]');
    check('on: every film has its picture and button', frames.length === offSizes.length, frames.length + ' of ' + offSizes.length);
    await frames[0].scrollIntoViewIfNeeded();
    await page.waitForTimeout(1500);
    const first = await frames[0].contentFrame();
    const look = await first.evaluate(() => {
      const img = document.querySelector('img');
      const a = document.querySelector('a');
      return { img: !!img && img.complete && img.naturalWidth > 0, src: img && img.src, button: !!document.querySelector('a[href*="/embed/"] b'), label: a && a.getAttribute('aria-label') };
    });
    check('on: the film\'s picture shown', look.img && /i\.ytimg\.com\/vi\/[\w-]{11}\/hqdefault\.jpg$/.test(look.src), JSON.stringify(look));
    check('on: a play button with its label', look.button && /Odtwórz|Play/.test(look.label), JSON.stringify(look));
    // a click: the player in the same frame, playing
    const before = hosts.length;
    await first.click('a');
    await page.waitForTimeout(6000);
    const player = await frames[0].contentFrame();
    check('a click: YouTube\'s player in that frame, autoplay', /\/embed\/[\w-]{11}\?.*autoplay=1/.test(player.url()), player.url());
    // that the film then plays cannot be seen here: in headless Chromium YouTube's player starts no film at all, not
    // even in a plain frame with autoplay=1 (getPlayerState() -1 everywhere) — checked by hand in a real browser
    check('a click: the frame may play it (allow autoplay)', /(^|;)\s*autoplay\b/.test(await frames[0].getAttribute('allow')), await frames[0].getAttribute('allow'));
    const others = await Promise.all(frames.slice(1).map(async (f) => (await f.contentFrame()).url()));
    check('a click: the other frames still not loaded', others.every((u) => u === 'about:srcdoc'), JSON.stringify(others));
    check('a click: YouTube asked for only now', hosts.slice(before).some((h) => YT.test(h)));
    await page.close();

    // a Content Security Policy with style nonces: the frame's document is under it too
    const N = 'TXRUZXN0Tm9uY2UxMjM0NTY3OA==';
    const csp = await ctx.newPage();
    const button = async (body) => {
      await csp.route('https://mt-csp.test/', (r) => r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!DOCTYPE html><body>' + body + '</body>',
        headers: { 'Content-Security-Policy': `default-src 'none'; style-src 'nonce-${N}'; img-src https://i.ytimg.com; frame-src https://www.youtube-nocookie.com` } }));
      await csp.goto('https://mt-csp.test/', { waitUntil: 'load' });
      await csp.waitForTimeout(1500);
      const frame = await (await csp.$('iframe')).contentFrame();
      const out = await frame.evaluate(() => { const b = document.querySelector('b'); const img = document.querySelector('img'); return { w: getComputedStyle(b).width, fit: getComputedStyle(img).objectFit }; });
      await csp.unroute('https://mt-csp.test/');
      return out;
    };
    const withNonce = await button(facade(N));
    check('CSP: with the page\'s nonce the picture and button are styled', withNonce.w === '68px' && withNonce.fit === 'cover', JSON.stringify(withNonce));
    const without = await button(facade(''));
    check('CSP: without it the browser refuses the style (so the check above means it)', without.w !== '68px', JSON.stringify(without));
    await csp.close();

    // a playlist pasted as in a Custom HTML element: the frame of a list with its first film, and one without a film
    const list = await ctx.newPage();
    const listHosts = [];
    list.on('request', (r) => listHosts.push(new URL(r.url()).hostname));
    const body = facade('', '<div style="width:800px"><iframe style="display:block;width:100%;aspect-ratio:16/9;border:0" src="https://www.youtube.com/embed/bHYXIThpjgA?list=PLUZoa45E6Xug" title="Nasze filmy" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen=""></iframe></div>'
      + '<iframe id="series" src="https://www.youtube.com/embed/videoseries?list=PLUZoa45E6Xug"></iframe>');
    check('list: the frame of a list with a film made a picture, the one without left', (body.match(/srcdoc=/g) || []).length === 1
      && body.endsWith('</div><iframe id="series" src="https://www.youtube.com/embed/videoseries?list=PLUZoa45E6Xug"></iframe>'), body.slice(-200));
    await list.route('https://mt-list.test/', (r) => r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!DOCTYPE html><body>' + body.replace(/<iframe id="series"[^]*$/, '') + '</body>' }));
    await list.goto('https://mt-list.test/', { waitUntil: 'load' });
    await list.waitForTimeout(2000);
    const lf = await list.$('iframe[data-mt-yt]');
    const lbox = await lf.boundingBox();
    check('list: the frame as large as written (800×450)', Math.round(lbox.width) === 800 && Math.round(lbox.height) === 450, JSON.stringify(lbox));
    check('list: nothing of YouTube before a click', !listHosts.some((h) => YT.test(h)), JSON.stringify([...new Set(listHosts)]));
    const ldoc = await lf.contentFrame();
    const limg = await ldoc.evaluate(() => { const i = document.querySelector('img'); return i && i.complete && i.naturalWidth > 0 ? i.src : ''; });
    check('list: the first film\'s picture', /i\.ytimg\.com\/vi\/bHYXIThpjgA\/hqdefault\.jpg$/.test(limg), limg);
    await ldoc.click('a');
    await list.waitForTimeout(6000);
    const lp = (await lf.contentFrame()).url();
    check('list: a click: the player with the list, autoplay', /\/embed\/bHYXIThpjgA\?list=PLUZoa45E6Xug&autoplay=1$/.test(lp) && listHosts.some((h) => YT.test(h)), lp);
    await list.close();
  } finally {
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
