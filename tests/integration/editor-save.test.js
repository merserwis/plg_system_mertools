/**
 * Integration test of the warning before closing the Gridbox editor unsaved (media/js/mertools-save.js),
 * on a real Joomla + Gridbox in docker (the test copy gbx-sitemap-test): a temporary Super User opens
 * the editor of a product and of a page with Playwright. What changes nothing must leave no dot on Save
 * and no question when leaving (clicks on the elements of the page, their settings opened, a field
 * typed in and typed back, the switch of the fields editor); a real change must leave both, until it
 * is saved or undone. The product saved during the test is put back as it was (its row of the pages
 * table, the values typed back before the last save).
 *
 *   node tests/integration/editor-save.test.js            the MerTools installed on the copy
 *   node tests/integration/editor-save.test.js --install  first installs pkg_mertools-<version>.zip (./build.sh)
 *
 * Env: MT_CONTAINER (gbx-sitemap-test), MT_URL (http://127.0.0.1:18096), PLAYWRIGHT (path of playwright-core).
 */
'use strict';
const { check, sql, run, install } = require('./lib');

const PRODUCT = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'products' AND p.page_category <> 'trashed'")[0][0]);
const PAGE = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'single' AND p.page_category <> 'trashed'")[0][0]);
const POST = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'blog' AND p.page_category <> 'trashed'")[0][0]);

