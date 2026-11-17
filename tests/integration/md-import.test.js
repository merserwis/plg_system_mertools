/**
 * Integration test of the Markdown import into a new blog post, in the Gridbox editor of the docker
 * Joomla: a document of a text, a heading and another text (fixtures/post.md, shaped as the document reported to
 * stop after its heading) comes in whole — every block a module with its own content, in order, and Gridbox's
 * empty text of the new post gone.
 *
 *   node tests/integration/md-import.test.js [--install]
 */
'use strict';
const path = require('path');
const { check, sql, run, install } = require('./lib');

const FILE = path.join(__dirname, 'fixtures/post.md');
// a blog post of the copy: its app, category and theme for the new one
const SRC = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'blog' AND p.page_category <> 'trashed'")[0][0]);

run(async ({ login, editor }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  // a new post as Gridbox makes it (its "create" of the list of posts: the post's template with its empty text)
  const [appId, category, theme] = sql(`SELECT app_id, page_category, theme FROM #__gridbox_pages WHERE id = ${SRC}`)[0];
  const page = await editor(admin, SRC);
  const title = 'MT import ' + Date.now();
  await page.evaluate(async ([a, c, t, title]) => {
    const body = new FormData();
    body.append('app_id', a); body.append('category', c); body.append('page_theme', t); body.append('title', title); body.append('published', '0');
    body.append(Joomla.getOptions('csrf.token'), '1');
    await fetch('index.php?option=com_gridbox&task=gridbox.createPage', { method: 'POST', body });
  }, [appId, category, theme, title]);
  const id = Number((sql(`SELECT id FROM #__gridbox_pages WHERE title = '${title}'`)[0] || [0])[0]);
  check('a new post made', id > 0, String(id));
  try {
    await editor(page, id);
    const before = await page.evaluate(() => [...app.editor.document.querySelectorAll('#ba-edit-section .ba-item-text .content-text')].map((e) => e.textContent.trim()));
    check('a new post: Gridbox\'s empty text', before.some((t) => /zacznij pisać|Start Typing/i.test(t)), JSON.stringify(before));
    await page.setInputFiles('input[type=file][accept*=".md"]', FILE);
    await page.waitForFunction(() => !document.querySelector('body > div[style*="z-index:99999"]'), null, { timeout: 90000 });
    await page.waitForTimeout(1500);
    const read = () => page.evaluate(() => [...app.editor.document.querySelectorAll('#ba-edit-section .ba-item-text, #ba-edit-section .ba-item-headline')]
      .map((e) => ({ type: e.classList.contains('ba-item-headline') ? 'h' : 't',
        text: (e.querySelector('.content-text, .headline-wrapper > *') || e).textContent.replace(/\s+/g, ' ').trim() })));
    const items = await read();
    const notices = await page.evaluate(() => [...document.querySelectorAll('#ba-notification, .ba-notification-message, #ba-notification p')].map((n) => n.textContent.trim()).filter(Boolean));
    check('three modules: text, heading, text', items.map((i) => i.type).join('') === 'tht', JSON.stringify(items));
    check('first text whole', items[0] && items[0].text.startsWith('Pierwszy akapit') && items[0].text.endsWith('pierwszy tekst.'), JSON.stringify(items[0]));
    check('the heading', items[1] && items[1].text === 'Posłuchaj koncertu „Tytuł”', JSON.stringify(items[1]));
    check('the text after the heading whole', items[2] && items[2].text.startsWith('Poniżej znajdziecie') && items[2].text.endsWith('— Zespół'), JSON.stringify(items[2]));
    check('no empty text left', !items.some((i) => /zacznij pisać|Start Typing/i.test(i.text)), JSON.stringify(items));
    check('no failure notice', !notices.some((n) => /nie udało|could not|failed/i.test(n)), JSON.stringify(notices));
    // saved with Gridbox's own save and opened again: the same three modules
    await page.click('.gridbox-save > span, .gridbox-save');
    await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 });
    await page.waitForTimeout(1500);
    await editor(page, id);
    const again = await read();
    check('saved and opened again: the same modules and texts', JSON.stringify(again) === JSON.stringify(items), JSON.stringify(again));
  } finally {
    if (id > 0) sql(`DELETE FROM #__gridbox_pages WHERE id = ${id}`);
  }
});
