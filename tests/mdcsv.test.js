// Many Markdown documents as one CSV file of Gridbox's own kind (media/js/mertools-mdcsv.js): the store's data,
// the app's fields by their labels and types, the rest into a chosen field, nothing of a document that could run
// in the page. Runs in Chromium (DOMParser), no Joomla.  Run: node tests/mdcsv.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT || '/home/administrator2/.npm/_npx/705bc6b22212b352/node_modules/playwright-core');

const JS = (name) => fs.readFileSync(path.join(__dirname, '../plg_system_mertools/media/js/mertools-' + name + '.js'), 'utf8');

let failed = 0;
const check = (label, got, expected) => {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  if (!ok) failed++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label.padEnd(70)}${ok ? '' : `\n   got=${JSON.stringify(got)}\n   exp=${JSON.stringify(expected)}`}`);
};

// the fields of the products app of the test copy, as Gridbox's getAppCells gives them
const FIELDS = {
  'item-1': { id: 11, title: 'Cechy i funkcje', field_type: 'textarea', params: { texteditor: true } },
  'item-2': { id: 12, title: 'Producent', field_type: 'url', params: {} },
  'item-3': { id: 13, title: 'Dostępność', field_type: 'textarea', params: { texteditor: false } },
  'item-4': { id: 14, title: 'Promocja', field_type: 'date', params: {} },
  'item-5': { id: 15, title: 'Kolor', field_type: 'select', params: { items: [{ title: 'Czerwony' }, { title: 'Niebieski' }] } },
  'item-6': { id: 16, title: 'Zastosowanie', field_type: 'checkbox', params: { items: [{ title: 'Dom' }, { title: 'Przemysł' }, { title: 'Laboratorium' }] } },
  'item-7': { id: 17, title: 'Karta katalogowa', field_type: 'file', params: { types: 'pdf, doc' } },
  'item-8': { id: 18, title: 'Uwagi', field_type: 'text', params: {} },
};

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setContent('<!DOCTYPE html><meta charset="utf-8"><body><script type="application/json" id="mertools-mdcsv-config">{"texts":{},"categories":[]}</script></body>');
    await page.addScriptTag({ content: JS('mdimport') });
    await page.addScriptTag({ content: JS('mdcsv') });
    const row = (text, rest = '', cats = ['Mierniki', 'Kamery'], pub = false) => page.evaluate(([t, f, r, c, p]) => window.MerToolsMdCsv.toRow(t, 'plik.md', f, r, c, p), [text, FIELDS, rest, cats, pub]);
    const csv = (rows) => page.evaluate((r) => window.MerToolsMdCsv.toCsv(r), rows);

    const doc = [
      'Tytuł: Miernik MPI-540',
      'Alias: miernik-mpi-540',
      'Opis: Miernik "instalacji" <3>',
      'SKU: WMPLMPI540',
      'Cena: 6 950,00 zł',
      'Stan: 4',
      'Kategoria: mierniki',
      'Producent: [Sonel](/sonel)',
      'Promocja: 2026-12-31',
      'Kolor: niebieski',
      'Zastosowanie: dom, laboratorium, kosmos',
      'Karta katalogowa: /images/karty/x_1.pdf',
      'Uwagi: zwykła uwaga',
      '',
      '## Cechy i funkcje',
      '',
      '- pomiar **RCD**',
      '- <script>alert(1)</script>',
      '',
      '## Dostępność',
      '',
      'Od ręki <b>teraz</b>',
      '',
      '## Opis szczegółowy',
      '',
      'Tekst spoza pól.',
    ].join('\n');
    let r = await row(doc);
    check('the item\'s own: title, alias, intro (no " < >)', [r.row.Title, r.row.Alias, r.row['Intro Text']], ['Miernik MPI-540', 'miernik-mpi-540', 'Miernik ”instalacji” ‹3›']);
    check('the store\'s data by its Polish names', [r.row.SKU, r.row.Price, r.row['In Stock']], ['WMPLMPI540', '6950.00', '4']);
    check('a category named in the document, as the app has it', r.row.Category, 'Mierniki');
    check('unpublished unless asked', r.row.Published, 'FALSE');
    check('a link field as Gridbox\'s "label; link"', r.row.Producent, 'Sonel; /sonel');
    check('a date, a select\'s option as written in the app', [r.row.Promocja, r.row.Kolor], ['2026-12-31', 'Niebieski']);
    check('checkboxes: the known ones, as " / "', r.row.Zastosowanie, 'Dom / Laboratorium');
    check('a field with an editor: the section\'s HTML, raw HTML of the document as text', r.row['Cechy i funkcje'],
      '<ul><li>pomiar <strong>RCD</strong></li><li>‹script›alert(1)‹/script›</li></ul>');
    check('a field without an editor: its text', r.row['Dostępność'], 'Od ręki ‹b›teraz‹/b›');
    check('a file field: the path from the site\'s root', [r.row['Karta katalogowa'], r.failed], ['images/karty/x_1.pdf', []]);
    let other = await row('Karta katalogowa: https://inny.pl/x.pdf\nUwagi: a\n\n## Tekst\n\nb');
    check('a file field: another site\'s address, named as not fitting', [other.row['Karta katalogowa'], other.failed], [undefined, ['Karta katalogowa']]);
    other = await row('# Kamera\n\nUwagi: a\n\n## Karta katalogowa\n\nimages/x.exe');
    check('a file field: a type the field does not take', [other.row['Karta katalogowa'], other.failed], [undefined, ['Karta katalogowa']]);
    check('the rest left out without a field chosen, and said', [r.left, Object.values(r.row).some((v) => String(v).includes('spoza'))], [true, false]);
    r = await row(doc, 'Cechy i funkcje', [], true);
    check('the rest into the chosen field, after its own section', r.row['Cechy i funkcje'],
      '<ul><li>pomiar <strong>RCD</strong></li><li>‹script›alert(1)‹/script›</li></ul><h2>Opis szczegółowy</h2><p>Tekst spoza pól.</p>');
    check('a category the app has not: left to Gridbox (Uncategorised), named', [r.row.Category, r.failed.includes('Kategoria')], ['', true]);
    check('published when asked', r.row.Published, 'TRUE');
    r = await row('# Kamera X\n\nCena: 100 zł + 23% VAT\nUwagi: a; b\n\nTekst.');
    check('the # heading as the title; an amount that is not one left out', [r.row.Title, 'Price' in r.row, r.failed], ['Kamera X', false, ['Cena']]);
    r = await row('Producent: [Zły](javascript:alert(1))\nSKU: X');
    check('an address that may not be a link: not one', ['Producent' in r.row, r.failed], [false, ['Producent']]);
    // a field of the app named as one of Gridbox's columns would write into that column (ID: another product)
    const own = await page.evaluate(([f]) => {
      const fields = Object.assign({}, f, { 'item-9': { id: 19, title: 'ID', field_type: 'textarea', params: {} }, 'item-10': { id: 20, title: 'Tags', field_type: 'text', params: {} } });
      return window.MerToolsMdCsv.toRow('# Nowy produkt\n\n123\n\n## Tags\n\nx', 'n.md', fields, 'ID', [], false);
    }, [FIELDS]);
    check('a field named as Gridbox\'s column: never written (ID stays empty, the rest left out, Tags named)', [own.row.ID, own.left, 'Tags' in own.row, own.failed], ['', true, false, ['Tags']]);
    r = await row('Tekst bez pól i tytułu.');
    check('no title: the file\'s name', r.row.Title, 'plik');

    // a document saved in Windows-1250 (Notepad on a Polish Windows): read as the import into one product reads it
    const cp = await page.evaluate(async () => {
      const bytes = new Uint8Array([0x23, 0x20, 0x5a, 0x61, 0xbf, 0xf3, 0xb3, 0xe6]); // "# Zażółć" in Windows-1250
      return window.MerToolsMarkdown.readFile(new File([bytes], 'cp.md'));
    });
    check('a Windows-1250 document read right', cp, '# Zażółć');

    // Gridbox's dialog: documents chosen, then (before they are read) a CSV file of Gridbox's own way: that file stays
    const race = await browser.newPage();
    await race.setContent('<!DOCTYPE html><meta charset="utf-8"><body><script type="application/json" id="mertools-mdcsv-config">'
      + '{"texts":{"choose":"","files":"%d","chosen":"%d","noCategory":"","restNone":"","restInto":"%s","published":"","filled":"%s","nothing":"","failed":"%s","left":"","large":"","fail":"x"},"categories":[]}</script>'
      + '<a class="apply-csv-import"></a><div class="csv-import-options csv-content-wrapper"><select class="csv-file-type"><option value="match"></option><option value="gridbox"></option></select>'
      + '<input class="trigger-csv-import"><input type="file" accept=".csv"></div></body>');
    await race.evaluate(() => {
      window.app = { csv: { url: 'x?', app_id: 1, file: null } };
      window.makeFetchRequest = () => Promise.resolve({ fields: {} });
    });
    await race.addScriptTag({ content: JS('mdimport') });
    // the reading of a document, finished when the test says so (the moment the person may choose a CSV meanwhile)
    await race.evaluate(() => {
      const real = window.MerToolsMarkdown.readFile;
      window.MerToolsMarkdown.readFile = (f) => new Promise((resolve) => { window.answer = () => resolve(real(f)); });
    });
    await race.addScriptTag({ content: JS('mdcsv') });
    await race.waitForSelector('.mt-mdcsv input[type="file"]', { state: 'attached' });
    await race.setInputFiles('.mt-mdcsv input[type="file"]', { name: 'a.md', mimeType: 'text/markdown', buffer: Buffer.from('# A\n\nSKU: X') });
    await race.waitForFunction(() => typeof window.answer === 'function');
    await race.evaluate(() => {
      // the person's own CSV, as Gridbox's handler takes it
      const own = document.querySelector('input[accept=".csv"]');
      own.addEventListener('change', () => { app.csv.file = { name: 'new.csv' }; });
      own.dispatchEvent(new Event('change'));
      window.answer();
    });
    await race.waitForTimeout(500);
    check('a CSV chosen while documents are read: that CSV stays', await race.evaluate(() => app.csv.file && app.csv.file.name), 'new.csv');
    check('…and the options of the documents hidden', await race.evaluate(() => [...document.querySelectorAll('.mt-mdcsv-options')].every((e) => e.style.display === 'none')), true);
    await race.evaluate(() => { const s = document.querySelector('.mt-mdcsv-published'); s.checked = true; s.dispatchEvent(new Event('change')); });
    await race.waitForTimeout(300);
    check('an option of the documents changed then: the CSV still stays', await race.evaluate(() => app.csv.file && app.csv.file.name), 'new.csv');
    await race.close();

    const out = await csv([{ ID: '', Category: '', Title: 'A "B"', 'Product Type': '', SKU: 'X' }, { ID: '', Category: 'Mierniki', Title: 'C', 'Product Type': '', Uwagi: 'a,\nb' }]);
    check('CSV: the required columns first, every column of any row, quotes doubled, lines kept in a value', out,
      '"ID","Category","Title","Product Type","SKU","Uwagi"\r\n"","","A ""B""","","X",""\r\n"","Mierniki","C","","","a,\nb"\r\n');
  } finally {
    await browser.close();
  }
  console.log(failed ? `\n${failed} FAILED` : '\nALL PASS');
  process.exit(failed ? 1 : 0);
})();
