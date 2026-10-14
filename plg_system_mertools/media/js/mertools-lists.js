/**
 * MerTools — Gridbox lists: the columns window (show, hide, order, add columns; saved per user on the
 * server) and editing prices, sale prices, stock and SKU right in the list (click or Enter; Enter or
 * leaving the field saves, Esc cancels). Config: <script type="application/json" id="mertools-lists-config">.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-lists-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const t = cfg.texts;

  const el = (tag, attrs, ...children) => {
    const n = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v; else n.setAttribute(k, v === true ? '' : v);
    });
    children.flat().forEach((c) => c !== null && c !== undefined && n.append(c instanceof Node ? c : document.createTextNode(String(c))));
    return n;
  };
  const post = async (data) => {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append(cfg.token, '1');
    const res = await fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return res.json();
  };

  // ---------------------------------------------------------------- columns window
  const style = el('style', {}, '.mt-cols-pop{position:absolute;z-index:10000;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.18);'
    + 'padding:12px;width:340px;max-height:70vh;overflow:auto;font-size:13px}.mt-cols-pop h4{margin:0 0 4px;font-size:15px}.mt-cols-pop p{margin:0 0 8px;color:#6b7280;font-size:12px}'
    + '.mt-cols-row{display:flex;align-items:center;gap:6px;padding:4px 2px;border-bottom:1px solid #f3f4f6}.mt-cols-row label{flex:1;display:flex;gap:6px;align-items:center;cursor:pointer}'
    + '.mt-cols-row button{border:1px solid #e5e7eb;background:#fff;border-radius:4px;width:26px;height:24px;cursor:pointer;padding:0}.mt-cols-row button:disabled{opacity:.3;cursor:default}'
    + '.mt-cols-tag{font-size:10px;background:#eef2ff;color:#3730a3;border-radius:3px;padding:0 4px}.mt-cols-btns{display:flex;gap:8px;margin-top:10px;justify-content:flex-end}'
    + '.mt-cols-btns button{border:1px solid #d1d5db;background:#fff;border-radius:5px;padding:5px 12px;cursor:pointer}.mt-cols-btns .mt-primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}');
  document.head.append(style);

  let pop = null;
  const closePop = () => { if (pop) { pop.remove(); pop = null; } };
  const openPop = (button) => {
    if (pop) { closePop(); return; }
    let cols = cfg.columns.map((c) => ({ ...c }));
    const list = el('div', {});
    const render = () => {
      list.replaceChildren(...cols.map((c, i) => {
        const box = el('input', { type: 'checkbox', checked: c.visible, disabled: c.locked });
        box.addEventListener('change', () => { c.visible = box.checked; });
        const up = el('button', { type: 'button', title: t.UP, 'aria-label': t.UP + ': ' + c.label, disabled: i === 0, text: '↑' });
        const down = el('button', { type: 'button', title: t.DOWN, 'aria-label': t.DOWN + ': ' + c.label, disabled: i === cols.length - 1, text: '↓' });
        up.addEventListener('click', () => { [cols[i - 1], cols[i]] = [cols[i], cols[i - 1]]; render(); });
        down.addEventListener('click', () => { [cols[i + 1], cols[i]] = [cols[i], cols[i + 1]]; render(); });
        return el('div', { class: 'mt-cols-row' }, el('label', {}, box, el('span', { text: c.label }), c.added ? el('span', { class: 'mt-cols-tag', text: t.ADDED }) : null), up, down);
      }));
    };
    render();
    const save = el('button', { type: 'button', class: 'mt-primary', text: t.SAVE });
    const reset = el('button', { type: 'button', text: t.RESET });
    const cancel = el('button', { type: 'button', text: t.CANCEL });
    const send = async (order) => {
      save.disabled = reset.disabled = true;
      save.textContent = t.SAVING;
      try {
        const data = await post({ mertools_action: 'list_columns', list: cfg.list, order: JSON.stringify(order), known: JSON.stringify(cfg.builtin) });
        if (!data.success) throw new Error(data.message || t.ERROR);
        window.location.reload();
      } catch (e) {
        alert(t.ERROR + ': ' + e.message);
        save.disabled = reset.disabled = false;
        save.textContent = t.SAVE;
      }
    };
    save.addEventListener('click', () => send(cols.filter((c) => c.visible || c.locked).map((c) => c.key)));
    reset.addEventListener('click', () => send([]));
    cancel.addEventListener('click', closePop);
    pop = el('div', { class: 'mt-cols-pop', role: 'dialog', 'aria-label': t.TITLE }, el('h4', { text: t.TITLE }), el('p', { text: t.HINT }), list,
      el('div', { class: 'mt-cols-btns' }, reset, cancel, save));
    document.body.append(pop);
    const r = button.getBoundingClientRect();
    pop.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - 360)) + 'px';
    pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
  };
  document.addEventListener('click', (e) => {
    const button = e.target.closest && e.target.closest('[data-mt-columns]');
    if (button) { e.preventDefault(); e.stopPropagation(); openPop(button); return; }
    if (pop && !pop.contains(e.target)) closePop();
  }, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePop(); });

  // ---------------------------------------------------------------- editing in the list
  if (!cfg.edit) return;
  const start = (cell) => {
    if (cell.querySelector('input.mt-edit-input')) return;
    const before = cell.innerHTML;
    const raw = cell.getAttribute('data-mt-raw') || '';
    const field = cell.getAttribute('data-mt-edit');
    const input = el('input', { type: 'text', class: 'mt-edit-input', value: raw, inputmode: field === 'sku' ? 'text' : 'decimal',
      placeholder: field === 'sale_price' ? t.EMPTY_SALE : '', 'aria-label': field });
    if (cell.hasAttribute('data-mt-variants')) input.title = t.VARIANTS;
    cell.replaceChildren(input);
    input.focus();
    input.select();
    let done = false;
    const finish = async (keep) => {
      if (done) return;
      done = true;
      const value = input.value.trim();
      if (!keep || value === raw) { cell.innerHTML = before; return; }
      cell.classList.add('mt-saving');
      try {
        const data = await post({ mertools_action: 'list_edit', id: cell.getAttribute('data-mt-id'), field, value });
        if (!data.success) throw new Error(data.message || t.ERROR);
        cell.textContent = data.display;
        cell.setAttribute('data-mt-raw', data.raw);
        cell.classList.add('mt-saved');
        cell.title = t.SAVED;
        setTimeout(() => cell.classList.remove('mt-saved'), 1600);
      } catch (e) {
        cell.innerHTML = before;
        alert(e.message);
      }
      cell.classList.remove('mt-saving');
    };
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      e.stopPropagation();
    });
    input.addEventListener('blur', () => finish(true));
    ['click', 'mousedown', 'dblclick', 'contextmenu'].forEach((type) => input.addEventListener(type, (e) => e.stopPropagation()));
  };
  // capture: Gridbox handles clicks on its rows itself
  document.addEventListener('click', (e) => {
    const cell = e.target.closest && e.target.closest('[data-mt-edit]');
    if (!cell || e.target.closest('input.mt-edit-input')) return;
    e.preventDefault();
    e.stopPropagation();
    start(cell);
  }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-mt-edit]')) { e.preventDefault(); start(e.target); }
  });
  document.querySelectorAll('[data-mt-edit]').forEach((cell) => {
    if (!cell.title) cell.title = cell.hasAttribute('data-mt-variants') ? t.VARIANTS : t.EDIT_HINT;
  });
})();
