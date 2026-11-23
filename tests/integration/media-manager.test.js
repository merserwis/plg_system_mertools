/**
 * Integration test of Gridbox's media manager (1.19.2), on the docker Joomla + Gridbox, with MerTools'
 * consent and dark mode on:
 *  - the frame of the media manager opened in the editor (view=uploader) gets none of the visitor's
 *    tools (no cookie button, no light/dark toggle), the site's page still does;
 *  - its window resized by dragging an edge and a corner (the frame inside of the window's height),
 *    the size kept after a reload, Gridbox's full screen over it and the size back after it, a double
 *    click giving Gridbox's 970 × 600 back; fitted into a smaller screen (also where the browser keeps
 *    nothing) and the size chosen back on a larger one; the same in the panel (an app's list); off as before.
 *
 *   node tests/integration/media-manager.test.js [--install]
 */
'use strict';
const { URL_, ADMIN, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const PAGE = Number(sql("SELECT MIN(id) FROM #__gridbox_pages WHERE page_category <> 'trashed'")[0][0]);
const BLOG_APP = Number(sql("SELECT id FROM #__gridbox_app WHERE type = 'blog' ORDER BY id LIMIT 1")[0][0]);
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const setParams = (extra) => {
  const all = Object.assign({ consent_enabled: '1', dark_enabled: '1', media_resize: '1', media_drop: '1', dark_toggle: '1', dark_toggle_place: 'float', speed_cache: '0' }, extra);
  const sets = Object.entries(all).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ');
  sql(`UPDATE #__extensions SET params = JSON_SET(params, ${sets}) WHERE ${PLUGIN}`);
};

/** opens the media manager as Gridbox's buttons do; its frame */
const open = async (page) => {
  await page.evaluate(() => checkIframe($g('#uploader-modal'), 'uploader'));
  await page.waitForFunction(() => {
    const m = document.querySelector('#uploader-modal');
    const f = m && m.querySelector('iframe');
    return m && m.classList.contains('in') && f.contentWindow && f.contentWindow.mediaManager && f.contentDocument.readyState === 'complete';
  }, null, { timeout: 60000 });
  await page.waitForTimeout(900); // Gridbox's opening animation
  return page.frame({ name: 'uploader-iframe' });
};
const rect = (page) => page.evaluate(() => {
  const m = document.querySelector('#uploader-modal');
  const r = m.getBoundingClientRect();
  const f = m.querySelector('.modal-body iframe').getBoundingClientRect();
  return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), fh: Math.round(f.height) };
});
const drag = async (page, dir, dx, dy) => {
  const b = await page.locator('#uploader-modal > .mt-rs-' + dir).boundingBox();
  const x = b.x + b.width / 2;
  const y = b.y + b.height / 2;
  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.mouse.move(x + dx / 2, y + dy / 2, { steps: 4 });
  await page.mouse.move(x + dx, y + dy, { steps: 4 });
  await page.mouse.up();
  await page.waitForTimeout(400);
};
const near = (a, b, d = 3) => Math.abs(a - b) <= d;

