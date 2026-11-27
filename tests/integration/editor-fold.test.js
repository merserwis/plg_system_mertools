/**
 * Integration test of folding the fields in the Gridbox editor (media/js/mertools-fold.js) kept on the
 * user's account, on a real Joomla + Gridbox in docker (the test copy gbx-sitemap-test): a temporary
 * Super User opens the editor of a product in one browser, folds a field, and finds it folded in another
 * browser (a context of its own, nothing shared but the account); an account that keeps no folds yet
 * takes over the ones its browser kept before (localStorage, MerTools 1.7.1–1.10); a folded field is out of
 * sight until Gridbox's "show hidden elements" of the left bar shows it, greyed. The user's folds go
 * with the temporary user at the end.
 *
 *   node tests/integration/editor-fold.test.js            the MerTools installed on the copy
 *   node tests/integration/editor-fold.test.js --install  first installs pkg_mertools-<version>.zip (./build.sh)
 *
 * Env: MT_CONTAINER (gbx-sitemap-test), MT_URL (http://127.0.0.1:18096), PLAYWRIGHT (path of playwright-core).
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PRODUCT = Number(sql("SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id WHERE a.type = 'products' AND p.page_category <> 'trashed'")[0][0]);

run(async ({ login, editor, uid }) => {
  const kept = () => {
    const row = sql(`SELECT profile_value FROM #__user_profiles WHERE user_id = ${uid} AND profile_key = 'mertools.foldedfields'`)[0];
    return row ? JSON.parse(row[0]) : null;
  };
  const folded = (page, key) => page.evaluate((key) => !!document.querySelector(`.blog-post-editor-options-group[data-field-key="${key}"].mt-folded`), key);
  const eye = (page, key) => page.click(`.blog-post-editor-options-group[data-field-key="${key}"] .mt-fold`);
  // a folded field is out of sight until Gridbox's "show hidden elements" of the left bar; then greyed
  const look = (page, key) => page.evaluate((key) => {
    const g = document.querySelector(`.blog-post-editor-options-group[data-field-key="${key}"]`);
    const el = g.querySelector(':scope > .blog-post-editor-group-element');
    return { shown: g.offsetParent !== null, opacity: getComputedStyle(el).opacity };
  }, key);
  // a click of the mouse (Gridbox turns the left bar off while the fields are open: the toggle must stay on)
  const hidden = async (page, show) => {
    await page.click(show ? '.show-hidden-elements a' : '.hide-hidden-elements a', { timeout: 5000 });
    await page.waitForFunction((on) => document.body.classList.contains('mt-show-folded') === on, show, { timeout: 5000 });
  };
  const control = (page, key) => page.evaluate((key) => {
    const g = document.querySelector(`.blog-post-editor-options-group[data-field-key="${key}"]`);
    const input = g.querySelector('input:not([type="hidden"]), textarea, select');
    return !!input && input.offsetParent !== null;
  }, key);

  // the first browser: before 1.11 it kept "price" folded by itself
  let page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  // the site's storage, set on a file of the site (no scripts of the copy's home page)
  await page.goto(URL_ + '/robots.txt');
  await page.evaluate(() => localStorage.setItem('mertools-folded-fields', JSON.stringify(['price'])));
  check('account: no folds kept at first', kept() === null);
  await editor(page, PRODUCT);
  await page.waitForTimeout(3000);
  check('browser\'s own fold shown', await folded(page, 'price'));
  check('…and taken over by the account', JSON.stringify(kept()) === '["price"]', JSON.stringify(kept()));
  check('a folded field out of sight, an unfolded one shown', !(await look(page, 'price')).shown && (await look(page, 'sku')).shown,
    JSON.stringify([await look(page, 'price'), await look(page, 'sku')]));
  await hidden(page, true);
  const greyed = await look(page, 'price');
  check('"show hidden elements": the folded field shown, greyed', greyed.shown && Number(greyed.opacity) < 1, JSON.stringify(greyed));
  await hidden(page, false);
  check('"hide hidden elements": out of sight again', !(await look(page, 'price')).shown);
  // a required field Gridbox finds empty on saving: shown with its control, folded and out of sight or not
  await page.evaluate(() => document.querySelector('.blog-post-editor-options-group[data-field-key="price"]').classList.add('ba-alert-label'));
  check('a required field marked empty: shown with its control though folded', (await look(page, 'price')).shown && (await control(page, 'price')));
  await page.evaluate(() => document.querySelector('.blog-post-editor-options-group[data-field-key="price"]').classList.remove('ba-alert-label'));
  // folded with the keyboard out of sight: the focus to the next eye, not lost
  await page.focus('.blog-post-editor-options-group[data-field-key="sku"] .mt-fold');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1500);
  const focus = await page.evaluate(() => { const a = document.activeElement; return a && a.classList.contains('mt-fold') && a.offsetParent !== null; });
  check('folded with the keyboard: out of sight, the focus on another eye', !(await look(page, 'sku')).shown && focus);
  check('a field folded: kept on the account', JSON.stringify(kept()) === '["sku","price"]', JSON.stringify(kept()));
  await hidden(page, true);
  await eye(page, 'price');
  await page.waitForTimeout(1500);
  check('a field unfolded: off the account', JSON.stringify(kept()) === '["sku"]', JSON.stringify(kept()));
  // unfolded while hidden ones are shown: it stays, with its control, when they are hidden again
  await hidden(page, false);
  check('unfolded, hidden ones hidden again: the field shown with its control, not greyed',
    (await look(page, 'price')).shown && (await control(page, 'price')) && Number((await look(page, 'price')).opacity) === 1, JSON.stringify(await look(page, 'price')));
  await page.close({ runBeforeUnload: false });

  // another browser (nothing of the first one): the account's folds
  page = await login('light');
  await page.goto(URL_ + '/robots.txt');
  await page.evaluate(() => localStorage.setItem('mertools-folded-fields', JSON.stringify(['stock'])));
  await editor(page, PRODUCT);
  await page.waitForTimeout(3000);
  await hidden(page, true);
  check('another browser: the account\'s fold shown', await folded(page, 'sku'));
  check('…its own old folds not taken over (the account keeps some)', !(await folded(page, 'stock')) && JSON.stringify(kept()) === '["sku"]', JSON.stringify(kept()));
  await eye(page, 'sku');
  await page.waitForTimeout(1500);
  check('all unfolded: the account keeps an empty list', JSON.stringify(kept()) === '[]', JSON.stringify(kept()));

  // two folds in a row, the answer to the first one late: the later fold is not undone by it
  let first = true;
  await page.route('**/index.php*', async (route) => {
    const req = route.request();
    if (req.method() !== 'POST' || !String(req.postData() || '').includes('mertools_fold')) return route.continue();
    const res = await route.fetch();
    if (first) {
      first = false;
      await new Promise((r) => setTimeout(r, 1500));
    }
    await route.fulfill({ response: res });
  });
  await eye(page, 'price');
  await eye(page, 'sku');
  await page.waitForTimeout(4000);
  check('two folds, the first answer late: both shown folded', (await folded(page, 'price')) && (await folded(page, 'sku')));
  check('…and both kept on the account', JSON.stringify(kept()) === '["sku","price"]', JSON.stringify(kept()));
  await page.unroute('**/index.php*');
  await eye(page, 'price');
  await eye(page, 'sku');
  await page.waitForTimeout(1500);

  // the endpoint: a form token and a logged-in user
  const status = await page.evaluate(async () => (await fetch('/index.php', { method: 'POST', body: new URLSearchParams({ mertools_fold: 'toggle', key: 'sku', fold: '1' }) })).status);
  check('without the form token: refused', status === 403, String(status));
  check('…and nothing kept', JSON.stringify(kept()) === '[]', JSON.stringify(kept()));
  await page.close({ runBeforeUnload: false });
});
