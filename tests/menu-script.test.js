// media/js/mertools-menu.js (fitting the main menu on one line) against a stand-in of a Gridbox main menu:
// top items laid out in lines like inline blocks (an item that does not fit goes to the next line), or
// one under another as wide as the menu (hamburger mode). Run: node tests/menu-script.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../plg_system_mertools/media/js/mertools-menu.js'), 'utf8');

function style(inline = {}) {
  const v = {};
  for (const [k, val] of Object.entries(inline)) v[k] = [val, ''];
  return {
    v,
    getPropertyValue: (p) => (v[p] ? v[p][0] : ''),
    getPropertyPriority: (p) => (v[p] ? v[p][1] : ''),
    setProperty: (p, val, prio = '') => { v[p] = [val, prio]; },
    removeProperty: (p) => { delete v[p]; },
  };
}

// items: [{ text, chars, pl, pr, ls, inline }] — pl/pr/ls are the stylesheet's values, inline the link's style attribute
function menu({ width, items, stacked = false, cfg = { hold: 0, fit: 1 } }) {
  const ul = { tagName: 'UL', clientWidth: width, parentElement: { closest: () => null }, closest: () => null };
  const px = (a, p, sheet) => (a.style.v[p] ? parseFloat(a.style.v[p][0]) : sheet);
  const lis = items.map((it) => {
    const a = { tagName: 'A', it, style: style(it.inline) };
    const li = { tagName: 'LI', a, offsetParent: {}, top: 0, width: 0, querySelector: (sel) => (sel.includes(':scope > a') ? a : null) };
    li.getBoundingClientRect = () => { layout(); return { top: li.top, width: li.width }; };
    return li;
  });
  ul.children = lis;
  function layout() {
    let x = 0, top = 0;
    for (const li of lis) {
      const { a } = li, it = a.it;
      const w = stacked ? width : it.text + px(a, 'letter-spacing', it.ls) * it.chars + px(a, 'padding-left', it.pl) + px(a, 'padding-right', it.pr);
      if (stacked || (x > 0 && x + w > width)) { if (x > 0 || stacked) top += 40; x = 0; }
      li.top = top; li.width = w; x += w;
    }
  }
  const computed = (a) => ({
    paddingLeft: px(a, 'padding-left', a.it.pl) + 'px',
    paddingRight: px(a, 'padding-right', a.it.pr) + 'px',
    letterSpacing: px(a, 'letter-spacing', a.it.ls) + 'px',
  });
  const window = { addEventListener() {} };
  const document = { readyState: 'complete', querySelectorAll: () => [ul], addEventListener() {}, removeEventListener() {} };
  const ctx = { window, document, getComputedStyle: (e) => computed(e.a ? e.a : e), setTimeout, clearTimeout, WeakMap,
    Joomla: { getOptions: (k) => (k === 'plg_system_mertools' ? { menu: cfg } : null) } };
  window.Joomla = ctx.Joomla;
  vm.runInNewContext(source, ctx);
  const lines = () => { layout(); return new Set(lis.map((l) => l.top)).size; };
  return { lis, lines, style: (i) => JSON.stringify(lis[i].a.style.v) };
}

let fail = 0;
const check = (label, got, exp) => {
  const ok = JSON.stringify(got) === JSON.stringify(exp);
  fail += ok ? 0 : 1;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label}${ok ? '' : `\n   got=${JSON.stringify(got)}\n   exp=${JSON.stringify(exp)}`}`);
};

const item = (text, extra = {}) => ({ text, chars: 8, pl: 15, pr: 15, ls: 3, ...extra });

// the menu of merserwis.pl at 1280: five items, 533 px, the last one on a second line
{
  const m = menu({ width: 533, items: [item(87), item(55), item(82), item(17), item(47)] });
  check('wrapped menu fits on one line', m.lines(), 1);
  check('the padding of the links is smaller', parseFloat(JSON.parse(m.style(0))['padding-left'][0]) < 15, true);
}

// a menu that fits keeps the inline values of its links (Codex review: they were removed)
{
  const inline = { 'padding-left': '32px', 'padding-right': '32px', 'letter-spacing': '2px' };
  const m = menu({ width: 2000, items: [item(80, { inline }), item(80, { inline })] });
  check('menu on one line: inline values kept', m.style(0), JSON.stringify({ 'padding-left': ['32px', ''], 'padding-right': ['32px', ''], 'letter-spacing': ['2px', ''] }));
}

// a menu in hamburger mode (every item as wide as the menu) is left alone, its inline values too
{
  const inline = { 'padding-left': '20px' };
  const m = menu({ width: 330, stacked: true, items: [item(80, { inline }), item(80), item(80)] });
  check('hamburger: inline values kept', m.style(0), JSON.stringify({ 'padding-left': ['20px', ''] }));
  check('hamburger: nothing set', m.style(1), '{}');
}

// two items that wrap are fitted (Codex review: two items on two lines looked like a vertical menu)
{
  const m = menu({ width: 300, items: [item(120, { pl: 20, pr: 20, ls: 0 }), item(120, { pl: 20, pr: 20, ls: 0 })] });
  check('two wrapped items fit on one line', m.lines(), 1);
}

// a menu that does not fit even at the narrowest step is left as it was, its inline values given back
{
  const inline = { 'padding-left': '15px', 'padding-right': '15px' };
  const m = menu({ width: 300, items: [item(200, { inline }), item(200, { inline })] });
  check('cannot fit: two lines stay', m.lines(), 2);
  check('cannot fit: inline values given back', m.style(0), JSON.stringify({ 'padding-left': ['15px', ''], 'padding-right': ['15px', ''] }));
}

console.log(fail ? `${fail} FAILED` : 'ALL PASS');
process.exit(fail ? 1 : 0);
