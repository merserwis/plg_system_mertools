/**
 * Integration test of the status of many orders at once (1.15.0), on the docker Joomla + Gridbox: three
 * orders of the test (customers @example.invalid, removed afterwards), two ticked in Gridbox's list get
 * Completed and a comment through the button in the toolbar and Gridbox's own window of a status — in
 * the database, in their status history (by the user, with the comment) and in the list —, the third
 * keeps its status; with Mark as read (ticked by default) the two unread are read — database, grey rows,
 * the count of new orders in the menu —, the third unread; an order's own window is Gridbox's again (its
 * history, one order); Mark as read alone, without a status: orders read, status and history unchanged,
 * with the box unticked the window does not apply; Send the customer an e-mail: ticked, Gridbox's e-mails
 * sent (a sendmail of the test in the container keeps them) and its delayed one queued, unticked none of
 * them, the status and its history changed; asked so without the form token, refused and nothing changed;
 * Select unread ticks the grey rows only; Mark as unread makes a read order grey and counted again; the
 * Mark all as read (asked first) reads every new order, the counts of the menu go; Download PDF gives
 * Gridbox's PDF of the orders ticked, in a ZIP for two, as it is for one; the dark mode dims Gridbox's
 * Apply that cannot be used; with the setting off there are no buttons.
 *
 *   node tests/integration/orders-status.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { ADMIN, C, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const TAG = 'MTBULK';

const cleanup = () => {
  const ids = sql(`SELECT id FROM #__gridbox_store_orders WHERE order_number LIKE '#${TAG}%'`).map((r) => r[0]);
  if (!ids.length) return;
  const list = ids.join(',');
  sql(`DELETE FROM #__gridbox_store_orders_status_history WHERE order_id IN (${list})`);
  sql(`DELETE FROM #__gridbox_email_delay WHERE order_id IN (${list})`);
  sql(`DELETE FROM #__gridbox_store_order_customer_info WHERE order_id IN (${list})`);
  sql(`DELETE FROM #__gridbox_store_orders WHERE id IN (${list})`);
};

// the e-mails of the copy: a sendmail of the test keeps them in /tmp/mt-mail (PHP's mail() and Joomla's sendmail use it)
const root = (cmd) => execFileSync('docker', ['exec', '-u', '0', C, 'sh', '-c', cmd], { encoding: 'utf8' });
const mails = () => Number(root('ls /tmp/mt-mail | wc -l').trim());
const store = sql("SELECT id, `key` FROM #__gridbox_api WHERE service = 'store'")[0];
// the new orders of the copy, new again afterwards (Mark all as read reads them)
const fresh0 = sql('SELECT id FROM #__gridbox_store_orders WHERE unread = 1').map((r) => r[0]);
const setStore = (json) => sql(`UPDATE #__gridbox_api SET \`key\` = CONVERT(UNHEX('${Buffer.from(json, 'utf8').toString('hex')}') USING utf8mb4) WHERE id = ${store[0]}`);

run(async ({ login, uid }) => {
  const ids = [];
  try {
    // in the try: restored by the finally whatever fails; an original kept aside earlier never overwritten
    root('[ -e /usr/sbin/sendmail ] && [ ! -e /usr/sbin/sendmail.mt-orig ] && ! grep -q mt-mail /usr/sbin/sendmail && mv /usr/sbin/sendmail /usr/sbin/sendmail.mt-orig;'
      + ' mkdir -p /tmp/mt-mail && chmod 777 /tmp/mt-mail && rm -f /tmp/mt-mail/*;'
      + ' printf \'#!/bin/sh\\ncat > /tmp/mt-mail/$(date +%%s%%N).eml\\n\' > /usr/sbin/sendmail && chmod 755 /usr/sbin/sendmail');
    const page = await login('light');
    if (process.argv.includes('--install')) {
      await install(page);
    }
    cleanup();
    for (let i = 1; i <= 3; i++) {
      // the newest orders: at the top of the list Gridbox opens with (by date, newest first)
      sql(`INSERT INTO #__gridbox_store_orders (date, status, published, unread, order_number, subtotal, tax, total, currency_symbol, currency_position, params, tax_mode)
        VALUES (DATE_ADD(NOW(), INTERVAL ${i} MINUTE), 'new', 1, 1, '#${TAG}${i}', '100', '23', '123', 'zł', 'right-currency-position', '{}', 'excl')`);
      const id = sql(`SELECT id FROM #__gridbox_store_orders WHERE order_number = '#${TAG}${i}'`)[0][0];
      ids.push(id);
      sql(`INSERT INTO #__gridbox_store_order_customer_info (order_id, title, type, value, options, order_list) VALUES (${id}, 'E-mail', 'email', 'mt${i}@example.invalid', '{}', 1)`);
    }
    const [a, b, c] = ids;
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.orders_bulk_status', '1') WHERE ${PLUGIN}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    // the count of new orders in Gridbox's menu (the store's own includes bookings, as in Gridbox)
    const counts = () => page.evaluate(() => [...document.querySelectorAll('.unread-comments-count[data-type="orders"]')].map((c) => Number(c.textContent)));
    const unreadDb = () => Object.fromEntries(sql(`SELECT id, unread FROM #__gridbox_store_orders WHERE id IN (${ids.join(',')})`).map((r) => [r[0], Number(r[1])]));
    const grey = () => page.evaluate((list) => list.map((id) => document.querySelector(`tr[data-id="${id}"]`).classList.contains('unread-order')), ids);
    const count0 = await counts();
    check('the count of new orders in the menu', count0.length > 0 && count0.every((n) => n >= 3), JSON.stringify(count0));
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
    const box = '#orders-status-modal #mt-orders-bulk-read';
    check('…Mark as read, ticked', await page.locator(box).isChecked());
    const seen = await page.evaluate(() => {
      const r = document.querySelector('label[for="mt-orders-bulk-read"]').getBoundingClientRect();
      const body = document.querySelector('#orders-status-modal .modal-footer').getBoundingClientRect();
      return r.height > 0 && r.bottom <= body.top;
    });
    check('…seen above the buttons without scrolling', seen);
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
    const ur = unreadDb();
    check('the two ticked: read in the database, the third not', ur[a] === 0 && ur[b] === 0 && ur[c] === 1, JSON.stringify(ur));
    check('…their rows no longer grey, the third still', JSON.stringify(await grey()) === '[false,false,true]', JSON.stringify(await grey()));
    const count1 = await counts();
    check('…the count in the menu two less', count1.length === count0.length && count1.every((n, i) => n === count0[i] - 2), JSON.stringify([count0, count1]));
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
    check('…without Mark as read', await page.locator(box).count() === 0);

    // Mark as read alone: no status, the status and its history unchanged; with the box unticked, no Apply
    sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id IN (${a}, ${c})`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const count2 = await counts();
    for (const id of ids) await tick(id);
    await page.waitForTimeout(100);
    await button.click();
    await page.waitForSelector('#orders-status-modal label[for="mt-orders-bulk-read"]', { state: 'visible' });
    const active = () => page.evaluate(() => document.querySelector('#orders-status-modal .apply-order-status').classList.contains('active-button'));
    check('without a status: Apply with Mark as read', await active());
    if (process.env.MT_SHOT) await page.screenshot({ path: process.env.MT_SHOT.replace(/\.png$/, '-read.png') });
    await page.click('#orders-status-modal label[for="mt-orders-bulk-read"]');
    check('…not with it unticked (its text clicked)', !(await page.locator(box).isChecked()) && !(await active()));
    await page.click('#orders-status-modal .apply-order-status');
    await page.waitForTimeout(500);
    check('…and Apply does nothing then', unreadDb()[a] === 1 && await page.evaluate(() => !!document.getElementById('orders-status-modal').dataset.mtBulk));
    await page.click('#orders-status-modal label[for="mt-orders-bulk-read"]');
    const statusPosts = [];
    page.on('request', (r) => { if (/task=orders\.updateStatus/.test(r.url())) statusPosts.push(1); });
    await page.click('#orders-status-modal .apply-order-status');
    await page.waitForFunction(() => !document.getElementById('orders-status-modal').dataset.mtBulk, null, { timeout: 30000 });
    await page.waitForTimeout(800);
    const ur2 = unreadDb();
    const st3 = Object.fromEntries(sql(`SELECT id, status FROM #__gridbox_store_orders WHERE id IN (${ids.join(',')})`).map((r) => [r[0], r[1]]));
    const hist3 = sql(`SELECT COUNT(*) FROM #__gridbox_store_orders_status_history WHERE order_id IN (${ids.join(',')})`)[0][0];
    check('all three read, no request of a status', ur2[a] === 0 && ur2[b] === 0 && ur2[c] === 0 && statusPosts.length === 0, JSON.stringify([ur2, statusPosts.length]));
    check('…their status and history unchanged', st3[a] === 'completed' && st3[b] === 'completed' && st3[c] === 'new' && Number(hist3) === 2, JSON.stringify([st3, hist3]));
    check('…no grey rows, nothing ticked', JSON.stringify(await grey()) === '[false,false,false]'
      && await page.evaluate(() => document.querySelectorAll('#adminForm input[name="cid[]"]:checked').length) === 0);
    const count3 = await counts();
    check('…the count in the menu two less', count3.every((n, i) => n === count2[i] - 2) && count3.length === count2.filter((n) => n > 2).length, JSON.stringify([count2, count3]));
    const readNotice = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    check('…the notice counts the two marked, not the three ticked', /\b2\b/.test(readNotice) && !/\b3\b/.test(readNotice) && readNotice !== notice, readNotice);

    // a status changed but marking read failing (an error page of Gridbox's opening): named, the row still grey, the status not again
    sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id = ${b}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    await page.route(/task=orders\.getOrder/, (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<html><body>Error</body></html>' }));
    const posts4 = [];
    const onReq = (r) => { if (/task=orders\.updateStatus/.test(r.url())) posts4.push(1); };
    page.on('request', onReq);
    await tick(b);
    await page.waitForTimeout(100);
    await button.click();
    await page.waitForSelector('#orders-status-modal label[for="mt-orders-bulk-read"]', { state: 'visible' });
    await page.click('#orders-status-modal .orders-status-select input[type="text"]');
    await page.click('#orders-status-modal .orders-status-select li[data-value="new"]');
    await page.click('#orders-status-modal .apply-order-status');
    await page.waitForFunction(() => !document.getElementById('orders-status-modal').dataset.mtBulk, null, { timeout: 30000 });
    await page.waitForTimeout(800);
    page.off('request', onReq);
    await page.unroute(/task=orders\.getOrder/);
    const n4 = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    const st4 = sql(`SELECT status, unread FROM #__gridbox_store_orders WHERE id = ${b}`)[0];
    check('status changed, marking read failed: the notice names it', n4.includes('#' + TAG + '2') && st4[0] === 'new' && Number(st4[1]) === 1 && posts4.length === 1,
      JSON.stringify([n4, st4, posts4.length]));
    check('…its row still grey', JSON.stringify(await grey()) === '[false,true,false]', JSON.stringify(await grey()));


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

    // Send the customer an e-mail: ticked as Gridbox (its e-mail of New and its delayed one queued), unticked none
    const json = JSON.parse(store[1]);
    json.notifications.find((n) => n.status === 'new' && n.type === 'customer').delay.enabled = true;
    setStore(JSON.stringify(json));
    const delayed = (id) => Number(sql(`SELECT COUNT(*) FROM #__gridbox_email_delay WHERE order_id = ${id}`)[0][0]);
    const history = (id) => Number(sql(`SELECT COUNT(*) FROM #__gridbox_store_orders_status_history WHERE order_id = ${id}`)[0][0]);
    const toNew = async (id, mail) => {
      await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
      await page.waitForSelector(`tr[data-id="${a}"]`);
      await tick(id);
      await page.waitForTimeout(100);
      await button.click();
      await page.waitForSelector('#orders-status-modal label[for="mt-orders-bulk-mail"]', { state: 'visible' });
      if (!(await page.locator('#mt-orders-bulk-mail').isChecked())) check('Send the customer an e-mail ticked at the start', false);
      if (!mail) await page.click('#orders-status-modal label[for="mt-orders-bulk-mail"]');
      await page.click('#orders-status-modal .orders-status-select input[type="text"]');
      await page.click('#orders-status-modal .orders-status-select li[data-value="new"]');
      await page.click('#orders-status-modal .apply-order-status');
      await page.waitForFunction(() => !document.getElementById('orders-status-modal').dataset.mtBulk, null, { timeout: 30000 });
      await page.waitForTimeout(800);
    };
    const m0 = mails();
    const hb0 = history(b);
    await toNew(a, true);
    const m1 = mails();
    check('with the e-mail: Gridbox\'s e-mail sent, its delayed one queued', m1 > m0 && delayed(a) === 1, JSON.stringify([m0, m1, delayed(a)]));
    // a row another request queued for the same order: not taken out with this request's own
    sql(`INSERT INTO #__gridbox_email_delay (order_id, notification, created) VALUES (${b}, 'mt-other', NOW())`);
    await toNew(b, false);
    const m2 = mails();
    const foreign = Number(sql(`SELECT COUNT(*) FROM #__gridbox_email_delay WHERE order_id = ${b} AND notification = 'mt-other'`)[0][0]);
    check('…a row queued for it by another request kept', foreign === 1, String(foreign));
    sql(`DELETE FROM #__gridbox_email_delay WHERE notification = 'mt-other'`);
    const sb = sql(`SELECT status FROM #__gridbox_store_orders WHERE id = ${b}`)[0][0];
    check('without it: no e-mail, none queued', m2 === m1 && delayed(b) === 0, JSON.stringify([m1, m2, delayed(b)]));
    check('…the status and its history changed', sb === 'new' && history(b) === hb0 + 1, JSON.stringify([sb, hb0, history(b)]));
    // asked without e-mails but without the form token: refused, nothing changed, nothing sent
    const refused = await page.evaluate(async (c) => {
      const fd = new FormData();
      fd.append('id', c);
      fd.append('status', 'completed');
      fd.append('comment', '');
      fd.append('mertools_quiet', '1');
      const r = await fetch('index.php?option=com_gridbox&task=orders.updateStatus', { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.status;
    }, c);
    const sc = sql(`SELECT status FROM #__gridbox_store_orders WHERE id = ${c}`)[0][0];
    check('without the token: refused, the status as it was', refused === 403 && sc === 'new' && mails() === m2, JSON.stringify([refused, sc]));
    setStore(store[1]);

    // Select unread: the grey rows ticked, the others not
    sql(`UPDATE #__gridbox_store_orders SET unread = IF(id = ${b}, 0, 1) WHERE id IN (${ids.join(',')})`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const sel = page.locator('#toolbar-mt-select-unread button');
    const unr = page.locator('#toolbar-mt-unread button');
    const off = (l) => l.evaluate((el) => el.classList.contains('disabled'));
    check('Select unread there, Mark as unread off with nothing ticked', !(await off(sel)) && await off(unr));
    await tick(b);
    await page.waitForTimeout(100);
    await page.evaluate(() => {
      window.mtChanges = 0;
      document.body.addEventListener('change', (e) => { if (e.target.name === 'cid[]') window.mtChanges++; });
    });
    await sel.click();
    const picked = await page.evaluate(() => [...document.querySelectorAll('#adminForm tr[data-id]')].map((tr) => [tr.dataset.id,
      tr.classList.contains('unread-order'), tr.querySelector('input[name="cid[]"]').checked]));
    check('Select unread: exactly the grey rows ticked', picked.every((r) => r[1] === r[2]) && picked.some((r) => r[0] === String(a) && r[2])
      && picked.some((r) => r[0] === String(c) && r[2]) && picked.some((r) => r[0] === String(b) && !r[2]), JSON.stringify(picked.slice(0, 6)));
    check('…Joomla counts them', await page.evaluate(() => Number(document.adminForm.boxchecked.value)) === picked.filter((r) => r[2]).length);
    check('…Gridbox told of each change (its CSV export)', await page.evaluate(() => window.mtChanges) === picked.filter((r) => r[2]).length + 1);
    check('…Mark as unread off: all ticked are unread', await off(unr));

    // Mark as unread: a read order grey and counted again
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const count4 = await counts();
    await tick(b);
    await page.waitForTimeout(100);
    check('Mark as unread on with a read order ticked', !(await off(unr)));
    // refused by the server: a notice, the row as it was, still ticked
    const refuse = (route) => (/orders_unread/.test(route.request().postData() || '')
      ? route.fulfill({ status: 403, contentType: 'application/json', body: '{"success":false}' }) : route.continue());
    await page.route(/\/administrator\/index\.php$/, refuse);
    await unr.click();
    await page.waitForTimeout(1200);
    await page.unroute(/\/administrator\/index\.php$/, refuse);
    const nFail = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    const kept5 = await page.evaluate((b) => [document.querySelector(`tr[data-id="${b}"]`).classList.contains('unread-order'),
      document.querySelector(`tr[data-id="${b}"] input[name="cid[]"]`).checked], b);
    check('refused: a notice, the row as it was and ticked', nFail.length > 0 && !/\b1\b/.test(nFail) && !kept5[0] && kept5[1] && unreadDb()[b] === 0, JSON.stringify([nFail, kept5]));
    // the counts made where Gridbox shows them, when there were none
    await page.evaluate(() => document.querySelectorAll('.unread-comments-count[data-type="orders"]').forEach((c) => c.remove()));
    await unr.click();
    await page.waitForFunction((b) => document.querySelector(`tr[data-id="${b}"]`).classList.contains('unread-order'), b, { timeout: 15000 });
    await page.waitForTimeout(700);
    const made = await counts();
    check('…unread in the database, grey, unticked', unreadDb()[b] === 1
      && !(await page.evaluate((b) => document.querySelector(`tr[data-id="${b}"] input[name="cid[]"]`).checked, b)));
    check('…with no count in the menu: one made where Gridbox has it', made.length === count4.length && made.every((n) => n === 1), JSON.stringify([count4, made]));
    const n5 = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    check('…the notice says so', /\b1\b/.test(n5), n5);
    await page.reload();
    await page.waitForSelector(`tr[data-id="${b}"]`);
    const count5 = await counts();
    check('…after a reload: grey, the count in the menu one more', await page.evaluate((b) => document.querySelector(`tr[data-id="${b}"]`).classList.contains('unread-order'), b)
      && count5.length === count4.length && count5.every((n, i) => n === count4[i] + 1), JSON.stringify([count4, count5]));
    // more orders than a page of "All" might tick: none left out
    sql(`UPDATE #__gridbox_store_orders SET unread = 0 WHERE id = ${b}`);
    const many = await page.evaluate(async (b) => {
      const cfg = JSON.parse(document.getElementById('mertools-orders-config').textContent);
      const fd = new FormData();
      fd.append('mertools_action', 'orders_unread');
      fd.append('ids', [...Array(700).keys()].map((i) => 2000000000 + i).concat([b]).join(','));
      fd.append(cfg.token, '1');
      return (await fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
    }, b);
    check('…700 orders at once: none left out', many.success && JSON.stringify(many.ids) === JSON.stringify([b]) && unreadDb()[b] === 1, JSON.stringify(many));

    // Mark all as read: asked first, every new order read, the counts of the menu without them
    sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id IN (${a}, ${c})`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const all = page.locator('#toolbar-mt-read-all button');
    const newDb = () => Number(sql('SELECT COUNT(*) FROM #__gridbox_store_orders WHERE unread = 1 AND published = 1')[0][0]);
    const n0 = newDb();
    let asked = '';
    const answer = (accept) => (d) => { asked = d.message(); return accept ? d.accept() : d.dismiss(); };
    const no = answer(false);
    page.once('dialog', no);
    await all.click();
    await page.waitForTimeout(500);
    check('Mark all as read asks first, with how many', asked.includes(String(n0)) && newDb() === n0, JSON.stringify([asked, n0]));
    page.once('dialog', answer(true));
    await all.click();
    await page.waitForFunction(() => !document.querySelector('#adminForm tr.unread-order'), null, { timeout: 15000 });
    await page.waitForTimeout(700);
    const counts6 = await counts();
    const booked = await page.evaluate(() => document.querySelectorAll('.unread-comments-count[data-type="booking"]').length);
    check('…all read in the database, no grey row', newDb() === 0, String(newDb()));
    check('…the counts of the orders gone from the menu', booked > 0 || counts6.length === 0, JSON.stringify(counts6));
    check('…the button off then', await off(all));
    const n6 = (await page.locator('#ba-notification p').textContent().catch(() => '')) || '';
    check('…the notice says how many', n6.includes(String(n0)), n6);
    // the last new order opened in Gridbox's own way: Mark all as read off again
    sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id = ${c}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    check('one new order: Mark all as read on', !(await off(all)));
    await page.click(`tr[data-id="${c}"] .order-number-td`);
    await page.waitForFunction((c) => !document.querySelector(`tr[data-id="${c}"]`).classList.contains('unread-order'), c, { timeout: 15000 });
    await page.waitForTimeout(300);
    check('…opened in Gridbox: off again', await off(all));

    // Download PDF: Gridbox's PDF of each order ticked, two in one ZIP, one as it is
    const fs = require('fs');
    const path = require('path');
    const dir = fs.mkdtempSync(path.join(require('os').tmpdir(), 'mt-pdf-'));
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const pdfBtn = page.locator('#toolbar-mt-pdf button');
    check('Download PDF off with nothing ticked', await off(pdfBtn));
    await tick(a);
    await tick(b);
    await page.waitForTimeout(100);
    const [zipDl] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), pdfBtn.click()]);
    const zipPath = path.join(dir, zipDl.suggestedFilename());
    await zipDl.saveAs(zipPath);
    const listed = JSON.parse(execFileSync('python3', ['-I', '-c', 'import json,sys,zipfile\nz=zipfile.ZipFile(sys.argv[1])\nprint(json.dumps([z.testzip(),[[i.filename,z.read(i)[:5].decode("latin1"),i.file_size] for i in z.infolist()]]))', zipPath], { encoding: 'utf8' }));
    check('two orders: one ZIP, a whole one', /\.zip$/.test(zipDl.suggestedFilename()) && listed[0] === null, JSON.stringify([zipDl.suggestedFilename(), listed[0]]));
    check('…with Gridbox\'s PDF of each, by its name', JSON.stringify(listed[1].map((f) => f[0]).sort()) === JSON.stringify(['order-' + TAG + '1.pdf', 'order-' + TAG + '2.pdf'])
      && listed[1].every((f) => f[1] === '%PDF-' && f[2] > 500), JSON.stringify(listed[1]));
    const direct = await page.evaluate(async (id) => {
      const r = await fetch('index.php?option=com_gridbox&view=orders&layout=pdf&tmpl=component&id=' + id, { credentials: 'same-origin' });
      return (await r.arrayBuffer()).byteLength;
    }, a);
    check('…the same as Gridbox\'s own PDF of the order', listed[1].some((f) => f[0] === 'order-' + TAG + '1.pdf' && Math.abs(f[2] - direct) < 64), JSON.stringify([direct, listed[1]]));
    // one order, unread: its PDF as it is; Gridbox's PDF opens the order, read now in the list and the menu too
    sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id = ${c}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    const count7 = await counts();
    await tick(c);
    await page.waitForTimeout(100);
    const [one] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), pdfBtn.click()]);
    const onePath = path.join(dir, one.suggestedFilename());
    await one.saveAs(onePath);
    check('one order: its PDF as it is', one.suggestedFilename() === 'order-' + TAG + '3.pdf' && fs.readFileSync(onePath).subarray(0, 5).toString() === '%PDF-', one.suggestedFilename());
    await page.waitForTimeout(500);
    const count8 = await counts();
    check('…read by Gridbox: the row no longer grey, the count one less', unreadDb()[c] === 0
      && !(await page.evaluate((c) => document.querySelector(`tr[data-id="${c}"]`).classList.contains('unread-order'), c))
      && JSON.stringify(count8) === JSON.stringify(count7.map((n) => n - 1).filter((n) => n > 0)), JSON.stringify([count7, count8, unreadDb()[c]]));
    fs.rmSync(dir, { recursive: true, force: true });

    // the dark mode: Gridbox's Apply that cannot be used yet dimmed, not as light as the text around it
    root('rm -f /var/www/html/media/mertools-admin/gridbox-dark-*.css');
    const dark = await login('dark');
    await dark.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await dark.waitForSelector(`tr[data-id="${a}"]`);
    await dark.evaluate((id) => document.querySelector(`tr[data-id="${id}"] td.select-td label`).click(), a);
    await dark.waitForTimeout(100);
    await dark.click('#toolbar-mt-status button');
    await dark.waitForSelector('#orders-status-modal label[for="mt-orders-bulk-mail"]', { state: 'visible' });
    await dark.click('#orders-status-modal label[for="mt-orders-bulk-read"]');
    await dark.waitForTimeout(800); // Gridbox's colours of its buttons change with a transition
    check('…its Apply not active (no status, Mark as read unticked)', !(await dark.evaluate(() => document.querySelector('#orders-status-modal .apply-order-status').classList.contains('active-button'))));
    const dim = await dark.evaluate(() => getComputedStyle(document.querySelector('#orders-status-modal .apply-order-status')).color);
    check('dark: Apply that cannot be used dimmed', dim === 'rgb(93, 102, 112)', dim);

    // the setting off: no buttons
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.orders_bulk_status', '0') WHERE ${PLUGIN}`);
    await page.goto(ADMIN + 'index.php?option=com_gridbox&view=orders');
    await page.waitForSelector(`tr[data-id="${a}"]`);
    check('setting off: no buttons', await page.locator('#toolbar-mt-status, #toolbar-mt-select-unread, #toolbar-mt-unread, #toolbar-mt-read-all, #toolbar-mt-pdf').count() === 0);
  } finally {
    if (fresh0.length) sql(`UPDATE #__gridbox_store_orders SET unread = 1 WHERE id IN (${fresh0.join(',')})`);
    setStore(store[1]);
    root('rm -f /usr/sbin/sendmail; [ -e /usr/sbin/sendmail.mt-orig ] && mv /usr/sbin/sendmail.mt-orig /usr/sbin/sendmail; rm -rf /tmp/mt-mail; true');
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    cleanup();
  }
});
