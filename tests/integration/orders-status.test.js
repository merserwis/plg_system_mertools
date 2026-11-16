/**
 * Integration test of the status of many orders at once (1.15.0), on the docker Joomla + Gridbox: three
 * orders of the test (customers @example.invalid, removed afterwards), two ticked in Gridbox's list get
 * Completed and a comment through the button in the toolbar and Gridbox's own window of a status — in
 * the database, in their status history (by the user, with the comment) and in the list —, the third
 * keeps its status; an order's own window is Gridbox's again (its history, one order); with the setting
 * off there is no button.
 *
 *   node tests/integration/orders-status.test.js [--install]
 */
'use strict';
const { ADMIN, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const TAG = 'MTBULK';

const cleanup = () => {
  const ids = sql(`SELECT id FROM #__gridbox_store_orders WHERE order_number LIKE '#${TAG}%'`).map((r) => r[0]);
  if (!ids.length) return;
  const list = ids.join(',');
  sql(`DELETE FROM #__gridbox_store_orders_status_history WHERE order_id IN (${list})`);
  sql(`DELETE FROM #__gridbox_store_order_customer_info WHERE order_id IN (${list})`);
  sql(`DELETE FROM #__gridbox_store_orders WHERE id IN (${list})`);
};

run(async ({ login, uid }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  cleanup();
  const ids = [];
  try {
    for (let i = 1; i <= 3; i++) {
      // the newest orders: at the top of the list Gridbox opens with (by date, newest first)
      sql(`INSERT INTO #__gridbox_store_orders (date, status, published, unread, order_number, subtotal, tax, total, currency_symbol, currency_position, params, tax_mode)
        VALUES (DATE_ADD(NOW(), INTERVAL ${i} MINUTE), 'new', 1, 0, '#${TAG}${i}', '100', '23', '123', 'zł', 'right-currency-position', '{}', 'excl')`);
      const id = sql(`SELECT id FROM #__gridbox_store_orders WHERE order_number = '#${TAG}${i}'`)[0][0];
      ids.push(id);
      sql(`INSERT INTO #__gridbox_store_order_customer_info (order_id, title, type, value, options, order_list) VALUES (${id}, 'E-mail', 'email', 'mt${i}@example.invalid', '{}', 1)`);
    }
    const [a, b, c] = ids;
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.orders_bulk_status', '1') WHERE ${PLUGIN}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const button = page.locator('#toolbar-mt-status button');
    check('the button in the toolbar', await button.count() === 1);
    check('…disabled with nothing ticked', await button.evaluate((el) => el.classList.contains('disabled')));
    // Gridbox's box of a row: its label (0 px wide, the icons over it) clicked as a click on it would
    const tick = (id) => page.evaluate((id) => document.querySelector(`tr[data-id="${id}"] td.select-td label`).click(), id);
    await tick(a);
    await tick(b);
    await page.waitForTimeout(100);
    check('…enabled with orders ticked', !(await button.evaluate((el) => el.classList.contains('disabled'))));
    await button.click();
    await page.waitForSelector('#orders-status-modal.in, #orders-status-modal[style*="block"]', { timeout: 5000 });
    const shown = await page.evaluate(() => {
      const m = document.getElementById('orders-status-modal');
      return { title: (m.querySelector('.mt-orders-bulk-title') || {}).textContent || '', nav: getComputedStyle(m.querySelector('.nav-tabs')).display,
        status: m.querySelector('.orders-status-select input[type="hidden"]').value };
    });
    check('Gridbox\'s window for the two orders ticked', /\b2\b/.test(shown.title) && shown.nav === 'none' && shown.status === '', JSON.stringify(shown));
    await page.click('#orders-status-modal .orders-status-select input[type="text"]');
    await page.click('#orders-status-modal .orders-status-select li[data-value="completed"]');
    await page.fill('#orders-status-modal textarea', 'MerTools bulk test');
    if (process.env.MT_SHOT) await page.screenshot({ path: process.env.MT_SHOT });
    const posts = [];
    page.on('request', (r) => { if (/task=orders\.updateStatus/.test(r.url())) posts.push(r.postData() || ''); });
    await page.click('#orders-status-modal .apply-order-status');
    await page.waitForFunction(() => !document.getElementById('orders-status-modal').dataset.mtBulk, null, { timeout: 30000 });
    await page.waitForTimeout(800);
    check('one request of Gridbox per order', posts.length === 2, String(posts.length));
    const rows = sql(`SELECT id, status FROM #__gridbox_store_orders WHERE id IN (${ids.join(',')}) ORDER BY id`);
    const st = Object.fromEntries(rows.map((r) => [r[0], r[1]]));
    check('the two ticked: Completed in the database', st[a] === 'completed' && st[b] === 'completed', JSON.stringify(st));
    check('the third: as it was', st[c] === 'new', JSON.stringify(st));
    const hist = sql(`SELECT order_id, status, comment, user_id FROM #__gridbox_store_orders_status_history WHERE order_id IN (${ids.join(',')}) ORDER BY order_id`);
    check('their status history: the status, the comment, the user',
      hist.length === 2 && hist.every((h) => h[1] === 'completed' && h[2] === 'MerTools bulk test' && Number(h[3]) === uid), JSON.stringify(hist));
    const cells = await page.evaluate((list) => list.map((id) => document.querySelector(`tr[data-id="${id}"] .order-status-title`).textContent.trim()), ids);
    const titles = await page.evaluate(() => [app.statuses.completed.title, app.statuses.new.title]);
    check('the list shows the new status', cells[0] === titles[0] && cells[1] === titles[0] && cells[2] === titles[1], JSON.stringify(cells));
    check('…and nothing ticked any more', await page.evaluate(() => document.querySelectorAll('#adminForm input[name="cid[]"]:checked').length) === 0);
    const notice = await page.locator('#ba-notification p').textContent().catch(() => '');
    check('Gridbox\'s notice says how many', /\b2\b/.test(notice || ''), notice);

    // an order's own window: Gridbox's (one order, its history)
    await page.click(`tr[data-id="${c}"] .order-number-td`);
    await page.waitForSelector('.edit-order-status', { state: 'visible', timeout: 15000 });
    await page.click('.edit-order-status');
    await page.waitForFunction(() => document.getElementById('orders-status-modal').getAttribute('data-id'), null, { timeout: 10000 });
    await page.waitForTimeout(500);
    const own = await page.evaluate(() => {
      const m = document.getElementById('orders-status-modal');
      return { bulk: m.dataset.mtBulk || '', title: !!m.querySelector('.mt-orders-bulk-title'), nav: getComputedStyle(m.querySelector('.nav-tabs')).display, id: m.getAttribute('data-id') };
    });
    check('an order\'s own window: Gridbox\'s', !own.bulk && !own.title && own.nav !== 'none' && own.id === String(c), JSON.stringify(own));

    // one order whose change fails (an error page, as when Gridbox's e-mail breaks): named, its row and tick kept;
    // while the orders are being changed, leaving the page asks first
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const other = await page.evaluate(() => Object.keys(app.statuses).find((k) => !['undefined', 'completed', 'new'].includes(k)));
    let release;
    const held = new Promise((r) => { release = r; });
    await page.route(/task=orders\.updateStatus/, async (route) => {
      const body = route.request().postData() || '';
      if (new RegExp('name="id"\\r\\n\\r\\n' + c + '\\r').test(body)) {
        await held;
        return route.fulfill({ status: 500, contentType: 'text/html', body: '<html><body>Error</body></html>' });
      }
      return route.continue();
    });
    for (const id of ids) await tick(id);
    await page.waitForTimeout(100);
    await button.click();
    await page.waitForSelector('#orders-status-modal .orders-status-select', { state: 'visible' });
    await page.click('#orders-status-modal .orders-status-select input[type="text"]');
    await page.click(`#orders-status-modal .orders-status-select li[data-value="${other}"]`);
    await page.click('#orders-status-modal .apply-order-status');
    const leave = () => page.evaluate(() => {
      const e = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(e);
      return e.defaultPrevented;
    });
    await page.waitForTimeout(1500);
    check('while the orders are changed: leaving the page asks', await leave());
    release();
    await page.waitForFunction(() => !document.getElementById('orders-status-modal').dataset.mtBulk, null, { timeout: 30000 });
    await page.waitForTimeout(800);
    check('…and no more once done', !(await leave()));
    await page.unroute(/task=orders\.updateStatus/);
    const st2 = Object.fromEntries(sql(`SELECT id, status FROM #__gridbox_store_orders WHERE id IN (${ids.join(',')})`).map((r) => [r[0], r[1]]));
    check('the others changed, the failed one not', st2[a] === other && st2[b] === other && st2[c] === 'new', JSON.stringify(st2));
    const failNotice = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    check('the notice names the failed order', failNotice.includes('#' + TAG + '3') && !failNotice.includes('#' + TAG + '1'), failNotice);
    const kept = await page.evaluate((c) => ({ title: document.querySelector(`tr[data-id="${c}"] .order-status-title`).textContent.trim(),
      ticked: document.querySelector(`tr[data-id="${c}"] input[name="cid[]"]`).checked, newTitle: app.statuses.new.title }), c);
    check('…its row keeps its status and its tick', kept.title === kept.newTitle && kept.ticked, JSON.stringify(kept));

    // the setting off: no button
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.orders_bulk_status', '0') WHERE ${PLUGIN}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    check('setting off: no button', await page.locator('#toolbar-mt-status').count() === 0);
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    cleanup();
  }
});
