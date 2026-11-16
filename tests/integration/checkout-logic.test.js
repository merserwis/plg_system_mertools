/**
 * Integration test of the conditional logic of the checkout fields (1.16.0), on the docker Joomla +
 * Gridbox (the copy's checkout form: a required drop-down of the topic, fields after it, a required
 * name, a text field that is not required):
 *  - the editor of the checkout page: the group under the list of fields of the checkout form opens the
 *    window of rules; rules made in it (the options of the drop-down as values, no headline among the
 *    conditions, no e-mail among the actions), a change a step of Gridbox's undo history, saved with the
 *    page by Gridbox's own save;
 *  - the checkout as a guest with a product in the cart: the fields a rule shows hidden at first, shown
 *    while the topic chosen is the one of the rule; a field a rule hides hidden while the text field is
 *    filled; a hidden required field is passed over by Gridbox's check (its warning goes), a hidden
 *    field goes into the order empty and keeps what was typed for when it is shown again; a rule
 *    switched off does nothing; the state after a reload (Gridbox keeps the values of the cart);
 *  - the setting off: no script, the fields as Gridbox shows them.
 * A check box field of the test (an option written with an entity) is added and removed; the settings of
 * the checkout page are put back as they were.
 *
 *   node tests/integration/checkout-logic.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const [PAGE_ID, CHECKOUT, ITEMS, HTML, FONTS, SAVED] = sql("SELECT id, alias, items, html, fonts, saved_time FROM #__gridbox_system_pages WHERE type = 'checkout' ORDER BY id LIMIT 1")[0];
// a check box field of the test, its first option written with an entity (Gridbox writes the value of
// a check box as the escaped text: "A &amp;amp; B" in the page, "A &amp; B" read by a script)
sql(`INSERT INTO #__gridbox_store_customer_info (title, type, required, invoice, options, order_list) VALUES
  ('MerTools test', 'checkbox', 0, 0, '{"placeholder":"","html":"","options":["A &amp; B","C"],"width":"100"}', 999)`);
const CHECK = { id: Number(sql("SELECT MAX(id) FROM #__gridbox_store_customer_info WHERE title = 'MerTools test' AND order_list = 999")[0][0]) };
const PRODUCT = Number(sql(`SELECT MIN(p.id) FROM #__gridbox_pages p JOIN #__gridbox_store_product_data d ON d.product_id = p.id
  WHERE p.published = 1 AND p.page_category <> 'trashed' AND d.price > 0 AND (d.variations = '' OR d.variations = '{}' OR d.variations = '[]')`)[0][0]);
const SITE_PAGE = Number(sql("SELECT MIN(id) FROM #__gridbox_pages WHERE page_category <> 'trashed'")[0][0]);
// the copy's fields: the topic (a required drop-down), the two fields after it, a required name, a text field not required
const FIELDS = sql(`SELECT id, type, required, options FROM #__gridbox_store_customer_info WHERE id <> ${CHECK.id} ORDER BY order_list`).map(([id, type, required, options]) =>
  ({ id: Number(id), type, required: Number(required), options: (JSON.parse(options || '{}').options || []) }));
const TOPIC = FIELDS.find((f) => f.type === 'dropdown' && f.required && f.options.length >= 2);
const after = FIELDS.slice(FIELDS.indexOf(TOPIC) + 1).filter((f) => !f.required && f.type !== 'headline' && f.type !== 'acceptance');
const [SHOWN1, SHOWN2] = after;
const NAME = FIELDS.find((f) => f.type === 'text' && f.required);
const FILL = FIELDS.find((f) => f.type === 'text' && !f.required && f !== SHOWN1 && f !== SHOWN2);
const HEADLINE = FIELDS.find((f) => f.type === 'headline');
const EMAIL = FIELDS.find((f) => f.type === 'email');
const [CHOSEN, OTHER] = TOPIC.options;

run(async ({ login, editor, uid }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  try {
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.shop_checkout_logic', '1') WHERE ${PLUGIN}`);
    // ---------------------------------------------------------------- the editor of the checkout page
    await editor(admin, SITE_PAGE);
    await admin.goto(URL_ + '/index.php?option=com_gridbox&view=editor&tmpl=component&edit_type=system&id=' + PAGE_ID, { waitUntil: 'load', timeout: 90000 });
    await admin.waitForSelector('.gridbox-save', { timeout: 60000 });
    await admin.waitForFunction(() => window.app && app.editor && app.editor.app && app.editor.app.items && app.editor.document.querySelector('.ba-item-checkout-form'), null, { timeout: 60000 });
    await admin.evaluate(() => { app.editor.app.edit = app.editor.document.querySelector('.ba-item-checkout-form').id; app.editor.app.checkModule('editItem'); });
    await admin.waitForSelector('#customer-info-settings-dialog.in', { timeout: 30000 });
    check('editor: the group of the logic under the fields of the checkout form', await admin.isVisible('#customer-info-settings-dialog .mt-logic-group'));
    const before = await admin.evaluate(() => app.history.length);
    await admin.locator('.mt-logic-group').scrollIntoViewIfNeeded();
    await admin.click('.mt-logic-group .ba-settings-item');
    check('editor: the window of the rules opens', await admin.isVisible('#mertools-logic .mt-logic-modal'));

    const win = '#mertools-logic ';
    const pick = (sel, value) => admin.selectOption(win + sel, String(value));
    // rule 1: the topic chosen → two fields shown
    await admin.click(win + '.mt-logic-add');
    await admin.fill(win + '.mt-logic-name input', 'Order');
    await pick('.mt-logic-when select >> nth=0', TOPIC.id);
    const values = await admin.$$eval(win + '.mt-logic-when select:nth-of-type(3) option', (o) => o.map((x) => x.value).filter(Boolean));
    check('editor: the options of the drop-down as the values to compare', JSON.stringify(values) === JSON.stringify(TOPIC.options), JSON.stringify(values));
    const sources = await admin.$$eval(win + '.mt-logic-when select:nth-of-type(1) option', (o) => o.map((x) => Number(x.value)).filter(Boolean));
    check('editor: no headline among the fields of a condition', HEADLINE ? !sources.includes(HEADLINE.id) && sources.includes(NAME.id) : true, JSON.stringify(sources));
    await pick('.mt-logic-when select:nth-of-type(3)', CHOSEN);
    await pick('.mt-logic-then select >> nth=1', SHOWN1.id);
    const targets = await admin.$$eval(win + '.mt-logic-then select:nth-of-type(2) option', (o) => o.map((x) => Number(x.value)).filter(Boolean));
    check('editor: no e-mail among the fields of an action', EMAIL ? !targets.includes(EMAIL.id) && targets.includes(NAME.id) : true, JSON.stringify(targets));
    await admin.click(win + '.mt-logic-add-then');
    await pick('.mt-logic-then >> nth=1 >> select >> nth=1', SHOWN2.id);
    // rule 2: the text field filled → the name hidden
    await admin.click(win + '.mt-logic-add');
    await pick('.mt-logic-when select >> nth=0', FILL.id);
    await pick('.mt-logic-when select >> nth=1', 'filled');
    check('editor: no value for "filled"', await admin.$eval(win + '.mt-logic-when input', (i) => getComputedStyle(i).visibility === 'hidden'));
    await pick('.mt-logic-then select >> nth=0', 'hide');
    await pick('.mt-logic-then select >> nth=1', NAME.id);
    // rule 3, switched off: the text field filled → the first field shown by rule 1 hidden
    await admin.click(win + '.mt-logic-add');
    await pick('.mt-logic-when select >> nth=0', FILL.id);
    await pick('.mt-logic-when select >> nth=1', 'filled');
    await pick('.mt-logic-then select >> nth=0', 'hide');
    await pick('.mt-logic-then select >> nth=1', SHOWN1.id);
    await admin.click(win + '.mt-logic-list li.active .mt-logic-icon >> nth=0');
    check('editor: a rule switched off is marked so', await admin.isVisible(win + '.mt-logic-list li.off'));
    // rule 4: the option of the check box with an entity → the headline shown
    await admin.click(win + '.mt-logic-add');
    await pick('.mt-logic-when select >> nth=0', CHECK.id);
    const checkValues = await admin.$$eval(win + '.mt-logic-when select:nth-of-type(3) option', (o) => o.map((x) => x.value).filter(Boolean));
    check('editor: an option written with an entity offered as its text', JSON.stringify(checkValues) === JSON.stringify(['A & B', 'C']), JSON.stringify(checkValues));
    await pick('.mt-logic-when select:nth-of-type(3)', 'A & B');
    await pick('.mt-logic-then select >> nth=1', HEADLINE.id);
    await admin.keyboard.press('Escape');
    check('editor: Escape closes the window', await admin.isHidden('#mertools-logic'));
    check('editor: the group counts the rules', /4/.test(await admin.textContent('.mt-logic-group .mt-logic-count')));
    check('editor: the changes are steps of the undo history', (await admin.evaluate(() => app.history.length)) > before);
    const kept = await admin.evaluate(() => JSON.stringify(app.edit.mertools_logic));
    check('editor: the rules in the settings of the element', JSON.parse(kept).length === 4, kept);

    // saved by Gridbox's own save
    await admin.click('.gridbox-save > span, .gridbox-save');
    await admin.waitForFunction(() => document.querySelector('.gridbox-save').dataset.action !== 'clicked', null, { timeout: 30000 }).catch(() => {});
    let saved = null;
    for (let i = 0; i < 30 && !saved; i++) {
      await admin.waitForTimeout(500);
      const items = JSON.parse(sql(`SELECT items FROM #__gridbox_system_pages WHERE id = ${PAGE_ID}`)[0][0]);
      saved = Object.values(items).find((it) => it.type === 'checkout-form' && Array.isArray(it.mertools_logic) && it.mertools_logic.length === 4) || null;
    }
    const rules = saved ? saved.mertools_logic : [];
    check('saved with the page', !!saved);
    check('…rule 1: the topic equal to its option, two fields shown', JSON.stringify(rules[0] && [rules[0].title, rules[0].when, rules[0].then])
      === JSON.stringify(['Order', [{ field: TOPIC.id, op: 'equal', value: CHOSEN }], [{ action: 'show', field: SHOWN1.id }, { action: 'show', field: SHOWN2.id }]]), JSON.stringify(rules[0]));
    check('…rule 3 switched off', rules[2] && rules[2].off === true, JSON.stringify(rules[2]));

    // an undo while the window is open: it closes (what it holds is no more the page's), nothing of it
    // is noted after the undo
    await admin.evaluate(() => { app.editor.app.edit = app.editor.document.querySelector('.ba-item-checkout-form').id; app.editor.app.checkModule('editItem'); });
    await admin.waitForSelector('#customer-info-settings-dialog.in', { timeout: 30000 });
    await admin.locator('.mt-logic-group').scrollIntoViewIfNeeded();
    await admin.click('.mt-logic-group .ba-settings-item');
    await admin.click(win + '.mt-logic-add');
    check('undo: a fifth rule in the element', (await admin.evaluate(() => app.edit.mertools_logic.length)) === 5);
    // (the window covers the editor's Undo button: the key, as Gridbox takes it anywhere in the editor)
    await admin.keyboard.press('Control+z');
    await admin.waitForTimeout(800);
    check('undo: the window of the rules closed', await admin.isHidden('#mertools-logic'));
    const back = await admin.evaluate(() => {
      const items = app.editor.app.items;
      const id = Object.keys(items).find((k) => items[k].type === 'checkout-form');
      return { rules: (items[id].mertools_logic || []).length, redo: !!document.querySelector('.ba-action-redo.active') };
    });
    check('undo: the element back to its four rules, what was undone can be redone', back.rules === 4 && back.redo, JSON.stringify(back));

    // ---------------------------------------------------------------- the checkout, as a guest
    const guest = await (await admin.context().browser().newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    // (the copy's cookie banner fails on every page of its own: not the checkout's)
    guest.on('pageerror', (e) => { if (!/cookieconsent is not defined/.test(e.message)) check('no script error on the checkout', false, e.message); });
    await guest.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT, { waitUntil: 'load', timeout: 90000 });
    await guest.evaluate((id) => app.fetch(JUri + 'index.php?option=com_gridbox&view=editor&task=store.addProductToCart',
      { id: id, variation: '', extra_options: '{}', quantity: 1, booking: '{}' }), PRODUCT);
    const open = async () => {
      await guest.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
      await guest.waitForFunction(() => window.app && app.checkoutForm && document.querySelector('.ba-item-checkout-form.mt-logic'), null, { timeout: 30000 });
    };
    const field = (f) => '.ba-checkout-form-fields[data-name="' + f.id + '"]';
    const shown = (f) => guest.$eval(field(f), (el) => getComputedStyle(el).display !== 'none');
    const state = async () => ({ s1: await shown(SHOWN1), s2: await shown(SHOWN2), name: await shown(NAME) });
    const data = () => guest.evaluate(() => app.checkoutForm.pickData());
    await open();
    check('checkout: the style that hides the fields of rule 1 until the script looked at them', await guest.$eval('#mertools-conditions', (s) => s.textContent.includes('data-name')));
    // start from nothing chosen (the cart may keep values of an earlier run)
    await guest.selectOption(field(TOPIC) + ' select', '');
    await guest.fill(field(FILL) + ' input', '');
    await guest.fill(field(NAME) + ' input', '');
    let s = await state();
    check('checkout: no topic: the fields of rule 1 hidden, the name shown', !s.s1 && !s.s2 && s.name, JSON.stringify(s));
    await guest.selectOption(field(TOPIC) + ' select', CHOSEN);
    s = await state();
    check('checkout: the topic of rule 1: its fields shown', s.s1 && s.s2, JSON.stringify(s));
    const input = SHOWN2.type === 'textarea' ? ' textarea' : SHOWN2.type === 'dropdown' ? ' select' : ' input';
    if (SHOWN2.type === 'dropdown') await guest.selectOption(field(SHOWN2) + input, SHOWN2.options[0]);
    else await guest.fill(field(SHOWN2) + input, 'MerTools test');
    const typed = SHOWN2.type === 'dropdown' ? SHOWN2.options[0] : 'MerTools test';
    await guest.selectOption(field(TOPIC) + ' select', OTHER);
    s = await state();
    check('checkout: another topic: hidden again', !s.s1 && !s.s2, JSON.stringify(s));
    check('…a hidden field goes into the order empty', (await data())[SHOWN2.id] === '', JSON.stringify(await data()));
    await guest.selectOption(field(TOPIC) + ' select', CHOSEN);
    check('…shown again with what was typed in it', (await data())[SHOWN2.id] === typed, JSON.stringify(await data()));

    // the check box: its option with an entity is the one of rule 4
    const headline = () => guest.$eval(field(HEADLINE), (el) => getComputedStyle(el).display !== 'none');
    check('checkout: the headline of rule 4 hidden while its option is not ticked', !(await headline()));
    await guest.$eval(field(CHECK) + ' .ba-checkbox-wrapper:nth-child(1) label', (l) => l.click());
    check('…the option written with an entity ticked: shown', await headline(),
      await guest.$eval(field(CHECK) + ' input', (i) => i.value + ' ' + i.checked));
    await guest.$eval(field(CHECK) + ' .ba-checkbox-wrapper:nth-child(1) label', (l) => l.click());

    // the name: Gridbox's own check of the required fields first marks it empty
    await guest.evaluate(() => $g('.ba-checkout-form-wrapper').find('input[required], textarea[required], select[required]').not('[type="checkbox"], [type="radio"]').trigger('checkAlert'));
    check('checkout: Gridbox\'s check marks the empty name', await guest.$$eval(field(NAME) + ' .ba-checkout-alert', (n) => n.length) > 0);
    await guest.fill(field(NAME) + ' input', 'Jan');
    await guest.fill(field(FILL) + ' input', '1234567890');
    s = await state();
    check('checkout: the text field filled: the name hidden', !s.name, JSON.stringify(s));
    check('…a rule switched off does nothing (the field of rule 3 still shown)', s.s1, JSON.stringify(s));
    check('…the warning on the hidden name gone', await guest.$$eval(field(NAME) + ' .ba-checkout-alert, ' + field(NAME) + '.ba-checkout-alert', (n) => n.length) === 0);
    // typed into while hidden (a script, an autofill of the browser)
    const type = (value) => guest.evaluate(([sel, v]) => { const i = document.querySelector(sel); i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); },
      [field(NAME) + ' input', value]);
    await type('');
    await guest.evaluate(() => $g('.ba-checkout-form-wrapper').find('input[required], textarea[required], select[required]').not('[type="checkbox"], [type="radio"]').trigger('checkAlert'));
    check('…Gridbox\'s check passes the hidden empty name over', await guest.$$eval(field(NAME) + ' .ba-checkout-alert', (n) => n.length) === 0);
    check('…the hidden name goes into the order empty', (await data())[NAME.id] === '');
    await type('Jan');
    check('…typed into while hidden: still empty in the order', (await data())[NAME.id] === '');
    s = await (async () => { await open(); return state(); })();
    check('checkout: a guest\'s reload starts empty (Gridbox keeps no values of a guest): rule 1 fields hidden', !s.s1 && !s.s2 && s.name, JSON.stringify(s));

    // a customer logged in: Gridbox fills the form with the values kept on the account, the rules
    // follow them as soon as the page is shown
    const customer = await admin.context().newPage();
    customer.on('pageerror', (e) => { if (!/cookieconsent is not defined/.test(e.message)) check('no script error on the checkout', false, e.message); });
    await customer.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT, { waitUntil: 'load', timeout: 90000 });
    await customer.evaluate((id) => app.fetch(JUri + 'index.php?option=com_gridbox&view=editor&task=store.addProductToCart',
      { id: id, variation: '', extra_options: '{}', quantity: 1, booking: '{}' }), PRODUCT);
    await customer.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
    await customer.selectOption(field(TOPIC) + ' select', CHOSEN);
    await customer.fill(field(FILL) + ' input', '1234567890');
    await customer.waitForTimeout(1500); // Gridbox keeps a text 500 ms after the last key
    await customer.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
    await customer.waitForSelector('.ba-item-checkout-form.mt-logic', { timeout: 30000 });
    s = await customer.evaluate((ids) => ids.map((id) => getComputedStyle(document.querySelector('.ba-checkout-form-fields[data-name="' + id + '"]')).display !== 'none'),
      [SHOWN1.id, SHOWN2.id, NAME.id]);
    check('checkout: a customer\'s values kept by Gridbox: rule 1 fields shown, the name hidden at once', s[0] && s[1] && !s[2], JSON.stringify(s));

    // ---------------------------------------------------------------- the setting off
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.shop_checkout_logic', '0') WHERE ${PLUGIN}`);
    await guest.goto(URL_ + '/' + CHECKOUT, { waitUntil: 'load', timeout: 90000 });
    s = await state();
    check('setting off: no rules on the page, every field shown', !(await guest.$('#mertools-conditions-config')) && s.s1 && s.s2 && s.name, JSON.stringify(s));
    await guest.selectOption(field(TOPIC) + ' select', '');
    await guest.fill(field(FILL) + ' input', '');
    await guest.waitForTimeout(1200);
  } finally {
    sql(`DELETE FROM #__gridbox_store_user_info WHERE user_id = ${uid}`);
    sql(`DELETE FROM #__gridbox_store_customer_info WHERE id = ${CHECK.id}`);
    sql(`DELETE FROM #__gridbox_store_customer_info_data WHERE field_id = ${CHECK.id}`);
    sql(`DELETE FROM #__gridbox_store_order_customer_info WHERE customer_id = ${CHECK.id}`);
    const was = (JSON.parse(params || '{}') || {}).shop_checkout_logic;
    sql(was === undefined ? `UPDATE #__extensions SET params = JSON_REMOVE(params, '$.shop_checkout_logic') WHERE ${PLUGIN}`
      : `UPDATE #__extensions SET params = JSON_SET(params, '$.shop_checkout_logic', '${String(was).replace(/\D/g, '')}') WHERE ${PLUGIN}`);
    const q = (s) => s === null ? 'NULL' : "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    sql(`UPDATE #__gridbox_system_pages SET items = ${q(ITEMS)}, html = ${q(HTML)}, fonts = ${q(FONTS)}, saved_time = ${q(SAVED)} WHERE id = ${PAGE_ID}`);
  }
});
