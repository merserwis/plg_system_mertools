/**
 * MerTools — the Link Picker of the Gridbox editor opens with the folders open the last time. Gridbox
 * makes its tree (apps, categories, pages, menus) anew at every opening, all closed; the folders the
 * user opened (by the path of their names) and the place of the list are kept in this browser, for each
 * user, and given back to the new tree. Gridbox's own clicks (open, close, choose a link) are untouched.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-linktree-config');
  const box = document.querySelector('#link-select-modal .availible-folders');
  if (!node || !box) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const KEY = 'mertools-link-tree:' + (cfg.user || 0);
  const MAX = 300;
  const read = () => {
    try {
      const v = JSON.parse(localStorage.getItem(KEY) || 'null');
      return v && Array.isArray(v.open) ? { open: v.open.map(String), top: Number(v.top) || 0 } : { open: [], top: 0 };
    } catch (e) {
      return { open: [], top: 0 };
    }
  };
  const write = (v) => {
    try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* storage full or off: not kept */ }
  };
  const name = (li) => {
    const span = li.querySelector(':scope > span');
    return span ? span.textContent.trim() : '';
  };
  /** a folder by the names from the top of the tree down to it */
  const path = (li) => {
    const names = [];
    for (let el = li; el && el !== box; el = el.parentElement) {
      if (el.tagName === 'LI') names.unshift(name(el));
    }
    return names.join('\n');
  };
  const list = () => box.querySelector(':scope > ul');
  let state = read();
  let restoring = false;

  const save = () => {
    const open = [...box.querySelectorAll('li.visible-branch')].map(path).slice(0, MAX);
    const ul = list();
    state = { open, top: ul ? ul.scrollTop : state.top };
    write(state);
  };

  const restore = () => {
    const ul = list();
    if (!ul) return;
    restoring = true;
    const open = new Set(state.open);
    if (open.size) {
      box.querySelectorAll('li').forEach((li) => {
        if (li.querySelector(':scope > ul') && open.has(path(li))) li.classList.add('visible-branch');
      });
    }
    // the window is shown right after the tree is put in: the place of the list once it has its height
    const top = state.top;
    const scroll = () => { if (top) ul.scrollTop = top; };
    scroll();
    requestAnimationFrame(() => {
      scroll();
      setTimeout(() => { scroll(); restoring = false; }, 350);
    });
  };

  // a new tree from Gridbox (selectLink.js: the whole list put in at every opening)
  new MutationObserver((records) => {
    if (records.some((r) => [...r.addedNodes].some((n) => n.nodeType === 1 && n.tagName === 'UL'))) restore();
  }).observe(box, { childList: true });
  if (list()) restore();

  // Gridbox opens and closes a folder in its own handler: what it did is read right after it
  box.addEventListener('click', (e) => {
    if (e.target.closest && e.target.closest('i.zmdi-chevron-right')) setTimeout(save, 0);
  });
  let timer = 0;
  box.addEventListener('scroll', () => {
    if (restoring) return;
    clearTimeout(timer);
    timer = setTimeout(save, 200);
  }, true);
})();
