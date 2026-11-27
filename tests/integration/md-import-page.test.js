/**
 * Integration test of the Markdown import into a product and into a page, in the Gridbox editor of the
 * docker Joomla (the copy of merserwis.pl, its products app with the fields Cechy i funkcje, Dane
 * techniczne, Wyposażenie, Akcesoria, Producent):
 *  - a new product: the label lines fill SKU, price, Producent (label and link), title and intro; the sections
 *    named as fields fill those fields (with their sub-headings, tables and lists); a section of no field goes into
 *    the content of the product's page; Gridbox's save keeps it all; a second import asks before replacing;
 *  - a new page: its # heading an h1 headline, its texts and heading in order, saved and opened again;
 *  - a page with content: the modules in a new section at its end, what was there untouched.
 *
 *   node tests/integration/md-import-page.test.js [--install]
 */
'use strict';
const path = require('path');
const { check, sql, run, install } = require('./lib');

const PRODUCT = path.join(__dirname, 'fixtures/product.md');
const PAGE = path.join(__dirname, 'fixtures/page.md');
const EDGE = path.join(__dirname, 'fixtures/product-edge.md');
const SRC_PRODUCT = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'products' AND p.page_category <> 'trashed'")[0][0]);
const SRC_PAGE = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'single' AND p.page_category <> 'trashed' AND p.params LIKE '%ba-item-headline%'")[0][0]);
const field = (label) => Number(sql(`SELECT id FROM #__gridbox_fields WHERE app_id = (SELECT app_id FROM #__gridbox_pages WHERE id = ${SRC_PRODUCT}) AND options LIKE '%"label":"${label}"%'`)[0][0]);

const create = async (page, src, title) => {
  const [appId, category, theme] = sql(`SELECT app_id, page_category, theme FROM #__gridbox_pages WHERE id = ${src}`)[0];
  await page.evaluate(async ([a, c, t, title]) => {
    const body = new FormData();
    body.append('app_id', a); body.append('category', c); body.append('page_theme', t); body.append('title', title); body.append('published', '0');
    body.append(Joomla.getOptions('csrf.token'), '1');
    await fetch('index.php?option=com_gridbox&task=gridbox.createPage', { method: 'POST', body });
  }, [appId, category, theme, title]);
  return Number((sql(`SELECT id FROM #__gridbox_pages WHERE title = '${title}'`)[0] || [0])[0]);
};
const importFile = async (page, file) => {
  // the button of the editor's left bar, clicked as a person does (in an item's form Gridbox takes the clicks of that bar)
  await page.waitForSelector('.ba-sidebar .mt-md-import-side a', { timeout: 15000 });
  const [chooser] = await Promise.all([page.waitForEvent('filechooser', { timeout: 10000 }), page.click('.ba-sidebar .mt-md-import-side a')]);
  await chooser.setFiles(file);
  await page.waitForTimeout(500);
  await page.waitForFunction(() => !document.querySelector('body > div[style*="z-index:99999"]'), null, { timeout: 90000 });
  await page.waitForTimeout(1500);
};
const save = async (page) => {
  await page.click('.gridbox-save > span, .gridbox-save');
  await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 });
  await page.waitForTimeout(2000);
};
const modules = (page) => page.evaluate(() => [...app.editor.document.querySelectorAll('#ba-edit-section .ba-item-text, #ba-edit-section .ba-item-headline')]
  .map((e) => {
    const h = e.querySelector('.headline-wrapper > *');
    return (h ? h.tagName.toLowerCase() : 't') + ':' + (e.querySelector('.content-text') || h || e).textContent.replace(/\s+/g, ' ').trim();
  }));
const notices = (page) => page.evaluate(() => [...document.querySelectorAll('#ba-notification p, .ba-notification-message p')].map((n) => n.textContent.trim()).filter(Boolean));

