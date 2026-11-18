/**
 * Integration test of the corner buttons and YouTube without cookies (1.18.2), on the docker Joomla +
 * Gridbox. MerTools' consent and dark mode both on, the toggle floating in the bottom right corner:
 *  - "above the mode toggle": the cookie button in the toggle's box, centred above it, of its size and
 *    colours (also in dark mode), where it is from the first moment it is seen; opens the window, and
 *    is back with the focus on it when the window closes; on a phone the same;
 *  - "in one button": a gear instead of the toggle, its list (the toggle, the palette dots, the cookie
 *    button with their names) opened by a click, closed by Escape / a click elsewhere, the mode
 *    switched from it, the window opened from it with the focus back on the gear; the toggle in the
 *    menu → the cookie button alone in its corner;
 *  - a corner of its own in the toggle's look (its size) or its own (44 px);
 *  - the window's buttons each on one line;
 *  - YouTube: a frame of the page, a player of YouTube's API given www.youtube.com (as Gridbox's video
 *    background does) and a frame written on a click (Gridbox's thumbnail) all on youtube-nocookie.com,
 *    no cookie of YouTube; with the setting off www.youtube.com as before.
 *
 *   node tests/integration/corner-buttons.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const site = sql('SELECT id, header_code, body_code FROM #__gridbox_website LIMIT 1')[0];
const SHOTS = process.env.MT_SHOTS || '';
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

// YouTube as Gridbox has it: a video element's frame, a video background through YouTube's API (its
// loader: window.YT, then the widget API of s.ytimg.com, the host given), a thumbnail replaced on a click
// (its frame split in the code, as in Gridbox's own script file, which the plugin's pass over the page never sees)
const BODY = '<iframe id="mt-yt-frame" width="320" height="180" src="https://www.youtube.com/embed/KCEh7dUO5l8?start=0&amp;showinfo=1&amp;controls=1&amp;autoplay=0" frameborder="0" allowfullscreen></iframe>'
  + '<div id="mt-yt-bg"></div><div id="mt-yt-thumb" style="width:40px;height:20px"></div>'
  + '<script>document.addEventListener("DOMContentLoaded",function(){'
  + 'if(!window.YT){window.YT={loading:0,loaded:0}}if(!window.YTConfig){window.YTConfig={host:"http://www.youtube.com"}}'
  + 'window.onYouTubeIframeAPIReady=function(){window.__ytPlayer=new YT.Player("mt-yt-bg",{host:"https://www.youtube.com",width:320,height:180,videoId:"AZvIC1iEfYw",'
  + 'playerVars:{controls:0,loop:1,autoplay:1,mute:1},events:{onReady:function(e){e.target.mute();e.target.playVideo()},onStateChange:function(e){window.__ytState=e.data}}})};'
  + 'var s=document.createElement("script");s.src="https://s.ytimg.com/yts/jsbin/www-widgetapi-vflLM1tGT/www-widgetapi.js";s.async=true;document.head.appendChild(s);'
  + 'setTimeout(function(){var $=window.$g||window.jQuery;$&&$("#mt-yt-thumb").replaceWith(\'<if\'+\'rame id="mt-yt-click" width="320" height="180" src="https://www.youtube.com/embed/YxU0jqe8zAY?autoplay=1" allow="autoplay"></iframe>\')},1500)});</script>';

const setParams = (extra) => {
  const all = Object.assign({
    consent_enabled: '1', consent_policy: '/polityka-prywatnosci', consent_reopen: 'toggle', consent_reopen_look: '1', consent_youtube: '1',
    dark_enabled: '1', dark_base: 'light', dark_default: 'light', dark_palette: 'slate', dark_toggle: '1', dark_picker: '1',
    dark_toggle_place: 'float', dark_mobile_place: 'same', dark_float_x: '18', dark_float_y: '18', dark_toggle_size: '50', speed_cache: '0',
  }, extra);
  const sets = Object.entries(all).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ');
  sql(`UPDATE #__extensions SET params = JSON_SET(params, ${sets}) WHERE ${PLUGIN}`);
};

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  const browser = admin.context().browser();
  /** a guest who already rejected (so the cookie button is there from the start) */
  const guest = async (opts = {}, chosen = true) => {
    const ctx = await browser.newContext(Object.assign({ viewport: { width: 1440, height: 900 } }, opts));
    if (chosen) await ctx.addCookies([{ name: 'mertools_consent', value: 'f0t0m0.' + Math.floor(Date.now() / 1000), url: URL_ }]);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => check('no script error on ' + page.url(), false, e.message));
    return page;
  };
  const go = (page) => page.goto(URL_ + '/', { waitUntil: 'load', timeout: 90000 });
  /** the bottom of a button alone bottom left: 18 px from the edge, or 10 px above the copy's accessibility icon */
  const leftBottom = (page) => page.evaluate(() => { const a = document.querySelector('._access-icon'), r = a && a.getBoundingClientRect();
    return r && r.width && r.left < 70 ? Math.round(r.top) - 10 : innerHeight - 18; });
  const rect = (page, sel) => page.evaluate((s) => {
    const e = document.querySelector(s);
    if (!e) return null;
    const r = e.getBoundingClientRect(), cs = getComputedStyle(e);
    return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), cx: Math.round(r.x + r.width / 2),
      bg: cs.backgroundColor, vis: r.width > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' };
  }, sel);

  try {
    sql(`UPDATE #__gridbox_website SET body_code = ${q(BODY)} WHERE id = ${site[0]}`);

    // ---- above the mode toggle
    setParams({});
    let p = await guest();
    // where the cookie button is seen, every 50 ms from the first script on
    await p.addInitScript(() => {
      const log = window.__seen = [];
      const iv = setInterval(() => {
        const e = document.querySelector('.mtc-re');
        if (!e) return;
        const r = e.getBoundingClientRect(), box = e.closest('.mtc-re-box,.mertools-dt-box');
        const seen = r.width > 0 && (!box || getComputedStyle(box).visibility !== 'hidden');
        if (seen) { const v = Math.round(r.x) + ',' + Math.round(r.y); if (log[log.length - 1] !== v) log.push(v); }
      }, 50);
      setTimeout(() => clearInterval(iv), 4000);
    });
    await go(p);
    await p.waitForTimeout(4200);
    let re = await rect(p, '.mtc-re'), dt = await rect(p, '.mertools-dt-float > .mertools-dt');
    check('toggle: the cookie button in the toggle\'s box', await p.evaluate(() => !!document.querySelector('.mertools-dt-float:not(.mtc-re-box) .mertools-dt-stack .mtc-re')), '');
    check('toggle: no box of its own', await p.evaluate(() => !document.querySelector('.mtc-re-box')), '');
    check('toggle: centred above the toggle', re && dt && Math.abs(re.cx - dt.cx) <= 1, JSON.stringify([re, dt]));
    check('toggle: 12 px above it', re && dt && dt.y - (re.y + re.h) === 12, JSON.stringify([re, dt]));
    check('toggle: of the toggle\'s size (50 px)', re && re.w === 50 && re.h === 50 && dt.w === 50, JSON.stringify([re, dt]));
    check('toggle: in the toggle\'s colours', re && re.bg === dt.bg, JSON.stringify([re.bg, dt.bg]));
    const seen = await p.evaluate(() => window.__seen);
    check('toggle: where it is from the first moment it is seen', seen.length === 1, JSON.stringify(seen));
    if (SHOTS) await p.screenshot({ path: SHOTS + '/corner-toggle.png' });
    // dark mode: both change together
    await p.click('.mertools-dt-float > .mertools-dt');
    await p.waitForTimeout(700);
    re = await rect(p, '.mtc-re'); dt = await rect(p, '.mertools-dt-float > .mertools-dt');
    check('toggle: in dark mode the toggle\'s colours too', re.bg === dt.bg && await p.evaluate(() => document.documentElement.dataset.mertoolsTheme === 'dark'), JSON.stringify([re.bg, dt.bg]));
    // the window from it, the button back with the focus
    await p.click('.mtc-re');
    check('toggle: the button opens the settings', await p.evaluate(() => !document.querySelector('.mtc').hidden && !document.querySelector('.mtc-prefs').hidden), '');
    check('toggle: the button hidden while the window asks', !(await rect(p, '.mtc-re')).vis, '');
    await p.click('.mtc-save');
    check('toggle: back after saving, the focus on it', (await rect(p, '.mtc-re')).vis && await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('mtc-re')), '');
    await p.context().close();

    // a phone: the same column
    p = await guest({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await go(p);
    await p.waitForTimeout(800);
    re = await rect(p, '.mtc-re'); dt = await rect(p, '.mertools-dt-float > .mertools-dt');
    check('toggle, phone: above the toggle, on the screen', re && dt && Math.abs(re.cx - dt.cx) <= 1 && dt.y - (re.y + re.h) === 12 && re.y > 0, JSON.stringify([re, dt]));
    await p.context().close();

    // ---- one button
    setParams({ consent_reopen: 'merge' });
    p = await guest();
    await go(p);
    await p.waitForTimeout(800);
    const gear = await rect(p, '.mertools-dt-gear');
    check('merge: a gear where the toggle was', gear && gear.vis && gear.w === 50 && gear.x + gear.w === 1440 - 18, JSON.stringify(gear));
    check('merge: the toggle and the cookie button not seen', !(await rect(p, '.mertools-dt-panel')).vis, '');
    check('merge: the gear tells its list is closed', await p.getAttribute('.mertools-dt-gear', 'aria-expanded') === 'false', '');
    await p.click('.mertools-dt-gear');
    await p.waitForTimeout(300); // its slide in
    let panel = await rect(p, '.mertools-dt-panel');
    const items = await p.evaluate(() => [...document.querySelectorAll('.mertools-dt-panel > button')].map((b) => [b.className, (b.querySelector('.mertools-dt-label') || {}).textContent]));
    check('merge: a click opens the list above the gear', panel.vis && panel.y + panel.h <= gear.y - 12 && panel.x + panel.w === gear.x + gear.w && panel.y >= 0, JSON.stringify([panel, gear]));
    check('merge: the gear tells its list is open', await p.getAttribute('.mertools-dt-gear', 'aria-expanded') === 'true', '');
    check('merge: the mode and the cookie settings with their names', items.length === 2 && /Tryb ciemny/.test(items[0][1]) && /mtc-re/.test(items[1][0]) && items[1][1] === 'Ustawienia plików cookie', JSON.stringify(items));
    check('merge: the palette dots in the list', await p.evaluate(() => { const d = document.querySelector('.mertools-dt-panel .mertools-dt-dots'); return !!d && getComputedStyle(d).visibility === 'visible'; }), '');
    if (SHOTS) await p.screenshot({ path: SHOTS + '/corner-merge-open.png' });
    await p.keyboard.press('Escape');
    check('merge: Escape closes it, the focus on the gear', !(await rect(p, '.mertools-dt-panel')).vis && await p.evaluate(() => document.activeElement.classList.contains('mertools-dt-gear')), '');
    await p.click('.mertools-dt-gear');
    await p.mouse.click(500, 400);
    check('merge: a click elsewhere closes it', !(await rect(p, '.mertools-dt-panel')).vis, '');
    await p.click('.mertools-dt-gear');
    await p.click('.mertools-dt-panel > .mertools-dt:not(.mtc-re)');
    await p.waitForTimeout(700);
    check('merge: the mode switched from the list, the list still open, its name the other mode',
      await p.evaluate(() => document.documentElement.dataset.mertoolsTheme === 'dark') && (await rect(p, '.mertools-dt-panel')).vis
      && /Tryb jasny/.test(await p.textContent('.mertools-dt-panel > .mertools-dt:not(.mtc-re) .mertools-dt-label')), '');
    if (SHOTS) await p.screenshot({ path: SHOTS + '/corner-merge-dark.png' });
    await p.click('.mertools-dt-panel .mtc-re');
    check('merge: the cookie button opens the settings, the list closed', await p.evaluate(() => !document.querySelector('.mtc').hidden) && !(await rect(p, '.mertools-dt-panel')).vis, '');
    await p.click('.mtc-save');
    check('merge: the focus back on the gear', await p.evaluate(() => document.activeElement.classList.contains('mertools-dt-gear')), '');
    await p.context().close();

    // the toggle in the menu: nothing to join, the cookie button alone in its corner
    setParams({ consent_reopen: 'merge', dark_toggle_place: 'end' });
    p = await guest();
    await go(p);
    await p.waitForTimeout(1200);
    const inMenu = await p.evaluate(() => !!document.querySelector('.mertools-dt-li .mertools-dt'));
    re = await rect(p, '.mtc-re');
    const lb = await leftBottom(p);
    check('merge, toggle in the menu: no gear, the cookie button alone bottom left', inMenu && !(await p.$('.mertools-dt-gear')) && re.vis && re.x === 18 && re.y + re.h === lb,
      JSON.stringify([inMenu, re, lb]));
    await p.context().close();

    // ---- a corner of its own: the toggle's look, or its own
    setParams({ consent_reopen: 'left' });
    p = await guest();
    await go(p);
    await p.waitForTimeout(1200);
    re = await rect(p, '.mtc-re');
    check('left, the toggle\'s look: its size, bottom left', re.vis && re.w === 50 && re.x === 18 && re.y + re.h === await leftBottom(p), JSON.stringify(re));
    check('left, the toggle\'s look: its colours', re.bg === (await rect(p, '.mertools-dt-float:not(.mtc-re-box) > .mertools-dt')).bg, re.bg);
    await p.context().close();
    // a picture of the page that never finishes loading (no load event): the button shown all the same
    p = await guest();
    await p.route('**/mt-never.png', () => { /* never answered */ });
    await p.route('**/?mt-hang=1', async (r) => {
      const res = await r.fetch();
      await r.fulfill({ response: res, body: (await res.text()).replace(/<body([^>]*)>/, '<body$1><img src="/mt-never.png" alt="">') });
    });
    await p.goto(URL_ + '/?mt-hang=1', { waitUntil: 'domcontentloaded', timeout: 90000 });
    await p.waitForTimeout(3500);
    re = await rect(p, '.mtc-re');
    check('left, the page never loaded: the button shown after a short wait', await p.evaluate(() => document.readyState) !== 'complete' && re && re.vis, JSON.stringify([re, await p.evaluate(() => document.readyState)]));
    await p.context().close();
    setParams({ consent_reopen: 'right', consent_reopen_look: '0' });
    p = await guest();
    await go(p);
    await p.waitForTimeout(1200);
    re = await rect(p, '.mtc-re'); dt = await rect(p, '.mertools-dt-float > .mertools-dt');
    check('right, its own look: 44 px, above the toggle sharing the corner', re.vis && re.w === 44 && re.y + re.h <= dt.y - 10 && re.x + re.w === 1440 - 18, JSON.stringify([re, dt]));
    await p.context().close();

    // ---- the window's buttons on one line, YouTube without cookies
    setParams({});
    p = await guest({}, false);
    await go(p);
    await p.waitForTimeout(500);
    const btns = await p.evaluate(() => [...document.querySelectorAll('.mtc-btns button')].filter((b) => !b.hidden).map((b) => Math.round(b.getBoundingClientRect().height)));
    check('the window\'s buttons each on one line', btns.length === 3 && btns.every((h) => h === btns[0] && h <= 52), JSON.stringify(btns));
    await p.waitForFunction(() => window.__ytPlayer && document.querySelector('#mt-yt-click'), null, { timeout: 30000 });
    await p.waitForTimeout(6000);
    const yt = await p.evaluate(() => ({
      html: document.getElementById('mt-yt-frame').getAttribute('src'),
      bg: (document.getElementById('mt-yt-bg') || {}).src || '',
      click: document.getElementById('mt-yt-click').getAttribute('src'),
      state: window.__ytState,
    }));
    check('YouTube: the frame of the page from youtube-nocookie.com', /^https:\/\/www\.youtube-nocookie\.com\/embed\/KCEh7dUO5l8\?/.test(yt.html), yt.html);
    check('YouTube: the player given www.youtube.com plays from youtube-nocookie.com', /^https:\/\/www\.youtube-nocookie\.com\/embed\/AZvIC1iEfYw/.test(yt.bg) && yt.state === 1, JSON.stringify([yt.bg, yt.state]));
    check('YouTube: the frame written on a click from youtube-nocookie.com', /^https:\/\/www\.youtube-nocookie\.com\/embed\/YxU0jqe8zAY/.test(yt.click), yt.click);
    const ytCookies = (await p.context().cookies()).filter((c) => /youtube|google/.test(c.domain)).map((c) => c.domain + ':' + c.name);
    check('YouTube: no cookie of YouTube before any consent, also playing', ytCookies.length === 0, ytCookies.join());
    await p.context().close();

    setParams({ consent_youtube: '0' });
    p = await guest();
    await go(p);
    await p.waitForFunction(() => window.__ytPlayer && document.querySelector('#mt-yt-click'), null, { timeout: 30000 });
    await p.waitForTimeout(1500);
    const off = await p.evaluate(() => [document.getElementById('mt-yt-frame').getAttribute('src'), (document.getElementById('mt-yt-bg') || {}).src || '', document.getElementById('mt-yt-click').getAttribute('src')]);
    check('YouTube, setting off: www.youtube.com as before', off.every((u) => /^https:\/\/www\.youtube\.com\/embed\//.test(u)), JSON.stringify(off));
    await p.context().close();
  } finally {
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
    sql(`UPDATE #__gridbox_website SET header_code = ${q(site[1] === null ? '' : site[1])}, body_code = ${q(site[2] === null ? '' : site[2])} WHERE id = ${site[0]}`);
  }
});
