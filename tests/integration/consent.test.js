/**
 * Integration test of the consent to cookies (1.18.0), on the docker Joomla + Gridbox. Gridbox's own
 * code of the head and body gets merserwis.pl's setup in small: a GTM-like script noting what the
 * dataLayer held when it ran, Facebook's pixel (a stand-in noting it ran) with its <noscript> image,
 * and TermsFeed's window. A guest:
 *  - sees MerTools' head script first in <head>, Google's consent denied before the "GTM" runs, the
 *    pixel waiting, its <noscript> and TermsFeed gone, the window in the centre over a dimmed page,
 *    focus kept inside it;
 *  - rejects: kept in the cookie, denied passed on, the pixel still waiting; after a reload no window
 *    and the stored choice in the dataLayer before the "GTM";
 *  - opens the settings by the cookie button, allows marketing: the pixel runs at once, the event
 *    mertools_consent in the dataLayer; withdraws it: the page reloads, the pixel's cookie is gone;
 *  - who chose in TermsFeed before is not asked, the choice carried over;
 *  - on a phone the window fits the screen; with the setting off the page is Gridbox's.
 *
 *   node tests/integration/consent.test.js [--install]
 */
'use strict';
const fs = require('fs');
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const site = sql('SELECT id, header_code, body_code, compress_js FROM #__gridbox_website LIMIT 1')[0];
const { C } = require('./lib');
const { execFileSync } = require('child_process');
// a tracker of the site's own, a file Gridbox's joining of scripts (compress_js) would take into its bundle
const LOCAL = '/media/mt-test-tracker.js';
const SHOTS = process.env.MT_SHOTS || '';
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

const HEAD = "<script>window.__gtm=JSON.stringify((window.dataLayer||[]).map(function(a){return Array.prototype.slice.call(a)}));</script>";
const BODY = "<script>\n!function(f,b,e,v){window.fbq=function(){};window.__pixel=(window.__pixel||0)+1;document.cookie='_fbp=fb.1.test;path=/'}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\nfbq('init','1');\n</script>"
  + '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1&amp;ev=PageView&amp;noscript=1"/></noscript>'
  // a necessary script marked for TermsFeed: runs without waiting for a choice
  + '<script type="text/plain" cookie-consent="strictly-necessary">window.__need=(window.__need||0)+1;</script>'
  + '<script type="text/plain" data-mertools-consent="tracking" src="' + LOCAL + '"></script>'
  + '<!-- Cookie Consent by TermsFeed -->\n<script type="text/javascript" src="/assets/js/cookie-consent.js" charset="UTF-8"></script>\n'
  + '<script type="text/javascript" charset="UTF-8">document.addEventListener(\'DOMContentLoaded\',function(){window.cookieconsent&&cookieconsent.run({"language":"pl"});});</script>\n'
  + '<noscript>Free cookie consent management tool by TermsFeed</a></noscript>\n<!-- End Cookie Consent by TermsFeed -->';

