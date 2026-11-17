/**
 * Integration test of light mode for a site designed dark (1.18.0), on the docker Joomla + Gridbox.
 * With "The site is designed: dark" and the default "Dark", a guest sees the site as Gridbox paints it
 * (no palette, nothing adapted) and a toggle offering light mode; a click: the light palette in
 * Gridbox's variables, the dark opaque backgrounds of the page light, their texts dark enough to read
 * (4.5:1), the choice kept — after a reload light from the first paint; back to dark: nothing of
 * MerTools left. A palette dot of light mode, and a light site still offering dark mode.
 *
 *   node tests/integration/light-mode.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const SHOTS = process.env.MT_SHOTS || '';
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

const setParams = (extra) => {
  const all = Object.assign({ dark_enabled: '1', dark_base: 'dark', dark_default: 'dark', light_palette: 'paper', dark_palette: 'slate',
    dark_intensity: '50', dark_toggle: '1', dark_picker: '1', dark_toggle_place: 'float', speed_cache: '0' }, extra);
  const sets = Object.entries(all).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ');
  sql(`UPDATE #__extensions SET params = JSON_SET(params, ${sets}) WHERE ${PLUGIN}`);
};

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) {
    await install(admin);
  }
  const browser = admin.context().browser();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
  const page = await ctx.newPage();
  // the copy's pages call TermsFeed, whose file is not on the copy: not this test's
  page.on('pageerror', (e) => /cookieconsent is not defined/.test(e.message) || check('no script error on ' + page.url(), false, e.message));
  const look = () => page.evaluate(() => {
    const lum = (c) => {
      const m = (c.match(/[\d.]+/g) || []).map(Number);
      const a = m.slice(0, 3).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
      return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    };
    const marked = [].map.call(document.querySelectorAll('[data-test-dark]'), (e) => {
      const bg = getComputedStyle(e).backgroundColor;
      // the texts directly in it: their contrast with it
      const worst = [].reduce.call(e.querySelectorAll('h1,h2,h3,h4,p,a,li,span'), (w, t) => {
        if (!t.textContent.trim() || t.closest('[data-test-dark]') !== e || !t.getBoundingClientRect().height) return w;
        const a = lum(getComputedStyle(t).color), b = lum(bg);
        return Math.min(w, (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05));
      }, 99);
      return { bg, l: lum(bg), worst };
    });
    const b = document.querySelector('.mertools-dt');
    return {
      theme: document.documentElement.getAttribute('data-mertools-theme'),
      on: document.documentElement.hasAttribute('data-mt-on'),
      fixes: document.querySelectorAll('[data-mt-bg],[data-mt-fg]').length,
      bgPrimary: getComputedStyle(document.body).getPropertyValue('--bg-primary').trim(),
      inverse: getComputedStyle(document.body).getPropertyValue('--title-inverse').trim(),
      scheme: getComputedStyle(document.documentElement).colorScheme,
      label: b && b.getAttribute('aria-label'),
      stored: localStorage.getItem('mertools-theme'),
      marked,
    };
  });
// the dark, opaque, plain backgrounds of the page as Gridbox paints it (not over a photo), marked
const markDark = () => page.evaluate(() => {
    const lum = (c) => {
      const m = (c.match(/[\d.]+/g) || []).map(Number);
      if (m.length > 3 && m[3] < 0.95) return 1;
      const a = m.slice(0, 3).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
      return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    };
    let n = 0;
    document.querySelectorAll('body *').forEach((e) => {
      const s = getComputedStyle(e), r = e.getBoundingClientRect();
      if (n >= 6 || r.width < 200 || r.height < 40 || s.backgroundImage !== 'none' || e.closest('.mertools-dt-box')) return;
      if (e.querySelector('img,video,iframe,.parallax-wrapper') || /url\(/.test(getComputedStyle(e.parentElement).backgroundImage)) return;
      const l = lum(s.backgroundColor);
      const ch = (s.backgroundColor.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
      // greyish (a brand colour such as a navy stays dark by design)
      if (l < 0.1 && Math.max(...ch) - Math.min(...ch) < 60) { e.setAttribute('data-test-dark', ''); n++; }
    });
    return n;
  });
  try {
    setParams({});
    await page.goto(URL_ + '/', { waitUntil: 'load', timeout: 90000 });
    await page.waitForSelector('.mertools-dt', { timeout: 15000 });
    const dark = await markDark();
    // a section with a photo (its own background), Gridbox's dark see-through overlay and a heading in
    // the inverse title colour, as Gridbox builds a hero
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = c.height = 8;
      const g = c.getContext('2d');
      g.fillStyle = '#7a6a55';
      g.fillRect(0, 0, 8, 8);
      const sec = document.createElement('div');
      sec.id = 'test-photo';
      sec.style.cssText = 'position:relative;height:300px;background-image:url(' + c.toDataURL() + ');background-size:cover';
      sec.innerHTML = '<div class="ba-overlay" style="position:absolute;inset:0;background-color:rgba(20,20,20,.55)"></div>'
        + '<h2 style="position:relative;margin:0;padding:40px;color:var(--title-inverse)">Na zdjęciu</h2>';
      document.body.insertBefore(sec, document.body.firstChild);
    });
    const photo = () => page.evaluate(() => {
      const o = getComputedStyle(document.querySelector('#test-photo .ba-overlay')).backgroundColor;
      const h = getComputedStyle(document.querySelector('#test-photo h2')).color;
      const lum = (c) => {
        const a = (c.match(/[\d.]+/g) || []).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
      };
      return { overlay: o, heading: h, headingLum: lum(h) };
    });
    const photoBefore = await photo();
    let s = await look();
    check('first visit: the site as designed (dark), nothing of the palette', s.theme === 'dark' && !s.on && s.fixes === 0 && s.inverse !== '#16181d', JSON.stringify(s));
    check('the toggle offers light mode', s.label === 'Przełącz na tryb jasny', s.label);
    check('the page has dark plain backgrounds to test', dark > 0, String(dark));

    await page.click('.mertools-dt');
    // the part below the first screen is adapted in the browser's idle time: waited for, not a fixed time
    await page.waitForFunction(() => [...document.querySelectorAll('[data-test-dark]')].every((e) => e.hasAttribute('data-mt-bg')), null, { timeout: 15000 })
      .catch(() => {});
    await page.waitForTimeout(500);
    s = await look();
    if (SHOTS) await page.screenshot({ path: SHOTS + '/light-mode.png', fullPage: true });
    check('light: theme, palette and fixes on', s.theme === 'light' && s.on && s.bgPrimary === '#fbfbfa' && s.fixes > 0, JSON.stringify(s));
    check('light: Gridbox inverse texts dark', s.inverse === '#16181d', s.inverse);
    check('light: light form controls', s.scheme === 'light', s.scheme);
    check('light: the dark backgrounds light now', s.marked.every((m) => m.l > 0.6), JSON.stringify(s.marked));
    check('light: their texts readable (4.5:1)', s.marked.every((m) => m.worst >= 4.5), JSON.stringify(s.marked));
    check('light: the toggle offers dark mode back', s.label === 'Przełącz na tryb ciemny', s.label);
    const photoAfter = await photo();
    check('light: the dark overlay on a photo stays', photoAfter.overlay === photoBefore.overlay, JSON.stringify([photoBefore, photoAfter]));
    check('light: the heading on the photo stays light', photoAfter.headingLum > 0.6, JSON.stringify([photoBefore, photoAfter]));
    check('light: kept', s.stored === 'light', String(s.stored));

    await page.reload({ waitUntil: 'domcontentloaded' });
    const early = await page.evaluate(() => document.documentElement.getAttribute('data-mertools-theme'));
    check('reload: light from the first paint', early === 'light', String(early));
    await page.waitForSelector('.mertools-dt');
    await page.waitForTimeout(1200);

    // a dot of another light palette
    const dot = await page.$('.mertools-dt-dot[data-palette="cream"]');
    check('light palette dots offered', !!dot && !(await page.$('.mertools-dt-dot[data-palette="slate"]')), '');
    if (dot) {
      await page.hover('.mertools-dt');
      await page.click('.mertools-dt-dot[data-palette="cream"]');
      await page.waitForTimeout(1200);
      s = await look();
      check('cream chosen: its colours', s.theme === 'light' && s.bgPrimary === '#f7f3ea', JSON.stringify(s));
    }

    await page.click('.mertools-dt');
    await page.waitForTimeout(1200);
    s = await look();
    const again = await markDark();
    s = await look();
    check('dark again: the palette and the fixes off, the dark backgrounds back', s.theme === 'dark' && !s.on && s.bgPrimary !== '#f7f3ea' && again === dark, JSON.stringify([again, dark, s]));

    // a light site: dark mode as before
    setParams({ dark_base: 'light', dark_default: 'light' });
    await page.evaluate(() => localStorage.clear());
    await page.goto(URL_ + '/', { waitUntil: 'load', timeout: 90000 });
    await page.waitForSelector('.mertools-dt');
    s = await look();
    check('a light site: the toggle offers dark mode', s.theme === 'light' && !s.on && s.label === 'Przełącz na tryb ciemny', JSON.stringify(s));
    await page.click('.mertools-dt');
    await page.waitForTimeout(1200);
    s = await look();
    check('a light site: dark mode with its palette', s.theme === 'dark' && s.on && s.bgPrimary === '#1b2230', JSON.stringify(s));
  } finally {
    await ctx.close();
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