run(async ({ login, editor }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  const ctx = admin.context();
  const open = async (id) => {
    const page = await ctx.newPage();
    await editor(page, id);
    await page.waitForTimeout(6000);
    return page;
  };
  const dot = async (page) => { await page.waitForTimeout(1500); return page.evaluate(() => !!document.querySelector('.gridbox-save.mt-unsaved')); };
  // closing the tab: does the browser ask first (the beforeunload of MerTools)?
  const asks = async (page) => {
    let asked = false;
    page.once('dialog', (d) => { asked = d.type() === 'beforeunload'; d.dismiss().catch(() => {}); });
    await page.close({ runBeforeUnload: true });
    await new Promise((r) => setTimeout(r, 1500));
    if (!page.isClosed()) await page.close();
    return asked;
  };

  // ------------------------------------------------------------ a page and a blog post: the canvas
  // (the post: its editor's bar gets MerTools' import button after Gridbox's first step, and the texts
  // of a post are edited by an inline text editor that marks the text in focus)
  const canvas = async (id, name) => {
    const page = await open(id);
    check(name + ': nothing done, no dot', !(await dot(page)));
    const frame = await page.evaluate(() => { const r = document.querySelector('iframe.editor-iframe').getBoundingClientRect(); return { x: r.x, y: r.y }; });
    const items = await page.evaluate(() => [...document.querySelector('iframe.editor-iframe').contentDocument.querySelectorAll('.ba-item')]
      .map((e) => { const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + Math.min(r.height / 2, 20), w: r.width, h: r.height, text: e.classList.contains('ba-item-text') }; })
      .filter((i) => i.w > 10 && i.h > 10 && i.y > 0 && i.y < 800));
    const steps = await page.evaluate(() => app.history.length);
    for (const it of items.slice(0, 5)) {
      await page.mouse.click(frame.x + it.x, frame.y + it.y);
      await page.waitForTimeout(400);
      await page.mouse.dblclick(frame.x + it.x, frame.y + it.y);
      await page.keyboard.press('Escape');
    }
    // on a page each click is a step of Gridbox's history (in a post, not always)
    if (name === 'page') check(name + ': clicks on elements made steps of Gridbox\'s history (the case tested)', (await page.evaluate(() => app.history.length)) > steps);
    check(name + ': clicks on elements, no dot', !(await dot(page)));
    const text = items.find((i) => i.text);
    if (!text) {
      check(name + ': has a text element to type in', false);
      await page.close({ runBeforeUnload: false });
      return;
    }
    await page.mouse.dblclick(frame.x + text.x, frame.y + text.y);
    await page.keyboard.press('End');
    await page.keyboard.type('Q');
    await page.waitForTimeout(800);
    check(name + ': a letter typed in a text, the dot', await dot(page));
    await page.click('.ba-action-undo');
    await page.waitForTimeout(800);
    check(name + ': undone with Gridbox\'s undo, no dot', !(await dot(page)));
    await page.click('.ba-action-redo');
    check(name + ': redone, the dot', await dot(page));
    check(name + ': closing it asks', await asks(page));
  };
  await canvas(PAGE, 'page');
  await canvas(POST, 'post');

  // ------------------------------------------------------------ a product: the fields
  sql('DROP TABLE IF EXISTS mt_test_pages_backup');
  // the copy's dates of Gridbox (zero dates) need the server's default mode off for the copy table
  sql(`SET SESSION sql_mode = ''; CREATE TABLE mt_test_pages_backup AS SELECT * FROM #__gridbox_pages WHERE id = ${PRODUCT}`);
  sql('DROP TABLE IF EXISTS mt_test_product_backup');
  sql(`SET SESSION sql_mode = ''; CREATE TABLE mt_test_product_backup AS SELECT * FROM #__gridbox_store_product_data WHERE product_id = ${PRODUCT}`);
  try {
    const page = await open(PRODUCT);
    const field = (key) => page.locator(`#blog-post-editor-fields-options .blog-post-editor-options-group[data-field-key="${key}"] input[type=text]`).first();
    check('product: nothing done, no dot', !(await dot(page)));
    const toggle = page.locator('.apps-fields-editor-toggle');
    if (await toggle.count()) {
      await page.locator('label:has(> .apps-fields-editor-toggle)').click();
      await page.waitForTimeout(500);
      await page.locator('label:has(> .apps-fields-editor-toggle)').click();
      check('product: the fields editor switched on and off, no dot', !(await dot(page)));
    }
    await field('price').click();
    await page.keyboard.press('End');
    await page.keyboard.type('5');
    check('product: a digit typed in the price, the dot', await dot(page));
    await page.keyboard.press('Backspace');
    check('product: …and typed back, no dot', !(await dot(page)));
    await field('sku').click();
    await page.keyboard.press('End');
    await page.keyboard.type('-X');
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Backspace');
    await page.mouse.click(5, 5);
    check('product: the SKU typed in and typed back, no dot', !(await dot(page)));
    const cke = page.locator('iframe.cke_wysiwyg_frame').first();
    if (await cke.count() && await cke.isVisible()) {
      await cke.scrollIntoViewIfNeeded();
      await page.frameLocator('iframe.cke_wysiwyg_frame').first().locator('body').click();
      await page.keyboard.press('Control+End');
      await page.keyboard.type('Z');
      check('product: a letter typed in a text editor of a field, the dot', await dot(page));
      await page.keyboard.press('Backspace');
      check('product: …and typed back, no dot', !(await dot(page)));
    }

    // a real change, saved: no dot, nothing asked; typed back and saved again (the product as it was)
    const sku = await field('sku').inputValue();
    await field('sku').click();
    await page.keyboard.press('End');
    await page.keyboard.type('-T');
    check('product: the SKU changed, the dot', await dot(page));
    const save = async () => {
      await page.click('.gridbox-save > span, .gridbox-save');
      await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 });
      await page.waitForTimeout(1500);
    };
    await save();
    const saved = sql(`SELECT sku FROM #__gridbox_store_product_data WHERE product_id = ${PRODUCT}`)[0][0];
    check('product: saved (the SKU in the database)', saved === sku + '-T', saved);
    check('product: saved, no dot', !(await dot(page)));
    await field('sku').click();
    await page.keyboard.press('End');
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Backspace');
    check('product: the SKU typed back after the save is a change, the dot', await dot(page));
    await save();
    check('product: the SKU as before in the database', sql(`SELECT sku FROM #__gridbox_store_product_data WHERE product_id = ${PRODUCT}`)[0][0] === sku);
    check('product: saved again, no dot, closing asks nothing', !(await dot(page)) && !(await asks(page)));
  } finally {
    sql(`SET SESSION sql_mode = ''; REPLACE INTO #__gridbox_pages SELECT * FROM mt_test_pages_backup`);
    sql(`SET SESSION sql_mode = ''; UPDATE #__gridbox_store_product_data d JOIN mt_test_product_backup b ON b.id = d.id SET d.sku = b.sku`);
    sql('DROP TABLE IF EXISTS mt_test_pages_backup');
    sql('DROP TABLE IF EXISTS mt_test_product_backup');
  }
});
