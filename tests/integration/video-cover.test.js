/**
 * Integration test of a background video filling its section (1.19.3, setting speed_video_cover), on the docker
 * Joomla + Gridbox 2.20.5: on a page of the site, with Gridbox's own CSS and MerTools' style, a section taller than
 * 16:9 of the window (a narrow window, a tall section) gets a background like Gridbox's (.ba-video-background with
 * a YouTube frame, another with a <video>); with the setting on both cover the section, centred; off: Gridbox's
 * 100vw × 56.25vw with bands above and below on a desktop (1270 px, as on black.merserwis.pl). A wide window: the frame
 * as wide as before. On tablets and phones Gridbox's responsive CSS already covers the section with a frame 200vh/.5624
 * wide (about 3.5 heights of the window); with the setting the frame is just large enough to cover it.
 *
 *   node tests/integration/video-cover.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const setCover = (v) => sql(`UPDATE #__extensions SET params = JSON_SET(params, '$.speed_video_cover', ${q(v)}) WHERE ${PLUGIN}`);

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const ctx = admin.context();
  await ctx.addCookies([{ name: 'mertools_consent', value: 'f0t0m0.' + Math.floor(Date.now() / 1000), url: URL_ }]);
  // the section and its frame / video as the page lays them out
  const measure = async (width, height) => {
    const page = await ctx.newPage();
    await page.setViewportSize({ width, height: 900 });
    await page.goto(URL_ + '/?mt=' + Date.now(), { waitUntil: 'load', timeout: 90000 });
    const r = await page.evaluate((h) => {
      const add = (inner) => {
        const s = document.createElement('section');
        s.className = 'ba-section';
        s.style.cssText = 'position:relative;width:100%;height:' + h + 'px';
        s.innerHTML = '<div class="ba-video-background">' + inner + '</div>';
        document.body.prepend(s);
        return s;
      };
      const a = add('<iframe src="about:blank" style="border:0"></iframe>');
      const b = add('<div><video width="1920" height="1080"></video></div>');
      const box = (e) => { const x = e.getBoundingClientRect(); return [x.left, x.top, x.width, x.height].map(Math.round); };
      return { style: !!document.getElementById('mertools-vcover'), sec: box(a), frame: box(a.querySelector('iframe')), sec2: box(b),
        video: box(b.querySelector('video')), fit: getComputedStyle(b.querySelector('video')).objectFit };
    }, height);
    await page.close();
    return r;
  };
  const covers = (s, f) => f[0] <= s[0] + 1 && f[1] <= s[1] + 1 && f[0] + f[2] >= s[0] + s[2] - 1 && f[1] + f[3] >= s[1] + s[3] - 1;
  const centred = (s, f) => Math.abs((f[0] + f[2] / 2) - (s[0] + s[2] / 2)) <= 2 && Math.abs((f[1] + f[3] / 2) - (s[1] + s[3] / 2)) <= 2;
  try {
    setCover('1');
    const on = await measure(600, 725);
    check('on: the style on the page', on.style, JSON.stringify(on));
    check('on, 600 px, a section of 725 px: the frame covers it, centred', covers(on.sec, on.frame) && centred(on.sec, on.frame), JSON.stringify(on));
    check('on: the frame 16:9, just large enough (725 × 16/9 wide, not Gridbox\'s 200vh/.5624 of phones)',
      Math.abs(on.frame[2] / on.frame[3] - 16 / 9) < 0.02 && Math.abs(on.frame[2] - 725 * 16 / 9) <= 2, JSON.stringify(on.frame));
    const desk = await measure(1270, 900);
    check('on, 1270 px, a section of 900 px: covered, centred', covers(desk.sec, desk.frame) && centred(desk.sec, desk.frame), JSON.stringify(desk));
    check('on: the video covers its section (object-fit:cover)', covers(on.sec2, on.video) && on.fit === 'cover', JSON.stringify(on));
    const wide = await measure(1920, 725);
    check('on, 1920 px: the frame as wide as the window (as Gridbox had it)', covers(wide.sec, wide.frame) && Math.abs(wide.frame[2] - 1920) <= 2,
      JSON.stringify(wide));
    setCover('0');
    const off = await measure(1270, 900);
    check('off, 1270 px, a section of 900 px: no style, Gridbox\'s bands as before', !off.style && !covers(off.sec, off.frame), JSON.stringify(off));
  } finally {
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
