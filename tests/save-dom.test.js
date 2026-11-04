// media/js/mertools-save.js in a real DOM (headless Chromium, no Joomla): the steps of Gridbox's undo
// history hold real elements, so the comparison of pages is the one the editor makes. What the editor
// itself changes is no change; what is in a text (its colours, its marks) is one.
// Run: node tests/save-dom.test.js   (Env: PLAYWRIGHT, the path of playwright-core)
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT || '/home/administrator2/.npm/_npx/705bc6b22212b352/node_modules/playwright-core');

const source = fs.readFileSync(path.join(__dirname, '../plg_system_mertools/media/js/mertools-save.js'), 'utf8');
const PAGE = `<header class="header "><div class="ba-section" id="s1">
  <div class="ba-item ba-item-text" id="t1"><div class="content-text" contenteditable="true"><p>Cena <span style="color:red">netto</span> <span class="lead">teraz</span></p></div>
  <div class="ba-edit-item"><span class="edit-settings"></span></div></div>
  <div class="ba-item ba-item-main-menu" id="m1"><div class="ba-menu-wrapper vertical-menu ba-hamburger-menu"><ul><li class="active">A</li></ul></div></div>
  <div class="ba-item ba-item-slideshow" id="sl"><ul class="slideshow-content" style="height:300px"><li class="active">1</li><li>2</li></ul></div>
</div></header><div id="global-css-sheets"><style></style></div>`;

let failed = 0;
const check = (name, got, want) => {
  const ok = got === want;
  if (!ok) failed++;
  console.log((ok ? 'OK  ' : 'FAIL') + ' ' + name.padEnd(76) + (ok ? '' : ' got ' + got + ', want ' + want));
};

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    // each case: a fresh editor whose page as opened is PAGE, then one step changed by `change`
    const after = async (change, items = null) => {
      await page.setContent('<button class="gridbox-save gridbox-enabled-save">Save</button>'
        + '<script type="application/json" id="mertools-save-config">' + JSON.stringify({ warn: true, autosave: 0, saved: ['saved'], texts: { unsaved: 'x' } }) + '</script>');
      await page.evaluate((html) => {
        const step = (h, items) => { const div = document.createElement('div'); div.innerHTML = h; return { content: div, items }; };
        window.mkStep = step;
        window.app = { history: [step(html, { t1: { desktop: { color: '@text' } } })], hIndex: 1, checkModule() {}, showNotice() {} };
      }, PAGE);
      await page.addScriptTag({ content: source });
      await page.waitForTimeout(1100);
      await page.mouse.click(5, 5); // something done in the editor, then Gridbox's step
      await page.evaluate(([html, items]) => {
        app.history.push(mkStep(html, items || { t1: { desktop: { color: '@text' } } }));
        app.hIndex = app.history.length;
      }, [change(PAGE), items]);
      await page.waitForTimeout(1100);
      return page.evaluate(() => document.querySelector('.gridbox-save').classList.contains('mt-unsaved'));
    };

    check('the same page: no change', await after((h) => h), false);
    check('the editor\'s bars, a placeholder, sliders, menus, the header, its styles: no change', await after((h) => h
      .replace('<div class="ba-edit-item">', '<div class="ba-edit-item" style="display:none">')
      .replace('class="header "', 'class="header not-default-header"')
      .replace('vertical-menu ba-hamburger-menu', 'ba-hamburger-menu vertical-menu')
      .replace('style="height:300px"', 'style="height:280px"')
      .replace('<li class="active">1</li><li>2</li>', '<li>1</li><li class="active">2</li>')
      .replace('<style></style>', '<style>body{color:red}</style>')
      .replace('</header>', '</header><div class="sortable-placeholder" style="left:3px"></div>')), false);
    check('a text in focus (marks of the text editor on it): no change', await after((h) => h
      .replace('<div class="content-text" contenteditable="true">', '<div class="cke_editable cke_focus content-text" contenteditable="true" title="Rich Text Editor">')), false);
    check('an empty default of a setting filled in: no change', await after((h) => h, { t1: { desktop: { color: '@text' }, presets: '' } }), false);
    check('the colour of a part of a text changed: a change', await after((h) => h.replace('color:red', 'color:blue')), true);
    check('a class of a part of a text changed: a change', await after((h) => h.replace('class="lead"', 'class="lead hidden"')), true);
    check('a word of a text changed: a change', await after((h) => h.replace('teraz', 'jutro')), true);
    check('a setting changed: a change', await after((h) => h, { t1: { desktop: { color: '@primary' } } }), true);
  } finally {
    await browser.close();
  }
  console.log(failed ? `${failed} FAILED` : 'ALL PASS');
  process.exit(failed ? 1 : 0);
})();
