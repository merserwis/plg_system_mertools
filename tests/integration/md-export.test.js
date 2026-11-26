/**
 * Integration test of the Markdown export of a product (the editor's left bar), on the docker Joomla + Gridbox: a new
 * product filled by the import of fixtures/product.md and saved; its export downloaded as a .md file with its fields
 * ("**SKU:** …", the link of Producent), the sections of its fields with an editor (lists, the table, a sub-heading) and
 * the content of its page; that file imported into another new product gives the same fields and content (the round
 * trip). Both products removed afterwards.
 *
 *   node tests/integration/md-export.test.js [--install]
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { check, sql, run, install } = require('./lib');

const PRODUCT = path.join(__dirname, 'fixtures/product.md');
const SRC = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'products' AND p.page_category <> 'trashed'")[0][0]);
const OUT = path.join(process.env.TMPDIR || '/tmp', 'mt-export-' + process.pid + '.md');

const create = async (page, title) => {
  const [appId, category, theme] = sql(`SELECT app_id, page_category, theme FROM #__gridbox_pages WHERE id = ${SRC}`)[0];
  await page.evaluate(async ([a, c, t, title]) => {
    const body = new FormData();
    body.append('app_id', a); body.append('category', c); body.append('page_theme', t); body.append('title', title); body.append('published', '0');
    body.append(Joomla.getOptions('csrf.token'), '1');
    await fetch('index.php?option=com_gridbox&task=gridbox.createPage', { method: 'POST', body });
  }, [appId, category, theme, title]);
  return Number((sql(`SELECT id FROM #__gridbox_pages WHERE title = '${title}'`)[0] || [0])[0]);
};
const importFile = async (page, file) => {
  await page.waitForSelector('.ba-sidebar .mt-md-import-side:not(.mt-md-export-side) a', { timeout: 15000 });
  const [chooser] = await Promise.all([page.waitForEvent('filechooser', { timeout: 10000 }), page.click('.ba-sidebar .mt-md-import-side:not(.mt-md-export-side) a')]);
  await chooser.setFiles(file);
  await page.waitForTimeout(500);
  await page.waitForFunction(() => !document.querySelector('body > div[style*="z-index:99999"]'), null, { timeout: 90000 });
  await page.waitForTimeout(1500);
};
const save = async (page) => {
  await page.evaluate(() => document.querySelectorAll('#blog-post-editor-fields-options [data-required]').forEach((g) => {
    if (/slideshow|gallery/.test(g.dataset.fieldType)) g.removeAttribute('data-required');
  }));
  await page.click('.gridbox-save > span, .gridbox-save');
  await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 });
  await page.waitForTimeout(2000);
};
// what the editor holds: the fields of the form and the modules of the page
const state = (page) => page.evaluate(() => {
  const group = (label) => [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type]')]
    .find((g) => (g.querySelector('.ba-field-editor-label') || {}).textContent?.replace('*', '').trim() === label);
  const cke = (label) => { const g = group(label); return g ? app.fieldsCKE[g.dataset.id].getData().replace(/\s+/g, ' ').replace(/> </g, '><').trim() : null; };
  const maker = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="url"]')].find((g) => /Producent/.test(g.textContent));
  return {
    sku: document.querySelector('#ba-group-product-pricing [data-field-key="sku"] input').value,
    price: document.querySelector('#ba-group-product-pricing [data-field-key="price"] input').value,
    intro: document.querySelector('#blog-post-editor-general-options [name="intro_text"]').value,
    maker: [maker.querySelector('[data-name="label"]').value, maker.querySelector('[data-name="link"]').value],
    features: cke('Cechy i funkcje'), tech: cke('Dane techniczne'), kit: cke('Wyposażenie'), acc: cke('Akcesoria'),
    modules: [...app.editor.document.querySelectorAll('#ba-edit-section .ba-item-text, #ba-edit-section .ba-item-headline')].map((e) => {
      const h = e.querySelector('.headline-wrapper > *');
      return (h ? h.tagName.toLowerCase() : 't') + ':' + (e.querySelector('.content-text') || h || e).textContent.replace(/\s+/g, ' ').trim();
    }),
  };
});

run(async ({ login, editor }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const made = [];
  try {
    const page = await editor(admin, SRC);
    page.on('dialog', (d) => d.accept());
    const a = await create(page, 'MT eksport A ' + Date.now());
    made.push(a);
    await editor(page, a);
    await importFile(page, PRODUCT);
    await save(page);
    await editor(page, a);
    const before = await state(page);
    check('the product to export: filled by the import and saved', before.sku === 'MT/XY100' && /<table/.test(before.tech), JSON.stringify(before).slice(0, 200));

    // the export: a button in the editor's left bar, a .md file downloaded
    await page.waitForSelector('.ba-sidebar .mt-md-export-side a', { timeout: 15000 });
    const [download] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click('.ba-sidebar .mt-md-export-side a')]);
    await download.saveAs(OUT);
    const md = fs.readFileSync(OUT, 'utf8');
    check('the file named by the alias, .md', /\.md$/.test(download.suggestedFilename()) && !/gridbox-/.test(download.suggestedFilename()), download.suggestedFilename());
    check('label lines: SKU, price, Producent as a link', /^\*\*SKU:\*\* MT\/XY100$/m.test(md) && /^\*\*(Cena|Regular Price|Price):\*\* 1234\.50$/m.test(md)
      && /^\*\*Producent:\*\* \[Sonel\]\(index\.php\?option=com_gridbox&view=page&id=256\)$/m.test(md), md.slice(0, 400));
    check('the sections of the fields with an editor: a list, a sub-heading of level 3, the table', /^## Cechy i funkcje$/m.test(md) && /^### Bezpieczeństwo$/m.test(md)
      && /^- obudowa IP54$/m.test(md) && /^\| Izolacja \| 0–10 GΩ \|$/m.test(md), md);
    check('the content of the page: its headline and text', /^## Do pobrania$/m.test(md) && /^Instrukcja jest dostępna u producenta\.$/m.test(md), md);
    const said = await page.evaluate(() => [...document.querySelectorAll('#ba-notification p, .ba-notification-message p')].map((n) => n.textContent.trim()).join(' '));
    check('a notice names the file', said.includes(download.suggestedFilename()), said);

    // the round trip: the file into another new product
    const b = await create(page, 'MT eksport B ' + Date.now());
    made.push(b);
    await editor(page, b);
    await importFile(page, OUT);
    const after = await state(page);
    for (const k of ['sku', 'price', 'intro', 'maker', 'features', 'tech', 'kit', 'acc', 'modules']) {
      check('round trip: ' + k + ' the same', JSON.stringify(after[k]) === JSON.stringify(before[k]), JSON.stringify([after[k], before[k]]));
    }
  } finally {
    made.forEach((id) => {
      sql(`DELETE FROM #__gridbox_page_fields WHERE page_id = ${id}`);
      sql(`DELETE FROM #__gridbox_store_product_data WHERE product_id = ${id}`);
      sql(`DELETE FROM #__gridbox_pages WHERE id = ${id}`);
    });
    fs.rmSync(OUT, { force: true });
  }
});
