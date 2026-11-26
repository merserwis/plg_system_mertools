/**
 * Integration test of Gridbox's Video element with lazy loading (its own picture of the film, .video-lazy-load-thumbnail)
 * played by one click (Speedup::youtubeLazyPlay, setting speed_youtube), on the docker Joomla + Gridbox 2.20.5:
 * a page with two Video elements of YouTube gets lazyLoad for the test (its style is put back afterwards).
 *  - off: Gridbox's own frame after a click has autoplay=0 (the second click the fix is about: the check sees the difference);
 *  - on, with the cookie consent's YouTube without cookies: the frame of the clicked picture with autoplay=1 on
 *    youtube-nocookie.com, its other parameters as Gridbox wrote them, the other picture still a picture;
 *  - on, without the consent: autoplay=1 on Gridbox's www.youtube.com (the wrap alone, not after Consent's);
 *  - a page without such a picture gets no script.
 * Whether the film then plays with sound needs a real browser (headless Chromium does not play YouTube).
 *
 *   node tests/integration/video-lazy-play.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const setParams = (pairs) => sql(`UPDATE #__extensions SET params = JSON_SET(params, ${Object.entries(pairs).map(([k, v]) => `'$.${k}', ${q(v)}`).join(', ')}) WHERE ${PLUGIN}`);

// a published page with two or more Video elements of YouTube (not videos in the backgrounds of sections)
const youtubeVideos = (items) => Object.keys(items).filter((k) => items[k] && items[k].type === 'video' && items[k].video && items[k].video.type === 'youtube');
const styleOf = (id) => sql(`SELECT style FROM #__gridbox_pages WHERE id = ${Number(id)}`)[0][0];
const count = (s) => { try { return youtubeVideos(JSON.parse(s)).length; } catch (e) { return 0; } };
const ID = Number((sql(`SELECT id FROM #__gridbox_pages WHERE published = 1 AND page_category <> 'trashed'
  AND style LIKE '%"type":"video"%' AND style LIKE '%"video":{"type":"youtube"%' ORDER BY id`)
  .find(([id]) => count(styleOf(id)) >= 2) || [0])[0]);
const style = ID ? styleOf(ID) : '{}';
const items = JSON.parse(style);
const videos = youtubeVideos(items);
const PAGE = URL_ + '/index.php?option=com_gridbox&view=page&id=' + ID;

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  const ctx = admin.context();
  // the consent's window is not in the way: a choice saved (necessary only)
  await ctx.addCookies([{ name: 'mertools_consent', value: 'f0t0m0.' + Math.floor(Date.now() / 1000), url: URL_ }]);
  const open = async (url = PAGE) => {
    const page = await ctx.newPage();
    await page.goto(url + '&mt=' + Date.now(), { waitUntil: 'load', timeout: 90000 });
    await page.waitForTimeout(1500);
    return page;
  };
  // the first picture clicked: the address of the frame written in its place
  const click = async (page) => {
    const pics = await page.$$('.video-lazy-load-thumbnail');
    if (!pics.length) return { pics: 0 };
    await pics[0].scrollIntoViewIfNeeded();
    await pics[0].click();
    await page.waitForTimeout(800);
    return page.evaluate(() => {
      const box = document.querySelectorAll('.ba-item-video .ba-video-wrapper')[0];
      const f = box && box.querySelector('iframe');
      return { src: f ? f.getAttribute('src') : null, allow: f && f.getAttribute('allow'), left: document.querySelectorAll('.video-lazy-load-thumbnail').length };
    });
  };
  try {
    check('a page with two Videos of YouTube: ' + ID, ID > 0 && videos.length >= 2, String(videos.length));
    const lazy = { ...items };
    videos.forEach((k) => { lazy[k] = { ...items[k], lazyLoad: true }; });
    sql(`UPDATE #__gridbox_pages SET style = ${q(JSON.stringify(lazy))} WHERE id = ${ID}`);

    setParams({ speed_youtube: '0', speed_cache: '0', consent_enabled: '1', consent_youtube: '1' });
    let page = await open();
    check('off: no script of MerTools for it', !(await page.content()).includes('mtLazyPlay'));
    let got = await click(page);
    check('off: Gridbox\'s frame with autoplay=0 (the second click)', /[?&]autoplay=0(&|$)/.test(got.src || ''), JSON.stringify(got));
    await page.close();

    setParams({ speed_youtube: '1' });
    page = await open();
    check('on: the script once, before </body>', ((await page.content()).match(/mtLazyPlay=1/g) || []).length === 1);
    check('on: the pictures are Gridbox\'s', (await page.$$('.video-lazy-load-thumbnail')).length === videos.length);
    got = await click(page);
    check('on: one click — the frame with autoplay=1', /[?&]autoplay=1(&|$)/.test(got.src || '') && !/autoplay=0/.test(got.src), JSON.stringify(got));
    check('on: YouTube without cookies still (the consent\'s wrap too)', /^https:\/\/www\.youtube-nocookie\.com\/embed\/[\w-]{11}\?/.test(got.src || ''), got.src);
    check('on: Gridbox\'s other parameters kept', /[?&]controls=1/.test(got.src || '') && /[?&]start=0/.test(got.src || ''), got.src);
    check('on: the frame may play', /autoplay/.test(got.allow || ''), got.allow);
    check('on: the other picture still a picture', got.left === videos.length - 1, String(got.left));
    await page.close();

    setParams({ consent_enabled: '0' });
    page = await open();
    got = await click(page);
    check('on, no consent: autoplay=1 on Gridbox\'s www.youtube.com', /^https:\/\/www\.youtube\.com\/embed\/[\w-]{11}\?.*autoplay=1/.test(got.src || ''), JSON.stringify(got));
    await page.close();

    // a page without Gridbox's picture: nothing added
    const other = Number(sql(`SELECT id FROM #__gridbox_pages WHERE published = 1 AND page_category <> 'trashed' AND id <> ${ID}
      AND style NOT LIKE '%"lazyLoad":true%' ORDER BY id LIMIT 1`)[0][0]);
    page = await open(URL_ + '/index.php?option=com_gridbox&view=page&id=' + other);
    check('a page without such a picture: no script', !(await page.content()).includes('mtLazyPlay'), String(other));
    await page.close();
  } finally {
    sql(`UPDATE #__gridbox_pages SET style = ${q(style)} WHERE id = ${ID}`);
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
    check('the page and the settings put back', sql(`SELECT style FROM #__gridbox_pages WHERE id = ${ID}`)[0][0] === style
      && sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0] === params);
  }
});
