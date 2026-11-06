/**
 * Integration test of 1.13.3 on a real Joomla + Gridbox in docker (the test copy gbx-sitemap-test):
 * the sale badge ("- n%") with a sale of the store (Gridbox → Discounts), which Gridbox leaves out of it
 * ("- 0%" beside the lowered price).
 *
 *  - Gridbox's "- 0%" on the tile with the setting off (the price beside it lowered);
 *  - on: the percentage of the sale on the tile of the category and on the picture of the product page,
 *    a discount of an amount as a percentage, a sale for another access level not for a guest but for
 *    somebody of that level logged in on the site, a product with a sale price of its own as Gridbox shows it (the
 *    store's sale does not lower it);
 *  - a product with versions and a discount of an amount: the tile at the price of its default version,
 *    the product page following the version chosen in Gridbox's options.
 *
 * The store's own sales of the copy are switched off for the test (and back on). The pages are read as
 * a guest gets them (fetch, no scripts: the copy lacks some of the site's files), the versions with a
 * browser.
 *
 *   node tests/integration/badge-sales.test.js [--install]
 */
'use strict';
const crypto = require('crypto');
const { URL_, check, cli, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', '${v}'`).join(', ')}) WHERE ${PLUGIN}`);
const SALE_BADGE = Number((sql("SELECT id FROM #__gridbox_store_badges WHERE type = 'sale' ORDER BY id LIMIT 1")[0] || [0])[0]);

// a product in stock, without variations and without a sale price, in the category with the fewest
// products (so its tile is on the first page of the category)
const found = sql(`SELECT p.id, p.app_id, p.page_category, d.price FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id AND a.type = 'products'
  JOIN #__gridbox_store_product_data d ON d.product_id = p.id
  WHERE p.published = 1 AND p.page_category > 0 AND d.sale_price = '' AND d.stock = '' AND d.price REGEXP '^[0-9]+(\\\\.[0-9]+)?$' AND d.price + 0 >= 10
    AND NOT EXISTS (SELECT 1 FROM #__gridbox_store_product_variations_map v WHERE v.product_id = p.id)
  ORDER BY (SELECT COUNT(*) FROM #__gridbox_pages q WHERE q.page_category = p.page_category AND q.published = 1), p.id LIMIT 1`)[0];
const [PRODUCT, APP, CATEGORY] = found.map(Number);
const PRICE = Number(found[3]);
// a product with versions of one option, none with a sale price, whose default version is priced otherwise than the product
const VERSIONED = (() => {
  const rows = sql(`SELECT p.id, p.page_category, d.price, d.variations FROM #__gridbox_pages p JOIN #__gridbox_app a ON a.id = p.app_id AND a.type = 'products'
    JOIN #__gridbox_store_product_data d ON d.product_id = p.id
    WHERE p.published = 1 AND p.page_category > 0 AND d.sale_price = '' AND d.stock = '' AND d.variations NOT IN ('', '[]', '{}')
      AND (SELECT COUNT(DISTINCT v.field_id) FROM #__gridbox_store_product_variations_map v WHERE v.product_id = p.id) = 1
    ORDER BY (SELECT COUNT(*) FROM #__gridbox_pages q WHERE q.page_category = p.page_category AND q.published = 1), p.id`);
  for (const [id, category, price, json] of rows) {
    const versions = JSON.parse(json || '{}');
    const keys = Object.keys(versions);
    const def = keys.find((k) => versions[k].default);
    const other = keys.find((k) => k !== def && Number(versions[k].price) !== Number(versions[def] ? versions[def].price : 0));
    if (def && other && keys.every((k) => versions[k].sale_price === '' && Number(versions[k].price) > 0) && Number(versions[def].price) !== Number(price)) {
      return { id: Number(id), category: Number(category), price: Number(price), versions, def, other };
    }
  }
  return null;
})();
const BADGED = [PRODUCT, ...(VERSIONED ? [VERSIONED.id] : [])];
const hadBadge = Object.fromEntries(BADGED.map((id) => [id, sql(`SELECT COUNT(*) FROM #__gridbox_store_badges_map WHERE product_id = ${id} AND badge_id = ${SALE_BADGE}`)[0][0] > 0]));
// the store's own sales running on the copy: off for the test, back on at the end
const OWN_SALES = sql('SELECT id FROM #__gridbox_store_sales WHERE published = 1').map((r) => Number(r[0]));
const CUSTOMER = 'mtcu' + crypto.randomBytes(3).toString('hex');
const CUSTOMER_PASS = crypto.randomBytes(12).toString('hex');
let saleId = 0;
let customer = false;

const html = async (url) => (await fetch(url, { headers: { 'Cache-Control': 'no-cache' } })).text();
/** the texts of the badges in the tile of a product on its category (a page given: read in it, with its session) */
const tileBadges = async (product = PRODUCT, category = CATEGORY, browserPage = null) => {
  const url = URL_ + `/index.php?option=com_gridbox&view=blog&app=${APP}&id=${category}`;
  const page = browserPage ? await (await browserPage.goto(url)).text() : await html(url);
  const start = page.search(new RegExp(`<(?:li|div)\\b[^>]*\\sclass="(?:[^"]*\\s)?ba-store-app-product(?=[\\s"])[^>]*\\sdata-id="${product}"`));
  if (start < 0) {
    return null;
  }
  const rest = page.slice(start + 10);
  const next = rest.search(/<(?:li|div)\b[^>]*\sclass="(?:[^"]*\s)?ba-store-app-product(?=[\s"])/);
  const tile = next < 0 ? rest : rest.slice(0, next);
  const wrapper = /<div class="ba-blog-post-badge-wrapper">([\s\S]*?)<\/div>/.exec(tile);
  return { badges: wrapper ? [...wrapper[1].matchAll(/<span class="ba-blog-post-badge"[^>]*>\s*([^<]*?)\s*<\/span>/g)].map((m) => m[1]) : [],
    lowered: tile.includes('ba-blog-post-add-to-cart-sale-price-wrapper') };
};
/** the texts of MerTools' badges on the picture of the product page */
const pageBadges = async () => {
  const page = await html(URL_ + '/index.php?option=com_gridbox&view=page&id=' + PRODUCT);
  const w = /<div class="ba-blog-post-badge-wrapper mt-badges-page"[^>]*>([\s\S]*?)<\/div>/.exec(page);
  return w ? [...w[1].matchAll(/<span class="ba-blog-post-badge"[^>]*>([^<]*)<\/span>/g)].map((m) => m[1]) : null;
};
const has = (list, text) => !!list && list.includes(text);

const addSale = (unit, discount, appliesTo, access, product) => {
  sql(`INSERT INTO #__gridbox_store_sales (title, published, unit, discount, applies_to, access) VALUES ('mt-badge-sales-test', 1, '${unit}', '${discount}', '${appliesTo}', ${access})`);
  saleId = Number(sql("SELECT MAX(id) FROM #__gridbox_store_sales WHERE title = 'mt-badge-sales-test'")[0][0]);
  if (product) {
    sql(`INSERT INTO #__gridbox_store_sales_map (type, sale_id, item_id, variation) VALUES ('product', ${saleId}, ${product}, '')`);
  }
};
const dropSale = () => {
  if (saleId) {
    sql(`DELETE FROM #__gridbox_store_sales_map WHERE sale_id = ${saleId}`);
    sql(`DELETE FROM #__gridbox_store_sales WHERE id = ${saleId}`);
    saleId = 0;
  }
};
const pct = (price, off) => '- ' + Math.round(off * 100 / price) + '%';

run(async ({ login }) => {
  check('a sale badge in Gridbox and a product for the test', SALE_BADGE > 0 && PRODUCT > 0, JSON.stringify({ SALE_BADGE, found }));
  if (process.argv.includes('--install')) {
    await install(await login('light'));
  }
  // no kept pages; Omnibus off (the test's sale is not to go into the copy's price history)
  setParams({ speed_cache: '0', speed_cache_data: '0', shop_omnibus: '0', badge_on: '1', badge_product: '1', badge_sales: '0' });
  for (const id of BADGED) {
    if (!hadBadge[id]) {
      sql(`INSERT INTO #__gridbox_store_badges_map (product_id, badge_id, order_list) VALUES (${id}, ${SALE_BADGE}, 0)`);
    }
  }
  if (OWN_SALES.length) {
    sql(`UPDATE #__gridbox_store_sales SET published = 0 WHERE id IN (${OWN_SALES.join(',')})`);
  }
  addSale('%', '20', 'product', 1, PRODUCT);

  const off = await tileBadges();
  check('off: Gridbox\'s "- 0%" on the tile beside the lowered price', !!off && off.lowered && has(off.badges, '- 0%'), JSON.stringify(off) + ` product ${PRODUCT} category ${CATEGORY}`);
  check('off: the product page as Gridbox works it out', has(await pageBadges(), '- 0%'));

  setParams({ badge_sales: '1' });
  const on = await tileBadges();
  check('on: the tile "- 20%"', !!on && has(on.badges, '- 20%') && !has(on.badges, '- 0%'), JSON.stringify(on));
  const onPage = await pageBadges();
  check('on: the product page "- 20%"', has(onPage, '- 20%'), JSON.stringify(onPage));

  sql(`UPDATE #__gridbox_store_sales SET unit = '', discount = '${(PRICE * 0.25).toFixed(2)}' WHERE id = ${saleId}`);
  const amount = await tileBadges();
  check('a discount of an amount: as a percentage on the tile', !!amount && has(amount.badges, '- 25%'), JSON.stringify(amount));
  check('…and on the product page', has(await pageBadges(), '- 25%'));

  sql(`UPDATE #__gridbox_store_sales SET unit = '%', discount = '20', access = 2 WHERE id = ${saleId}`);
  const special = await tileBadges();
  check('a sale for registered customers: a guest sees no discount, "- 0%" as Gridbox', !!special && !special.lowered && has(special.badges, '- 0%'), JSON.stringify(special));
  // somebody of that access level logged in on the site gets it (on the copy only Super Users may log in
  // on the site — Public is refused it —, and they are in the level Registered)
  cli('user:add', '--username=' + CUSTOMER, '--name=MerTools test customer', '--password=' + CUSTOMER_PASS, '--email=' + CUSTOMER + '@example.invalid', '--usergroup=Super Users');
  customer = true;
  const browser = (await login('light')).context().browser();
  const ctx = await browser.newContext();
  const site = await ctx.newPage();
  await site.goto(URL_ + '/index.php?option=com_users&view=login');
  await site.fill('#username', CUSTOMER);
  await site.fill('#password', CUSTOMER_PASS);
  await Promise.all([site.waitForNavigation(), site.click('#com-users-login__form button[type="submit"]')]);
  const logged = await tileBadges(PRODUCT, CATEGORY, site);
  check('…logged in with that level: the lowered price and "- 20%"', !!logged && logged.lowered && has(logged.badges, '- 20%'), JSON.stringify(logged));
  await ctx.close();

  sql(`UPDATE #__gridbox_store_sales SET access = 1 WHERE id = ${saleId}`);
  sql(`UPDATE #__gridbox_store_product_data SET sale_price = '${(PRICE * 0.9).toFixed(2)}' WHERE product_id = ${PRODUCT}`);
  const own = await tileBadges();
  check('a sale price of its own: the store\'s sale does not lower it, "- 10%" as Gridbox', !!own && has(own.badges, '- 10%'), JSON.stringify(own));
  check('…and on the product page', has(await pageBadges(), '- 10%'));
  sql(`UPDATE #__gridbox_store_product_data SET sale_price = '' WHERE product_id = ${PRODUCT}`);
  dropSale();

  // ---------------------------------------------------------------- versions, a discount of an amount on the whole store
  if (!VERSIONED) {
    check('a product with versions priced otherwise than the product (none on this copy)', false);
    return;
  }
  const { id: VID, category: VCAT, versions, def, other } = VERSIONED;
  const OFF = Math.round(Math.min(...Object.values(versions).map((v) => Number(v.price))) * 0.3);
  addSale('', String(OFF), '*', 1, 0);
  const vtile = await tileBadges(VID, VCAT);
  const wantDef = pct(Number(versions[def].price), OFF);
  const wantOther = pct(Number(versions[other].price), OFF);
  check(`versions: a discount of ${OFF} gives the versions ${wantDef} and ${wantOther}`, OFF > 0 && wantDef !== wantOther && wantDef !== '- 0%' && wantOther !== '- 0%');
  check(`…the tile at the price of the default version (${wantDef}; Gridbox: ${VERSIONED.price > 0 ? pct(VERSIONED.price, OFF) : "- 0%"} from the product's price)`, !!vtile && has(vtile.badges, wantDef), JSON.stringify(vtile) + ` product ${VID} category ${VCAT}`);
  const guest = await (await login('light')).context().browser().newPage();
  await guest.goto(URL_ + '/index.php?option=com_gridbox&view=page&id=' + VID, { waitUntil: 'load' });
  await guest.waitForTimeout(800);
  const badgeText = () => guest.evaluate(() => [...document.querySelectorAll('[data-mt-badges] [data-mt-sale]')].map((s) => s.textContent));
  const before = await badgeText();
  check('…the product page: the default version\'s first', before.length > 0 && before.every((t) => t === wantDef), JSON.stringify(before));
  const pick = guest.locator(`.ba-add-to-cart-variations li[data-value="${other}"]`).first();
  await pick.evaluate((li) => li.closest('.ba-custom-select').querySelector('input[type="text"]').click());
  await pick.click();
  await guest.waitForFunction((t) => [...document.querySelectorAll('[data-mt-badges] [data-mt-sale]')].some((s) => s.textContent === t), wantOther, { timeout: 10000 }).catch(() => {});
  const after = await badgeText();
  check(`…another version chosen in Gridbox's options: its percentage (${wantOther})`, after.length > 0 && after.every((t) => t === wantOther), JSON.stringify(after));
  await guest.close();
});

process.on('exit', () => {
  try {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
    sql(`UPDATE #__gridbox_store_product_data SET sale_price = '' WHERE product_id = ${PRODUCT}`);
    dropSale();
    if (OWN_SALES.length) {
      sql(`UPDATE #__gridbox_store_sales SET published = 1 WHERE id IN (${OWN_SALES.join(',')})`);
    }
    for (const id of BADGED) {
      if (!hadBadge[id]) {
        sql(`DELETE FROM #__gridbox_store_badges_map WHERE product_id = ${id} AND badge_id = ${SALE_BADGE}`);
      }
    }
    if (customer) {
      cli('user:delete', '--username=' + CUSTOMER);
    }
  } catch (e) {
    console.log('cleanup: ' + e.message);
  }
});
