/**
 * MerTools — edit history of Gridbox pages.
 *  - Gridbox lists: a click on "✎ date · user" under a title opens a window with the whole history of
 *    that page (config: <script type="application/json" id="mertools-history-config">).
 *  - The tab "Edit history" of the plugin: the list of all changes with filters (config: data attribute).
 * Entries come from index.php?mertools_action=history_list (POST, form token).
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

  const request = async (cfg, params) => {
    const body = new FormData();
    body.append(cfg.token, '1');
    Object.entries(params).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) body.append(k, v); });
    const res = await fetch(cfg.url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || cfg.texts.ERROR);
    return data;
  };

  const actionLabel = (cfg, row) => cfg.texts['A_' + String(row.action).toUpperCase()] || row.action;

  /** The table of entries; withPage = a column with the page (the panel lists every page). */
  const table = (cfg, rows, withPage) => {
    const t = cfg.texts;
    const head = el('tr', {}, el('th', { text: t.WHEN }), el('th', { text: t.WHO }), withPage ? el('th', { text: t.PAGE }) : null,
      el('th', { text: t.WHAT }), el('th', { text: t.CHANGES }));
    const body = rows.map((r) => el('tr', {},
      el('td', { class: 'mt-hist-when', text: r.when }),
      el('td', { text: r.who }),
      withPage ? el('td', {}, el('a', { href: r.edit, target: '_blank', rel: 'noopener', text: r.title || ('#' + r.page) }), el('div', { class: 'mt-hist-id', text: 'ID ' + r.page })) : null,
      el('td', {}, el('span', { class: 'mt-hist-act mt-hist-act-' + r.action, text: actionLabel(cfg, r) }),
        el('div', { class: 'mt-hist-client', text: r.client === 'admin' ? t.CLIENT_ADMIN : t.CLIENT_SITE })),
      el('td', {}, r.changes.length ? el('ul', { class: 'mt-hist-changes' }, r.changes.map((c) => el('li', { text: c }))) : '—')));
    return el('table', { class: 'mt-hist-table table table-sm' }, el('thead', {}, head), el('tbody', {}, body));
  };

  const style = () => {
    if (document.getElementById('mt-hist-style')) return;
    document.head.append(el('style', { id: 'mt-hist-style' }, '.mt-hist-table{width:100%;border-collapse:collapse;font-size:13px}'
      + '.mt-hist-table th{text-align:left;font-size:12px;color:#6b7280;border-bottom:2px solid #e5e7eb;padding:6px 8px;white-space:nowrap}'
      + '.mt-hist-table td{border-bottom:1px solid #f0f0f0;padding:6px 8px;vertical-align:top}.mt-hist-when{white-space:nowrap}'
      + '.mt-hist-changes{margin:0;padding-left:16px}.mt-hist-changes li{margin:0 0 2px;word-break:break-word}'
      + '.mt-hist-id,.mt-hist-client{font-size:11px;color:#9ca3af}.mt-hist-act{font-weight:600}.mt-hist-act-deleted,.mt-hist-act-trashed{color:#b91c1c}'
      + '.mt-hist-act-created,.mt-hist-act-duplicated,.mt-hist-act-restored,.mt-hist-act-published{color:#15803d}'
      + '.mt-hist-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:10px}.mt-hist-filters label{display:flex;flex-direction:column;font-size:12px;gap:2px}'
      + '.mt-hist-more{margin-top:8px}.mt-hist-total{font-size:12px;color:#6b7280;margin:4px 0}'));
  };

  // ---------------------------------------------------------------- window of one page (Gridbox lists)
  const openWindow = async (cfg, page, title) => {
    style();
    const t = cfg.texts;
    const body = el('div', { class: 'mt-hist-body' }, t.LOADING);
    const close = el('button', { type: 'button', 'aria-label': t.CLOSE, title: t.CLOSE, text: '×' });
    const dlg = el('dialog', { class: 'mt-hist-dlg' }, el('div', { class: 'mt-hist-head' }, el('h3', { text: t.TITLE + ': ' + title }), close), body);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('close', () => dlg.remove());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    document.body.append(dlg);
    dlg.showModal();
    try {
      const data = await request(cfg, { page, limit: 200 });
      body.replaceChildren(data.rows.length ? table(cfg, data.rows, false) : t.NONE);
    } catch (e) {
      body.replaceChildren(t.ERROR + ': ' + e.message);
    }
  };

  const listConfig = document.getElementById('mertools-history-config');
  if (listConfig) {
    let cfg = null;
    try { cfg = JSON.parse(listConfig.textContent); } catch (e) { cfg = null; }
    if (cfg) {
      // capture: Gridbox handles clicks in its lists itself
      document.addEventListener('click', (e) => {
        const link = e.target.closest && e.target.closest('[data-mt-history]');
        if (!link) return;
        e.preventDefault();
        e.stopPropagation();
        const cell = link.closest('td');
        const titleLink = cell && cell.querySelector('a:not([data-mt-history])');
        openWindow(cfg, link.getAttribute('data-mt-history'), titleLink ? titleLink.textContent.trim() : '#' + link.getAttribute('data-mt-history'));
      }, true);
    }
  }

  // ---------------------------------------------------------------- panel (tab "Edit history")
  document.querySelectorAll('[data-mt-history-panel]').forEach((box) => {
    let cfg;
    try { cfg = JSON.parse(box.getAttribute('data-mt-history-panel')); } catch (e) { return; }
    style();
    const t = cfg.texts;
    const q = el('input', { type: 'search', class: 'form-control form-control-sm', placeholder: t.SEARCH, 'aria-label': t.SEARCH });
    const user = el('select', { class: 'form-select form-select-sm', 'aria-label': t.WHO }, el('option', { value: '', text: t.ALL_USERS }));
    const action = el('select', { class: 'form-select form-select-sm', 'aria-label': t.WHAT }, el('option', { value: '', text: t.ALL_ACTIONS }),
      Object.keys(t).filter((k) => k.startsWith('A_')).map((k) => el('option', { value: k.slice(2).toLowerCase(), text: t[k] })));
    const from = el('input', { type: 'date', class: 'form-control form-control-sm' });
    const to = el('input', { type: 'date', class: 'form-control form-control-sm' });
    const go = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: t.FILTER });
    const total = el('div', { class: 'mt-hist-total', role: 'status', 'aria-live': 'polite' });
    const list = el('div', {});
    const more = el('button', { type: 'button', class: 'btn btn-sm btn-secondary mt-hist-more', text: t.MORE, hidden: true });
    box.replaceChildren(el('div', { class: 'mt-hist-filters' }, el('label', {}, t.SEARCH, q), el('label', {}, t.WHO, user), el('label', {}, t.WHAT, action),
      el('label', {}, t.FROM, from), el('label', {}, t.TO, to), go), total, list, more);

    let rows = [];
    let usersLoaded = false;
    const load = async (append) => {
      go.disabled = more.disabled = true;
      if (!append) { rows = []; list.replaceChildren(t.LOADING); }
      try {
        const data = await request(cfg, { q: q.value.trim(), user: user.value, filter_action: action.value, from: from.value, to: to.value,
          offset: rows.length, limit: 50, with_users: usersLoaded ? 0 : 1 });
        if (data.users && !usersLoaded) {
          usersLoaded = true;
          Object.entries(data.users).forEach(([id, name]) => user.append(el('option', { value: id, text: name })));
        }
        rows = rows.concat(data.rows);
        list.replaceChildren(rows.length ? table(cfg, rows, true) : t.NONE);
        total.textContent = t.TOTAL.replace('%d', String(data.total));
        more.hidden = rows.length >= data.total;
      } catch (e) {
        list.replaceChildren(t.ERROR + ': ' + e.message);
      }
      go.disabled = more.disabled = false;
    };
    go.addEventListener('click', () => load(false));
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); load(false); } });
    more.addEventListener('click', () => load(true));
    load(false);
  });
})();
