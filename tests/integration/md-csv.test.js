/**
 * Integration test of many Markdown documents as products through Gridbox's own CSV import (the list of the products
 * app, Import/Export CSV), on the docker Joomla + Gridbox: in the first step of its dialog two .md files are chosen
 * (the field the rest goes into, a category); Gridbox's check counts them new; its import creates two unpublished
 * products with their SKU, price, Producent link and the fields of their sections. The products removed afterwards.
 *
 *   node tests/integration/md-csv.test.js [--install]
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL_, check, sql, run, install } = require('./lib');

const APP = Number(sql("SELECT id FROM #__gridbox_app WHERE type = 'products' ORDER BY id LIMIT 1")[0][0]);
const CATEGORY = sql(`SELECT title FROM #__gridbox_categories WHERE app_id = ${APP} ORDER BY id LIMIT 1`)[0][0];
const STAMP = Date.now();
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mt-mdcsv-'));
const files = [
  ['a.md', `# MT CSV A ${STAMP}\n\n**SKU:** MT-CSV-A-${STAMP}\n**Cena:** 1 234,50 zł\n**Producent:** [Sonel](index.php?option=com_gridbox&view=page&id=256)\n\n## Akcesoria\n\n- adapter <b>EVSE</b>\n\n## Do pobrania\n\nInstrukcja u producenta.\n`],
  ['b.md', `# MT CSV B ${STAMP}\n\nSKU: MT-CSV-B-${STAMP}\nCena: 99\n\n## Wyposażenie\n\n- futerał\n`],
].map(([name, text]) => { const f = path.join(dir, name); fs.writeFileSync(f, text); return f; });
// (Gridbox keeps the label in JSON with \u escapes: "Wyposa\u017cenie")
const field = (label) => Number(sql(`SELECT id FROM #__gridbox_fields WHERE app_id = ${APP} AND JSON_UNQUOTE(JSON_EXTRACT(options, '$.label')) = '${label}'`)[0][0]);

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  try {
    await admin.goto(URL_ + '/administrator/index.php?option=com_gridbox&view=apps&id=' + APP, { waitUntil: 'load' });
    await admin.evaluate(() => Joomla.submitbutton('apps.exportcsv'));
    await admin.waitForSelector('.csv-import-options .mt-mdcsv-trigger', { state: 'attached', timeout: 15000 });
    const [chooser] = await Promise.all([admin.waitForEvent('filechooser', { timeout: 10000 }),
      admin.evaluate(() => document.querySelector('.mt-mdcsv input[type="file"]').click())]);
    await chooser.setFiles(files);
    await admin.waitForFunction(() => document.querySelectorAll('.mt-mdcsv-report > div').length === 2, null, { timeout: 15000 });
    // the rest of the documents into Akcesoria? no: left out for A ("Do pobrania"), named; a category chosen
    await admin.evaluate((cat) => { const c = document.querySelector('.mt-mdcsv-category'); c.value = cat; c.dispatchEvent(new Event('change')); }, CATEGORY);
    await admin.waitForTimeout(1500);
    const report = await admin.evaluate(() => [...document.querySelectorAll('.mt-mdcsv-report > div')].map((d) => d.textContent));
    check('the report: each file and its fields, the rest of A named as left out', report.length === 2 && /SKU/.test(report[0]) && /Producent/.test(report[0])
      && /(pominięta|left out)/i.test(report[0]), JSON.stringify(report));
    const ready = await admin.evaluate(() => ({ type: document.querySelector('.csv-file-type').value, file: app.csv.file && app.csv.file.name,
      next: document.querySelector('.apply-csv-import').classList.contains('active-button') }));
    check('Gridbox\'s dialog has the file, of its own kind, Next active', ready.type === 'gridbox' && /^markdown-2\.csv$/.test(ready.file) && ready.next, JSON.stringify(ready));
    // a choice changed: while the documents are read again Gridbox's Next has no file, and then the new one
    const during = await admin.evaluate(() => {
      const p = document.querySelector('.mt-mdcsv-published');
      p.checked = true;
      p.dispatchEvent(new Event('change'));
      return { file: app.csv.file, next: document.querySelector('.apply-csv-import').classList.contains('active-button') };
    });
    check('a changed choice: no file of the earlier one meanwhile, Next off', during.file === null && !during.next, JSON.stringify(during));
    await admin.waitForFunction(() => app.csv.file, null, { timeout: 15000 });
    check('then the file of the new choice (published)', /"TRUE"/.test(await admin.evaluate(() => app.csv.file.text())));
    await admin.evaluate(() => { const p = document.querySelector('.mt-mdcsv-published'); p.checked = false; p.dispatchEvent(new Event('change')); });
    await admin.waitForFunction(() => app.csv.file, null, { timeout: 15000 });
    const csv = await admin.evaluate(() => app.csv.file.text());
    check('the CSV: Gridbox\'s columns, the category for both', /^"ID","Category","Title","Product Type","Published"/.test(csv) && csv.split(CATEGORY).length === 3, csv.slice(0, 300));

    // Gridbox's own steps: its check, then its import
    await admin.click('.apply-csv-import');
    await admin.waitForSelector('.csv-import-step-3 [data-type="new"] .csv-import-status-color', { state: 'attached', timeout: 30000 });
    await admin.waitForTimeout(1000);
    const counted = await admin.evaluate(() => {
      const n = (t) => (document.querySelector('.csv-import-step-3 [data-type="' + t + '"] .csv-import-status-color') || {}).textContent;
      return { new: n('new'), errors: n('errors') };
    });
    check('Gridbox\'s check: 2 new, no errors', counted.new === '2' && (counted.errors === '0' || counted.errors === undefined), JSON.stringify(counted));
    await Promise.all([admin.waitForEvent('response', { predicate: (r) => /task=apps\.importCSV/.test(r.url()), timeout: 60000 }), admin.click('.apply-csv-import')]);
    await admin.waitForTimeout(2000);

    const made = sql(`SELECT id, title, published, page_category FROM #__gridbox_pages WHERE app_id = ${APP} AND title LIKE 'MT CSV _ ${STAMP}' ORDER BY title`);
    check('two products made, unpublished, in the category', made.length === 2 && made.every((r) => String(r[2]) === '0')
      && made.every((r) => Number(r[3]) === Number(sql(`SELECT id FROM #__gridbox_categories WHERE app_id = ${APP} AND title = '${CATEGORY.replace(/'/g, "''")}'`)[0][0])), JSON.stringify(made));
    if (made.length === 2) {
      const [a, b] = made.map((r) => Number(r[0]));
      const data = (id) => sql(`SELECT sku, price FROM #__gridbox_store_product_data WHERE product_id = ${id}`)[0] || [];
      check('A: SKU and price', data(a)[0] === 'MT-CSV-A-' + STAMP && Number(data(a)[1]) === 1234.5, JSON.stringify(data(a)));
      check('B: SKU and price', data(b)[0] === 'MT-CSV-B-' + STAMP && Number(data(b)[1]) === 99, JSON.stringify(data(b)));
      const value = (id, label) => (sql(`SELECT value FROM #__gridbox_page_fields WHERE page_id = ${id} AND field_id = ${field(label)}`)[0] || [''])[0];
      check('A: Producent, its label and link', /"label":"Sonel"/.test(value(a, 'Producent')) && /id=256/.test(value(a, 'Producent')), value(a, 'Producent'));
      check('A: Akcesoria from its section, the document\'s HTML as text', /<li>adapter ‹b›EVSE‹\/b›<\/li>/.test(value(a, 'Akcesoria')), value(a, 'Akcesoria'));
      check('B: Wyposażenie', /futerał/.test(value(b, 'Wyposażenie')), value(b, 'Wyposażenie'));
    }
  } finally {
    sql(`SELECT id FROM #__gridbox_pages WHERE app_id = ${APP} AND title LIKE 'MT CSV _ ${STAMP}'`).forEach(([id]) => {
      sql(`DELETE FROM #__gridbox_page_fields WHERE page_id = ${id}`);
      sql(`DELETE FROM #__gridbox_store_product_data WHERE product_id = ${id}`);
      sql(`DELETE FROM #__gridbox_pages WHERE id = ${id}`);
    });
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
