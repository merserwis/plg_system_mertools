/**
 * Integration test of the AI bots (Sitemap → AI bots), on the docker Joomla: the settings saved on MerTools' page in the
 * administrator as a person does it (Save of com_mertools: Joomla's plugin model, so onExtensionAfterSave).
 *  - two bots ticked: robots.txt gets MerTools' block at its end, the rest of the file byte for byte, a copy of the old
 *    file in Joomla's tmp folder, a message; the table of the tab shows them blocked, another bot as "*" says;
 *  - none ticked: robots.txt exactly as it was before;
 *  - llms.txt: answered by Joomla with the site's name and menu, or the text of the settings; off: not there.
 * robots.txt and the settings put back afterwards.
 *
 *   node tests/integration/aibots.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, URL_, check, sql, run, install } = require('./lib');

const PLUGIN = "element = 'mertools' AND folder = 'system' AND type = 'plugin'";
const params = sql(`SELECT params FROM #__extensions WHERE ${PLUGIN}`)[0][0];
const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const sh = (cmd, input) => execFileSync('docker', ['exec', '-i', C, 'sh', '-c', cmd], { encoding: 'utf8', input });
const ROBOTS = '/var/www/html/robots.txt';
const had = sh(`test -f ${ROBOTS} && echo yes || echo no`).trim() === 'yes';
const original = had ? sh(`cat ${ROBOTS}`) : null;
// a llms.txt of the site's own is served before Joomla (and wins): out of the way while the test runs
const LLMS = '/var/www/html/llms.txt';
const ownLlms = sh(`test -f ${LLMS} && echo yes || echo no`).trim() === 'yes';

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  // the settings form: the boxes ticked as given, saved with Joomla's own Save
  const saveWith = async (bots, llms = {}) => {
    await admin.goto(URL_ + '/administrator/index.php?option=com_mertools&view=page&page=sitemap', { waitUntil: 'load' });
    await admin.evaluate(([b, l]) => {
      document.querySelectorAll('input[name="jform[params][aibots_block][]"]').forEach((i) => { i.checked = b.includes(i.value); });
      const on = document.querySelector('input[name="jform[params][llms_enabled]"][value="' + (l.on ? 1 : 0) + '"]');
      if (on) on.checked = true;
      const text = document.querySelector('textarea[name="jform[params][llms_text]"]');
      if (text) text.value = l.text || '';
      Joomla.submitbutton('page.apply');
    }, [bots, llms]);
    await admin.waitForLoadState('load');
    await admin.waitForTimeout(1000);
    return admin.evaluate(() => [...document.querySelectorAll('joomla-alert, .alert')].map((a) => a.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
  };
  const table = () => admin.evaluate(() => Object.fromEntries([...document.querySelectorAll('.mt-aibots tbody tr')]
    .map((tr) => [tr.querySelector('code').textContent, tr.querySelector('.badge').className.replace(/.*bg-/, '')])));
  try {
    check('the copy has a robots.txt to keep', had);
    if (ownLlms) sh(`mv ${LLMS} ${LLMS}.mt-test`);
    sh('rm -f /var/www/html/tmp/mertools-robots-*.txt');
    let said = await saveWith(['GPTBot', 'PerplexityBot']);
    const now = sh(`cat ${ROBOTS}`);
    check('robots.txt: the old file after the block, byte for byte', now.endsWith(original) && now.length > original.length, now.slice(-200));
    check('robots.txt: the block at its start with the two bots, in the file\'s line endings', /^# BEGIN MerTools: AI bots\r?\n[^]*User-agent: GPTBot\r?\nDisallow: \/\r?\n\r?\nUser-agent: PerplexityBot\r?\nDisallow: \/\r?\n# END MerTools: AI bots\r?\n\r?\n/.test(now)
      && (original.includes('\r\n') ? !/[^\r]\n/.test(now) : true), JSON.stringify(now.slice(0, 300)));
    check('a message names them', /GPTBot, PerplexityBot/.test(said), said);
    const copies = sh('ls /var/www/html/tmp/mertools-robots-*.txt 2>/dev/null || true').trim().split('\n').filter(Boolean);
    check('a copy of the old file in Joomla\'s tmp folder', copies.length === 1 && sh(`cat ${copies[0]}`) === original, JSON.stringify(copies));
    const t = await table();
    check('the table: the two blocked, ClaudeBot as "*" says (not blocked)', t.GPTBot === 'danger' && t.PerplexityBot === 'danger' && t.ClaudeBot !== 'danger', JSON.stringify(t));
    said = await saveWith(['GPTBot', 'PerplexityBot']);
    check('saved again unchanged: no new copy', sh('ls /var/www/html/tmp/mertools-robots-*.txt | wc -l').trim() === '1');

    // no copy possible (tmp not writable): robots.txt not changed, said
    const blocked = sh(`cat ${ROBOTS}`);
    sh('chmod 555 /var/www/html/tmp');
    try {
      said = await saveWith(['ClaudeBot']);
    } finally {
      sh('chmod 755 /var/www/html/tmp');
    }
    check('no copy of robots.txt possible: the file not changed, a warning', sh(`cat ${ROBOTS}`) === blocked && /(kopii|copied)/.test(said), said);

    said = await saveWith([], { on: true });
    check('none ticked: robots.txt exactly as before', sh(`cat ${ROBOTS}`) === original, sh(`cat ${ROBOTS}`).slice(-200));
    check('a message says the block went', said.length > 0, said);

    const llms = await admin.request.get(URL_ + '/llms.txt');
    const body = await llms.text();
    check('llms.txt: answered, text/plain, the site\'s name as its heading, a list of pages', llms.status() === 200 && /^text\/plain/.test(llms.headers()['content-type'])
      && /^# \S/.test(body) && /^- \[.+\]\(https?:\/\/.+\)$/m.test(body), llms.status() + ' ' + body.slice(0, 300));
    await saveWith([], { on: true, text: '# Moja strona\n\n> Opis' });
    const own = await (await admin.request.get(URL_ + '/llms.txt')).text();
    check('llms.txt: the text of the settings', own === '# Moja strona\n\n> Opis\n', JSON.stringify(own));
    await saveWith([], { on: false });
    const gone = await admin.request.get(URL_ + '/llms.txt');
    check('llms.txt off: not answered by MerTools', gone.status() !== 200 || !/Moja strona/.test(await gone.text()), String(gone.status()));
  } finally {
    if (had) sh(`cat > ${ROBOTS}`, original);
    if (ownLlms) sh(`mv ${LLMS}.mt-test ${LLMS}`);
    sh('rm -f /var/www/html/tmp/mertools-robots-*.txt');
    sql(`UPDATE #__extensions SET params = ${q(params)} WHERE ${PLUGIN}`);
  }
});
