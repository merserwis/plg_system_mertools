/**
 * Integration test of the range of dates of Gridbox's dashboard (1.13.1), on the docker Joomla + Gridbox:
 * a range chosen in Gridbox's own list (Month) is the one the dashboard opens with next time — its
 * statistics asked for in that range —, own dates too (Gridbox's date event, as its calendar sends it);
 * the arrows that move the day are not a choice; with the setting off it opens in Day as before;
 * own dates kept are asked for alone (1.13.4: not also today's, whose answer could come last).
 *
 *   node tests/integration/dashboard-range.test.js [--install]
 */
'use strict';
const { ADMIN, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];

run(async ({ login }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  try {
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.dash_range', '1') WHERE ${PLUGIN}`);
    // the statistics Gridbox asks for: the last request's range
    let last = null;
    let asked = [];
    page.on('request', (r) => {
      if (!/task=orders\.getStatistic/.test(r.url())) return;
      last = r.postData() || '';
      asked.push(field('type') + ' ' + field('date'));
    });
    const open = async () => {
      last = null;
      asked = [];
      await page.goto(ADMIN + 'index.php?option=com_gridbox&view=dashboard');
      await page.waitForSelector('.ba-store-statistic-select');
      await page.waitForTimeout(2500);
      return page.evaluate(() => ({ type: app.statistic && app.statistic.type, value: app.statistic && String(app.statistic.value),
        text: document.querySelector('.ba-store-statistic-select input[type="text"]').value }));
    };
    // a field of that request (multipart form data)
    const field = (name) => (new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)').exec(last || '') || [])[1];
    await page.evaluate(() => localStorage.removeItem('mertools-gridbox-dashboard-range'));
    let s = await open();
    check('first visit: Day, as Gridbox opens it', s.type === 'd', JSON.stringify(s));
    // Month chosen in Gridbox's list
    await page.click('.ba-store-statistic-select input[type="text"]');
    await page.click('.ba-store-statistic-select li[data-value="m"]');
    await page.waitForTimeout(800);
    s = await open();
    check('next visit: Month, as last chosen', s.type === 'm' && /2026|20\d\d/.test(s.text), JSON.stringify(s));
    check('…its statistics asked for by month', field('type') === 'm', String(last).slice(0, 300));
    // the arrow back a year is not a choice of range
    await page.click('.ba-store-statistic-action[data-action="-"]');
    await page.waitForTimeout(500);
    s = await open();
    check('the arrows move, the range stays Month (this year)', s.type === 'm' && s.value === String(new Date().getFullYear()), JSON.stringify(s));
    // own dates: chosen as Gridbox's calendar sends them
    await page.click('.ba-store-statistic-select input[type="text"]');
    await page.click('.ba-store-statistic-select li[data-value="c"]');
    await page.evaluate(() => jQuery('.ba-store-statistic-header-filter-wrapper').trigger('dateUpdated', ['2026-10-01', '2026-10-15']));
    await page.waitForTimeout(500);
    s = await open();
    check('own dates kept with their dates', s.type === 'c' && s.value === '2026-10-01 - 2026-10-15' && /2026.*-.*2026/.test(s.text), JSON.stringify(s));
    check('…its statistics asked for those dates', field('type') === 'c' && field('date') === '2026-10-01 - 2026-10-15', String(last).slice(0, 300));
    // Gridbox draws whichever answer comes last: no request for other own dates (today's, of its list) beside them
    check('…and for no other own dates', asked.filter((a) => a.startsWith('c ')).length === 1, JSON.stringify(asked));
    // off: as Gridbox
    sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.dash_range', '0') WHERE ${PLUGIN}`);
    s = await open();
    check('setting off: Day again', s.type === 'd', JSON.stringify(s));
    await page.evaluate(() => localStorage.removeItem('mertools-gridbox-dashboard-range'));
  } finally {
    sql(`UPDATE #__extensions SET params = CONVERT(UNHEX('${Buffer.from(params, 'utf8').toString('hex')}') USING utf8mb4) WHERE ${PLUGIN}`);
  }
});
