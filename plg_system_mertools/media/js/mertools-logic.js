/**
 * MerTools — the conditional logic of the checkout form in the Gridbox editor: in the settings of the
 * checkout form (Gridbox's window of its fields), under the list of fields, a group "Conditional logic"
 * opens a window of rules, as in Balbooa Forms: on the left the rules (switched off, copied, deleted),
 * on the right the conditions of the one chosen (a field, how it is compared, a value — the options of a
 * field with options to choose from) and its actions (show or hide a field).
 *
 * The rules are kept in the settings of the checkout form element (mertools_logic) and saved with the
 * page by Gridbox (a change is a step of its undo history). Only fields saved already have the number
 * the page knows them by: a field added since the last save comes into the lists after the next one.
 * The e-mail and the country of the order (Gridbox's own, needed by the order) are not hidden.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-logic-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const app = window.app;
  const dialog = document.getElementById('customer-info-settings-dialog');
  const list = dialog && dialog.querySelector('#customer-info-general-options .items-list');
  if (!app || !list) return;
  const T = cfg.texts || {};
  const KEY = 'mertools_logic';
  const OPS = [['equal', T.equal], ['not-equal', T.not_equal], ['filled', T.filled], ['empty', T.not_filled], ['greater', T.greater],
    ['less', T.less], ['contains', T.contains], ['not-contains', T.not_contains]];
  const NO_VALUE = ['filled', 'empty'];
  const CHOICES = ['dropdown', 'radio', 'checkbox'];

  const style = document.createElement('style');
  style.textContent = '#customer-info-settings-dialog:not([data-edit="checkout-form"]) .mt-logic-group{display:none}'
    + '.mt-logic-group .ba-settings-item{cursor:pointer}'
    + '#mertools-logic{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;font:14px/1.4 Roboto,Arial,sans-serif;color:#333}'
    + '#mertools-logic[hidden]{display:none}'
    + '#mertools-logic .mt-logic-modal{background:#fff;width:min(960px,calc(100vw - 40px));height:min(640px,calc(100vh - 40px));display:flex;flex-direction:column;border-radius:6px;box-shadow:0 15px 40px rgba(0,0,0,.25);overflow:hidden}'
    + '#mertools-logic .mt-logic-head{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;background:#1da6f4;color:#fff;font-weight:500;font-size:16px}'
    + '#mertools-logic button{font:inherit;cursor:pointer;border:0;background:none;color:inherit;padding:0}'
    + '#mertools-logic .mt-logic-head button{font-size:22px;line-height:1;width:32px;height:32px}'
    + '#mertools-logic .mt-logic-body{flex:1;display:flex;min-height:0}'
    + '#mertools-logic .mt-logic-side{width:280px;border-right:1px solid #f3f3f3;display:flex;flex-direction:column;padding:16px}'
    + '#mertools-logic .mt-logic-list{list-style:none;margin:0;padding:0;flex:1;overflow:auto}'
    + '#mertools-logic .mt-logic-list li{display:flex;align-items:center;border-radius:4px;margin-bottom:4px}'
    + '#mertools-logic .mt-logic-list li.active{background:#f0f8fe}'
    + '#mertools-logic .mt-logic-list li.off .mt-logic-pick{opacity:.45;text-decoration:line-through}'
    + '#mertools-logic .mt-logic-pick{flex:1;text-align:left;padding:10px 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '#mertools-logic .mt-logic-list li.active .mt-logic-pick{color:#1da6f4;font-weight:500}'
    + '#mertools-logic .mt-logic-icon{width:30px;height:30px;font-size:18px;color:#757575;border-radius:50%}'
    + '#mertools-logic .mt-logic-icon:hover,#mertools-logic .mt-logic-icon:focus-visible{color:#1da6f4;background:#f0f8fe}'
    + '#mertools-logic .mt-logic-add{margin-top:12px;background:#1da6f4;color:#fff;border-radius:4px;padding:12px;font-weight:500}'
    + '#mertools-logic .mt-logic-main{flex:1;overflow:auto;padding:16px 24px}'
    + '#mertools-logic h3{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;color:#1da6f4;margin:22px 0 10px}'
    + '#mertools-logic label.mt-logic-name{display:flex;flex-direction:column;gap:6px;color:#757575;font-size:12px}'
    + '#mertools-logic input,#mertools-logic select{font:inherit;color:#333;height:40px;box-sizing:border-box;border:1px solid #e3e3e3;border-radius:4px;padding:0 10px;background:#fff;min-width:0}'
    + '#mertools-logic input:focus,#mertools-logic select:focus{border-color:#1da6f4;outline:0}'
    + '#mertools-logic .mt-logic-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}'
    + '#mertools-logic .mt-logic-row>select,#mertools-logic .mt-logic-row>input{flex:1}'
    + '#mertools-logic .mt-logic-row>.mt-logic-op,#mertools-logic .mt-logic-row>.mt-logic-action{flex:0 0 170px}'
    + '#mertools-logic .mt-logic-more{color:#1da6f4;font-weight:500;padding:6px 0}'
    + '#mertools-logic .mt-logic-note{margin:0;padding:12px 24px;border-top:1px solid #f3f3f3;color:#757575;font-size:12px}'
    + '#mertools-logic .mt-logic-warn{color:#e65100}'
    + '#mertools-logic .mt-logic-none{color:#757575;padding:10px 8px}';
  document.head.append(style);

  const el = (tag, attrs = {}, ...children) => {
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === 'text') n.textContent = v;
      else if (k === 'on') Object.entries(v).forEach(([type, fn]) => n.addEventListener(type, fn));
      else if (v !== false && v !== null && v !== undefined) n.setAttribute(k, v === true ? '' : v);
    });
    children.forEach((c) => c && n.append(c));

    return n;
  };
  const icon = (name, label, fn) => el('button', { type: 'button', class: 'mt-logic-icon', title: label, 'aria-label': label, on: { click: fn } },
    el('i', { class: 'zmdi zmdi-' + name, 'aria-hidden': 'true' }));
  // the text of a name or an option written as HTML (read by a parser that runs nothing of it)
  const plain = (html) => new DOMParser().parseFromString(String(html), 'text/html').body.textContent.replace(/\s+/g, ' ').trim();

  // ---------------------------------------------------------------- the checkout form being edited

  let item = null;
  const rulesOf = (it) => (it && Array.isArray(it[KEY]) ? it[KEY] : []);
  const fields = () => (Array.isArray(item && item.items) ? item.items : []).filter((f) => Number(f.id) > 0).map((f) => {
    const s = f.settings || {};
    const name = f.type === 'acceptance' ? T.acceptance : plain(f.title || s.placeholder || '');

    return { id: Number(f.id), type: f.type, name: name || '#' + f.id, options: Array.isArray(s.options) ? s.options.map(plain) : [] };
  });
  const unsaved = () => (Array.isArray(item && item.items) ? item.items : []).some((f) => !(Number(f.id) > 0));

  // a change: a step of Gridbox's undo history (the page has changes to save); typing, once it stops
  let timer = 0;
  let pending = false;
  const step = () => {
    clearTimeout(timer);
    pending = false;
    if (typeof app.addHistory === 'function') app.addHistory();
  };
  // the element as the editor has it now: an undo or redo puts copies of the elements in place of
  // them (and closes Gridbox's windows): the window of the rules closes too, what it holds is no more
  let itemId = '';
  const live = () => (itemId && app.editor && app.editor.app && app.editor.app.items ? app.editor.app.items[itemId] : item);
  const changed = (later) => {
    if (live() !== item) { close(true); return; }
    item[KEY] = rules;
    count();
    clearTimeout(timer);
    pending = true;
    if (later) timer = setTimeout(step, 500); else step();
  };

  // ---------------------------------------------------------------- the group in Gridbox's window

  const counter = el('span', { class: 'mt-logic-count' });
  const opener = el('div', { class: 'ba-settings-item', role: 'button', tabindex: '0', on: {
    click: () => open(),
    keydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } },
  } }, counter, el('span', { class: 'category-list-fields-wrapper' }, el('i', { class: 'zmdi zmdi-edit mt-logic-open', 'aria-hidden': 'true' })));
  const group = el('div', { class: 'ba-settings-group mt-logic-group' },
    el('div', { class: 'settings-group-title' }, el('i', { class: 'zmdi zmdi-compass' }), el('span', { text: T.title })), opener);
  list.after(group);
  function count() {
    const it = app.edit && app.edit.type === 'checkout-form' ? app.edit : null;
    const all = rulesOf(it);
    counter.textContent = all.length ? String(T.count).replace('%d', all.filter((r) => !r.off).length).replace('%s', all.length) : T.empty;
  }
  new MutationObserver(() => {
    count();
    if (!win.hidden && live() !== item) close(true);
  }).observe(dialog, { attributes: true, attributeFilter: ['class', 'data-edit'] });
  count();

  // ---------------------------------------------------------------- the window of the rules

  let rules = [];
  let current = 0;
  const side = el('ul', { class: 'mt-logic-list', 'aria-label': T.rules });
  const main = el('div', { class: 'mt-logic-main' });
  const note = el('p', { class: 'mt-logic-note' });
  // closed by the user: a change still being typed is a step of the history now; closed after an undo
  // (stale): nothing of it is noted, the step would take the place of what can be redone
  const close = (stale) => {
    win.hidden = true;
    if (stale === true) { clearTimeout(timer); pending = false; } else if (pending) step();
    opener.focus();
  };
  // a click in it is not one outside Gridbox's window, which would close that (as Gridbox's own windows do)
  const win = el('div', { id: 'mertools-logic', hidden: true, on: { mousedown: (e) => { e.stopPropagation(); if (e.target === win) close(); } } },
    el('div', { class: 'mt-logic-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'mt-logic-title' },
      el('div', { class: 'mt-logic-head' }, el('span', { id: 'mt-logic-title', text: T.title }),
        el('button', { type: 'button', class: 'mt-logic-close', title: T.close, 'aria-label': T.close, on: { click: close } }, el('i', { class: 'zmdi zmdi-close', 'aria-hidden': 'true' }))),
      el('div', { class: 'mt-logic-body' },
        el('div', { class: 'mt-logic-side' }, side, el('button', { type: 'button', class: 'mt-logic-add', text: T.add, on: { click: () => {
          rules.push({ title: T.new + ' ' + (rules.length + 1), off: false, when: [{ field: '', op: 'equal', value: '' }], then: [{ action: 'show', field: '' }] });
          current = rules.length - 1;
          changed(false);
          render();
          const name = main.querySelector('.mt-logic-name input');
          if (name) { name.focus(); name.select(); }
        } } })),
        main),
      note));
  // Escape closes it (before Gridbox's own handlers; the focus may be on the page after a list is made anew)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !win.hidden) { e.stopPropagation(); e.preventDefault(); close(); }
  }, true);
  document.body.append(win);

  function open() {
    item = app.edit && app.edit.type === 'checkout-form' ? app.edit : null;
    if (!item) return;
    itemId = (app.editor && app.editor.app && app.editor.app.edit) || '';
    if (!itemId || live() !== item) {
      // the id of the element by its settings (the editor keeps it as app.edit there)
      const items = app.editor && app.editor.app && app.editor.app.items;
      itemId = items ? Object.keys(items).find((id) => items[id] === item) || '' : '';
    }
    rules = rulesOf(item);
    current = Math.min(current, Math.max(rules.length - 1, 0));
    win.hidden = false;
    render();
    win.querySelector('.mt-logic-close').focus();
  }

  const fieldSelect = (value, pool, onChange) => {
    const select = el('select', { 'aria-label': T.field, on: { change: () => onChange(select.value) } }, el('option', { value: '', text: T.choose }));
    pool.forEach((f) => select.append(el('option', { value: String(f.id), text: f.name })));
    if (value !== '' && !pool.some((f) => String(f.id) === String(value))) {
      select.append(el('option', { value: String(value), text: T.missing + ' #' + value }));
    }
    select.value = String(value);

    return select;
  };

  function render() {
    const all = fields();
    const sources = all.filter((f) => f.type !== 'headline');
    const targets = all.filter((f) => f.type !== 'email' && f.type !== 'country');
    side.replaceChildren();
    if (!rules.length) side.append(el('li', { class: 'mt-logic-none', text: T.empty }));
    rules.forEach((rule, i) => {
      side.append(el('li', { class: (i === current ? 'active' : '') + (rule.off ? ' off' : '') },
        el('button', { type: 'button', class: 'mt-logic-pick', 'aria-current': i === current ? 'true' : false, text: rule.title || T.new, on: { click: () => { current = i; render(); } } }),
        icon(rule.off ? 'eye-off' : 'eye', rule.off ? T.on : T.off, () => { rule.off = !rule.off; changed(false); render(); }),
        icon('copy', T.copy, () => {
          rules.splice(i + 1, 0, JSON.parse(JSON.stringify(rule)));
          current = i + 1;
          changed(false);
          render();
        }),
        icon('delete', T.delete, () => {
          rules.splice(i, 1);
          current = Math.max(0, Math.min(current, rules.length - 1));
          changed(false);
          render();
        })));
    });
    note.replaceChildren(el('span', { text: T.help }));
    if (unsaved()) note.append(' ', el('span', { class: 'mt-logic-warn', text: T.unsaved }));
    main.replaceChildren();
    const rule = rules[current];
    if (!rule) return;
    rule.when = Array.isArray(rule.when) ? rule.when : [];
    rule.then = Array.isArray(rule.then) ? rule.then : [];
    const name = el('input', { type: 'text', value: rule.title || '', on: { input: () => {
      rule.title = name.value;
      side.querySelector('li.active .mt-logic-pick').textContent = name.value || T.new;
      changed(true);
    } } });
    main.append(el('label', { class: 'mt-logic-name' }, el('span', { text: T.name }), name));

    main.append(el('h3', { text: T.when }));
    rule.when.forEach((cond, i) => {
      const source = sources.find((f) => String(f.id) === String(cond.field));
      const op = el('select', { class: 'mt-logic-op', 'aria-label': T.when, on: { change: () => { cond.op = op.value; changed(false); render(); } } });
      OPS.forEach(([value, label]) => op.append(el('option', { value, text: label })));
      op.value = OPS.some(([value]) => value === cond.op) ? cond.op : 'equal';
      let value;
      if (source && CHOICES.includes(source.type) && source.options.length) {
        value = el('select', { 'aria-label': T.value, on: { change: () => { cond.value = value.value; changed(false); } } }, el('option', { value: '', text: T.choose }));
        source.options.forEach((o) => value.append(el('option', { value: o, text: o })));
        if (cond.value && !source.options.includes(cond.value)) value.append(el('option', { value: cond.value, text: cond.value }));
        value.value = cond.value || '';
      } else {
        value = el('input', { type: 'text', placeholder: T.value, 'aria-label': T.value, value: cond.value || '', on: { input: () => { cond.value = value.value; changed(true); } } });
      }
      if (NO_VALUE.includes(op.value)) value.style.visibility = 'hidden';
      main.append(el('div', { class: 'mt-logic-row mt-logic-when' },
        fieldSelect(cond.field, sources, (v) => { cond.field = v === '' ? '' : Number(v); cond.value = ''; changed(false); render(); }),
        op, value,
        icon('close', T.remove, () => { rule.when.splice(i, 1); changed(false); render(); })));
    });
    main.append(el('button', { type: 'button', class: 'mt-logic-more mt-logic-add-when', text: '+ ' + T.add_when, on: { click: () => {
      rule.when.push({ field: '', op: 'equal', value: '' });
      changed(false);
      render();
    } } }));

    main.append(el('h3', { text: T.then }));
    rule.then.forEach((act, i) => {
      const action = el('select', { class: 'mt-logic-action', 'aria-label': T.then, on: { change: () => { act.action = action.value; changed(false); } } },
        el('option', { value: 'show', text: T.show }), el('option', { value: 'hide', text: T.hide }));
      action.value = act.action === 'hide' ? 'hide' : 'show';
      main.append(el('div', { class: 'mt-logic-row mt-logic-then' }, action,
        fieldSelect(act.field, targets, (v) => { act.field = v === '' ? '' : Number(v); changed(false); }),
        icon('close', T.remove, () => { rule.then.splice(i, 1); changed(false); render(); })));
    });
    main.append(el('button', { type: 'button', class: 'mt-logic-more mt-logic-add-then', text: '+ ' + T.add_then, on: { click: () => {
      rule.then.push({ action: 'show', field: '' });
      changed(false);
      render();
    } } }));
  }
})();
