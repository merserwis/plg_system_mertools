/**
 * Integration test of the checkout page fixes (1.15.0), on the docker Joomla + Gridbox (the copy's
 * store: currency "zł netto", tax added to the prices): a guest with a product in the cart opens the
 * checkout — the drop-down fields of its form have the text fields' inner space at the sides and their
 * height, and do not stick out past them; the total says "zł brutto", the tax "zł", the net rows keep
 * "zł netto", also after another shipping is chosen (Gridbox's script changes only the amounts) and
 * after the order form is loaded again (Gridbox's reloadCheckout); with the setting off, as Gridbox.
 *
 *   node tests/integration/checkout-page.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const CHECKOUT = sql("SELECT alias FROM #__gridbox_system_pages WHERE type = 'checkout'")[0][0];
// a product with a price, nothing to choose before it goes into the cart
const PRODUCT = Number(sql(`SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_store_product_data d ON d.product_id = p.id
  WHERE p.published = 1 AND p.page_category <> 'trashed' AND d.price > 0 AND (d.variations = '' OR d.variations = '{}' OR d.variations = '[]')`)[0][0]);

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  const guest = await (await admin.context().browser().newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  const look = () => guest.evaluate(() => {
    const field = (sel) => {
      const el = document.querySelector('.ba-item-checkout-form .ba-checkout-form-field-wrapper ' + sel);
      if (!el) return null;
      const s = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return { pl: s.paddingLeft, pr: s.paddingRight, h: Math.round(r.height), right: Math.round(r.right), wrap: Math.round(el.parentElement.getBoundingClientRect().right) };
    };
    const cur = {};
    document.querySelectorAll('.ba-checkout-order-form-total-wrapper .ba-checkout-order-form-row').forEach((row) => {
      const c = row.querySelector('.ba-checkout-order-price-currency');
      if (c) cur[row.className.replace(/^.*ba-checkout-order-form-/, '')] = c.textContent.trim();
    });
    return { input: field('input[type="text"]'), select: field('select'), cur, total: (document.querySelector('.ba-checkout-order-form-total .ba-checkout-order-price-value') || {}).textContent };
  });
  try {
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.shop_checkout_fix', '1') WHERE ${PLUGIN}`);
    await guest.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT, { waitUntil: 'load', timeout: 90000 });
    // into the cart as Gridbox's button puts it (initAddToCart.js)
    await guest.evaluate((id) => app.fetch(JUri + 'index.php?option=com_gridbox&view=editor&task=store.addProductToCart',
      { id: id, variation: '', extra_options: '{}', quantity: 2, booking: '{}' }), PRODUCT);
    await guest.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
    let s = await look();
    check('a checkout with the product', !!s.cur.total, JSON.stringify(s.cur));
    check('drop-down field: the text fields\' space at the left', !!s.select && s.select.pl === s.input.pl && s.select.pl !== '0px', JSON.stringify(s));
    check('…and at the right', s.select && s.select.pr === s.input.pl, JSON.stringify(s.select));
    check('…as high as a text field', s.select && s.select.h === s.input.h, JSON.stringify(s));
    check('…not past its place', s.select && s.select.right <= s.select.wrap, JSON.stringify(s.select));
    check('total: brutto', s.cur.total === 'zł brutto', JSON.stringify(s.cur));
    check('tax: the currency alone', s.cur.tax === 'zł', JSON.stringify(s.cur));
    check('net rows: as they were', s.cur.subtotal === 'zł netto' && (!s.cur.shipping || s.cur.shipping === 'zł netto'), JSON.stringify(s.cur));
    // a shipping chosen (another one, or the only one again): Gridbox's script puts in the amounts only
    const written = await guest.evaluate(() => {
      const all = [...document.querySelectorAll('input[name="ba-checkout-shipping"]')];
      const pick = all.find((i) => !i.checked) || all[0];
      pick.checked = true;
      jQuery(pick).trigger('change');
      return pick.dataset.total;
    });
    await guest.waitForTimeout(500);
    s = await look();
    check('a shipping chosen: Gridbox\'s script wrote its total', !!written && s.total.trim() === written.trim(), `${written} / ${s.total}`);
    check('…the total still brutto, the tax still zł', s.cur.total === 'zł brutto' && s.cur.tax === 'zł', JSON.stringify(s.cur));
    // the order form loaded again from the page (Gridbox's reloadCheckout, e.g. after a country)
    await guest.evaluate(() => app.checkoutForm.reloadCheckout());
    await guest.waitForTimeout(800);
    s = await look();
    check('the order form loaded again: still brutto', s.cur.total === 'zł brutto' && s.cur.tax === 'zł', JSON.stringify(s.cur));
    if (process.env.MT_SHOT) {
      await guest.evaluate(() => document.querySelector('.ba-item-checkout-form select').scrollIntoView({ block: 'center' }));
      await guest.screenshot({ path: process.env.MT_SHOT });
    }
    // the setting off: as Gridbox
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.shop_checkout_fix', '0') WHERE ${PLUGIN}`);
    await guest.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
    s = await look();
    check('setting off: total as Gridbox writes it', s.cur.total === 'zł netto', JSON.stringify(s.cur));
    check('setting off: drop-down field without space', s.select && s.select.pl === '0px', JSON.stringify(s.select));
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
  }
});
