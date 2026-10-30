/**
 * MerTools — saving in the Gridbox editor: a warning before the editor is closed (tab, reload, a link
 * away) with changes not saved, an orange dot on Save while there are any, and, when set, a save of
 * its own a few minutes after the first change not saved.
 *
 * A change: the page as Gridbox's undo history has it now differs from the page as last saved (so an
 * undo back to it is no change; a text of the page is in it 300 ms after its last key), or text typed
 * in the editor's panels (not in a search box) since the last save. A save counts when Gridbox answers
 * with its "saved" text; a refused save (the edit lock, missing permission, required fields left
 * empty) keeps the changes as not saved. A save only as a version leaves the page as it was.
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
  let gen = 0; // changes typed in this editor
  let savedGen = 0; // the typed changes the last save kept
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

  // a step of the history: the page's elements and their settings (only the saved one is kept)
  const print = (entry) => {
    try {
      return (entry.content ? entry.content.innerHTML : '') + '\n' + JSON.stringify(entry.items || null);
    } catch (e) {
      return String(Math.random());
    }
  };
  const current = () => (Array.isArray(app.history) && app.hIndex > 0 ? app.history[app.hIndex - 1] || null : null);
  // Gridbox adds steps that change nothing (Ctrl+S in a text): only another page is a change
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

  const dirty = () => gen !== savedGen || text !== textNoted || stepDiffers;

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

  const changed = () => {
    gen++;
    paint();
  };

  const searchBox = (el) => el.type === 'search'
    || /search/i.test([el.getAttribute('class'), el.name, el.id, el.getAttribute('placeholder')].join(' '))
    || !!el.closest('[class*="search"]');
  const typed = (canvas) => (e) => {
    const el = e.target;
    if (!e.isTrusted || !el || el.nodeType !== 1) return;
    // in the page only its texts are edited (its form fields are the page's own); in the panels any field but a search
    if (canvas ? !el.isContentEditable : searchBox(el)) return;
    if (canvas && el.closest(NOTED_TEXT)) {
      // until the step of the history that has it: then an undo back to the saved text is no change
      text++;
      textAt = Date.now();
      paint();

      return;
    }
    changed();
  };
  const used = () => { lastUse = Date.now(); };
  const acted = () => { lastUse = lastAct = Date.now(); };
  const watch = (doc, canvas) => {
    doc.__mtSave = true;
    const onType = typed(canvas);
    doc.addEventListener('input', onType, true);
    doc.addEventListener('change', onType, true);
    doc.addEventListener('wheel', used, { capture: true, passive: true });
    ['keydown', 'touchstart'].forEach((type) => doc.addEventListener(type, acted, { capture: true, passive: true }));
    doc.addEventListener('mousedown', () => { acted(); pressed = true; }, { capture: true, passive: true });
    doc.addEventListener('mouseup', () => { used(); pressed = false; }, { capture: true, passive: true });
  };
  watch(document, false);
  const canvas = () => {
    try {
      const doc = document.querySelector('iframe.editor-iframe')?.contentDocument;
      if (doc && doc.__mtSave !== true) watch(doc, true);
    } catch (e) {
      // not reachable: the page is watched through the undo history only
    }
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
      saving = { gen, text, step: current(), at: Date.now(), auto, mode: app.saveMode };
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
    canvas();
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
