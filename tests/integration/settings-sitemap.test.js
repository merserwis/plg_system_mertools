/**
 * Integration test of the settings in a file (export, import) and of other sitemaps in the index, on the
 * docker Joomla + Gridbox (see lib.js). The settings of MerTools on the copy are put back as they were.
 *
 *   node tests/integration/settings-sitemap.test.js [--install]
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { ADMIN, URL_, check, sql, run, install } = require('./lib');

const params = () => JSON.parse(sql("SELECT params FROM #__extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'mertools'")[0][0]);

run(async ({ login }) => {
  const page = await login();
  if (process.argv.includes('--install')) {
    await install(page);
  }
  const before = sql("SELECT params FROM #__extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'mertools'")[0][0];
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mt-settings-'));
  try {
    // ------------------------------------------------------------ export
    await page.goto(ADMIN + 'index.php?option=com_mertools');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('form input[name="task"][value="settings.export"] ~ button, form:has(input[value="settings.export"]) button')]);
    const file = path.join(dir, download.suggestedFilename());
    await download.saveAs(file);
    const data = JSON.parse(fs.readFileSync(file, 'utf8'));
    const saved = params();
    check('export: a file of MerTools settings, named after the site', data.format === 'mertools-settings' && /^mertools-settings-.+-\d{4}-\d{2}-\d{2}\.json$/.test(download.suggestedFilename()), download.suggestedFilename());
    check('export: the saved values', data.settings.sitemap_enabled === saved.sitemap_enabled && data.settings.list_dark === (saved.list_dark ?? '1'));
    check('export: the PageSpeed key left out', !('speed_key' in data.settings) && !(saved.speed_key && JSON.stringify(data).includes(saved.speed_key)));
    check('export: no panels or internal keys', !('sitemap_panel' in data.settings) && !('setup_done' in data.settings));

    // ------------------------------------------------------------ import: changed values, other sitemaps, unknown keys
    const exported = { ...data.settings };
    const extra = ['/index.php?option=com_gallery&view=sitemap&format=xml', '/sitemap-galeria.xml?lang=pl', 'https://other.example.org/map.xml', '/sitemap.xml?source=x'];
    data.settings.list_pins = '0';
    data.settings.sitemap_extra = extra.join('\n');
    data.settings.from_a_newer_version = '1';
    data.settings.speed_key = 'NOT-TAKEN';
    fs.writeFileSync(file, JSON.stringify(data));
    page.once('dialog', (d) => d.accept());
    await page.setInputFiles('#mt_settings_file', file);
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[value="settings.import"]) button')]);
    const messages = await page.evaluate(() => [...document.querySelectorAll('joomla-alert')].map((a) => a.innerText.replace(/\s+/g, ' ')).join(' | '));
    const now = params();
    check('import: reported as done', /Imported \d+ settings|Zaimportowano ustawień: \d+/.test(messages), messages);
    check('import: unknown and secret keys listed as left out', messages.includes('from_a_newer_version') && messages.includes('speed_key'), messages);
    check('import: the changed values saved', now.list_pins === '0' && now.sitemap_extra === extra.join('\n'));
    check('import: the secret of this site kept', now.speed_key === saved.speed_key);
    // the other settings: as in the file (a default never saved before is now saved with the same value)
    const same = (a, b) => JSON.stringify(Array.isArray(a) ? a.map(String) : String(a)) === JSON.stringify(Array.isArray(b) ? b.map(String) : String(b));
    const differ = Object.keys(exported).filter((k) => !['list_pins', 'sitemap_extra'].includes(k) && !same(now[k], exported[k]));
    check('import: every other setting as in the file', differ.length === 0, differ.map((k) => k + '=' + JSON.stringify([exported[k], now[k]])).join(' '));

    // a file with some settings only: the others stay as they are
    const importFile = async (settings) => {
      fs.writeFileSync(file, JSON.stringify({ format: 'mertools-settings', version: data.version, site: data.site, settings }));
      page.once('dialog', (d) => d.accept());
      await page.setInputFiles('#mt_settings_file', file);
      await Promise.all([page.waitForNavigation(), page.click('form:has(input[value="settings.import"]) button')]);
    };
    await importFile({ list_pins: '1' });
    const partial = params();
    check('partial import: its setting saved', partial.list_pins === '1');
    check('partial import: the settings not in the file kept', partial.sitemap_extra === extra.join('\n') && same(partial.list_dark, exported.list_dark));
    // an empty list of apps in the file empties the list on the site
    check('export: a list of apps never chosen exported as an empty list', Array.isArray(exported.apps_unlocked), JSON.stringify(exported.apps_unlocked));
    await importFile({ apps_unlocked: ['7'] });
    check('import: a list of apps set', same(params().apps_unlocked, ['7']), JSON.stringify(params().apps_unlocked));
    await importFile({ apps_unlocked: [] });
    const emptied = params().apps_unlocked;
    check('import: an empty list empties it again', emptied === undefined || emptied === '' || (Array.isArray(emptied) && emptied.length === 0)
      || (typeof emptied === 'object' && emptied !== null && Object.keys(emptied).length === 0), JSON.stringify(emptied));

    // a file that is not one of settings
    const kept = JSON.stringify(params());
    fs.writeFileSync(file, '{"hello":"world"}');
    page.once('dialog', (d) => d.accept());
    await page.setInputFiles('#mt_settings_file', file);
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[value="settings.import"]) button')]);
    const refused = await page.evaluate(() => document.querySelector('joomla-alert[type="danger"]')?.innerText || '');
    check('import: another JSON refused, nothing changed', refused.length > 0 && JSON.stringify(params()) === kept, refused);

    // ------------------------------------------------------------ the index lists the other sitemaps
    await page.goto(ADMIN + 'index.php?option=com_mertools&view=page&page=sitemap');
    const status = await page.evaluate(async () => {
      const fd = new FormData();
      fd.append('mertools_action', 'sm_rebuild');
      fd.append(Joomla.getOptions('csrf.token'), '1');
      await fetch('index.php', { method: 'POST', body: fd });
      const st = new FormData();
      st.append('mertools_action', 'sm_status');
      st.append(Joomla.getOptions('csrf.token'), '1');
      return (await fetch('index.php', { method: 'POST', body: st })).json();
    });
    const index = await (await fetch(URL_ + '/sitemap.xml')).text();
    const domain = /<loc>(https?:\/\/[^/<]+)/.exec(index)[1];
    check('index: a path of the site added', index.includes('<loc>' + domain + '/index.php?option=com_gallery&amp;view=sitemap&amp;format=xml</loc>'), index.slice(0, 600));
    check('index: a sitemap-….xml of another extension with parameters added', index.includes('<loc>' + domain + '/sitemap-galeria.xml?lang=pl</loc>'));
    check('index: never itself', !index.includes('sitemap.xml?source=x'));
    check("index: another site's map left out", !index.includes('other.example.org'));
    check("index: MerTools' own maps still listed", /<loc>[^<]+\/sitemap-pages\.xml<\/loc>/.test(index));
    check('panel: the added and the left-out maps', (status.extra || []).length === 2 && (status.skipped || []).includes('https://other.example.org/map.xml') && (status.skipped || []).includes('/sitemap.xml?source=x'), JSON.stringify([status.extra, status.skipped]));
    const other = await fetch(URL_ + '/sitemap-galeria.xml?lang=pl');
    const otherBody = await other.text();
    check("a listed map named sitemap-….xml is not MerTools' 404", !otherBody.includes('<error>Not Found</error>'), other.status + ' ' + otherBody.slice(0, 100));
    const own = await fetch(URL_ + '/sitemap-nothing-here.xml');
    check("a map of nobody: MerTools' own 404", own.status === 404 && (await own.text()).includes('<error>Not Found</error>'));
  } finally {
    // the settings of the copy as they were, and its maps built with them
    sql(`UPDATE #__extensions SET params = FROM_BASE64('${Buffer.from(before, 'utf8').toString('base64')}') WHERE type = 'plugin' AND folder = 'system' AND element = 'mertools'`);
    check('settings of the copy put back', JSON.stringify(params()) === JSON.stringify(JSON.parse(before)));
    await page.goto(ADMIN + 'index.php?option=com_mertools&view=page&page=sitemap');
    await page.evaluate(async () => {
      const fd = new FormData();
      fd.append('mertools_action', 'sm_rebuild');
      fd.append(Joomla.getOptions('csrf.token'), '1');
      await fetch('index.php', { method: 'POST', body: fd });
    });
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
