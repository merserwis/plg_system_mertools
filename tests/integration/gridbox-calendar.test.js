/**
 * Integration test of Gridbox's calendar in its panel (1.19.2), on the docker Joomla + Gridbox 2.20.5:
 * the settings of a page in an app's list, tab Publication, the calendar of Start publishing opened by
 * its icon shows the days of a month (Gridbox's panel asks the site for it with the panel's token: the
 * site answered "403 Invalid token" in its place); the panel's other call to the site, one that changes
 * something (store.removeAttachment), still refused without the site's token.
 *
 *   node tests/integration/gridbox-calendar.test.js [--install]
 */
'use strict';
const { ADMIN, check, sql, run, install } = require('./lib');

const BLOG_APP = Number(sql("SELECT a.id FROM #__gridbox_app a JOIN #__gridbox_pages p ON p.app_id = a.id WHERE a.type = 'blog' AND p.page_category <> 'trashed' ORDER BY a.id LIMIT 1")[0][0]);

run(async ({ login }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  await page.goto(ADMIN + 'index.php?option=com_gridbox&view=apps&id=' + BLOG_APP);
  const answers = [];
  page.on('response', (r) => { if (r.url().includes('task=calendar.render')) answers.push(r.status()); });
  // the settings of the first page, as Gridbox's context menu opens them
  await page.click('tbody tr .select-td ~ td', { button: 'right' });
  await page.dispatchEvent('.page-context-menu span.page-settings', 'mousedown');
  await page.waitForSelector('#settings-dialog.in, .page-settings-modal.in, .modal.in #published_on', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(800);
  await page.locator('a:visible', { hasText: /publi/i }).first().click();
  await page.waitForTimeout(400);
  await page.click('#calendar-button');
  const ok = await page.waitForSelector('.ba-gridbox-calendar .ba-gridbox-calendar-inner', { state: 'attached', timeout: 20000 }).then(() => true, () => false);
  const text = await page.evaluate(() => (document.querySelector('.ba-gridbox-calendar') || {}).textContent || '');
  check('Start publishing: the calendar answered 200', answers.length > 0 && answers.every((s) => s === 200), JSON.stringify(answers));
  check('Start publishing: the calendar shows a month', ok && !/Invalid Token|403/i.test(text) && /\b28\b/.test(text), text.replace(/\s+/g, ' ').slice(0, 200));
  // a change asked of the site with the panel's token: still Gridbox's refusal
  const other = await page.evaluate(() => app.fetch(JUri + 'index.php?option=com_gridbox&task=store.removeAttachment', { id: 0 }));
  check('a change from the panel to the site: still refused without the site\'s token', /Invalid Token/i.test(other), other.slice(0, 200));
});