const setParams = (extra) => {
  const all = Object.assign({ consent_enabled: '1', consent_policy: '/polityka-prywatnosci', speed_cache: '0' }, extra);
  const sets = Object.entries(all).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ');
  sql(`UPDATE #__extensions SET params = JSON_SET(params, ${sets}) WHERE ${PLUGIN}`);
};

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  const browser = admin.context().browser();
  const guest = async (opts = {}) => {
    const ctx = await browser.newContext(Object.assign({ viewport: { width: 1440, height: 900 } }, opts));
    const page = await ctx.newPage();
    page.on('pageerror', (e) => check('no script error on ' + page.url(), false, e.message));
    return page;
  };
  const state = (page) => page.evaluate(() => {
    const box = document.querySelector('.mtc-box');
    const root = document.querySelector('.mtc');
    const r = box && box.getBoundingClientRect();
    return {
      first: document.head.firstElementChild && document.head.firstElementChild.hasAttribute('data-mertools-consent-head'),
      gtm: window.__gtm, pixel: window.__pixel || 0, need: window.__need || 0, local: window.__local || 0,
      bundled: [...document.scripts].some((x) => /templates\/gridbox\/js\/min\//.test(x.src)),
      held: document.querySelectorAll('script[type="text/plain"][data-mertools-consent="targeting"]').length,
      noscriptPixel: document.documentElement.innerHTML.includes('facebook.com/tr'),
      termsfeed: /cookie-consent\.js|cookieconsent\.run|TermsFeed/.test(document.documentElement.innerHTML),
      open: !!root && !root.hidden,
      box: r ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) } : null,
      vw: innerWidth, vh: innerHeight,
      dimTop: (document.elementFromPoint(5, 5) || {}).className || '',
      role: box && box.getAttribute('role'), modal: box && box.getAttribute('aria-modal'),
      focusIn: !!box && box.contains(document.activeElement),
      cookie: document.cookie,
      dl: (window.dataLayer || []).map((a) => JSON.stringify(a.length !== undefined && !a.event ? Array.prototype.slice.call(a) : a)),
      // seen, not only the property: a style of its own must not show it while the window asks
      reopen: (() => { const b = document.querySelector('.mtc-re'); return b && getComputedStyle(b).display !== 'none' ? b.getAttribute('aria-label') : null; })(),
      policy: (document.querySelector('.mtc-text a') || {}).href || '',
      yesColour: box ? getComputedStyle(box.querySelector('.mtc-yes')).backgroundColor : '',
    };
  });
  const cookieOf = (s) => (s.cookie.match(/mertools_consent=([^;]*)/) || [])[1] || '';
  const go = (page) => page.goto(URL_ + '/', { waitUntil: 'load', timeout: 90000 });
  try {
    execFileSync('docker', ['exec', C, 'sh', '-c', `printf 'window.__local=(window.__local||0)+1;' > /var/www/html${LOCAL}`]);
    sql(`UPDATE #__gridbox_website SET header_code = ${q(HEAD)}, body_code = ${q(BODY)}, compress_js = 1 WHERE id = ${site[0]}`);
    setParams({});

    // ---- first visit
    const p = await guest();
    await go(p);
    await p.waitForSelector('.mtc-box', { timeout: 15000 });
    let s = await state(p);
    if (SHOTS) await p.screenshot({ path: SHOTS + '/consent-desktop.png' });
    check('head script first in <head>', s.first, '');
    check('Google consent denied before the GTM ran', /"consent","default",\{"ad_storage":"denied".*"analytics_storage":"denied"/.test(s.gtm || ''), s.gtm);
    check('…and no update yet (no choice)', !/"update"/.test(s.gtm || ''), s.gtm);
    check('pixel waits for marketing', s.held === 1 && s.pixel === 0, JSON.stringify([s.held, s.pixel]));
    check('a necessary script runs before any choice', s.need === 1, String(s.need));
    check('Gridbox joins the site\'s scripts (compress_js)', s.bundled, '');
    check('…but the held local tracker is not in its bundle: not run', s.local === 0, String(s.local));
    check('pixel <noscript> taken out', !s.noscriptPixel, '');
    check('TermsFeed taken out', !s.termsfeed, '');
    check('window open, a modal dialog', s.open && s.role === 'dialog' && s.modal === 'true', JSON.stringify(s));
    check('…in the centre', s.box && Math.abs(s.box.x + s.box.w / 2 - s.vw / 2) < 3 && Math.abs(s.box.y + s.box.h / 2 - s.vh / 2) < 3, JSON.stringify(s.box));
    check('…large (at least 560 px wide)', s.box && s.box.w >= 560, JSON.stringify(s.box));
    check('…over a dimmed page', s.dimTop === 'mtc-dim', s.dimTop);
    check('…focus inside', s.focusIn, '');
    check('…policy link', s.policy.endsWith('/polityka-prywatnosci'), s.policy);
    check('…no cookie button while asking', s.reopen === null, String(s.reopen));
    for (let i = 0; i < 9; i++) await p.keyboard.press('Tab');
    check('Tab stays inside the window', (await state(p)).focusIn, '');

    // ---- reject
    await p.click('.mtc-no');
    s = await state(p);
    check('reject: window closed', !s.open, '');
    check('reject: kept in the cookie', /^f0t0m0\.\d+$/.test(cookieOf(s)), s.cookie);
    check('reject: denied passed on', s.dl.some((x) => /"consent","update",\{"ad_storage":"denied"/.test(x)), s.dl.join('\n'));
    check('reject: pixel still waiting', s.pixel === 0, String(s.pixel));
    check('reject: the cookie button shown', s.reopen === 'Ustawienia plików cookie', String(s.reopen));
    await p.waitForTimeout(1800);
    const clash = await p.evaluate(() => {
      const me = document.querySelector('.mtc-re').getBoundingClientRect();
      return [].map.call(document.querySelectorAll('._access-icon,.mertools-dt-float'), (o) => {
        const r = o.getBoundingClientRect();
        return r.width && !(r.right <= me.left || r.left >= me.right || r.bottom <= me.top || r.top >= me.bottom) ? o.className : '';
      }).filter(Boolean);
    });
    check('the cookie button clear of the accessibility icon and the dark-mode toggle', clash.length === 0, clash.join());
    if (SHOTS) await p.screenshot({ path: SHOTS + '/consent-closed.png' });
    await go(p);
    s = await state(p);
    check('after a reload: no window', !s.open, '');
    check('after a reload: the choice in the dataLayer before the GTM', /"consent","update",\{"ad_storage":"denied"/.test(s.gtm || ''), s.gtm);
    check('after a reload: the event mertools_consent for Tag Manager again', s.dl.some((x) => /"event":"mertools_consent".*"targeting":false/.test(x)), s.dl.join('\n'));
    check('after a reload: the necessary script once', s.need === 1, String(s.need));

    // ---- allow marketing in the settings (once the button has taken its place above the accessibility icon)
    await p.waitForTimeout(1800);
    await p.click('.mtc-re');
    await p.waitForSelector('.mtc-prefs:not([hidden])');
    check('settings: necessary always on', await p.$eval('.mtc-prefs input[name="necessary"]', (i) => i.checked && i.disabled), '');
    check('settings: marketing off as chosen', await p.$eval('.mtc-prefs input[name="targeting"]', (i) => !i.checked), '');
    if (SHOTS) await p.screenshot({ path: SHOTS + '/consent-settings.png' });
    await p.click('.mtc-prefs input[name="targeting"] + .mtc-sw');
    await p.click('.mtc-save');
    s = await state(p);
    check('marketing allowed: the pixel ran at once', s.pixel === 1 && s.held === 0, JSON.stringify([s.pixel, s.held]));
    check('…cookie f0t0m1', /^f0t0m1\./.test(cookieOf(s)), s.cookie);
    check('…event mertools_consent', s.dl.some((x) => /"event":"mertools_consent".*"targeting":true/.test(x)), s.dl.join('\n'));
    check('…granted passed on', s.dl.some((x) => /"consent","update",\{"ad_storage":"granted"/.test(x)), '');

    // ---- withdraw it: the page reloads, the pixel's cookie goes
    await p.click('.mtc-re');
    await p.click('.mtc-prefs input[name="targeting"] + .mtc-sw');
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('.mtc-save')]);
    s = await state(p);
    check('withdrawn: reloaded, pixel not run', s.pixel === 0, String(s.pixel));
    check('withdrawn: _fbp deleted', !/_fbp=/.test(s.cookie), s.cookie);
    check('withdrawn: cookie f0t0m0', /^f0t0m0\./.test(cookieOf(s)), s.cookie);
    await p.context().close();

    // ---- a choice made in TermsFeed before
    const tf = await guest();
    await tf.context().addCookies([
      { name: 'cookie_consent_user_accepted', value: 'true', url: URL_ },
      { name: 'cookie_consent_level', value: encodeURIComponent(JSON.stringify({ 'strictly-necessary': true, functionality: false, tracking: true, targeting: true })), url: URL_ },
    ]);
    await go(tf);
    await tf.waitForTimeout(800);
    s = await state(tf);
    check('TermsFeed choice: not asked again', !s.open, '');
    check('TermsFeed choice: carried over', /^f0t1m1\./.test(cookieOf(s)), s.cookie);
    check('TermsFeed choice: granted before the GTM', /"update",\{"ad_storage":"granted".*"analytics_storage":"granted","functionality_storage":"denied"/.test(s.gtm || ''), s.gtm);
    check('TermsFeed choice: pixel ran', s.pixel === 1, String(s.pixel));
    check('TermsFeed choice (analytics allowed): the local tracker loaded from its own file', s.local === 1, String(s.local));
    check('TermsFeed choice: its cookies gone once carried over', !/cookie_consent_/.test(s.cookie), s.cookie);
    // rejected in MerTools, then MerTools' cookie expires: TermsFeed's old consent must not come back
    await tf.click('.mtc-re');
    await Promise.all([tf.waitForNavigation({ timeout: 30000 }), tf.click('.mtc-prefs input[name="targeting"] + .mtc-sw').then(() => tf.click('.mtc-save'))]);
    await tf.context().clearCookies({ name: 'mertools_consent' });
    await go(tf);
    await tf.waitForSelector('.mtc-box');
    s = await state(tf);
    check('consent expired: asked again, TermsFeed\'s old consent not taken', s.open && s.pixel === 0, JSON.stringify([s.open, s.pixel, s.cookie]));
    await tf.context().close();

    // ---- a phone
    const ph = await guest({ viewport: { width: 390, height: 740 }, isMobile: true, hasTouch: true });
    await go(ph);
    await ph.waitForSelector('.mtc-box');
    s = await state(ph);
    if (SHOTS) await ph.screenshot({ path: SHOTS + '/consent-phone.png' });
    check('phone: the window fits the screen', s.box && s.box.x >= 0 && s.box.x + s.box.w <= s.vw && s.box.y >= 0 && s.box.y + s.box.h <= s.vh, JSON.stringify(s.box));
    await ph.context().close();

    // ---- the bar at the bottom, no dimming
    setParams({ consent_position: 'bottom' });
    const bar = await guest();
    await go(bar);
    await bar.waitForSelector('.mtc-box');
    s = await state(bar);
    if (SHOTS) await bar.screenshot({ path: SHOTS + '/consent-bottom.png' });
    check('bottom: full width at the bottom', s.box && s.box.w === s.vw && s.box.y + s.box.h === s.vh, JSON.stringify(s.box));
    check('bottom: the page usable (no dimming)', s.dimTop !== 'mtc-dim', s.dimTop);
    await bar.context().close();

    // ---- the centre without dimming: not a modal, the keyboard leaves it
    setParams({ consent_position: 'center', consent_backdrop: '0' });
    const free = await guest();
    await go(free);
    await free.waitForSelector('.mtc-box');
    s = await state(free);
    check('centre, not dimmed: no modal', s.modal === 'false' && s.dimTop !== 'mtc-dim', JSON.stringify([s.modal, s.dimTop]));
    check('centre, not dimmed: the cookie button not seen while asking', s.reopen === null, String(s.reopen));
    await free.focus('.mtc-more');
    await free.keyboard.press('Tab');
    check('centre, not dimmed: Tab goes on to the page', !(await state(free)).focusIn, '');
    await free.context().close();

    // ---- off
    setParams({ consent_enabled: '0' });
    const off = await guest();
    await go(off);
    await off.waitForTimeout(800);
    s = await state(off);
    check('off: no head script, no window, the pixel as Gridbox has it', !s.first && !s.open && s.pixel === 1, JSON.stringify([s.first, s.open, s.pixel]));
    await off.context().close();
  } finally {
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
    sql(`UPDATE #__gridbox_website SET header_code = ${q(site[1] === null ? '' : site[1])}, body_code = ${q(site[2] === null ? '' : site[2])}, compress_js = ${q(site[3])} WHERE id = ${site[0]}`);
    execFileSync('docker', ['exec', C, 'rm', '-f', '/var/www/html' + LOCAL]);
  }
});
