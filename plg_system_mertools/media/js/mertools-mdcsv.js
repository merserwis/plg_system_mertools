/**
 * MerTools — many Markdown documents as products at once, through Gridbox's own CSV import (the list of an app's
 * items, Import CSV). Its first step gets a second way of choosing: .md files. Each is read as the import into one
 * product reads it (MerToolsMarkdown.parse of mertools-mdimport.js): "Label: value" lines and sections named as a
 * field fill the store's data (price, SKU, stock…) and the app's fields by their labels, the title, alias and intro
 * text the item's own; what is in no field goes into the field chosen for it, or is left out. The documents become
 * one CSV file of Gridbox's own kind (the first line its column names), given to Gridbox's dialog in place of a
 * chosen file: Gridbox checks it (new, updated, errors), makes its backup when asked and creates the products.
 * Nothing is written here; a value that does not fit its field is left out and named before the import.
 */
(() => {
  'use strict';

  const node = document.getElementById('mertools-mdcsv-config');
  const md = window.MerToolsMarkdown;
  if (!node || !md) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const T = cfg.texts;
  const say = (text, ...values) => values.reduce((s, v) => s.replace(/%[ds]/, v), text);
  const MAX = 2 * 1024 * 1024;

  // a label as it is compared (as the import into one product does)
  const key = (label) => String(label).normalize('NFD').replace(/\p{M}/gu, '').replace(/[łŁ]/g, 'l')
    .replace(/\*/g, '').replace(/:\s*$/, '').replace(/\s+/g, ' ').trim().toLowerCase();
  // the store's data of a product: Gridbox's CSV column, and the names a document may give it
  const CELLS = {
    sku: ['SKU', ['sku', 'kod', 'kod produktu', 'symbol', 'indeks', 'index', 'numer katalogowy', 'nr katalogowy', 'part number', 'mpn', 'product code']],
    price: ['Price', ['cena', 'cena regularna', 'price', 'regular price']],
    sale_price: ['Sale Price', ['cena promocyjna', 'cena po rabacie', 'sale price']],
    stock: ['In Stock', ['stan', 'stan magazynowy', 'w magazynie', 'stock', 'in stock']],
    weight: ['Weight', ['waga', 'weight']],
    min: ['Min. Qty', ['minimalna ilosc', 'min. ilosc', 'min. qty', 'min qty']],
    category: ['Category', ['kategoria', 'category']],
  };
  // the columns of Gridbox's CSV (AppsModel::getCSVAppCells): Gridbox finds a column by its name, the first of that
  // name, so a field of the app named as one of them (a text field "ID") would write into that column — such a field
  // is left out (named as not fitting), never a column of its own
  const GRIDBOX_COLUMNS = ['ID', 'Category', 'Title', 'Product Type', 'Option', 'Variation', 'Extra Options', 'Price', 'Sale Price', 'SKU',
    'In Stock', 'Weight', 'Min. Qty', 'Default Variation', 'Default Extra Option', 'Product File Link', 'Link Expiration', 'Max. Downloads',
    'Image', 'Tags', 'Product Badges', 'Intro Text', 'Alias', 'SEO Title', 'SEO Description', 'Published'].map((c) => c.toLowerCase());
  const ownColumn = (title) => GRIDBOX_COLUMNS.includes(String(title).trim().toLowerCase());
  const CELL_OF = new Map();
  Object.keys(CELLS).forEach((k) => CELLS[k][1].forEach((name) => CELL_OF.set(key(name), k)));
  const oneLine = (s) => String(s).replace(/\s+/g, ' ').trim();
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const htmlOf = (e) => (e.html !== undefined ? e.html : (e.value ? '<p>' + esc(e.value) + '</p>' : ''));
  const textOf = (e) => (e.text !== undefined ? e.text : e.value);
  const firstLink = (html) => {
    const a = new DOMParser().parseFromString(html || '', 'text/html').querySelector('a[href]');
    return a ? { text: oneLine(a.textContent), link: md.fieldUrl(a.getAttribute('href')) } : null;
  };

  /**
   * The value of an entry of the document (a label line {label, value, link} or a section {label, html, text})
   * in Gridbox's CSV for a field, or null: it does not fit the field.
   */
  const fieldValue = (field, e) => {
    const text = oneLine(textOf(e));
    const items = (field.params && Array.isArray(field.params.items) ? field.params.items : []).map((i) => String(i.title));
    const pick = (want) => items.find((t) => key(t) === key(want));
    switch (field.field_type) {
      case 'text':
        return text ? md.safeText(text) : null;
      case 'number':
      case 'price':
      case 'range': {
        const v = md.amount(text);
        return v === '' ? null : v;
      }
      case 'date':
      case 'event-date':
        return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null;
      case 'textarea':
        if (field.params && field.params.texteditor) return htmlOf(e) ? md.safeHtml(htmlOf(e)) : null;
        return textOf(e) ? md.safeText(String(textOf(e)).trim()) : null;
      case 'url': {
        const a = e.html ? firstLink(e.html) : null;
        // as the import into one product: an address only from a link (a bare https://… or /… is one), never plain text
        const link = e.link || (a ? a.link : '');
        const label = a ? a.text : (e.link ? text : '');
        // Gridbox reads "label; link": no "; " may be in the label
        return link ? md.safeText(label).replace(/;\s/g, ', ') + '; ' + link : null;
      }
      case 'select':
      case 'radio':
        return pick(text) || null;
      case 'checkbox': {
        const hit = String(textOf(e)).split(/[,;\n]/).map(pick).filter(Boolean);
        return hit.length ? hit.join(' / ') : null;
      }
      default:
        return null; // files, galleries, videos, maps: not from a text
    }
  };

  /**
   * One document as a row of Gridbox's CSV: {row: column => value, filled: [labels], failed: [labels], rest: html}.
   * fields: Gridbox's fields of the app (getAppCells), restField: the title of the field the rest goes into.
   */
  const toRow = (text, name, fields, restField, categories, published) => {
    const byKey = new Map();
    Object.keys(fields).forEach((k) => { const f = fields[k]; if (f && f.title && !byKey.has(key(f.title))) byKey.set(key(f.title), f); });
    const r = md.parse(text, { field: (label) => byKey.has(key(label)) || CELL_OF.has(key(label)) });
    const row = { ID: '', Category: '', Title: md.safeText(r.title || name.replace(/\.(md|markdown|txt)$/i, '')), 'Product Type': '', Published: published ? 'TRUE' : 'FALSE' };
    if (r.alias) row.Alias = r.alias;
    if (r.intro) row['Intro Text'] = md.safeText(r.intro);
    const filled = [];
    const failed = [];
    [...r.extra, ...r.sections].forEach((e) => {
      const cell = CELL_OF.get(key(e.label));
      const field = cell ? null : byKey.get(key(e.label));
      let v = null;
      if (cell === 'category') v = categories.find((c) => key(c) === key(oneLine(textOf(e)))) || null;
      else if (cell === 'price' || cell === 'sale_price' || cell === 'weight') v = md.amount(oneLine(textOf(e))) || null;
      else if (cell === 'stock' || cell === 'min') v = /^\d+$/.test(oneLine(textOf(e))) ? oneLine(textOf(e)) : null;
      else if (cell === 'sku') v = oneLine(textOf(e)) ? md.safeText(oneLine(textOf(e))) : null;
      else if (field) v = ownColumn(field.title) ? null : fieldValue(field, e);
      else return;
      const column = cell ? CELLS[cell][0] : field.title;
      if (v === null || (column in row && !(cell === 'category' && row.Category === ''))) { failed.push(e.label); return; }
      row[column] = v;
      filled.push(cell ? CELLS[cell][0] : field.title);
    });
    // what is in no field: into the chosen field (after what a section of its name put there), or left out
    const rest = r.blocks.map((b) => (b.type === 'headline' ? '<h' + b.level + '>' + esc(b.text) + '</h' + b.level + '>' : b.html)).join('');
    let left = false;
    if (rest) {
      const field = restField ? byKey.get(key(restField)) : null;
      if (field && !ownColumn(field.title)) {
        const v = field.params && field.params.texteditor ? md.safeHtml(rest) : md.safeText(new DOMParser().parseFromString(rest, 'text/html').body.textContent.trim());
        row[field.title] = (row[field.title] || '') + v;
        if (!filled.includes(field.title)) filled.push(field.title);
      } else {
        left = true;
      }
    }
    return { row, filled, failed, left };
  };

  const csvCell = (v) => '"' + String(v).replace(/"/g, '""') + '"';
  const toCsv = (rows) => {
    const columns = ['ID', 'Category', 'Title', 'Product Type'];
    rows.forEach((r) => Object.keys(r).forEach((c) => { if (!columns.includes(c)) columns.push(c); }));
    return [columns, ...rows.map((r) => columns.map((c) => (c in r ? r[c] : '')))].map((line) => line.map(csvCell).join(',')).join('\r\n') + '\r\n';
  };

  const api = { toRow, toCsv, fieldValue, ownColumn };
  window.MerToolsMdCsv = api;

  // ---------------------------------------------------------------- Gridbox's dialog

  const app = window.app;
  // as the import into one product: UTF-8, else Windows-1250
  const read = md.readFile;

  const attach = (wrap) => {
    if (wrap.querySelector('.mt-mdcsv')) return;
    const box = document.createElement('div');
    box.className = 'mt-mdcsv';
    box.style.cssText = 'margin:18px 0 6px;padding-top:14px;border-top:1px solid rgba(128,128,128,.25)';
    box.innerHTML = '<div class="ba-options-group-element"><input class="mt-mdcsv-trigger" readonly type="text" style="cursor:pointer"><i class="zmdi zmdi-file-text"></i>'
      + '<input type="file" accept=".md,.markdown,.txt,text/markdown" multiple style="display:none"></div>'
      + '<div class="ba-options-group-element mt-mdcsv-options" style="display:none;gap:8px;flex-wrap:wrap">'
      + '<select class="mt-mdcsv-category"></select><select class="mt-mdcsv-rest"></select></div>'
      + '<div class="ba-checkbox-parent mt-mdcsv-options" style="display:none"><label class="ba-checkbox ba-hide-checkbox"><input type="checkbox" class="mt-mdcsv-published"><span></span></label><span></span></div>'
      + '<div class="mt-mdcsv-report" role="status" style="max-height:180px;overflow:auto;font-size:13px;line-height:1.5"></div>';
    const trigger = box.querySelector('.mt-mdcsv-trigger');
    const input = box.querySelector('input[type="file"]');
    const category = box.querySelector('.mt-mdcsv-category');
    const restSel = box.querySelector('.mt-mdcsv-rest');
    const published = box.querySelector('.mt-mdcsv-published');
    const report = box.querySelector('.mt-mdcsv-report');
    trigger.placeholder = T.choose;
    trigger.setAttribute('aria-label', T.choose);
    box.querySelector('.ba-checkbox-parent > span:last-child').textContent = T.published;
    const opt = (sel, value, text) => { const o = document.createElement('option'); o.value = value; o.textContent = text; sel.append(o); };
    opt(category, '', T.noCategory);
    (cfg.categories || []).forEach((c) => opt(category, c, c));
    let files = [];
    let fields = null;
    let run = 0;
    const build = async () => {
      // no documents (none chosen, or a CSV file chosen after them): Gridbox's own choice is left as it is
      if (!files.length) return;
      // while the documents are read again Gridbox's Next takes no file: none of an earlier choice (Publish, category…)
      const mine = ++run;
      app.csv.file = null;
      document.querySelectorAll('.apply-csv-import').forEach((b) => b.classList.remove('active-button'));
      report.textContent = '';
      fields = fields || await window.makeFetchRequest(app.csv.url + 'getAppCells', { id: app.csv.app_id, pks: [] }).then((j) => (j && j.fields) || {});
      if (mine !== run) return;
      if (!restSel.options.length) {
        opt(restSel, '', T.restNone);
        Object.keys(fields).map((k) => fields[k]).filter((f) => f && f.field_type === 'textarea' && !ownColumn(f.title)).forEach((f) => opt(restSel, f.title, say(T.restInto, f.title)));
      }
      const rows = [];
      const lines = [];
      for (const file of files) {
        if (file.size > MAX) { lines.push([file.name, say(T.large, Math.round(MAX / 1048576)), true]); continue; }
        const text = await read(file);
        if (mine !== run) return; // a later choice builds its own
        const one = toRow(text, file.name, fields, restSel.value, cfg.categories || [], published.checked);
        if (!one.row.Category && category.value) one.row.Category = category.value;
        rows.push(one.row);
        lines.push([file.name, [one.filled.length ? say(T.filled, one.filled.join(', ')) : T.nothing,
          one.failed.length ? say(T.failed, one.failed.join(', ')) : '', one.left ? T.left : ''].filter(Boolean).join(' '), !!(one.failed.length || one.left)]);
      }
      lines.forEach(([name, text, warn]) => {
        const p = document.createElement('div');
        const b = document.createElement('b');
        b.textContent = name + ': ';
        p.append(b, document.createTextNode(text));
        if (warn) p.style.color = '#e08a00';
        report.append(p);
      });
      if (!rows.length || mine !== run) return;
      // the file Gridbox's dialog takes: one of its own kind
      app.csv.file = new File([toCsv(rows)], 'markdown-' + rows.length + '.csv', { type: 'text/csv' });
      const type = wrap.querySelector('.csv-file-type');
      if (type) { type.value = 'gridbox'; type.dispatchEvent(new Event('change', { bubbles: true })); }
      const own = wrap.querySelector('.trigger-csv-import');
      if (own) own.value = say(T.chosen, rows.length);
      document.querySelectorAll('.apply-csv-import').forEach((b) => b.classList.add('active-button'));
    };
    trigger.addEventListener('click', () => input.click());
    trigger.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', () => {
      files = [...(input.files || [])];
      input.value = '';
      // no documents any more: the file made of the earlier ones goes (one chosen in Gridbox's own way stays)
      if (!files.length && app.csv.file && /^markdown-\d+\.csv$/.test(app.csv.file.name)) {
        run++;
        app.csv.file = null;
        document.querySelectorAll('.apply-csv-import').forEach((b) => b.classList.remove('active-button'));
      }
      trigger.value = files.length ? say(T.files, files.length) : '';
      box.querySelectorAll('.mt-mdcsv-options').forEach((el) => { el.style.display = files.length ? 'flex' : 'none'; });
      build().catch(() => { report.textContent = T.fail; });
    });
    [category, restSel, published].forEach((el) => el.addEventListener('change', () => build().catch(() => { report.textContent = T.fail; })));
    const own = wrap.querySelector('.csv-import-options input[type="file"], input[type="file"][accept=".csv"]');
    // a CSV file chosen after the documents: Gridbox's own choice again, and a reading of documents still going on dropped
    if (own) {
      own.addEventListener('change', () => {
        run++;
        files = [];
        trigger.value = '';
        report.textContent = '';
        box.querySelectorAll('.mt-mdcsv-options').forEach((el) => { el.style.display = 'none'; });
      });
    }
    wrap.append(box);
  };

  // Gridbox puts the options of its first step into the dialog each time it opens it
  const look = () => {
    const wrap = document.querySelector('.csv-import-options.csv-content-wrapper');
    if (wrap && wrap.querySelector('.csv-file-type') && app && app.csv && app.csv.url) attach(wrap);
  };
  new MutationObserver(look).observe(document.body, { childList: true, subtree: true });
  look();
})();
