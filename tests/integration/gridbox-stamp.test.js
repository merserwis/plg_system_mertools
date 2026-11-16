/**
 * Integration test of Gridbox's files with the time of its installation (Tool/GridboxStamp), on the
 * docker Joomla + Gridbox:
 *  - a page for a guest: every address of Gridbox's files with its version carries the stamp (the time
 *    of Gridbox's manifest), gridboxVersion too, nothing with the bare version is left;
 *  - the editor of a product with extra options (what the old files of 2.20.5 could not save):
 *    gridboxVersion stamped also in the frame of the page, the modules loaded on the click on Save
 *    (gridboxSave.js) with the stamp, the save done with Gridbox's own button, none of the stamped
 *    addresses answered with an error;
 *  - the corrected package of Gridbox installed again with Joomla's installer (same version), the page
 *    cache on and holding the page: the same page with the new stamp, none of the old one;
 *  - setting off: as before.
 *
 *   node tests/integration/gridbox-stamp.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { C, URL_, ADMIN, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', '${v}'`).join(', ')}) WHERE ${PLUGIN}`);
const MANIFEST = '/var/www/html/administrator/components/com_gridbox/gridbox.xml';
const mtime = () => Number(execFileSync('docker', ['exec', C, 'stat', '-c', '%Y', MANIFEST], { encoding: 'utf8' }).trim());
const VERSION = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache, '$.version')) FROM #__extensions WHERE element = 'com_gridbox' AND type = 'component'")[0][0];
const TIME = mtime();
const stampOf = (t) => VERSION + '.' + t.toString(36);
// a published product with extra options
const PRODUCT = Number(sql(`SELECT d.product_id FROM #__gridbox_store_product_data d JOIN #__gridbox_pages p ON p.id = d.product_id
  WHERE p.published = 1 AND d.extra_options NOT IN ('', '{}', '[]') ORDER BY d.product_id LIMIT 1`)[0][0]);

/** the addresses of Gridbox's files in a page that end with its version (bare or stamped), and its gridboxVersion */
const versions = (html) => {
  const v = VERSION.replace(/\./g, '\\.');
  const urls = [...html.matchAll(/\b(?:src|href)=["']([^"'<>]*)["']/gi)].map((m) => m[1]).filter((u) => /com_gridbox|\/templates\/gridbox\//i.test(u));
  const bare = urls.filter((u) => new RegExp('(?:\\?|&|&amp;)' + v + '$').test(u));
  const stamped = urls.filter((u) => new RegExp('(?:\\?|&|&amp;)' + v + '\\.[0-9a-z]+$').test(u));
  const gv = (/gridboxVersion\s*=\s*['"]([^'"]+)['"]/.exec(html) || [])[1] || null;
  return { bare, stamped, gv };
};
// a query keeps the page out of MerTools' page cache
const guestHome = async () => (await fetch(URL_ + '/?mtstamp=' + Date.now())).text();
// the home page as kept by the page cache (no query)
const guestPage = async () => {
  const r = await fetch(URL_ + '/');
  return { html: await r.text(), cache: r.headers.get('x-mertools-cache') || 'MISS' };
};
// the corrected package of Gridbox 2.20.5 (Balbooa, 06.11.2026), installed again over the same version
const GRIDBOX_ZIP = process.env.MT_GRIDBOX_ZIP || path.join(os.homedir(), '.panel_uploads/pkg_Gridbox_2.20.5.0.zip');
let reinstalled = 0;

run(async ({ login, editor }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  try {
    // the pages kept by MerTools would answer with a page made before this test
    setParams({ speed_cache: '0', gridbox_stamp: '1' });
    check('Gridbox ' + VERSION + ', product with extra options ' + PRODUCT, !!VERSION && PRODUCT > 0);

    // a page for a guest
    let home = versions(await guestHome());
    check('guest page: Gridbox\'s files with the stamp (' + home.stamped.length + ')', home.stamped.length >= 3 && home.stamped.every((u) => u.endsWith(stampOf(TIME))), home.stamped.slice(0, 3).join(' '));
    check('…none with the bare version', home.bare.length === 0, home.bare.slice(0, 3).join(' '));
    check('…gridboxVersion with the stamp', home.gv === stampOf(TIME), String(home.gv));

    // the editor of the product: the modules loaded later, the save with Gridbox's own button
    const asked = [];
    const failed = [];
    page.on('request', (r) => asked.push(r.url()));
    page.on('response', (r) => { if (r.status() >= 400 && r.url().includes(stampOf(TIME))) failed.push(r.status() + ' ' + r.url()); });
    await editor(page, PRODUCT);
    await page.waitForFunction(() => document.querySelector('.gridbox-save').classList.contains('gridbox-enabled-save'), null, { timeout: 90000 });
    const gv = await page.evaluate(() => {
      const frame = document.querySelector('iframe.editor-iframe');
      return { top: window.gridboxVersion, frame: frame && frame.contentWindow ? frame.contentWindow.gridboxVersion : null };
    });
    check('editor: gridboxVersion with the stamp', gv.top === stampOf(TIME), String(gv.top));
    check('…and in the frame of the page', gv.frame === stampOf(TIME), String(gv.frame));
    // the path the old files of 2.20.5 broke on: the extra options known to the editor (not passed over)
    const options = await page.waitForFunction(() => Object.keys((window.app && window.app.productOptions) || {}).length > 0, null, { timeout: 60000 })
      .then(() => true, () => false);
    check('…the editor knows the product options', options);
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && /task=editor\.(saveGridbox|gridboxSave)\b/.test(r.url()), { timeout: 60000 }).catch(() => null);
    await page.click('.gridbox-save');
    const r = await sent;
    await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 }).catch(() => {});
    const saveJs = asked.find((u) => /\/libraries\/modules\/gridboxSave\.js/.test(u)) || '';
    check('…gridboxSave.js loaded with the stamp', saveJs.endsWith('?' + stampOf(TIME)), saveJs);
    const answer = r ? await r.text() : '';
    check('…the product with extra options saved with Gridbox\'s button', !!r && r.status() === 200 && /saved|zapisan/i.test(answer), (r ? r.status() + ' ' : 'no save ') + answer.slice(0, 120));
    check('…the button ready again', (await page.evaluate(() => document.querySelector('.gridbox-save').dataset.action)) === 'enabled');
    check('…no stamped address answered with an error', failed.length === 0, failed.slice(0, 3).join(' '));

    // the administrator's Gridbox pages
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=pages', { waitUntil: 'load', timeout: 90000 });
    const admin = versions(await page.content());
    check('administrator: no Gridbox file with the bare version', admin.bare.length === 0, admin.bare.slice(0, 3).join(' '));

    // the package installed again with Joomla's installer, the page cache on and holding the page
    if (fs.existsSync(GRIDBOX_ZIP)) {
      setParams({ speed_cache: '1' });
      await guestPage();
      const kept = await guestPage();
      check('page cache: the page kept (' + kept.cache + ')', kept.cache === 'HIT');
      await page.goto(ADMIN + 'index.php?option=com_installer&view=install');
      await page.setInputFiles('#install_package', GRIDBOX_ZIP);
      await page.waitForSelector('joomla-alert[type="success"], joomla-alert[type="danger"]', { timeout: 180000 });
      check('Gridbox installed again (' + path.basename(GRIDBOX_ZIP) + ')', !!(await page.$('joomla-alert[type="success"]')));
      reinstalled = mtime();
      check('…its manifest with a new time', reinstalled > TIME, `${TIME} → ${reinstalled}`);
      const after = await guestPage();
      home = versions(after.html);
      check('…the same page with the new stamp (' + after.cache + ')', home.stamped.length >= 3 && home.stamped.every((u) => u.endsWith(stampOf(reinstalled))) && home.gv === stampOf(reinstalled),
        String(home.gv) + ' ' + home.stamped.slice(0, 2).join(' '));
      check('…no address with the old stamp', !after.html.includes(stampOf(TIME)));
      setParams({ speed_cache: '0' });
    } else {
      console.log('SKIP the package installed again: no ' + GRIDBOX_ZIP + ' (MT_GRIDBOX_ZIP)');
    }

    // the setting off: as before
    setParams({ gridbox_stamp: '0' });
    home = versions(await guestHome());
    check('setting off: the bare version', home.stamped.length === 0 && home.bare.length >= 3 && home.gv === VERSION, String(home.gv));
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
  }
});