run(async ({ login, editor }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const made = [];
  try {
    // ---- a new product
    const page = await editor(admin, SRC_PRODUCT);
    const id = await create(page, SRC_PRODUCT, 'MT import produkt ' + Date.now());
    check('a new product made', id > 0, String(id));
    made.push(id);
    await editor(page, id);
    const dialogs = [];
    page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
    await importFile(page, PRODUCT);
    const form = await page.evaluate(() => {
      const val = (sel) => (document.querySelector(sel) || {}).value;
      const cke = (label) => {
        const g = [...document.querySelectorAll('#blog-post-editor-fields-options .blog-post-editor-options-group[data-field-type="textarea"]')]
          .find((x) => x.querySelector('.ba-field-editor-label').textContent.trim() === label);
        return g ? app.fieldsCKE[g.dataset.id].getData() : null;
      };
      const url = document.querySelector('#blog-post-editor-fields-options [data-field-type="url"] input[data-name="label"]').closest('[data-field-type]');
      return {
        sku: val('#ba-group-product-pricing [data-field-key="sku"] input'), price: val('#ba-group-product-pricing [data-field-key="price"] input'),
        title: val('#blog-post-editor-general-options [name="page_title"]'), intro: val('#blog-post-editor-general-options [name="intro_text"]'),
        maker: [url.querySelector('[data-name="label"]').value, url.querySelector('[data-name="link"]').value],
        sheet: (() => {
          const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="file"]')]
            .find((x) => x.querySelector('.ba-field-editor-label').textContent.replace('*', '').trim() === 'Karta katalogowa');
          const el = g && g.querySelector('input[name]');
          return el && [el.value, el.dataset.value];
        })(),
        features: cke('Cechy i funkcje'), tech: cke('Dane techniczne'), kit: cke('Wyposażenie'), acc: cke('Akcesoria'),
      };
    });
    check('a new product: asked once, for the title Gridbox gave it', dialogs.length === 1 && /tytuł|title/i.test(dialogs[0]), JSON.stringify(dialogs));
    check('SKU', form.sku === 'MT/XY100', form.sku);
    check('price as a number', form.price === '1234.50', form.price);
    check('title and intro text', form.title === 'MT Miernik testowy XY-100' && form.intro.startsWith('Miernik testowy XY-100 do'), JSON.stringify([form.title, form.intro]));
    check('Producent: label and link', form.maker[0] === 'Sonel' && form.maker[1] === 'index.php?option=com_gridbox&view=page&id=256', JSON.stringify(form.maker));
    check('Karta katalogowa: the path of the file from the site\'s root, its name shown', JSON.stringify(form.sheet) === JSON.stringify(['karta_XY-100.pdf', 'images/mt-test/karta_XY-100.pdf']), JSON.stringify(form.sheet));
    check('Cechy i funkcje: text with its sub-heading and list', /pętlę zwarcia/.test(form.features) && /<h3>Bezpieczeństwo<\/h3>/.test(form.features) && /IP54/.test(form.features), form.features);
    check('Dane techniczne: the table', /<table/.test(form.tech) && /0(–|&ndash;)10 G(Ω|&Omega;)/.test(form.tech), form.tech);
    check('Wyposażenie and Akcesoria: lists', /futerał/.test(form.kit) && /<li>adapter EVSE-01<\/li>/.test(form.acc), JSON.stringify([form.kit, form.acc]));
    check('no Akcesoria in Wyposażenie', !/EVSE/.test(form.kit), form.kit);
    const content = await modules(page);
    check('the section of no field: in the content of the page', content.join('|') === 'h2:Do pobrania|t:Instrukcja jest dostępna u producenta.', JSON.stringify(content));
    const said = await notices(page);
    check('notice names the content and the fields', said.some((n) => /nagłówków 1|1 headlines/.test(n) && /SKU/.test(n)), JSON.stringify(said));
    // Gridbox's own save (the copy's product has a required gallery: one picture for the test)
    await page.evaluate(() => document.querySelectorAll('#blog-post-editor-fields-options [data-required]').forEach((g) => {
      if (/slideshow|gallery/.test(g.dataset.fieldType)) g.removeAttribute('data-required');
    }));
    await save(page);
    const data = sql(`SELECT sku, price FROM #__gridbox_store_product_data WHERE product_id = ${id}`)[0] || [];
    check('saved: SKU and price', data[0] === 'MT/XY100' && Number(data[1]) === 1234.5, JSON.stringify(data));
    const value = (label) => (sql(`SELECT value FROM #__gridbox_page_fields WHERE page_id = ${id} AND field_id = ${field(label)}`)[0] || [''])[0];
    check('saved: Dane techniczne, Akcesoria, Producent', /<table/.test(value('Dane techniczne')) && /EVSE/.test(value('Akcesoria')) && /"label":"Sonel"/.test(value('Producent')),
      JSON.stringify([value('Dane techniczne').slice(0, 60), value('Akcesoria'), value('Producent')]));
    check('saved: Karta katalogowa', value('Karta katalogowa') === 'images/mt-test/karta_XY-100.pdf', value('Karta katalogowa'));
    check('saved: title', sql(`SELECT title FROM #__gridbox_pages WHERE id = ${id}`)[0][0] === 'MT Miernik testowy XY-100');
    // again into the filled product: it asks, and Cancel keeps what is there
    await editor(page, id);
    dialogs.length = 0;
    page.removeAllListeners('dialog');
    page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
    await page.evaluate(() => {
      const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="textarea"]')].find((x) => /Akcesoria/.test(x.textContent));
      app.fieldsCKE[g.dataset.id].setData('<p>Moje akcesoria</p>');
    });
    await page.waitForTimeout(500);
    await importFile(page, PRODUCT);
    const acc = await page.evaluate(() => {
      const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="textarea"]')].find((x) => /Akcesoria/.test(x.textContent));
      return app.fieldsCKE[g.dataset.id].getData();
    });
    check('a filled product: asked, naming the field that differs', dialogs.length === 1 && /Akcesoria/.test(dialogs[0]), JSON.stringify(dialogs));
    check('Cancel: the field kept', /Moje akcesoria/.test(acc) && !/EVSE/.test(acc), acc);
    page.removeAllListeners('dialog');

    // the fields are not in Gridbox's history: the bar after an import puts back what they held before it, but a field
    // changed since the import; the bar goes with its ×, and with the next import
    const fields = () => page.evaluate(() => {
      const group = (label) => [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type]')]
        .find((g) => (g.querySelector('.ba-field-editor-label') || {}).textContent?.replace('*', '').trim() === label);
      const maker = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="url"]')].find((g) => /Producent/.test(g.textContent));
      return {
        sku: document.querySelector('#ba-group-product-pricing [data-field-key="sku"] input').value,
        price: document.querySelector('#ba-group-product-pricing [data-field-key="price"] input').value,
        title: document.querySelector('#blog-post-editor-general-options [name="page_title"]').value,
        maker: [maker.querySelector('[data-name="label"]').value, maker.querySelector('[data-name="link"]').value],
        kit: app.fieldsCKE[group('Wyposażenie').dataset.id].getData(),
        acc: app.fieldsCKE[group('Akcesoria').dataset.id].getData(),
        sheet: (() => { const el = group('Karta katalogowa').querySelector('input[name]'); return [el.value, el.dataset.value]; })(),
      };
    });
    await page.evaluate(() => {
      const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); };
      set(document.querySelector('#ba-group-product-pricing [data-field-key="sku"] input'), 'MT/STARY');
      set(document.querySelector('#ba-group-product-pricing [data-field-key="price"] input'), '99.00');
      const maker = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="url"]')].find((g) => /Producent/.test(g.textContent));
      set(maker.querySelector('[data-name="label"]'), 'Stary producent');
      set(maker.querySelector('[data-name="link"]'), '/stary');
      const kit = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="textarea"]')].find((x) => /Wyposażenie/.test(x.textContent));
      app.fieldsCKE[kit.dataset.id].setData('<p>Stare wyposażenie</p>');
      const sheet = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="file"]')].find((x) => /Karta katalogowa/.test(x.textContent)).querySelector('input[name]');
      sheet.value = 'stara.pdf';
      sheet.dataset.value = 'images/stara.pdf';
    });
    await page.waitForTimeout(500);
    const was = await fields();
    page.on('dialog', (d) => d.accept());
    await importFile(page, PRODUCT);
    page.removeAllListeners('dialog');
    const now = await fields();
    check('restore: the import replaced the fields', now.sku === 'MT/XY100' && now.price === '1234.50' && now.maker[0] === 'Sonel' && /futerał/.test(now.kit) && /EVSE/.test(now.acc),
      JSON.stringify(now));
    const bar = await page.evaluate(() => {
      const b = document.querySelector('.mertools-md-restore');
      return b && { text: b.textContent, button: !!b.querySelector('button.mertools-md-restore-button'), role: b.getAttribute('role') };
    });
    check('restore: the bar with the fields filled and its button', !!bar && bar.button && bar.role === 'status' && /SKU/.test(bar.text) && /Akcesoria/.test(bar.text), JSON.stringify(bar));
    // one field changed by hand after the import: left as it is
    await page.evaluate(() => {
      const el = document.querySelector('#ba-group-product-pricing [data-field-key="price"] input');
      el.value = '777.00';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.evaluate(() => { window.__mtN = []; const o = app.showNotice; app.showNotice = function (m) { window.__mtN.push(m); return o.apply(this, arguments); }; });
    await page.click('.mertools-md-restore-button');
    await page.waitForTimeout(800);
    const back = await fields();
    check('restore: SKU, title, Producent (label and link) as before the import', back.sku === was.sku && back.title === was.title
      && JSON.stringify(back.maker) === JSON.stringify(was.maker), JSON.stringify([back, was]));
    check('restore: the file field, its name and its path, as before', JSON.stringify(now.sheet) === JSON.stringify(['karta_XY-100.pdf', 'images/mt-test/karta_XY-100.pdf'])
      && JSON.stringify(back.sheet) === JSON.stringify(['stara.pdf', 'images/stara.pdf']), JSON.stringify([now.sheet, back.sheet]));
    check('restore: the text editor fields as before', back.kit === was.kit && back.acc === was.acc && /Stare wyposażenie/.test(back.kit) && /Moje akcesoria/.test(back.acc),
      JSON.stringify([back.kit, back.acc]));
    check('restore: the price changed since, left as it is', back.price === '777.00', back.price);
    check('restore: the bar gone', !(await page.$('.mertools-md-restore')));
    const restoredSaid = await page.evaluate(() => window.__mtN);
    check('restore: the notice names what came back and what was left', restoredSaid.length === 1 && /SKU/.test(restoredSaid[0].split(/Zmienione|Changed/)[0])
      && /(Cena|Price)/.test(restoredSaid[0].split(/Zmienione|Changed/)[1] || ''),
      JSON.stringify(restoredSaid));
    // a link changed by hand into text + address in its label: the same words as the import's, but not the same field
    page.on('dialog', (d) => d.accept());
    await importFile(page, PRODUCT);
    page.removeAllListeners('dialog');
    await page.evaluate(() => {
      const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="url"]')]
        .find((x) => (x.querySelector('.ba-field-editor-label') || {}).textContent?.replace('*', '').trim() === 'Producent');
      const label = g.querySelector('[data-name="label"]');
      const link = g.querySelector('[data-name="link"]');
      label.value = label.value + ' ' + link.value; // "Sonel index.php?…": the same words joined as before
      link.value = '';
    });
    const moved = (await fields()).maker;
    await page.click('.mertools-md-restore-button');
    await page.waitForTimeout(800);
    check('restore: a link moved by hand from its address into its label, left as it is', /^Sonel \S+/.test(moved[0]) && moved[1] === ''
      && JSON.stringify((await fields()).maker) === JSON.stringify(moved),
      JSON.stringify((await fields()).maker));
    // the × closes the bar, nothing changed
    page.on('dialog', (d) => d.accept());
    await importFile(page, PRODUCT);
    page.removeAllListeners('dialog');
    const filledAgain = await fields();
    await page.click('.mertools-md-restore-close');
    check('×: the bar gone, the fields as the import left them', !(await page.$('.mertools-md-restore')) && JSON.stringify(await fields()) === JSON.stringify(filledAgain));

    // what the document holds goes into the form as Gridbox can show it safely, also after the product is saved and its
    // editor opened again (Gridbox writes the fields into value="…" and <textarea> unescaped): no ", < or > in a
    // field, no javascript: address (also not as &colon;; a button of a fixed label: only its address), a new label
    // without an address not keeping the old address, a link under a heading, an exact amount
    page.on('dialog', (d) => d.accept());
    await page.evaluate(() => {
      const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="url"]')].find((x) => /Instrukcja Metrel/.test(x.textContent));
      g.querySelector('[data-name="label"]').value = 'Sonel';
      g.querySelector('[data-name="link"]').value = '/sonel';
    });
    await page.evaluate(() => document.getElementById('blog-post-editor-fields-options').insertAdjacentHTML('beforeend',
      '<div class="blog-post-editor-options-group mt-fake" data-field-type="field-button" data-id="999"><div class="blog-post-editor-group-element">'
      + '<label class="ba-field-editor-label">MT Przycisk</label></div><input type="text" name="999" data-name="link" value=""></div>'));
    // the notices asked of Gridbox: a notice asked for while others wait may be written over by an older one on the screen
    // (Gridbox's queue of notices), after the imports before this one
    await page.evaluate(() => { window.__mtN = []; });
    await importFile(page, EDGE);
    const edge = await page.evaluate(() => {
      const group = (label) => [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type]')]
        .find((g) => (g.querySelector('.ba-field-editor-label') || {}).textContent?.replace('*', '').trim() === label);
      const v = (label, sel) => group(label).querySelector(sel).value;
      const out = {
        maker: [v('Producent', '[data-name="label"]'), v('Producent', '[data-name="link"]')],
        button: v('MT Przycisk', '[data-name="link"]'),
        price: document.querySelector('#ba-group-product-pricing [data-field-key="price"] input').value,
        notes: v('Uwagi', 'input'),
        allegro: [v('Allegro', '[data-name="label"]'), v('Allegro', '[data-name="link"]')],
        stock: v('Dostępność', 'textarea'),
        delivery: [v('Dostawa', '[data-name="label"]'), v('Dostawa', '[data-name="link"]')],
        manual: [v('Instrukcja Metrel', '[data-name="label"]'), v('Instrukcja Metrel', '[data-name="link"]')],
        service: app.fieldsCKE[group('Serwis').dataset.id].getData(),
        table: (() => { const el = group('Tabela porównawcza').querySelector('input[name]'); return [el.value, el.dataset.value]; })(),
      };
      document.querySelector('.mt-fake').remove();
      return out;
    });
    const edgeSaid = await page.evaluate(() => window.__mtN);
    page.removeAllListeners('dialog');
    check('a link\'s label: no quote, no tag', edge.maker[0] === 'x” onmouseover=”window.__mtx=1” ‹img src=x onerror=window.__mtx=2›' && edge.maker[1] === '/sonel', JSON.stringify(edge.maker));
    check('no javascript&colon; address', edge.delivery[1] === '', JSON.stringify(edge.delivery));
    check('a new label without an address: the old address gone', edge.manual[0] === 'Fluke' && edge.manual[1] === '', JSON.stringify(edge.manual));
    check('a text editor field: the document\'s HTML as text that stays text', !/<img/.test(edge.service) && /(‹|&lsaquo;)img src=x onerror=/.test(edge.service), edge.service);
    check('an empty file field and another site\'s address: left empty, named as not filled', edge.table.join('') === '' && edgeSaid.some((n) => /Tabela porównawcza/.test(n)), JSON.stringify([edge.table, edgeSaid]));
    check('a button of a fixed label: no javascript: address, named as not filled', edge.button === '' && edgeSaid.some((n) => /MT Przycisk/.test(n)), JSON.stringify([edge.button, edgeSaid]));
    check('a price of three decimals kept', edge.price === '0.125', edge.price);
    check('a text field: no tag (Gridbox\'s editor writes it into value="…" as it is)', edge.notes === '‹b›uwaga‹/b› & reszta', edge.notes);
    check('a link under a heading: its text and its address', edge.allegro[0] === 'Sklep' && edge.allegro[1] === 'https://allegro.pl/a_(b)', JSON.stringify(edge.allegro));
    check('a plain textarea: HTML as text', edge.stock === '‹i›x‹/i› dostępny od ręki', edge.stock);
    // saved, and the editor opened again: nothing of the document became an attribute, a tag or ran
    await page.evaluate(() => document.querySelectorAll('#blog-post-editor-fields-options [data-required]').forEach((g) => {
      if (/slideshow|gallery/.test(g.dataset.fieldType)) g.removeAttribute('data-required');
    }));
    await save(page);
    await editor(page, id);
    await page.waitForTimeout(2000);
    const again = await page.evaluate(() => {
      const g = [...document.querySelectorAll('#blog-post-editor-fields-options [data-field-type="textarea"]')].find((x) => /Serwis/.test(x.textContent));
      const frames = [...document.querySelectorAll('iframe')].map((f) => { try { return f.contentWindow.__mtx; } catch (e) { return undefined; } });
      return { attr: document.querySelectorAll('#blog-post-editor-fields-options [onmouseover], #blog-post-editor-fields-options img[onerror]').length,
        ran: [window.__mtx, ...frames].filter((x) => x !== undefined), service: app.fieldsCKE[g.dataset.id].getData(),
        label: document.querySelector('#blog-post-editor-fields-options [data-field-type="url"] [data-name="label"]').value };
    });
    await page.hover('#blog-post-editor-fields-options [data-field-type="url"] [data-name="label"]').catch(() => {});
    check('saved and opened again: no attribute or tag made of the document, nothing ran', again.attr === 0 && again.ran.length === 0
      && !/<img/.test(again.service) && /onmouseover=”/.test(again.label), JSON.stringify(again));

    // ---- a new page
    const pid = await create(page, SRC_PAGE, 'MT import strona ' + Date.now());
    check('a new page made', pid > 0, String(pid));
    made.push(pid);
    await editor(page, pid);
    await importFile(page, PAGE);
    const items = await modules(page);
    check('a new page: h1, text, h2, text', items.join('|') === 'h1:MT Strona testowa|t:Pierwszy akapit strony.|h2:Druga część|t:Tekst drugiej części.', JSON.stringify(items));
    await save(page);
    await editor(page, pid);
    check('saved and opened again: the same', JSON.stringify(await modules(page)) === JSON.stringify(items), JSON.stringify(await modules(page)));
    check('saved: the title of the page', sql(`SELECT title FROM #__gridbox_pages WHERE id = ${pid}`)[0][0] === 'MT Strona testowa');

    // ---- a page with content (not saved)
    await editor(page, SRC_PAGE);
    const before = await modules(page);
    const sections = () => page.evaluate(() => app.editor.document.querySelectorAll('#ba-edit-section .ba-grid-column').length);
    const cols = await sections();
    const steps = await page.evaluate(() => app.history.length);
    const columns = await sections();
    const hasH1 = await page.evaluate(() => !!app.editor.document.querySelector('#ba-edit-section h1'));
    await importFile(page, PAGE);
    const after = await modules(page);
    check('a page with content: what was there untouched, first', JSON.stringify(after.slice(0, before.length)) === JSON.stringify(before), JSON.stringify(after.slice(0, before.length)));
    check('a page with content: the modules after it', after.slice(before.length).join('|') === (hasH1 ? 'h2' : 'h1') + ':MT Strona testowa|t:Pierwszy akapit strony.|h2:Druga część|t:Tekst drugiej części.', JSON.stringify(after.slice(before.length)));
    check('a page with content: in a new section of one column', (await sections()) === cols + 1, String(await sections()));
    const last = await page.evaluate(() => { const c = [...app.editor.document.querySelectorAll('#ba-edit-section .ba-grid-column')].pop(); return c.querySelectorAll('.ba-item').length; });
    check('a page with content: all four in that last column', last === 4, String(last));
    check('one step in the history', await page.evaluate((n) => app.history.length === n + 1 && app.hIndex === n + 1, steps));
    // Undo, once: the page as it was before the import, its new section gone
    await page.click('.ba-action-undo');
    await page.waitForTimeout(1500);
    check('one Undo: the modules as before', JSON.stringify(await modules(page)) === JSON.stringify(before), JSON.stringify(await modules(page)));
    check('one Undo: the sections as before', (await sections()) === columns, String(await sections()));
  } finally {
    made.forEach((id) => {
      sql(`DELETE FROM #__gridbox_page_fields WHERE page_id = ${id}`);
      sql(`DELETE FROM #__gridbox_store_product_data WHERE product_id = ${id}`);
      sql(`DELETE FROM #__gridbox_pages WHERE id = ${id}`);
    });
  }
});
