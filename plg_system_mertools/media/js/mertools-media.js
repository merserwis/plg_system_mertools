/**
 * MerTools — Gridbox's media manager: files dragged from the computer and dropped on it are uploaded
 * to the folder open in it. The dropped files go to Gridbox's own upload field (the one its button
 * "Upload" opens), so Gridbox checks the types, shows the count and reloads the folder as for that
 * button. Dragging the rows to a folder (Gridbox's move) is not a drag of files and is left alone.
 * With Size on upload on, its uploads also say the page it is open in (mt_ref).
 * Config: <script type="application/json" id="mertools-media-config">.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-media-config');
  const form = document.querySelector('#file-upload-form form');
  if (!node || !form || !window.mediaManager) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const t = cfg.texts || {};
  // Size on upload: each upload says the page of the editor or panel the media manager is open in (its
  // place: shop, blog, other), also where the site sends no Referer; Gridbox reads mediaManager.action
  // at every upload
  if (cfg.ref && typeof window.mediaManager.action === 'string' && !/[?&]mt_ref=/.test(window.mediaManager.action)) {
    try {
      const topLoc = window.top.location;
      if (topLoc.origin === location.origin) {
        window.mediaManager.action += (window.mediaManager.action.includes('?') ? '&' : '?') + 'mt_ref=' + encodeURIComponent(topLoc.pathname + topLoc.search);
      }
    } catch (e) { /* another site's frame: the Referer only */ }
  }
  if (cfg.resize) {
    try { resizable(); } catch (e) { /* the window of another site's page: left as it is */ }
  }
  if (cfg.drop === false) return;

  const style = document.createElement('style');
  style.textContent = '.mt-drop{position:fixed;inset:8px;z-index:100000;display:none;align-items:center;justify-content:center;pointer-events:none;'
    + 'border:3px dashed #1d4ed8;border-radius:10px;background:rgba(239,246,255,.95)}.mt-drop.mt-on{display:flex}'
    + '.mt-drop div{text-align:center;color:#1e3a8a;font:600 18px/1.4 sans-serif;padding:0 16px}.mt-drop small{display:block;font-weight:400;font-size:14px;color:#1e40af}';
  document.head.append(style);
  const box = document.createElement('div');
  box.className = 'mt-drop';
  box.setAttribute('aria-hidden', 'true');
  const title = document.createElement('div');
  const where = document.createElement('small');
  box.append(title);
  document.body.append(box);

  // the folder shown: the last part of Gridbox's breadcrumb, else its path
  const folder = () => {
    const crumbs = document.querySelectorAll('.uploader-nav .ba-breadcrumb > *');
    const last = crumbs.length ? crumbs[crumbs.length - 1].textContent.trim() : '';
    return last || String(window.mediaManager.path || '').split('/').filter(Boolean).pop() || '';
  };
  const hasFiles = (e) => !!e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
  let depth = 0;
  const hide = () => { depth = 0; box.classList.remove('mt-on'); };

  document.addEventListener('dragenter', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    if (depth++ === 0) {
      const name = folder();
      title.textContent = t.DROP;
      where.textContent = name ? t.FOLDER.replace('%s', name) : '';
      title.append(where);
      box.classList.add('mt-on');
    }
  });
  document.addEventListener('dragover', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  });
  document.addEventListener('dragleave', (e) => {
    if (hasFiles(e) && --depth <= 0) hide();
  });
  document.addEventListener('drop', (e) => {
    if (!hasFiles(e)) return;
    // never let the browser open the file instead
    e.preventDefault();
    hide();
    const files = e.dataTransfer.files;
    if (!files || !files.length) return;
    // a fresh field as Gridbox's button makes it, then its own "change" handler does the rest
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    form.replaceChildren(input);
    try {
      input.files = files;
    } catch (err) {
      return;
    }
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  window.addEventListener('blur', hide);

  /**
   * The window of the media manager (Gridbox's modal around this frame, in the page above) resized by
   * dragging its edges and corners; the size and place kept for the next time (localStorage), a double
   * click on an edge gives Gridbox's own size back. Its full-screen button still works: the size chosen
   * comes back when it is left. Everything is put in the page above, which keeps the modal when this
   * frame is loaded again, so what an earlier load put there is taken away first.
   */
  function resizable() {
    const modal = window.frameElement && window.frameElement.closest('.modal');
    if (!modal) return;
    const doc = modal.ownerDocument;
    const win = doc.defaultView;
    const KEY = 'mertools-media-size';
    const MIN_W = 480;
    const MIN_H = 320;
    if (!doc.getElementById('mertools-media-resize')) {
      const css = doc.createElement('style');
      css.id = 'mertools-media-resize';
      const on = '.modal.mt-sized:not(.fullscrean)';
      css.textContent = on + '{width:var(--mt-w)!important;height:var(--mt-h)!important;left:var(--mt-x)!important;top:var(--mt-y)!important;margin:0!important}'
        + on + ' .modal-body,' + on + ' .modal-body iframe{height:var(--mt-h)!important;max-height:none!important}'
        + '.mt-rs{position:absolute;z-index:10;touch-action:none}'
        + '.mt-rs-n,.mt-rs-s{left:12px;right:12px;height:6px;cursor:ns-resize}.mt-rs-n{top:0}.mt-rs-s{bottom:0}'
        + '.mt-rs-e,.mt-rs-w{top:12px;bottom:12px;width:6px;cursor:ew-resize}.mt-rs-e{right:0}.mt-rs-w{left:0}'
        + '.mt-rs-nw,.mt-rs-ne,.mt-rs-sw,.mt-rs-se{width:12px;height:12px}'
        + '.mt-rs-nw{top:0;left:0;cursor:nwse-resize}.mt-rs-se{bottom:0;right:0;cursor:nwse-resize}'
        + '.mt-rs-ne{top:0;right:0;cursor:nesw-resize}.mt-rs-sw{bottom:0;left:0;cursor:nesw-resize}'
        + '.modal.fullscrean .mt-rs{display:none}'
        + '.modal.mt-resizing,.modal.mt-resizing .modal-body iframe{transition:none!important}'
        + '.modal.mt-resizing .modal-body iframe{pointer-events:none}'
        + 'html.mt-resizing-page,html.mt-resizing-page *{user-select:none!important}';
      doc.head.append(css);
    }
    modal.querySelectorAll(':scope > .mt-rs').forEach((n) => n.remove());
    if (modal.mtResizeOff) modal.mtResizeOff();

    const clamp = (v, lo, hi) => Math.max(lo, Math.min(v, hi));
    const view = () => [doc.documentElement.clientWidth, doc.documentElement.clientHeight];
    const read = () => {
      try {
        const s = JSON.parse(win.localStorage.getItem(KEY) || 'null');
        return s && [s.x, s.y, s.w, s.h].every(Number.isFinite) ? s : null;
      } catch (e) { return null; }
    };
    // a size kept on a larger screen is fitted into this one; the kept one stays for that screen
    const apply = (s) => {
      const [vw, vh] = view();
      const w = clamp(s.w, Math.min(MIN_W, vw), vw);
      const h = clamp(s.h, Math.min(MIN_H, vh), vh);
      const st = modal.style;
      st.setProperty('--mt-w', w + 'px');
      st.setProperty('--mt-h', h + 'px');
      st.setProperty('--mt-x', clamp(s.x, 0, vw - w) + 'px');
      st.setProperty('--mt-y', clamp(s.y, 0, vh - h) + 'px');
      modal.classList.add('mt-sized');
    };
    // the size asked for, on the modal (it outlives this frame, also where the browser keeps nothing):
    // fitted again to a smaller screen
    const reset = () => {
      modal.mtAsked = null;
      modal.classList.remove('mt-sized');
      ['--mt-w', '--mt-h', '--mt-x', '--mt-y'].forEach((p) => modal.style.removeProperty(p));
      try { win.localStorage.removeItem(KEY); } catch (e) { /* not kept */ }
    };
    modal.mtAsked = modal.mtAsked || read();
    if (modal.mtAsked) apply(modal.mtAsked);
    const fit = () => { if (modal.mtAsked) apply(modal.mtAsked); };
    win.addEventListener('resize', fit);
    modal.mtResizeOff = () => win.removeEventListener('resize', fit);

    ['n', 's', 'e', 'w', 'nw', 'ne', 'sw', 'se'].forEach((dir) => {
      const h = doc.createElement('div');
      h.className = 'mt-rs mt-rs-' + dir;
      h.setAttribute('aria-hidden', 'true');
      modal.append(h);
      h.addEventListener('dblclick', reset);
      h.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        e.preventDefault();
        const r = modal.getBoundingClientRect();
        const x0 = e.clientX;
        const y0 = e.clientY;
        let box = null;
        h.setPointerCapture(e.pointerId);
        modal.classList.add('mt-resizing');
        doc.documentElement.classList.add('mt-resizing-page');
        const move = (ev) => {
          const [vw, vh] = view();
          const dx = ev.clientX - x0;
          const dy = ev.clientY - y0;
          let { left, top, right, bottom } = r;
          if (dir.includes('e')) right = clamp(r.right + dx, r.left + MIN_W, vw);
          if (dir.includes('w')) left = clamp(r.left + dx, 0, r.right - MIN_W);
          if (dir.includes('s')) bottom = clamp(r.bottom + dy, r.top + MIN_H, vh);
          if (dir.includes('n')) top = clamp(r.top + dy, 0, r.bottom - MIN_H);
          box = { x: Math.round(left), y: Math.round(top), w: Math.round(right - left), h: Math.round(bottom - top) };
          apply(box);
        };
        const end = () => {
          h.removeEventListener('pointermove', move);
          h.removeEventListener('pointerup', end);
          h.removeEventListener('pointercancel', end);
          modal.classList.remove('mt-resizing');
          doc.documentElement.classList.remove('mt-resizing-page');
          if (box) {
            modal.mtAsked = box;
            try { win.localStorage.setItem(KEY, JSON.stringify(box)); } catch (err) { /* only for now */ }
          }
        };
        h.addEventListener('pointermove', move);
        h.addEventListener('pointerup', end);
        h.addEventListener('pointercancel', end);
      });
    });
  }
})();
