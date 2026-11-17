/**
 * Integration test of the settings kept per device marked in the Gridbox editor (1.17.0), on the docker
 * Joomla + Gridbox: a page with a simple gallery, its settings: the number of columns, the height and each field
 * of the margins marked, the class suffix and the reset of the margins not; the number of columns set to 1 in the view of a phone held upright:
 * the mark blue, its tip naming the phone and 1, also back in the computer's view, the computer's own value
 * unchanged; the page not saved; with the setting off no marks.
 *
 *   node tests/integration/editor-devices.test.js [--install]
 */
'use strict';
const { check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const PAGE = Number(sql("SELECT id FROM #__gridbox_pages WHERE style LIKE '%\"type\":\"simple-gallery\"%' OR params LIKE '%\"type\":\"simple-gallery\"%' ORDER BY id LIMIT 1")[0][0]);

run(async ({ login, editor }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) await install(page);
  const before = sql(`SELECT MD5(params), MD5(style) FROM #__gridbox_pages WHERE id = ${PAGE}`)[0];
  const open = async () => {
    await editor(page, PAGE);
    await page.waitForFunction(() => window.app && app.editor && app.editor.app && app.editor.app.items
      && app.editor.document.querySelector('.ba-item-simple-gallery'), null, { timeout: 60000 });
    await page.evaluate(() => { app.editor.app.edit = app.editor.document.querySelector('.ba-item-simple-gallery').id; app.editor.app.checkModule('editItem'); });
    await page.waitForSelector('#item-settings-dialog.in', { timeout: 30000 });
    await page.waitForTimeout(1200);
  };
  // another device in the editor's menu: Gridbox closes the settings, opened again as a click on the element would
  const device = async (view) => {
    await page.evaluate((view) => $g('.responsive-context-menu > span[data-view="' + view + '"]').trigger('mousedown'), view);
    await page.waitForTimeout(800);
    await page.evaluate(() => { app.editor.app.edit = app.editor.document.querySelector('.ba-item-simple-gallery').id; app.editor.app.checkModule('editItem'); });
    await page.waitForSelector('#item-settings-dialog.in', { timeout: 30000 });
    await page.waitForTimeout(1200);
  };
  const marks = () => page.evaluate(() => [...document.querySelectorAll('#item-settings-dialog .ba-settings-item')].filter((i) => i.offsetParent).map((i) => {
    const el = i.querySelector('[data-option]');
    const icon = i.querySelector(':scope > span > .mt-dev');
    return { key: el ? (el.dataset.group || '') + '.' + el.dataset.option : (i.querySelector(':scope > span') || {}).textContent, mark: !!icon,
      own: !!icon && icon.classList.contains('mt-dev-own'), tip: icon ? icon.title : '' };
  }));
  const at = (list, key) => list.find((m) => m.key === key) || {};
  try {
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.editor_devices', '1') WHERE ${PLUGIN}`);
    await open();
    let m = await marks();
    check('the number of columns marked as per device, not blue', at(m, '.count').mark && !at(m, '.count').own, JSON.stringify(at(m, '.count')));
    check('…the height too', at(m, 'view.height').mark, JSON.stringify(at(m, 'view.height')));
    check('…not the class suffix (one for all devices)', !m.some((x) => x.key === '.suffix' && x.mark), JSON.stringify(m.filter((x) => x.key === '.suffix')));
    const margin = await page.evaluate(() => {
      const box = (sel) => { const i = document.querySelector(sel); return i ? i.closest('.ba-settings-toolbar > div') : null; };
      const top = box('#item-settings-dialog .ba-settings-toolbar input[data-group="margin"][data-option="top"]');
      const reset = box('#item-settings-dialog .ba-settings-toolbar [data-type="reset"][data-option="margin"]');
      return [!!top, !!(top && top.querySelector(':scope > span > .mt-dev')), !!(reset && reset.querySelector('.mt-dev'))];
    });
    check('…the margins too, each field of their row, not their reset', margin[0] && margin[1] && !margin[2], JSON.stringify(margin));
    check('…its tip says so', /\S/.test(at(m, '.count').tip) && !/:\s*\d/.test(at(m, '.count').tip), at(m, '.count').tip);

    // the phone held upright: 1 column
    const phone = await page.evaluate(() => (document.querySelector('.responsive-context-menu > span[data-view="phone-portrait"] span') || {}).textContent.trim());
    await device('phone-portrait');
    const desk = await page.evaluate(() => app.edit.desktop.count);
    await page.fill('#item-settings-dialog input[data-option="count"]:not([data-group])', '1');
    // leaving the field (Gridbox writes it again on its change), as the click on the menu of devices does
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.waitForTimeout(1200);
    m = await marks();
    const own = await page.evaluate(() => app.edit['phone-portrait'].count);
    check('Gridbox keeps 1 for the phone', String(own) === '1', String(own));
    check('…the mark blue, its tip: the phone and 1', at(m, '.count').own && at(m, '.count').tip.includes(phone + ': 1'), JSON.stringify(at(m, '.count')));
    if (process.env.MT_SHOT) {
      await page.hover('#item-settings-dialog .ba-settings-item:has(input[data-option="count"]:not([data-group])) .mt-dev');
      await page.locator('#item-settings-dialog').screenshot({ path: process.env.MT_SHOT });
    }
    check('…the height still not blue', at(m, 'view.height').mark && !at(m, 'view.height').own, JSON.stringify(at(m, 'view.height')));
    await device('desktop');
    m = await marks();
    const desk2 = await page.evaluate(() => [app.edit.desktop.count, document.querySelector('#item-settings-dialog input[data-option="count"]:not([data-group])').value]);
    check('back on the computer: its own value, the mark still blue', String(desk2[0]) === String(desk) && String(desk2[1]) === String(desk) && at(m, '.count').own,
      JSON.stringify([desk, desk2, at(m, '.count')]));
    check('the page not saved', JSON.stringify(sql(`SELECT MD5(params), MD5(style) FROM #__gridbox_pages WHERE id = ${PAGE}`)[0]) === JSON.stringify(before));

    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.editor_devices', '0') WHERE ${PLUGIN}`);
    page.on('dialog', (d) => d.accept());
    await open();
    check('setting off: no marks', (await marks()).every((x) => !x.mark));
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
  }
});
