/**
 * MerTools — folding the fields in the Gridbox editor of a page of an app (a product, a post): an eye
 * right of the name of every field hides the field down to its name; the eye again shows it. Which
 * fields are folded is kept on the user's account (by the key of the field — the same in every page of
 * an app), so the next editor opens with the same fields folded, in any browser. An account that keeps
 * none yet takes over the folds this browser kept before (localStorage, MerTools 1.7.1–1.10). A required
 * field Gridbox marks as empty on saving is shown even when folded. Nothing of it is saved with the page.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-fold-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  // the browser's own list (before 1.11), and the copy of the account's list other tabs follow
  const OLD = 'mertools-folded-fields';
  const MIRROR = OLD + ':' + (cfg.user || 0);
  const local = (key) => {
    try {
      const list = JSON.parse(localStorage.getItem(key) || 'null');
      return Array.isArray(list) ? list.map(String) : null;
    } catch (e) {
      return null;
    }
  };
  const mirror = () => {
    try { localStorage.setItem(MIRROR, JSON.stringify([...folded])); } catch (e) { /* the account keeps them */ }
  };
  let folded = new Set(Array.isArray(cfg.folded) ? cfg.folded.map(String) : (local(OLD) || []));

  // one request at a time, in the order of the clicks; the account's list replaces the one shown only
  // after the last of them (an answer to an earlier click does not have the later ones yet)
  let queue = Promise.resolve();
  let waiting = 0;
  const post = async (data) => {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append(cfg.token, '1');
    try {
      const res = await fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const json = await res.json();
      return json && json.success && Array.isArray(json.folded) ? json.folded.map(String) : null;
    } catch (e) {
      // not kept on the account this time: the folds stay as clicked until the editor is closed
      return null;
    }
  };
  const send = (data) => {
    waiting++;
    queue = queue.then(() => post(data)).then((list) => {
      waiting--;
      if (list && waiting === 0) {
        folded = new Set(list);
        mirror();
        repaint();
      }
    });
  };

  // folded: the name (with the sorting handle of Gridbox's field editor) and the eye; the rest hidden
  const group = '#blog-post-editor-fields-options .blog-post-editor-options-group';
  const style = document.createElement('style');
  style.textContent = group + '>.blog-post-editor-group-element{position:relative}'
    + group + ' .mt-fold{position:absolute;top:0;right:0;z-index:2;display:flex;align-items:center;justify-content:center;width:28px;height:24px;'
    + 'padding:0;margin:0;border:0;background:none;font-size:20px;line-height:1;cursor:pointer;opacity:.55;transition:opacity .2s}'
    + group + ' .mt-fold:hover,' + group + ' .mt-fold:focus-visible,' + group + '.mt-folded .mt-fold{opacity:1}'
    + group + '>.blog-post-editor-group-element>label.ba-field-editor-label{padding-right:34px}'
    + group + '.mt-folded:not(.ba-alert-label)>.blog-post-editor-group-element>:not(label.ba-field-editor-label):not(.sorting-handle):not(.mt-fold){display:none!important}'
    + group + '.mt-folded:not(.ba-alert-label)>.blog-post-editor-group-element>label.ba-field-editor-label{margin-bottom:0!important}';
  document.head.append(style);

  const paint = (field, button) => {
    const on = folded.has(field.dataset.fieldKey);
    field.classList.toggle('mt-folded', on);
    button.querySelector('i').className = 'zmdi ' + (on ? 'zmdi-eye-off' : 'zmdi-eye');
    button.title = on ? cfg.show : cfg.hide;
    button.setAttribute('aria-label', button.title);
    button.setAttribute('aria-expanded', on ? 'false' : 'true');
  };

  const setup = (field) => {
    if (field.dataset.mtFold) return;
    const element = field.querySelector(':scope > .blog-post-editor-group-element');
    const label = element && element.querySelector(':scope > label.ba-field-editor-label');
    if (!label || !field.dataset.fieldKey) return;
    // a field with nothing under its name (a headline) has nothing to fold
    const body = [...element.children].some((child) => child !== label && !child.matches('.sorting-handle, .ba-help-icon'));
    if (!body) return;
    field.dataset.mtFold = '1';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mt-fold';
    button.append(document.createElement('i'));
    element.append(button);
    paint(field, button);
    button.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const key = field.dataset.fieldKey;
      const fold = !folded.has(key);
      if (fold) folded.add(key); else folded.delete(key);
      paint(field, button);
      // editors, maps and sliders shown again measure themselves on a resize
      if (!fold) window.dispatchEvent(new Event('resize'));
      send({ mertools_fold: 'toggle', key, fold: fold ? '1' : '0' });
    });
  };

  const scan = () => document.querySelectorAll(group + '[data-field-key]').forEach(setup);
  let queued = false;
  scan();
  new MutationObserver(() => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; scan(); });
  }).observe(document.documentElement, { childList: true, subtree: true });

  const repaint = () => {
    document.querySelectorAll(group + '[data-mt-fold]').forEach((field) => {
      const button = field.querySelector(':scope > .blog-post-editor-group-element > .mt-fold');
      if (button) paint(field, button);
    });
    window.dispatchEvent(new Event('resize'));
  };

  // an account that keeps no folds yet: the ones this browser kept are taken over
  if (!Array.isArray(cfg.folded) && cfg.url) {
    send({ mertools_fold: 'import', list: JSON.stringify([...folded]) });
  }

  // the same browser, another editor open in a tab: follow its folds
  window.addEventListener('storage', (e) => {
    if (e.key !== MIRROR) return;
    const list = local(MIRROR);
    if (!list) return;
    folded = new Set(list);
    repaint();
  });
})();
