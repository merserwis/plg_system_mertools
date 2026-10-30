// media/js/mertools-save.js against a stand-in of the Gridbox editor (its undo history, its save
// button, its notices) with a clock of its own. Run: node tests/save-script.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../plg_system_mertools/media/js/mertools-save.js'), 'utf8');
const SAVED = 'Page has been saved successfully';

function editor(cfg = {}, look = true) {
  let clock = 1000000;
  const listeners = (doc) => (doc.on = {}, (type, fn) => (doc.on[type] ||= []).push(fn));
  const el = (props = {}) => ({
    nodeType: 1, isContentEditable: false, type: 'text', name: '', id: '', attrs: {}, parents: [],
    getAttribute(k) { return this.attrs[k] ?? null; },
    closest(sel) { return [this, ...this.parents].find((p) => sel.split(',').some((s) => p.matches && p.matches(s.trim()))) || null; },
    matches() { return false; },
    ...props,
  });
  const classes = new Set();
  const button = {
    dataset: {}, title: '',
    classList: { toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)), contains: (c) => classes.has(c) },
    removeAttribute() { this.title = ''; },
    click() { app.saveMode = 'save'; app.checkModule('gridboxSave'); },
  };
  classes.add('gridbox-enabled-save');
  const canvas = { __mtSave: false };
  canvas.addEventListener = listeners(canvas);
  const config = { warn: true, autosave: 60, saved: [SAVED], lang: 'pl-PL', texts: { unsaved: 'Niezapisane zmiany', autosaved: 'Zapisano automatycznie o %s' }, ...cfg };
  const document = {
    getElementById: (id) => (id === 'mertools-save-config' ? { textContent: JSON.stringify(config) } : null),
    querySelector: (sel) => (sel === '.gridbox-save' ? button : sel === 'iframe.editor-iframe' ? { contentDocument: canvas } : null),
    createElement: () => ({}),
    head: { append() {} },
  };
  document.addEventListener = listeners(document);
  const saves = [];
  const notices = [];
  const app = {
    history: [], hIndex: 0, saveMode: '',
    checkModule(name) { if (name === 'gridboxSave' && button.dataset.action !== 'clicked') { button.dataset.action = 'clicked'; saves.push(app.saveMode); } },
    showNotice(message) { notices.push(message); },
  };
  let tick = null;
  const win = { app };
  win.addEventListener = listeners(win);
  const Clock = class extends Date { static now() { return clock; } };
  const context = vm.createContext({
    window: win, document, Date: Clock, JSON, Math, String, Array, setInterval: (fn) => { tick = fn; }, console,
  });
  vm.runInContext(source, context);

  const step = (html) => {
    app.history.length = app.hIndex;
    app.history.push({ content: { innerHTML: html }, items: { a: html } });
    app.hIndex = app.history.length;
  };
  const t = {
    app, button, saves, notices,
    wait(ms) { for (let left = ms; left > 0; left -= 1000) { clock += Math.min(1000, left); tick(); } },
    init(html = 'A') { app.history = []; app.hIndex = 0; step(html); },
    step,
    // the undo and redo buttons (or Ctrl+Z): a click first
    undo() { t.click(); app.hIndex--; },
    redo() { t.click(); app.hIndex++; },
    fire(doc, type, target = {}) { (doc.on[type] || []).forEach((fn) => fn({ isTrusted: true, target })); },
    // a click takes a moment (mousedown … click)
    click(doc = document) { t.fire(doc, 'mousedown'); t.fire(doc, 'mouseup'); clock += 50; },
    // a key in a text of the page: Gridbox notes it as a step 300 ms later
    typeText(html) {
      t.fire(canvas, 'keydown');
      t.fire(canvas, 'input', el({ isContentEditable: true, matches: (s) => s === '.content-text[contenteditable]' }));
      clock += 300;
      step(html);
    },
    typePanel(target = el()) { t.fire(document, 'keydown'); t.fire(document, 'input', target); },
    save() { t.click(); button.click(); },
    // Gridbox's answer to the save, a moment later
    answer(text = SAVED) { clock += 200; button.dataset.action = 'enabled'; app.showNotice(text); },
    dirty() { return classes.has('mt-unsaved'); },
    leave() { const e = { prevented: false, preventDefault() { this.prevented = true; } }; (win.on.beforeunload || []).forEach((fn) => fn(e)); return e.prevented; },
    el,
    canvas,
    document,
  };
  t.init();
  if (look) t.wait(1000);

  return t;
}

