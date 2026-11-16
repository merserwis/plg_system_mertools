/**
 * Integration test of the Link Picker of the Gridbox editor keeping its open folders (1.15.0), on the
 * docker Joomla + Gridbox: opened the first time all closed, as Gridbox makes it; two folders opened
 * with Gridbox's own arrows and the list scrolled — the next opening, and the one after the editor is
 * loaded again, has them open at the same place; a folder closed stays closed; with the setting off
 * the tree opens closed again.
 *
 *   node tests/integration/link-tree.test.js [--install]
 */
'use strict';
const { check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const PAGE = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'products' AND p.page_category <> 'trashed'")[0][0]);

run(async ({ login, editor, uid }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  /** the Link Picker as a link field opens it (helper.js: .select-link), its tree loaded and shown */
  const open = async () => {
    await page.evaluate(() => {
      document.querySelectorAll('#link-select-modal .availible-folders > ul').forEach((ul) => ul.remove());
      window.fontBtn = jQuery('<input type="text">');
      app.checkModule('selectLink');
    });
    await page.waitForSelector('#link-select-modal .availible-folders > ul', { state: 'visible', timeout: 30000 });
    await page.waitForTimeout(700);
  };
  const close = async () => {
    await page.click('#link-select-modal .modal-footer [data-dismiss="modal"]');
    await page.waitForSelector('#link-select-modal', { state: 'hidden', timeout: 10000 });
  };
  /** the names of the open folders, from the top of the tree */
  const opened = () => page.evaluate(() => [...document.querySelectorAll('#link-select-modal li.visible-branch')].map((li) => {
    const names = [];
    for (let el = li; el && !el.classList.contains('availible-folders'); el = el.parentElement) {
      if (el.tagName === 'LI') names.unshift(el.querySelector(':scope > span').textContent.trim());
    }
    return names.join(' / ');
  }));
  const arrow = (selector) => page.click(selector + ' > i.zmdi-chevron-right');
  const top = () => page.evaluate(() => document.querySelector('#link-select-modal .availible-folders > ul').scrollTop);
  try {
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.editor_link_tree', '1') WHERE ${PLUGIN}`);
    await editor(page, PAGE);
    await page.evaluate((uid) => localStorage.removeItem('mertools-link-tree:' + uid), uid);
    await open();
    check('first opening: all closed, as Gridbox makes it', (await opened()).length === 0, JSON.stringify(await opened()));
    // Gridbox, then its first folder with folders in it (an app)
    const root = '#link-select-modal .availible-folders > ul > li:first-child';
    await arrow(root);
    const child = await page.evaluate((root) => {
      const li = [...document.querySelectorAll(root + ' > ul > li')].findIndex((l) => l.querySelector(':scope > ul > li'));
      return li < 0 ? null : root + ' > ul > li:nth-child(' + (li + 1) + ')';
    }, root);
    check('a folder with folders in it', !!child);
    await arrow(child);
    const first = await opened();
    check('two folders open', first.length === 2, JSON.stringify(first));
    // the list scrolled: the long tree of an app shown
    await page.evaluate(() => {
      const ul = document.querySelector('#link-select-modal .availible-folders > ul');
      ul.scrollTop = 80;
      ul.dispatchEvent(new Event('scroll'));
    });
    const scrolled = await top();
    await page.waitForTimeout(400);
    await close();
    await open();
    check('next opening: the same folders open', JSON.stringify(await opened()) === JSON.stringify(first), JSON.stringify(await opened()));
    check('…at the same place of the list', scrolled > 0 && Math.abs((await top()) - scrolled) <= 1, `${await top()} / ${scrolled}`);
    // the editor loaded again
    await close();
    await editor(page, PAGE);
    await open();
    check('the editor loaded again: still open', JSON.stringify(await opened()) === JSON.stringify(first), JSON.stringify(await opened()));
    // a folder closed stays closed
    await arrow(child);
    await page.waitForTimeout(200);
    await close();
    await open();
    check('a folder closed stays closed', JSON.stringify(await opened()) === JSON.stringify(first.slice(0, 1)), JSON.stringify(await opened()));
    // a link is still chosen and put into the field, as Gridbox does
    await arrow(child);
    const link = await page.evaluate(() => {
      const li = document.querySelector('#link-select-modal li.visible-branch li[data-url]');
      return li ? li.dataset.url : null;
    });
    await page.click('#link-select-modal li.visible-branch li[data-url] > span');
    await page.click('#link-select-modal .apply-link');
    check('a link chosen goes into the field', !!link && await page.evaluate(() => window.fontBtn.val()) === link, link);
    await page.waitForSelector('#link-select-modal', { state: 'hidden', timeout: 10000 });
    // the setting off: as Gridbox makes it
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.editor_link_tree', '0') WHERE ${PLUGIN}`);
    await editor(page, PAGE);
    check('setting off: no script', await page.locator('#mertools-linktree-config').count() === 0);
    await open();
    check('setting off: all closed', (await opened()).length === 0, JSON.stringify(await opened()));
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
  }
});
