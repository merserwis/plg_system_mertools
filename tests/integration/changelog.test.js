/**
 * Integration test of the list of changes in Joomla's updates (1.15.1), on the docker Joomla + Gridbox:
 * the package installed has the address of changelog.xml (from its manifest), every entry of update.xml
 * has it too, and Joomla's own changelog (com_installer, as its Changelog button loads it) shows the
 * titles of the version from changelog.xml — the version installed (Extensions: Manage) and a version
 * offered (Extensions: Update, an update of the test in #__updates); a fix version also the titles of the
 * versions of its line before it, marked by version (1.17.1); every version two links, each one point:
 * its GitHub release and all of them. changelog.xml is served from the copy itself for the test (the one
 * on GitHub is the one published), removed afterwards.
 *
 *   node tests/integration/changelog.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { ROOT, C, ADMIN, check, sql, run, install } = require('./lib');

const URL_CL = 'https://raw.githubusercontent.com/merserwis/plg_system_mertools/main/changelog.xml';
const FILE = 'mt-changelog-test.xml';

run(async ({ login }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  const version = /<version>([^<]+)/.exec(fs.readFileSync(path.join(ROOT, 'pkg_mertools.xml'), 'utf8'))[1];
  const [eid, url, cache] = sql("SELECT extension_id, changelogurl, manifest_cache FROM #__extensions WHERE element = 'pkg_mertools'")[0];
  check('the package installed knows its changelog', url === URL_CL, url);
  const upd = fs.readFileSync(path.join(ROOT, 'update.xml'), 'utf8');
  check('every entry of update.xml has it', (upd.match(/<update>/g) || []).length === (upd.match(new RegExp('<changelogurl>' + URL_CL.replace(/\./g, '\\.') + '</changelogurl>', 'g')) || []).length);
  const cl = fs.readFileSync(path.join(ROOT, 'changelog.xml'), 'utf8');
  check('changelog.xml has the version of the package', cl.includes('<version>' + version + '</version>'));
  const titles = (v) => {
    const m = new RegExp('<version>' + v.replace(/\./g, '\\.') + '</version>([\\s\\S]*?)</changelog>').exec(cl);
    return m ? [...m[1].matchAll(/<item>([^<]*)<\/item>/g)].map((x) => x[1].replace(/&amp;/g, '&')) : [];
  };
  /** a title shown by Joomla as one point of the list (its parser cuts text at a character beyond ASCII) */
  const one = (html, t) => html.includes('<li>' + t.replace(/&/g, '&amp;') + '</li>');
  check('changelog.xml: plain ASCII, no entities', /^[\x09\x0a\x0d\x20-\x7e]*$/.test(cl.replace(/^<\?xml[^>]*>/, '')) && !/&/.test(cl));
  execFileSync('docker', ['exec', '-i', C, 'sh', '-c', `cat > /var/www/html/${FILE}`], { input: cl });
  let updateId = null;
  try {
    // Joomla fetches the file itself: from the copy, at the address it has inside its container
    // (Extensions: Manage takes it from the manifest kept at installation, Extensions: Update from #__updates)
    sql(`UPDATE #__extensions SET changelogurl = 'http://127.0.0.1/${FILE}',
      manifest_cache = JSON_SET(manifest_cache, '$.changelogurl', 'http://127.0.0.1/${FILE}') WHERE extension_id = ${eid}`);
    const shown = async (source) => {
      await page.goto(ADMIN + `index.php?option=com_installer&task=manage.loadChangelogRaw&eid=${eid}&source=${source}&format=raw`);
      return page.content();
    };
    let text = await shown('manage');
    const mine = titles(version);
    check('Extensions: Manage: each title of the version installed one point', mine.length > 0 && mine.every((t) => one(text, t)), text.slice(0, 600));
    const parts = text.split('changelog__item');
    const part = (t) => parts.findIndex((p) => one(p, t));
    check('…a fix and a new feature under their own headings', part(mine[0]) > 0 && part(mine[mine.length - 1]) > part(mine[0]), text.slice(0, 600));
    const [maj, min, fix] = version.split('.');
    if (Number(fix) > 0) {
      const first = titles(maj + '.' + min + '.0');
      check('…a fix version: the titles of ' + maj + '.' + min + '.0 too, marked by it', first.length > 0 && first.every((t) => one(text, maj + '.' + min + '.0: ' + t)), text.slice(0, 900));
    }
    const link = (href, t) => '<li><a href="' + href + '" target="_blank" rel="noopener noreferrer">' + t + '</a></li>';
    const REL = 'https://github.com/merserwis/plg_system_mertools/releases';
    check('…its GitHub release and all of them, links of one point each',
      text.includes(link(REL + '/tag/v' + version, 'The full list of changes of ' + version + ' (GitHub)'))
      && text.includes(link(REL, 'All versions and their changes (GitHub releases)')), text.slice(-900));
    // an update offered: the version of update.xml's entry, as Joomla keeps it in #__updates
    sql(`INSERT INTO #__updates (update_site_id, extension_id, name, description, element, type, folder, client_id, version, data, detailsurl, infourl, changelogurl, extra_query)
      VALUES (0, ${eid}, 'MerTools test', '', 'pkg_mertools', 'package', '', 0, '1.15.0', '', '', '', 'http://127.0.0.1/${FILE}', '')`);
    updateId = sql(`SELECT MAX(update_id) FROM #__updates WHERE name = 'MerTools test'`)[0][0];
    text = await shown('update');
    const offered = titles('1.15.0');
    check('Extensions: Update: each title of the version offered one point, not those of another', offered.length > 0 && offered.every((t) => one(text, t)) && !text.includes(mine[0]), text.slice(0, 600));
    check('…its own release linked', text.includes('/releases/tag/v1.15.0"') && !text.includes('/releases/tag/v' + version + '"'), text.slice(-900));
  } finally {
    if (updateId) sql(`DELETE FROM #__updates WHERE update_id = ${updateId}`);
    sql(`UPDATE #__extensions SET changelogurl = '${url}', manifest_cache = CONVERT(UNHEX('${Buffer.from(cache, 'utf8').toString('hex')}') USING utf8mb4)
      WHERE extension_id = ${eid}`);
    execFileSync('docker', ['exec', C, 'rm', '-f', `/var/www/html/${FILE}`]);
  }
});
