/**
 * MerTools — Gridbox's media manager: files dragged from the computer and dropped on it are uploaded
 * to the folder open in it. The dropped files go to Gridbox's own upload field (the one its button
 * "Upload" opens), so Gridbox checks the types, shows the count and reloads the folder as for that
 * button. Dragging the rows to a folder (Gridbox's move) is not a drag of files and is left alone.
 * Config: <script type="application/json" id="mertools-media-config">.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-media-config');
  const form = document.querySelector('#file-upload-form form');
  if (!node || !form || !window.mediaManager) return;
  let t;
  try { t = JSON.parse(node.textContent).texts; } catch (e) { return; }

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
})();
