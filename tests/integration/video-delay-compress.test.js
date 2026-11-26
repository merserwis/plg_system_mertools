/**
 * Integration test of the video background started late (speed_video) and of popups opened after the first use
 * (speed_popups) with Gridbox's compress_js, on the docker Joomla + Gridbox: Gridbox joins its scripts into one file of
 * templates/gridbox/js/min/; MerTools' wrap goes right after that file (before 1.20.0 it looked for gridbox.js only and
 * did nothing), and Gridbox's own functions are the wrapped ones when the page has run.
 *
 *   node tests/integration/video-delay-compress.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const site = sql('SELECT id, compress_js FROM #__gridbox_website LIMIT 1')[0];
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ')}) WHERE ${PLUGIN}`);

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const ctx = admin.context();
  await ctx.addCookies([{ name: 'mertools_consent', value: 'f0t0m0.' + Math.floor(Date.now() / 1000), url: URL_ }]);
  const look = async () => {
    const page = await ctx.newPage();
    const res = await page.goto(URL_ + '/?mt=' + Date.now(), { waitUntil: 'load', timeout: 90000 });
    const html = await res.text();
    await page.waitForTimeout(1500);
    const out = await page.evaluate(() => ({
      wrapped: !!(window.app && window.app.mtVideoDelay) && /args=arguments/.test(String(window.app.checkVideoBackground)),
      popups: typeof window.initLightbox === 'function' && !!window.initLightbox.mtPopup,
    }));
    await page.close();
    return { html, ...out };
  };
  try {
    setParams({ speed_video: '1', speed_popups: '1', speed_cache: '0' });
    sql(`UPDATE #__gridbox_website SET compress_js = 1 WHERE id = ${site[0]}`);
    const on = await look();
    const min = /<script\b[^>]*src="[^"]*templates\/gridbox\/js\/min\/[0-9a-f]+\.min\.js[^"]*"[^>]*><\/script>/.exec(on.html);
    check('Gridbox joins its scripts (compress_js), no gridbox.js of its own', !!min && !/templates\/gridbox\/js\/gridbox\.js/.test(on.html), (min || [''])[0]);
    // right after it: only MerTools' inline wraps (the one of popups, put there later, comes first), no other script
    const after = min ? on.html.slice(min.index + min[0].length) : '';
    const wrap = after.indexOf('a.mtVideoDelay=1');
    check('the wrap of the video background right after that file', wrap > 0 && !/<script\b[^>]*\ssrc=/i.test(after.slice(0, wrap)) && /^\s*<script\b/.test(after), after.slice(0, 300));
    check('Gridbox\'s checkVideoBackground the wrapped one', on.wrapped);
    check('popups by a timer wait for the first use too (when the page has one)', !/ba-lightbox-backdrop/.test(on.html) || on.popups, String(on.popups));

    setParams({ speed_video: '0', speed_popups: '0' });
    const off = await look();
    check('off: nothing wrapped', !off.wrapped && !/mtVideoDelay/.test(off.html));
  } finally {
    sql(`UPDATE #__gridbox_website SET compress_js = ${q(site[1])} WHERE id = ${site[0]}`);
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
