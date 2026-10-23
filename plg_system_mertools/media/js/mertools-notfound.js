/**
 * MerTools — the log of missing addresses (tab "Page not found (404)").
 *  - Each logged address: visits, the last and the first one, where the last visitor came from, what
 *    happens to it now, and a field for a redirect of its own (with a suggestion of a similar page).
 *  - The old aliases of renamed pages and categories, followed with a 301 to where they are now.
 * Requests: index.php?mertools_action=nf_… (POST, form token); config in data-mt-notfound.
 */
(() => {
  'use strict';

  const el = (tag, attrs, ...children) => {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = v;
      else node.setAttribute(k, v === true ? '' : v);
    });
    children.flat().forEach((c) => c !== null && c !== undefined && node.append(c instanceof Node ? c : document.createTextNode(String(c))));
    return node;
  };

  const request = async (cfg, action, params) => {
    const body = new FormData();
    body.append(cfg.token, '1');
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) body.append(k, v); });
    const res = await fetch(cfg.url + '?mertools_action=' + action, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || cfg.texts.ERROR);
    return data;
  };

  /** A site address shown short: its path (the whole address as the title). */
  const short = (url) => {
    try { const u = new URL(url, location.href); return u.host === location.host ? u.pathname + u.search : u.host + u.pathname + u.search; } catch (e) { return url; }
  };
  const link = (url, text) => el('a', { href: url, target: '_blank', rel: 'noopener', title: url, text: text || short(url) });

  const style = () => {
    if (document.getElementById('mt-nf-style')) return;
    document.head.append(el('style', { id: 'mt-nf-style' }, '.mt-nf-table{width:100%;border-collapse:collapse;font-size:13px}'
      + '.mt-nf-table th{text-align:left;font-size:12px;color:#6b7280;border-bottom:2px solid #e5e7eb;padding:6px 8px;white-space:nowrap}'
      + '.mt-nf-table td{border-bottom:1px solid #f0f0f0;padding:6px 8px;vertical-align:top}.mt-nf-path{word-break:break-all;font-weight:600}'
      + '.mt-nf-small{font-size:11px;color:#6b7280;word-break:break-all}.mt-nf-num{text-align:right;white-space:nowrap}.mt-nf-when{white-space:nowrap}'
      + '.mt-nf-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:10px}.mt-nf-tools label{display:flex;flex-direction:column;font-size:12px;gap:2px}'
      + '.mt-nf-edit{display:flex;gap:4px;min-width:220px}.mt-nf-edit input{min-width:0;flex:1}.mt-nf-suggest{margin-top:4px;font-size:12px}'
      + '.mt-nf-res{font-size:12px;font-weight:600}.mt-nf-res-moved,.mt-nf-res-manual,.mt-nf-res-category{color:#15803d}.mt-nf-res-gone{color:#4b5563}.mt-nf-res-fallback{color:#b45309}.mt-nf-res-none{color:#b91c1c}'
      + '.mt-nf-total{font-size:12px;color:#6b7280;margin:4px 0}.mt-nf-more{margin-top:8px}.mt-nf-moved{margin-top:18px}.mt-nf-moved summary{cursor:pointer;font-weight:600}'
      + '.mt-nf-del{border:0;background:none;color:#b91c1c;font-size:18px;line-height:1;cursor:pointer;padding:0 4px}.mt-nf-hint{font-size:12px;color:#6b7280;margin:0 0 8px}'));
  };

  document.querySelectorAll('[data-mt-notfound]').forEach((box) => {
    let cfg;
    try { cfg = JSON.parse(box.getAttribute('data-mt-notfound')); } catch (e) { return; }
    style();
    const t = cfg.texts;

    // ---------------------------------------------------------------- the log
    const q = el('input', { type: 'search', class: 'form-control form-control-sm', placeholder: t.SEARCH, 'aria-label': t.SEARCH });
    const show = el('select', { class: 'form-select form-select-sm', 'aria-label': t.SHOW },
      el('option', { value: 'all', text: t.SHOW_ALL }), el('option', { value: 'open', text: t.SHOW_OPEN }), el('option', { value: 'redirected', text: t.SHOW_REDIRECTED }));
    const order = el('select', { class: 'form-select form-select-sm', 'aria-label': t.ORDER },
      el('option', { value: 'last', text: t.ORDER_LAST }), el('option', { value: 'hits', text: t.ORDER_HITS }));
    const refresh = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: t.REFRESH });
    const clear = el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: t.CLEAR });
    const total = el('div', { class: 'mt-nf-total', role: 'status', 'aria-live': 'polite' });
    const list = el('div', {});
    const more = el('button', { type: 'button', class: 'btn btn-sm btn-secondary mt-nf-more', text: t.MORE, hidden: true });

    const resultCell = (r) => {
      const label = el('div', { class: 'mt-nf-res mt-nf-res-' + r.result, text: t['R_' + r.result.toUpperCase()] || r.result });
      // a redirect typed after the last visit: that visit still got the old answer
      const next = r.target && r.result !== 'manual' ? el('div', { class: 'mt-nf-small mt-nf-res-manual', text: t.R_TYPED_NEXT }) : null;
      return el('td', {}, label, r.sent ? el('div', { class: 'mt-nf-small' }, '→ ', link(r.sent)) : null, next);
    };

    const editCell = (r) => {
      const input = el('input', { type: 'text', class: 'form-control form-control-sm', value: r.target, placeholder: '/…', 'aria-label': t.REDIRECT + ': ' + r.path });
      const save = el('button', { type: 'button', class: 'btn btn-sm btn-success', text: t.SAVE });
      const note = el('div', { class: 'mt-nf-small', role: 'status', 'aria-live': 'polite' });
      const store = async (value) => {
        save.disabled = true;
        try {
          await request(cfg, 'nf_target', { id: r.id, target: value });
          r.target = value;
          note.textContent = value ? t.SAVED : t.REMOVE;
          const cell = note.closest('tr') && note.closest('tr').children[3];
          if (cell) cell.replaceWith(resultCell(r));
        } catch (e) {
          note.textContent = t.ERROR + ': ' + e.message;
        }
        save.disabled = false;
      };
      save.addEventListener('click', () => store(input.value.trim()));
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); store(input.value.trim()); } });
      let suggestion = null;
      if (r.suggest) {
        const use = el('a', { href: '#', text: t.USE });
        use.addEventListener('click', (e) => { e.preventDefault(); input.value = short(r.suggest); store(input.value); });
        suggestion = el('div', { class: 'mt-nf-suggest' }, t.SUGGEST + ': ', link(r.suggest), ' · ', use);
      }
      return el('td', {}, el('div', { class: 'mt-nf-edit' }, input, save), suggestion, note);
    };

    let rows = [];
    const draw = () => {
      if (!rows.length) { list.replaceChildren(t.NONE); return; }
      const head = el('tr', {}, el('th', { text: t.ADDRESS }), el('th', { class: 'mt-nf-num', text: t.VISITS }), el('th', { text: t.LAST }),
        el('th', { text: t.NOW }), el('th', { text: t.REDIRECT }), el('th', {}));
      const body = rows.map((r) => {
        const del = el('button', { type: 'button', class: 'mt-nf-del', title: t.DELETE, 'aria-label': t.DELETE + ': ' + r.path, text: '×' });
        const tr = el('tr', {},
          el('td', {}, el('div', { class: 'mt-nf-path' }, link(r.url, r.path)), r.referer ? el('div', { class: 'mt-nf-small' }, t.FROM + ': ', link(r.referer)) : null),
          el('td', { class: 'mt-nf-num', text: String(r.hits) }),
          el('td', { class: 'mt-nf-when' }, r.last, el('div', { class: 'mt-nf-small', text: t.FIRST + ': ' + r.first })),
          resultCell(r), editCell(r), el('td', {}, del));
        del.addEventListener('click', async () => {
          if (!window.confirm(t.DELETE_CONFIRM)) return;
          try {
            await request(cfg, 'nf_delete', { id: r.id });
            rows = rows.filter((x) => x !== r);
            tr.remove();
          } catch (e) { window.alert(t.ERROR + ': ' + e.message); }
        });
        return tr;
      });
      list.replaceChildren(el('div', { class: 'table-responsive' }, el('table', { class: 'mt-nf-table table table-sm' }, el('thead', {}, head), el('tbody', {}, body))));
    };

    const load = async (append) => {
      refresh.disabled = more.disabled = true;
      if (!append) { rows = []; list.replaceChildren(t.LOADING); }
      try {
        const data = await request(cfg, 'nf_list', { q: q.value.trim(), show: show.value, order: order.value, offset: rows.length, limit: 50 });
        rows = rows.concat(data.rows);
        draw();
        total.textContent = t.TOTAL.replace('%d', String(data.total));
        more.hidden = rows.length >= data.total;
      } catch (e) {
        list.replaceChildren(t.ERROR + ': ' + e.message);
      }
      refresh.disabled = more.disabled = false;
    };
    refresh.addEventListener('click', () => load(false));
    show.addEventListener('change', () => load(false));
    order.addEventListener('change', () => load(false));
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); load(false); } });
    more.addEventListener('click', () => load(true));
    clear.addEventListener('click', async () => {
      if (!window.confirm(t.CLEAR_CONFIRM)) return;
      try {
        const data = await request(cfg, 'nf_clear', {});
        total.textContent = t.CLEARED.replace('%d', String(data.removed));
        load(false);
      } catch (e) { window.alert(t.ERROR + ': ' + e.message); }
    });

    // ---------------------------------------------------------------- old aliases (loaded when opened)
    const movedList = el('div', {}, t.LOADING);
    const movedTotal = el('div', { class: 'mt-nf-total', role: 'status', 'aria-live': 'polite' });
    const movedMore = el('button', { type: 'button', class: 'btn btn-sm btn-secondary mt-nf-more', text: t.MORE, hidden: true });
    const moved = el('details', { class: 'mt-nf-moved' }, el('summary', { text: t.MOVED_TITLE }), el('p', { class: 'mt-nf-hint mt-2', text: t.MOVED_HINT }),
      movedTotal, movedList, movedMore);
    let movedRows = [];
    const drawMoved = () => {
      if (!movedRows.length) { movedList.replaceChildren(t.MOVED_NONE); return; }
      const head = el('tr', {}, el('th', { text: t.MOVED_OLD }), el('th', { text: t.MOVED_PAGE }), el('th', { text: t.MOVED_AT }),
        el('th', { class: 'mt-nf-when', text: t.MOVED_WHEN }), el('th', { class: 'mt-nf-num', text: t.VISITS }), el('th', {}));
      const body = movedRows.map((r) => {
        const del = el('button', { type: 'button', class: 'mt-nf-del', title: t.DELETE, 'aria-label': t.DELETE + ': ' + r.alias, text: '×' });
        const title = r.title || ('#' + r.item);
        const tr = el('tr', {},
          el('td', { class: 'mt-nf-path', text: r.alias }),
          el('td', {}, r.edit ? el('a', { href: r.edit, target: '_blank', rel: 'noopener', text: title, title: t.EDIT }) : title,
            r.kind === 'c' ? el('div', { class: 'mt-nf-small', text: t.MOVED_CATEGORY }) : null),
          el('td', {}, r.url ? link(r.url) : el('span', { class: 'mt-nf-small', text: t.MOVED_HIDDEN })),
          el('td', { class: 'mt-nf-when', text: r.changed }),
          el('td', { class: 'mt-nf-num', text: String(r.hits) }),
          el('td', {}, del));
        del.addEventListener('click', async () => {
          if (!window.confirm(t.DELETE_CONFIRM)) return;
          try {
            await request(cfg, 'nf_moved_delete', { id: r.id });
            movedRows = movedRows.filter((x) => x !== r);
            tr.remove();
          } catch (e) { window.alert(t.ERROR + ': ' + e.message); }
        });
        return tr;
      });
      movedList.replaceChildren(el('div', { class: 'table-responsive' }, el('table', { class: 'mt-nf-table table table-sm' }, el('thead', {}, head), el('tbody', {}, body))));
    };
    const loadMoved = async (append) => {
      movedMore.disabled = true;
      if (!append) movedRows = [];
      try {
        const data = await request(cfg, 'nf_moved', { offset: movedRows.length, limit: 50 });
        movedRows = movedRows.concat(data.rows);
        drawMoved();
        movedTotal.textContent = t.TOTAL.replace('%d', String(data.total));
        movedMore.hidden = movedRows.length >= data.total;
      } catch (e) {
        movedList.replaceChildren(t.ERROR + ': ' + e.message);
      }
      movedMore.disabled = false;
    };
    let movedLoaded = false;
    moved.addEventListener('toggle', () => { if (moved.open && !movedLoaded) { movedLoaded = true; loadMoved(false); } });
    movedMore.addEventListener('click', () => loadMoved(true));

    box.replaceChildren(el('p', { class: 'mt-nf-hint', text: t.HINT }),
      el('div', { class: 'mt-nf-tools' }, el('label', {}, t.SEARCH, q), el('label', {}, t.SHOW, show), el('label', {}, t.ORDER, order), refresh, clear),
      total, list, more, moved);
    load(false);
  });
})();
