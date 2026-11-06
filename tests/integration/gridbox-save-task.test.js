/**
 * Integration test of the editor's save of Gridbox 2.20.5 (editor.saveGridbox, the page as the form field
 * obj), on the docker Joomla + Gridbox:
 *  - the real editor (the copy's Gridbox, 2.20.5 or later): a new title saved with its own button is in the
 *    page and in the edit history (edited, the title from → to); while somebody else holds the lock of the
 *    page its save is refused and the title stays;
 *  - the requests themselves, as 2.20.5 sends them and as 2.20.4 did (editor.gridboxSave, the body):
 *    MerTools sees both before Gridbox — noted in the history, refused under somebody else's lock.
 *
 *   node tests/integration/gridbox-save-task.test.js [--install]
 */
'use strict';
const { check, sql, run, install } = require('./lib');

// a published page of the blog
const ID = Number(sql("SELECT p.id FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'blog' AND p.published = 1 ORDER BY p.id DESC LIMIT 1")[0][0]);

run(async ({ login, editor, uid }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  await editor(page, ID);
  const title = sql(`SELECT title FROM #__gridbox_pages WHERE id = ${ID}`)[0][0];
  const gridbox = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache, '$.version')) FROM #__extensions WHERE element = 'com_gridbox' AND type = 'component'")[0][0];
  const [major, minor, patch] = gridbox.split('.').map(Number);
  const newer = major > 2 || (major === 2 && (minor > 20 || (minor === 20 && (patch || 0) >= 5)));
  // a title saved with the editor's own button: what the editor asked, and what it answered
  const saveTitle = async (text) => {
    await page.evaluate((t) => { document.querySelector('#settings-dialog .page-title').value = t; }, text);
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && /task=editor\.(saveGridbox|gridboxSave|gridboxAjaxSave)\b/.test(r.url()), { timeout: 60000 });
    await page.click('.gridbox-save > span, .gridbox-save');
    const r = await sent;
    await page.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(800);
    return { task: /task=editor\.(\w+)/.exec(r.url())[1], answer: await r.text() };
  };
  const t1 = title + ' (MerTools test)';
  const t2 = title + ' (MerTools test 2)';
  try {
    check('the copy\'s Gridbox is 2.20.5 or later (' + gridbox + ')', newer);
    const first = await saveTitle(t1);
    check('real editor: saved with editor.saveGridbox', first.task === 'saveGridbox', first.task);
    check('…the new title in the page', sql(`SELECT title FROM #__gridbox_pages WHERE id = ${ID}`)[0][0] === t1, first.answer.slice(0, 120));
    const entry = sql(`SELECT action, changes FROM #__mertools_history WHERE page_id = ${ID} AND user_id = ${uid} ORDER BY id DESC LIMIT 1`)[0] || [];
    const changes = (() => { try { return JSON.parse(entry[1] || '[]'); } catch (e) { return []; } })();
    check('…in the edit history: edited, the title from → to', entry[0] === 'edited' && changes.some((c) => c.f === 'title' && c.from === title && c.to === t1), JSON.stringify(entry));
    // somebody else takes the lock of the page: the editor's save is refused before Gridbox
    sql(`DELETE FROM #__mertools_locks WHERE lock_key = 'page:${ID}'`);
    sql(`INSERT INTO #__mertools_locks (lock_key, page_id, user_id, user_name, tab, since, seen, active)
      VALUES ('page:${ID}', ${ID}, 999999, 'Inna Osoba (inna)', '', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())`);
    const second = await saveTitle(t2);
    check('real editor under somebody else\'s lock: refused', second.answer.includes('Inna Osoba'), second.answer.slice(0, 160));
    check('…the title stays', sql(`SELECT title FROM #__gridbox_pages WHERE id = ${ID}`)[0][0] === t1);
  } finally {
    sql(`DELETE FROM #__mertools_locks WHERE lock_key = 'page:${ID}' AND user_id = 999999`);
    sql(`UPDATE #__gridbox_pages SET title = CONVERT(UNHEX('${Buffer.from(title, 'utf8').toString('hex')}') USING utf8mb4) WHERE id = ${ID}`);
  }
  // the save as Gridbox 2.20.5 sends it (app.fetch: form data, obj = the page as JSON) and as 2.20.4 did (the body)
  const save = (task) => page.evaluate(async ({ task, id, title }) => {
    const obj = JSON.stringify({ page: { id, title }, items: {} });
    const opts = task === 'editor.gridboxSave' ? { method: 'POST', body: obj } : { method: 'POST', body: (() => { const f = new FormData(); f.append('obj', obj); return f; })() };
    const r = await fetch('index.php?option=com_gridbox&task=' + task, opts);
    return r.text();
  }, { task, id: ID, title });
  const count = () => Number(sql(`SELECT COUNT(*) FROM #__mertools_history WHERE page_id = ${ID} AND user_id = ${uid}`)[0][0]);
  for (const task of ['editor.saveGridbox', 'editor.gridboxSave']) {
    const before = count();
    await save(task);
    await page.waitForTimeout(800);
    check(task + ': noted in the edit history', count() === before + 1, `${before} → ${count()}`);
  }
  // somebody else holds the lock of the page: the save refused before Gridbox
  sql(`DELETE FROM #__mertools_locks WHERE lock_key = 'page:${ID}'`);
  sql(`INSERT INTO #__mertools_locks (lock_key, page_id, user_id, user_name, tab, since, seen, active)
    VALUES ('page:${ID}', ${ID}, 999999, 'Inna Osoba (inna)', '', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())`);
  try {
    for (const task of ['editor.saveGridbox', 'editor.gridboxSave']) {
      const answer = await save(task);
      check(task + ': refused while somebody else edits the page', answer.includes('Inna Osoba') && !answer.includes('(inna)'), answer.slice(0, 200));
    }
    check('…the page as it was', sql(`SELECT title FROM #__gridbox_pages WHERE id = ${ID}`)[0][0] === title);
  } finally {
    sql(`DELETE FROM #__mertools_locks WHERE lock_key = 'page:${ID}' AND user_id = 999999`);
  }
});
