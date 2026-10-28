/**
 * MerTools — folding the fields in the Gridbox editor of a page of an app (a product, a post): an eye
 * right of the name of every field hides the field down to its name; the eye again shows it. Which
 * fields are folded is kept in this browser (localStorage, by the key of the field — the same in every
 * page of an app), so the next editor opens with the same fields folded. A required field Gridbox
 * marks as empty on saving is shown even when folded. Nothing of it is saved with the page.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-fold-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const STORE = 'mertools-folded-fields';
  // once the storage failed (private mode, full, blocked), the folds of this editor live in memory
  // only: reading it again would bring back a list without the last click
  let stored = true;
  const read = () => {
    try {
      const list = JSON.parse(localStorage.getItem(STORE) || '[]');
      return new Set(Array.isArray(list) ? list.map(String) : []);
    } catch (e) {
      stored = false;
      return null;
    }
  };
  const write = (folded) => {
    try { localStorage.setItem(STORE, JSON.stringify([...folded])); } catch (e) { stored = false; }
  };
  let folded = read() || new Set();

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
      if (stored) folded = read() || folded; // another editor may have changed the list meanwhile
      const key = field.dataset.fieldKey;
      if (folded.has(key)) folded.delete(key); else folded.add(key);
      write(folded);
      paint(field, button);
      // editors, maps and sliders shown again measure themselves on a resize
      if (!folded.has(key)) window.dispatchEvent(new Event('resize'));
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

  // the same browser, another editor open in a tab: follow its folds
  window.addEventListener('storage', (e) => {
    if (e.key !== STORE || !stored) return;
    folded = read() || folded;
    document.querySelectorAll(group + '[data-mt-fold]').forEach((field) => {
      const button = field.querySelector(':scope > .blog-post-editor-group-element > .mt-fold');
      if (button) paint(field, button);
    });
    window.dispatchEvent(new Event('resize'));
  });
})();
