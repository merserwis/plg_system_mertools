/**
 * MerTools — saving in the Gridbox editor: a warning before the editor is closed (tab, reload, a link
 * away) with changes not saved, an orange dot on Save while there are any, and, when set, a save of
 * its own a few minutes after the first change not saved.
 *
 * A change: the page as Gridbox's undo history has it now differs from the page as last saved (so an
 * undo back to it is no change; a text of the page is in it 300 ms after its last key), or a field of
 * the editor's panels (not a search box) that holds another value than before it was typed in. The
 * pages of the history are compared as Gridbox saves them: without the editor's own bars and markers,
 * the classes and styles its scripts change while the page is shown (a click on an element is a step
 * of the history for Gridbox, with nothing changed) and the lists it fills on the site. A save counts
 * when Gridbox answers with its "saved" text; a refused save (the edit lock, missing permission,
 * required fields left empty) keeps the changes as not saved. A save only as a version leaves the page
 * as it was.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-save-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const button = document.querySelector('.gridbox-save');
  const app = window.app;
  if (!button || !app || typeof app.checkModule !== 'function' || (!cfg.warn && !(cfg.autosave > 0))) return;

  const PAUSE = 4000; // the own save waits for this long without a key or a click
  const NOTED = 300; // Gridbox notes a text of the page in its history this long after the last key
  // the texts Gridbox notes in its history (its keydown handler of the page)
  const NOTED_TEXT = '.content-text[contenteditable], .headline-wrapper[contenteditable]';
  let gen = 0; // keys and choices in the fields since the editor opened
  let savedGen = 0; // those the last save kept
  let text = 0; // keys typed in the texts of the page
  let textNoted = 0; // the keys a step of the history has
  let textAt = 0;
  let savedStep = null; // the step of the undo history the last save kept (the page as opened, at first)
  let savedPrint = null;
  let savedAt = 0; // when the last save started
  let step = null; // the step of the undo history now, and whether it differs from the saved one
  let stepDiffers = false;
  let saving = null; // the save under way: what it keeps, whether it is our own, its mode
  let failed = null; // an own save that did not go through: not tried again before the next change
  let firstChange = 0; // when the first change not saved was made
  let lastUse = 0; // a key, a click, a scroll: the own save waits for a pause
  let lastAct = 0; // a key or a click
  let pressed = false;
  let auto = false;

  // ---------------------------------------------------------------- the page in the undo history

  // what Gridbox does not save: the bars and markers of the editor, the parts its scripts change while
  // the page is shown (the classes and styles of animations, sliders, menus, sticky headers…), and the
  // lists and counters it fills on the site (emptied when it saves, see its gridboxSave prepareHTML)
  // (the styles and scripts Gridbox writes into the page are made from the settings of the elements,
  // compared on their own: the first step of the history may be taken before they are written)
  // (the bar of the post editor holds MerTools' own buttons, put in after the first step)
  const CHROME = '.ba-edit-item, .ba-box-model, .sortable-placeholder, .sortable-backdrop, .blog-post-editor-header-panel, [class^="mt-"], [class*=" mt-"], '
    + '.cke_reset_all, .cke_panel, meta[http-equiv], style, script';
  const LIVE = ['.ba-item-search-result .ba-blog-posts-wrapper', '.ba-item-search-result .ba-blog-posts-pagination-wrapper',
    '.ba-item-store-search-result .ba-blog-posts-wrapper', '.ba-item-store-search-result .ba-blog-posts-pagination-wrapper',
    '.ba-item-categories .ba-categories-wrapper', '.ba-item-post-navigation .ba-blog-posts-wrapper', '.ba-item-post-tags .ba-button-wrapper',
    '.ba-item-recent-posts .ba-blog-posts-wrapper', '.ba-item-recent-posts-slider .slideshow-content', '.ba-item-related-posts-slider .slideshow-content',
    '.ba-item-recently-viewed-products .slideshow-content', '.ba-item-related-posts .ba-blog-posts-wrapper', '.ba-item-tags .ba-button-wrapper',
    '.ba-item-author .ba-posts-author-wrapper', '.ba-item-recent-comments .ba-blog-posts-wrapper', '.ba-item-recent-reviews .ba-blog-posts-wrapper',
    '.ba-item-fields-filter .ba-fields-filter-wrapper', '.ba-item-blog-posts .ba-blog-posts-wrapper', '.ba-item-blog-posts .ba-blog-posts-pagination-wrapper',
    '.ba-countdown .countdown-time', '.ba-weather', '.progress-bar-number', '.progress-pie-number'].join(', ');
  const TRANSIENT = /^(?:active|visible|animated|hidden|not-default-header|visible-sticky-header|ba-(?:next|prev|left|right)|burns-out|(?:left|right|prev|next)-animation|slideset-loaded|first-load-slideshow|ba-masonry-image-loaded|page-structure-item-active|visible-lightbox|visible-menu|hide-menu|(?:column|row)-with-menu|ba-visible-menu-backdrop|backside-fliped|flipbox-animation-started|visible-scroll-to-top|visible-section|(?:ba-)?lightbox-open|search-open|ba-opened-menu)$/;
  const TEXT = '.content-text, .headline-wrapper';
  const pageOf = (content) => {
    if (!content) return '';
    if (typeof content.cloneNode !== 'function') return String(content.innerHTML || '');
    const copy = content.cloneNode(true);
    copy.querySelectorAll(CHROME).forEach((n) => n.remove());
    copy.querySelectorAll(LIVE).forEach((n) => { n.textContent = ''; });
    // a text as Gridbox saves it: its content only, without the marks of the text editor on it (focus…)
    copy.querySelectorAll('.ba-item-text > .content-text').forEach((n) => {
      [...n.attributes].forEach((a) => n.removeAttribute(a.name));
      n.className = 'content-text';
    });
    // the styles and classes of the page's scripts, not those inside a text (its colours, its marks: content)
    const own = (n) => !n.parentElement || !n.parentElement.closest(TEXT);
    copy.querySelectorAll('[style]').forEach((n) => { if (own(n)) n.removeAttribute('style'); });
    copy.querySelectorAll('[class]').forEach((n) => {
      if (!own(n)) return;
      // Gridbox's scripts put a class back at the end: the order of the classes is no change
      const keep = [...n.classList].filter((c) => !TRANSIENT.test(c)).sort().join(' ');
      if (keep === '') n.removeAttribute('class'); else if (n.getAttribute('class') !== keep) n.setAttribute('class', keep);
    });

    return copy.innerHTML;
  };
  // the settings of the elements: Gridbox fills in an empty default when an element is chosen
  // ("presets": "") and writes a number as text: an empty value is no value, the keys in one order
  const settings = (value) => {
    if (Array.isArray(value)) return value.map(settings);
    if (value && typeof value === 'object') {
      const out = {};
      Object.keys(value).sort().forEach((key) => {
        const v = settings(value[key]);
        if (v !== '' && v !== null && !(typeof v === 'object' && Object.keys(v).length === 0)) out[key] = v;
      });

      return out;
    }

    return value === null || value === undefined ? null : String(value);
  };
  // a step of the history: the page's elements and their settings (only the saved one is kept)
  const print = (entry) => {
    try {
      return pageOf(entry.content) + '\n' + JSON.stringify(settings(entry.items || null));
    } catch (e) {
      return String(Math.random());
    }
  };
  const current = () => (Array.isArray(app.history) && app.hIndex > 0 ? app.history[app.hIndex - 1] || null : null);
  // Gridbox adds steps that change nothing (a click on an element, Ctrl+S in a text): only another page is a change
  const history = () => {
    const entry = current();
    if (!entry || entry === step) return;
    step = entry;
    if (Date.now() - textAt >= NOTED) textNoted = text;
    // the page as opened (Gridbox starts its history again when it loads the page anew), or a step
    // Gridbox made without anything done since the save started (a text is noted 300 ms after its
    // last key): the page the save kept
    if (savedStep === null) {
      // the first step is the page as opened, whatever was done before this first look
      savedStep = app.history[0] || entry;
      savedPrint = null;
    }
    if ((gen === savedGen && lastAct <= savedAt) || (app.history.length === 1 && app.hIndex === 1 && !dirty())) {
      savedStep = entry;
      savedPrint = null;
    }
    stepDiffers = entry !== savedStep && print(entry) !== (savedPrint ??= print(savedStep));
    paint();
  };

  // ---------------------------------------------------------------- the fields of the panels

  // a field typed in since the last save: its value before (null: not known, a change) and its value as
  // last typed (not as shown now: a panel of element settings shows the next element in the same fields)
  const fields = new Map();
  let kept = 0; // fields changed and then shown for something else: changes until the next save
  const valueOf = (el) => {
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? '1' : '0';
    if (el.type === 'file') return String(Math.random());
    if (el.multiple && el.selectedOptions) return [...el.selectedOptions].map((o) => o.value).join('\n');
    if (FORM.test(el.tagName || '')) return String(el.value ?? '');

    // a text editor (CKEditor) leaves markers where the caret was: a letter typed and deleted is no change
    return String(el.innerHTML ?? '').replace(/\u200b/g, '').replace(/<br>(?=<\/(?:p|div|li|h\d)>)/g, '').replace(/\sdata-cke-[\w-]+="[^"]*"/g, '');
  };
  const FORM = /^(?:INPUT|SELECT|TEXTAREA)$/;
  // the field of an event: a form field, or the whole text an element of it belongs to (its editing host)
  const fieldOf = (el) => {
    if (!el || el.nodeType !== 1) return null;
    if (FORM.test(el.tagName || '')) return el;
    if (!el.isContentEditable) return null;
    while (el.parentElement && el.parentElement.isContentEditable) el = el.parentElement;

    return el;
  };
  const fieldChanged = (f) => f.value !== f.base;
  // before a field is typed in: what it holds (a field shown again for something else starts anew)
  const touch = (el) => {
    const f = fields.get(el);
    const now = valueOf(el);
    if (f && f.value === now) return;
    if (f && fieldChanged(f)) kept++;
    fields.set(el, { base: now, value: now });
  };
  const changedFields = () => kept > 0 || [...fields.values()].some(fieldChanged);

  const dirty = () => text !== textNoted || stepDiffers || changedFields();

  const style = document.createElement('style');
  style.textContent = '.gridbox-save.mt-unsaved{position:relative}'
    + '.gridbox-save.mt-unsaved::after{content:"";position:absolute;top:7px;right:7px;width:8px;height:8px;border-radius:50%;'
    + 'background:#f59e0b;box-shadow:0 0 0 2px #fff;pointer-events:none}';
  document.head.append(style);
  const paint = () => {
    const on = dirty();
    // the own save counts from the first change not saved
    if (!on) firstChange = 0; else if (!firstChange) firstChange = Date.now();
    button.classList.toggle('mt-unsaved', on);
    if (on) button.title = cfg.texts.unsaved; else button.removeAttribute('title');
  };

  const searchBox = (el) => el.type === 'search'
    || /search/i.test([el.getAttribute('class'), el.name, el.id, el.getAttribute('placeholder')].join(' '))
    || !!el.closest('[class*="search"]');
  // switches of the view only: the editing of the app's fields (a page of a product or post)
  const VIEW = '.apps-fields-editor-toggle';
  // in the page only its texts are edited (its form fields are the page's own); in the panels any field but a search
  const counted = (el, canvas) => !!el && (canvas ? el.isContentEditable : !searchBox(el) && !el.matches(VIEW));
  const before = (canvas) => (e) => {
    const el = e.isTrusted ? fieldOf(e.target) : null;
    if (!counted(el, canvas) || (canvas && el.closest(NOTED_TEXT))) return;
    touch(el);
  };
  const typed = (canvas) => (e) => {
    const el = e.isTrusted ? fieldOf(e.target) : null;
    if (!counted(el, canvas)) return;
    gen++;
    if (canvas && el.closest(NOTED_TEXT)) {
      // until the step of the history that has it: then an undo back to the saved text is no change
      text++;
      textAt = Date.now();
      paint();

      return;
    }
    let f = fields.get(el);
    if (!f) {
      // typed without a key or a click on it first (a label, the browser filling it): a change
      f = { base: el.type === 'checkbox' || el.type === 'radio' ? (el.checked ? '0' : '1') : null };
      fields.set(el, f);
    }
    f.value = valueOf(el);
    paint();
  };
  const used = () => { lastUse = Date.now(); };
  const acted = () => { lastUse = lastAct = Date.now(); };
  const watch = (doc, canvas) => {
    doc.__mtSave = true;
    const onBefore = before(canvas);
    const onType = typed(canvas);
    doc.addEventListener('focusin', onBefore, true);
    doc.addEventListener('input', onType, true);
    doc.addEventListener('change', onType, true);
    doc.addEventListener('wheel', used, { capture: true, passive: true });
    doc.addEventListener('keydown', (e) => { acted(); onBefore(e); }, { capture: true, passive: true });
    doc.addEventListener('touchstart', acted, { capture: true, passive: true });
    doc.addEventListener('mousedown', (e) => { acted(); pressed = true; onBefore(e); }, { capture: true, passive: true });
    doc.addEventListener('mouseup', () => { used(); pressed = false; }, { capture: true, passive: true });
  };
  watch(document, false);
  // the page (its texts), and the text editors of the panels (CKEditor: a page of its own each)
  const frames = () => {
    document.querySelectorAll('iframe.editor-iframe, iframe.cke_wysiwyg_frame').forEach((frame) => {
      try {
        const doc = frame.contentDocument;
        if (doc && doc.__mtSave !== true) watch(doc, frame.classList.contains('editor-iframe'));
      } catch (e) {
        // not reachable: the page is watched through the undo history only
      }
    });
  };

  const time = () => {
    try { return new Date().toLocaleTimeString(cfg.lang || [], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return new Date().toTimeString().slice(0, 5); }
  };

  // the answer of a save: Gridbox shows it as its notice (also "complete the required fields")
  const finished = (message) => {
    const done = saving;
    saving = null;
    const ok = done.mode !== 'version' && (cfg.saved || []).some((text) => String(message).includes(text));
    if (ok) {
      savedGen = done.gen;
      textNoted = Math.max(textNoted, done.text);
      savedAt = done.at;
      if (done.step) {
        savedStep = done.step;
        savedPrint = null;
      }
      // the fields hold what the save kept; what was typed while it was saving is still to save
      kept = Math.max(0, kept - done.kept);
      done.fields.forEach(([el, value]) => {
        const f = fields.get(el);
        if (!f) return;
        f.base = value;
        if (!fieldChanged(f)) fields.delete(el);
      });
      failed = null;
      // what was changed while it was saving is still to save, counted from now
      firstChange = 0;
      step = null;
      stepDiffers = false;
      history();
    } else if (done.auto) {
      failed = { gen, text, step: current() };
    }
    paint();

    return ok && done.auto && cfg.texts.autosaved ? cfg.texts.autosaved.replace('%s', time()) : message;
  };
  const notices = () => {
    if (typeof app.showNotice !== 'function' || app.showNotice.__mtSave) return;
    const show = app.showNotice;
    const wrapped = function (message, className) {
      if (saving && button.dataset.action !== 'clicked') message = finished(message);

      return show.call(this, message, className);
    };
    wrapped.__mtSave = true;
    app.showNotice = wrapped;
  };

  // every save of the editor (the button, Ctrl+S, the menu "Save and …") starts with this module
  const check = app.checkModule;
  app.checkModule = function (name) {
    if (name === 'gridboxSave' && !saving && button.dataset.action !== 'clicked') {
      notices();
      history(); // a change just before the save is one it keeps
      saving = { gen, text, step: current(), at: Date.now(), auto, mode: app.saveMode, kept,
        fields: [...fields].map(([el, f]) => [el, f.value]) };
    }

    return check.apply(this, arguments);
  };

  const autosave = () => {
    if (!(cfg.autosave > 0) || saving || !dirty() || (failed && failed.gen === gen && failed.text === text && failed.step === current())) return;
    const now = Date.now();
    if (now - firstChange < cfg.autosave * 1000 || now - lastUse < PAUSE || (pressed && now - lastUse < 60000)) return;
    // the button works once the editor has loaded the page
    if (!button.classList.contains('gridbox-enabled-save') || button.dataset.action === 'clicked') return;
    auto = true;
    try { button.click(); } finally { auto = false; }
  };

  setInterval(() => {
    history();
    notices();
    frames();
    autosave();
  }, 1000);

  window.addEventListener('beforeunload', (e) => {
    if (!cfg.warn) return;
    history();
    if (!dirty() && !saving) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();
