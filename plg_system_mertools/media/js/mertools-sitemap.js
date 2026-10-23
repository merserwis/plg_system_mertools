/**
 * MerTools — the panel of the sitemap (tab "Sitemap").
 *  - The state: the tool, Gridbox's sitemap and its file, who answers /sitemap.xml, the last build.
 *  - The maps, what is left out (per reason, with a search) and "Why is this address not in the map?".
 *  - Rebuild now, Take over from Gridbox / Undo, Compare with Gridbox's sitemap.
 * Requests: index.php?mertools_action=sm_… (POST, form token); config in data-mt-sitemap.
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

  const short = (url) => {
    try { const u = new URL(url); return u.pathname + u.search; } catch (e) { return url; }
  };
  const link = (url, text) => el('a', { href: url, target: '_blank', rel: 'noopener', title: url, text: text || short(url) });
  const when = (unix) => (unix ? new Date(unix * 1000).toLocaleString() : '');
  const kb = (bytes) => (bytes / 1024).toFixed(1) + ' KB';

  const style = () => {
    if (document.getElementById('mt-sm-style')) return;
    document.head.append(el('style', { id: 'mt-sm-style' }, '.mt-sm dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:0 0 12px;font-size:13px}'
      + '.mt-sm dt{color:#6b7280;font-weight:400}.mt-sm dd{margin:0;font-weight:600}.mt-sm-ok{color:#15803d}.mt-sm-warn{color:#b45309}.mt-sm-bad{color:#b91c1c}'
      + '.mt-sm-tools{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 14px}.mt-sm-note{font-size:13px;margin:6px 0}'
      + '.mt-sm-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}.mt-sm-table th{text-align:left;font-size:12px;color:#6b7280;border-bottom:2px solid #e5e7eb;padding:6px 8px}'
      + '.mt-sm-table td{border-bottom:1px solid #f0f0f0;padding:5px 8px;vertical-align:top;word-break:break-all}.mt-sm-num{text-align:right;white-space:nowrap}'
      + '.mt-sm details{margin:4px 0}.mt-sm summary{cursor:pointer}.mt-sm-list{max-height:320px;overflow:auto;font-size:12px;margin:6px 0 10px}'
      + '.mt-sm-list div{padding:2px 0;border-bottom:1px solid #f5f5f5;word-break:break-all}.mt-sm-small{font-size:11px;color:#6b7280}'
      + '.mt-sm-why{display:flex;gap:6px;max-width:640px}.mt-sm-why input{flex:1;min-width:0}.mt-sm h4{font-size:14px;margin:16px 0 6px}'));
  };

  document.querySelectorAll('[data-mt-sitemap]').forEach((box) => {
    let cfg;
    try { cfg = JSON.parse(box.getAttribute('data-mt-sitemap')); } catch (e) { return; }
    style();
    const t = cfg.texts;
    const reason = (r) => t['R_' + r] || r;
    box.classList.add('mt-sm');
    const state = el('div', {}, el('p', { class: 'mt-sm-note', text: t.LOADING }));
    const note = el('div', { class: 'mt-sm-note', role: 'status', 'aria-live': 'polite' });
    const compareBox = el('div', {});

    const button = (text, cls, action, confirmText) => {
      const b = el('button', { type: 'button', class: 'btn btn-sm ' + cls, text });
      b.addEventListener('click', async () => {
        if (confirmText && !window.confirm(confirmText)) return;
        box.querySelectorAll('.mt-sm-tools button').forEach((x) => { x.disabled = true; });
        note.textContent = t.LOADING;
        try {
          const data = await request(cfg, action);
          const done = [t.DONE];
          if (action === 'sm_takeover') {
            if (!data.removed) done.push(t.REMOVED_NOT);
            done.push(t['CHECK_' + String(data.check).toUpperCase()] || '');
          }
          if (action === 'sm_undo') done.push(data.restored ? t.RESTORED : t.RESTORED_NOT);
          note.textContent = done.filter(Boolean).join(' ');
          render(data);
        } catch (e) {
          note.textContent = t.ERROR + ': ' + e.message;
          box.querySelectorAll('.mt-sm-tools button').forEach((x) => { x.disabled = false; });
        }
      });
      return b;
    };

    const compare = async () => {
      compareBox.replaceChildren(el('p', { class: 'mt-sm-note', text: t.LOADING }));
      try {
        const d = await request(cfg, 'sm_compare');
        const rows = d.list.map((r) => el('div', {}, link(r.loc), ' — ', r.reason ? reason(r.reason) : el('span', { class: 'mt-sm-bad', text: t.NO_REASON })));
        compareBox.replaceChildren(el('h4', { text: t.COMPARE }),
          el('p', { class: 'mt-sm-note' }, t.CMP_TOTAL.replace('%d', d.total), ' ', t.CMP_MISSING.replace('%d', d.missing), ' ',
            el('span', { class: d.unexplained ? 'mt-sm-bad' : 'mt-sm-ok', text: t.CMP_UNEXPLAINED.replace('%d', d.unexplained) }), ' ', t.CMP_ADDED.replace('%d', d.added)),
          rows.length ? el('div', { class: 'mt-sm-list' }, rows) : el('p', { class: 'mt-sm-note', text: t.CMP_NONE }));
      } catch (e) {
        compareBox.replaceChildren(el('p', { class: 'mt-sm-note mt-sm-bad', text: t.ERROR + ': ' + e.message }));
      }
    };

    const excludedList = (items) => {
      if (!items.length) return el('p', { class: 'mt-sm-note', text: t.NO_EXCLUDED });
      const by = {};
      items.forEach((x) => { (by[x[1]] = by[x[1]] || []).push(x); });
      return el('div', {}, Object.keys(by).map((r) => {
        const q = el('input', { type: 'search', class: 'form-control form-control-sm', placeholder: t.SEARCH, 'aria-label': t.SEARCH + ': ' + reason(r) });
        const list = el('div', { class: 'mt-sm-list' });
        const fill = () => {
          const s = q.value.trim().toLowerCase();
          const hits = by[r].filter((x) => !s || x[0].toLowerCase().includes(s) || String(x[3]).toLowerCase().includes(s));
          list.replaceChildren(...hits.slice(0, 300).map((x) => el('div', {}, link(x[0]), ' ', el('span', { class: 'mt-sm-small', text: x[3] + ' (' + x[2] + ')' }))),
            hits.length > 300 ? el('div', { class: 'mt-sm-small', text: t.SHOWN.replace('%1$d', 300).replace('%2$d', hits.length) }) : '');
        };
        q.addEventListener('input', fill);
        const d = el('details', {}, el('summary', {}, reason(r) + ': ', el('strong', { text: by[r].length })), q, list);
        d.addEventListener('toggle', () => { if (d.open && !list.childElementCount) fill(); }, { once: true });
        return d;
      }));
    };

    const why = () => {
      const input = el('input', { type: 'text', class: 'form-control form-control-sm', placeholder: t.WHY_HINT, 'aria-label': t.WHY });
      const go = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: t.WHY_BUTTON });
      const out = el('div', { class: 'mt-sm-list', role: 'status', 'aria-live': 'polite' });
      const ask = async () => {
        if (!input.value.trim()) return;
        out.replaceChildren(el('div', { text: t.LOADING }));
        try {
          const d = await request(cfg, 'sm_why', { q: input.value });
          out.replaceChildren(...(d.found.length ? d.found.map((f) => el('div', {}, link(f.loc), ' — ', f.map
            ? el('span', { class: 'mt-sm-ok', text: t.IN_MAP.replace('%s', f.map) })
            : el('span', { class: 'mt-sm-warn', text: reason(f.reason) + ' (' + f.type + (f.title ? ': ' + f.title : '') + ')' })))
            : [el('div', { text: t.NOT_FOUND })]));
        } catch (e) {
          out.replaceChildren(el('div', { class: 'mt-sm-bad', text: t.ERROR + ': ' + e.message }));
        }
      };
      go.addEventListener('click', ask);
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); ask(); } });
      return el('div', {}, el('h4', { text: t.WHY }), el('div', { class: 'mt-sm-why' }, input, go), out);
    };
    const whyBox = why();

    const render = (s) => {
      const yes = (on, a, b, good = true) => el('span', { class: on === good ? 'mt-sm-ok' : 'mt-sm-warn', text: on ? a : b });
      const answers = { gridbox: [t.BY_GRIDBOX, 'mt-sm-warn'], mertools: [t.BY_MERTOOLS, 'mt-sm-ok'], none: [t.BY_NONE, 'mt-sm-bad'] }[s.answers];
      const warnings = [];
      if (!s.enabled) warnings.push(t.W_OFF);
      if (s.enabled && s.static) warnings.push(t.W_STATIC);
      if (s.mass) warnings.push(t.W_MASS);
      if (!s.line) warnings.push(t.W_LINE);
      const tools = el('div', { class: 'mt-sm-tools' },
        s.enabled ? button(t.REBUILD, 'btn-primary', 'sm_rebuild') : null,
        s.enabled && (s.static || s.gridbox) ? button(t.TAKEOVER, 'btn-warning', 'sm_takeover', t.TAKEOVER_CONFIRM) : null,
        s.copy && !s.static && !s.gridbox ? button(t.UNDO, 'btn-outline-secondary', 'sm_undo', t.UNDO_CONFIRM) : null,
        s.copy ? (() => { const b = el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', text: t.COMPARE }); b.addEventListener('click', compare); return b; })() : null);
      const files = s.files.length ? el('table', { class: 'mt-sm-table' },
        el('thead', {}, el('tr', {}, el('th', { text: t.MAP }), el('th', { class: 'mt-sm-num', text: t.ADDRESSES }), el('th', { text: t.NEWEST }), el('th', { class: 'mt-sm-num', text: t.SIZE }))),
        el('tbody', {}, s.files.map((f) => el('tr', {}, el('td', {}, link(f.url, f.name)), el('td', { class: 'mt-sm-num', text: f.count }),
          el('td', { text: when(f.newest) }), el('td', { class: 'mt-sm-num', text: kb(f.size) }))))) : null;
      state.replaceChildren(...[
        el('dl', {},
          el('dt', { text: t.TOOL }), el('dd', {}, yes(s.enabled, t.ON, t.OFF)),
          el('dt', { text: t.GRIDBOX }), el('dd', {}, yes(s.gridbox, t.ON, t.OFF, false)),
          el('dt', { text: t.STATIC }), el('dd', {}, yes(s.static, t.PRESENT, t.ABSENT, false)),
          el('dt', { text: t.ANSWERS }), el('dd', {}, el('span', { class: answers[1], text: answers[0] })),
          el('dt', { text: t.LINE }), el('dd', {}, yes(s.line, t.PRESENT, t.ABSENT)),
          el('dt', { text: t.BUILT }), el('dd', {}, s.built ? [when(s.built), ' · ', t.SECONDS.replace('%s', s.seconds), ' · ', t.URLS.replace('%d', s.urls),
            s.stale ? el('span', { class: 'mt-sm-small', text: ' · ' + t.STALE }) : ''] : t.NEVER)),
        warnings.map((w) => el('div', { class: 'alert alert-warning mt-sm-note', text: w })),
        tools, note,
        files ? [el('h4', { text: t.MAPS }), files] : null,
        s.built ? [el('h4', { text: t.EXCLUDED }), excludedList(s.excluded)] : null,
        whyBox, compareBox].flat(2).filter(Boolean));
    };

    box.append(state);
    request(cfg, 'sm_status').then(render).catch((e) => { state.replaceChildren(el('p', { class: 'mt-sm-note mt-sm-bad', text: t.ERROR + ': ' + e.message })); });
  });
})();