run(async ({ login, editor }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  try {
    setParams({});
    // the site's page: the visitor's tools are there
    const guest = await admin.context().browser().newPage();
    await guest.goto(URL_ + '/', { waitUntil: 'load' });
    const site = await guest.content();
    check('the site: the cookie consent and the mode toggle there', site.includes('mertools-consent') && site.includes('mertools-dark'));
    await guest.close();

    const page = await editor(await admin.context().newPage(), PAGE);
    await page.evaluate(() => localStorage.removeItem('mertools-media-size'));
    let frame = await open(page);
    const inside = await frame.evaluate(() => ({
      html: document.documentElement.outerHTML,
      buttons: document.querySelectorAll('.mertools-dt, .mertools-dt-stack, .mertools-consent-reopen, [class*="mertools-cc"]').length,
    }));
    check('media manager: no cookie consent in it', !/mertools-consent/.test(inside.html));
    check('media manager: no light/dark toggle in it', !/mertools-dark/.test(inside.html) && inside.buttons === 0);
    check('media manager: its own script there (drop, resize)', /mertools-media/.test(inside.html));

    check('the window: 8 handles on its edges and corners', await page.locator('#uploader-modal > .mt-rs').count() === 8);
    const r0 = await rect(page);
    check('the window: Gridbox\'s size at first', near(r0.w, 970) && near(r0.h, 600), JSON.stringify(r0));
    await drag(page, 'se', 120, 100);
    const r1 = await rect(page);
    check('corner dragged: wider and higher, the same place', near(r1.w, r0.w + 120) && near(r1.h, r0.h + 100) && near(r1.x, r0.x) && near(r1.y, r0.y), JSON.stringify([r0, r1]));
    check('corner dragged: the frame of the window\'s height', near(r1.fh, r1.h), JSON.stringify(r1));
    await drag(page, 'w', -80, 0);
    const r2 = await rect(page);
    check('left edge dragged: wider to the left', near(r2.x, r1.x - 80) && near(r2.w, r1.w + 80) && near(r2.h, r1.h), JSON.stringify([r1, r2]));
    await drag(page, 'n', 0, 5000);
    const r3 = await rect(page);
    check('top edge dragged far down: stops at the smallest height', near(r3.h, 320) && near(r3.y + r3.h, r2.y + r2.h), JSON.stringify(r3));
    await drag(page, 'n', 0, r3.h - r2.h);
    const r4 = await rect(page);
    check('top edge dragged back up: the height before', near(r4.h, r2.h) && near(r4.y, r2.y) && near(r4.w, r2.w), JSON.stringify([r2, r4]));

    // kept: the editor loaded again
    await editor(page, PAGE);
    frame = await open(page);
    const r5 = await rect(page);
    check('reloaded: the size and place kept', near(r5.w, r4.w) && near(r5.h, r4.h) && near(r5.x, r4.x) && near(r5.y, r4.y), JSON.stringify([r4, r5]));
    // opened again in the same page (the frame loaded again by Gridbox): the handles once
    await page.evaluate(() => checkIframe($g('#uploader-modal'), 'uploader', null, true));
    await page.waitForTimeout(2500);
    check('frame reloaded: the handles still 8', await page.locator('#uploader-modal > .mt-rs').count() === 8);

    // Gridbox's full screen, then back
    frame = page.frame({ name: 'uploader-iframe' });
    await frame.click('.media-fullscrean');
    await page.waitForTimeout(700);
    const vp = page.viewportSize();
    const full = await rect(page);
    check('full screen: over the size chosen', full.w > vp.width * 0.95 && full.h > vp.height * 0.9, JSON.stringify(full));
    check('full screen: no handles', await page.locator('#uploader-modal > .mt-rs-se').isHidden());
    await frame.click('.media-fullscrean');
    await page.waitForTimeout(700);
    const back = await rect(page);
    check('out of full screen: the size chosen back', near(back.w, r4.w) && near(back.h, r4.h), JSON.stringify(back));

    // a smaller screen: fitted into it, from the size chosen even where the browser keeps nothing
    await page.evaluate(() => localStorage.removeItem('mertools-media-size'));
    await page.setViewportSize({ width: 800, height: 600 });
    await page.waitForTimeout(500);
    const small = await rect(page);
    check('smaller screen: the window inside it', small.x >= 0 && small.y >= 0 && small.x + small.w <= 800 && small.y + small.h <= 600 && near(small.fh, small.h), JSON.stringify(small));
    await page.setViewportSize(vp);
    await page.waitForTimeout(500);
    const large = await rect(page);
    check('the screen large again: the size chosen back', near(large.w, r4.w) && near(large.h, r4.h) && near(large.x, r4.x) && near(large.y, r4.y), JSON.stringify([r4, large]));

    // a double click: Gridbox's size, and nothing kept
    await page.locator('#uploader-modal > .mt-rs-e').dblclick();
    await page.waitForTimeout(500);
    const def = await rect(page);
    check('double click: Gridbox\'s 970 × 600 back', near(def.w, 970) && near(def.h, 600), JSON.stringify(def));
    check('double click: nothing kept', await page.evaluate(() => localStorage.getItem('mertools-media-size')) === null);

    // a browser that keeps nothing (storage refused): the size chosen still fitted to a smaller screen,
    // also after Gridbox loads the frame again
    await page.evaluate(() => Object.defineProperty(window, 'localStorage', { configurable: true, get() { throw new DOMException('denied', 'SecurityError'); } }));
    await drag(page, 'se', 150, 120);
    const b1 = await rect(page);
    check('storage refused: the window still resized', near(b1.w, 970 + 150) && near(b1.h, 600 + 120), JSON.stringify(b1));
    await page.evaluate(() => checkIframe($g('#uploader-modal'), 'uploader', null, true));
    await page.waitForTimeout(2500);
    check('storage refused, frame reloaded: the handles still 8', await page.locator('#uploader-modal > .mt-rs').count() === 8);
    await page.setViewportSize({ width: 800, height: 600 });
    await page.waitForTimeout(500);
    const b2 = await rect(page);
    check('storage refused, frame reloaded, smaller screen: the window inside it', b2.x >= 0 && b2.y >= 0 && b2.x + b2.w <= 800 && b2.y + b2.h <= 600, JSON.stringify(b2));
    await page.setViewportSize(vp);
    await page.waitForTimeout(500);
    const b3 = await rect(page);
    check('storage refused: the size chosen back on the larger screen', near(b3.w, b1.w) && near(b3.h, b1.h), JSON.stringify([b1, b3]));

    // the panel: an app's list
    await admin.goto(ADMIN + 'index.php?option=com_gridbox&view=apps&id=' + BLOG_APP);
    await admin.evaluate(() => localStorage.removeItem('mertools-media-size'));
    await open(admin);
    check('panel: the handles there', await admin.locator('#uploader-modal > .mt-rs').count() === 8);
    const p0 = await rect(admin);
    await drag(admin, 's', 0, 90);
    const p1 = await rect(admin);
    check('panel: bottom edge dragged, higher', near(p1.h, p0.h + 90) && near(p1.w, p0.w) && near(p1.fh, p1.h), JSON.stringify([p0, p1]));

    // off: as before
    setParams({ media_resize: '0' });
    await admin.reload();
    await open(admin);
    check('off: no handles', await admin.locator('#uploader-modal > .mt-rs').count() === 0);
  } finally {
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
