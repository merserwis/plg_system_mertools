/**
 * Integration test of the Omnibus line in another currency, on a real Joomla + Gridbox in docker (the
 * test copy gbx-sitemap-test): a second currency is added to the store for the test (EUR, its own
 * rate and format), a product on sale with an Omnibus history is opened as a guest with Gridbox's
 * currency cookie, and the line must show the lowest price converted as Gridbox converts the prices
 * shown (rounded to the currency's decimals, in its format); a currency without a rate gets no line.
 * The store's settings are put back at the end.
 *
 *   node tests/integration/omnibus-currency.test.js
 *
 * Env: MT_CONTAINER (gbx-sitemap-test), MT_URL (http://127.0.0.1:18096).
 */
'use strict';
const { URL_, check, sql } = require('./lib');

// a product on sale without versions (its item is the product itself; compared here: two collations in SQL)
const row = sql('SELECT item, product_id, lowest FROM #__mertools_omnibus WHERE sale IS NOT NULL AND lowest IS NOT NULL ORDER BY product_id LIMIT 200')
  .find((r) => String(r[0]) === String(r[1]));
const [product, lowest] = [Number(row[1]), Number(row[2])];
// a price as MerTools writes it (Omnibus::format()): rounded to the currency's decimals, its separators, its symbol
const format = (n, c) => {
  const [int, dec] = n.toFixed(Math.max(0, Math.min(4, Number(c.decimals ?? 2)))).split('.');
  const number = int.replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand ?? ' ') + (dec ? (c.separator ?? ',') + dec : '');
  const symbol = String(c.symbol || '').trim();
  return symbol === '' ? number : String(c.position || '').includes('left') ? symbol + ' ' + number : number + ' ' + symbol;
};
const EUR = { symbol: '€', position: 'left-currency-position', thousand: '.', separator: ',', decimals: '2' };
const original = sql("SELECT `key` FROM #__gridbox_api WHERE service = 'store'")[0][0];
const hex = (text) => Buffer.from(text, 'utf8').toString('hex');
const store = JSON.parse(original);
const def = store.currencies.list.find((c) => c.default) || store.currencies.list[0];
store.currencies.list = [def,
  { ...def, code: 'EUR', ...EUR, default: false, rate: '0.2312', title: 'Euro', language: '' },
  { ...def, code: 'USD', symbol: '$', default: false, rate: '0', title: 'Dollar', language: '' }];

const page = async (currency) => {
  const res = await fetch(URL_ + '/index.php?option=com_gridbox&view=page&id=' + product, { headers: currency ? { Cookie: 'gridbox-currency=' + currency } : {} });
  const html = await res.text();
  const m = /<span class="mt-omnibus-price">([^<]*)<\/span>/.exec(html);
  return m ? m[1].replace(/&nbsp;| /g, ' ') : null;
};

(async () => {
  let fail = 0;
  const ok = (label, cond, info) => { check(label, cond, info); fail += cond ? 0 : 1; };
  sql(`UPDATE #__gridbox_api SET \`key\` = CONVERT(UNHEX('${hex(JSON.stringify(store))}') USING utf8mb4) WHERE service = 'store'`);
  try {
    const own = await page('');
    ok('the store\'s currency: the lowest price as it is, in its format', own === format(lowest, def), own + ' (want ' + format(lowest, def) + ')');
    const eur = await page('EUR');
    const want = format(Math.round(lowest * 0.2312 * 100) / 100, EUR);
    ok('in euro: converted with the rate of the list, in its format', eur === want, eur + ' (want ' + want + ')');
    ok('a currency without a rate: no line', (await page('USD')) === null);
  } finally {
    sql(`UPDATE #__gridbox_api SET \`key\` = CONVERT(UNHEX('${hex(original)}') USING utf8mb4) WHERE service = 'store'`);
  }
  ok('the store\'s settings put back', sql("SELECT `key` FROM #__gridbox_api WHERE service = 'store'")[0][0] === original);
  console.log(fail ? 'FAILED: ' + fail : 'ALL PASS');
  process.exit(fail ? 1 : 0);
})();