let failed = 0;
const check = (name, got, want) => {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (!ok) failed++;
  console.log((ok ? 'OK  ' : 'FAIL') + ' ' + name.padEnd(70) + (ok ? '' : ' got ' + JSON.stringify(got) + ', want ' + JSON.stringify(want)));
};

let t = editor();
check('opened: nothing to save, leaving asks nothing', [t.dirty(), t.leave()], [false, false]);

t = editor();
t.typeText('A typed');
t.wait(1000);
check('a text typed: the dot, leaving asks', [t.dirty(), t.leave()], [true, true]);
t.undo();
t.wait(1000);
check('the text undone back to the saved page: nothing to save', [t.dirty(), t.leave()], [false, false]);

{
  // a change before the first look at the history (the review: the page as opened is the first step)
  const e = editor({}, false);
  e.click();
  e.step('A without an element');
  e.wait(1000);
  check('a change made before the first look at the history counts', e.dirty(), true);
}

t = editor();
t.typeText('A typed');
t.wait(1000);
t.save();
t.answer();
check('saved: nothing to save', [t.dirty(), t.leave()], [false, false]);
t.undo();
t.wait(1000);
check('an undo after the save is a change', t.dirty(), true);
t.redo();
t.wait(1000);
check('the redo back to the saved page is none', t.dirty(), false);

t = editor();
t.fire(t.canvas, 'keydown');
t.fire(t.canvas, 'input', t.el({ isContentEditable: true, matches: (s) => s === '.content-text[contenteditable]' }));
t.save();
t.wait(1000);
t.step('A typed'); // Gridbox notes the text after the save started, nothing done since
t.answer();
t.wait(1000);
check('a text noted after the save started is the saved page', t.dirty(), false);

t = editor();
t.step('A same');
t.app.history[t.app.hIndex - 1] = { content: { innerHTML: 'A' }, items: { a: 'A' } };
t.click();
t.wait(1000);
check('a step that changes nothing (Ctrl+S in a text) is no change', t.dirty(), false);

t = editor();
t.typePanel(t.el({ attrs: { class: 'ba-search-input' } }));
t.wait(1000);
check('a search box is no change', t.dirty(), false);
t.typePanel();
t.wait(1000);
check('a field of the panel (a product field, no step) is a change', t.dirty(), true);
t.click();
t.step('A other');
t.undo();
t.wait(1000);
check('…and stays one after steps of the page', t.dirty(), true);

t = editor();
t.typeText('A typed');
t.wait(1000);
t.save();
t.answer('Nie zapisano: tę stronę edytuje teraz Ktoś Inny.');
check('a refused save keeps the changes', [t.dirty(), t.leave()], [true, true]);

t = editor();
t.typeText('A typed');
t.wait(30000);
check('no own save before the time', t.saves.length, 0);
t.wait(31000);
check('the own save after the time and a pause', t.saves.length, 1);
t.answer();
check('its notice says it saved by itself', /^Zapisano automatycznie o \d\d:\d\d$/.test(t.notices.at(-1)), true);
check('…and nothing is left to save', t.dirty(), false);

t = editor();
t.typeText('A typed');
t.wait(61000);
t.answer('Nie zapisano: tę stronę edytuje teraz Ktoś Inny.');
t.wait(120000);
check('a refused own save is not tried again', t.saves.length, 1);
t.typeText('A typed more');
t.wait(66000);
check('…until the next change', t.saves.length, 2);

t = editor();
t.typeText('A typed');
t.wait(57000);
t.fire(t.document, 'keydown');
t.wait(3000);
check('the own save waits for a pause in typing', t.saves.length, 0);
t.wait(5000);
check('…and saves after it', t.saves.length, 1);

t = editor({ autosave: 0 });
t.typeText('A typed');
t.wait(200000);
check('autosave 0: no save of its own', t.saves.length, 0);

t = editor();
t.typeText('A typed');
t.wait(1000);
t.app.saveMode = 'version';
t.click();
t.app.checkModule('gridboxSave');
t.answer();
check('a save only as a version keeps the changes', t.dirty(), true);

console.log(failed ? `${failed} FAILED` : 'ALL PASS');
process.exit(failed ? 1 : 0);
