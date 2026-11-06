/**
 * Integration test of files of two versions of MerTools side by side (1.13.2), on the docker Joomla + Gridbox:
 * after the update to 1.13.1 PHP ran the new GridboxLists.php with the old Compress.php from OPcache, and
 * every page of the administrator and of the site showed "Call to undefined method …Compress::boxes()"
 * for minutes. Files changed in the container stand for the old ones:
 *  - Compress.php without boxes(): the administrator and the site open, the administrator tells why MerTools
 *    paused, the log has the error, and the guard of Gridbox's sorting still works (it comes after the step
 *    that fails);
 *  - Layout.php without css() (onBeforeCompileHead): the page made without it is not kept in MerTools' cache;
 *  - any other error (a TypeError), or the same mix with Joomla's debug on: the page still breaks, as before.
 * With the right files back the message is gone and pages are kept again.
 *
 *   node tests/integration/stale-code.test.js [--install]
 */
'use strict';
const { execFileSync } = require('child_process');
const { C, ADMIN, URL_, check, run, install } = require('./lib');

const SRC = '/var/www/html/plugins/system/mertools/src';
const COMPRESS = SRC + '/Feature/Compress.php';
const LAYOUT = SRC + '/Tool/Layout.php';
const CONFIG = '/var/www/html/configuration.php';
const LOG = '/var/www/html/administrator/logs/plg_system_mertools.php';
const sh = (cmd) => execFileSync('docker', ['exec', C, 'sh', '-c', cmd], { encoding: 'utf8' });
const logSize = () => Number(sh(`[ -f ${LOG} ] && stat -c %s ${LOG} || echo 0`).trim());
// PHP looks at a changed file again after opcache.revalidate_freq (2 s on the copy)
const settle = (page) => page.waitForTimeout(2600);
/** a file changed for the time of body(), put back afterwards */
const changed = async (page, file, perl, body) => {
  sh(`cp -p ${file} ${file}.mtbak && perl -0pi -e '${perl}' ${file}`);
  try {
    await settle(page);
    await body();
  } finally {
    sh(`mv ${file}.mtbak ${file} && chown www-data:www-data ${file}`);
    await settle(page);
  }
};
/** a guest's request (no cookies, redirects not followed); its scripts are not run: the copy lacks the site's own files */
const guest = async (path) => {
  const res = await fetch(URL_ + path, { redirect: 'manual' });
  return { status: res.status, location: res.headers.get('location') || '', cache: res.headers.get('x-mertools-cache') || '', html: await res.text() };
};
const emptyPageCache = () => sh('rm -rf /var/www/html/cache/mertools_page/*');

run(async ({ login }) => {
  const page = await login('light');
  if (process.argv.includes('--install')) {
    await install(page);
  }
  const open = async (url) => {
    const res = await page.goto(url);
    const html = await page.content();
    const warning = await page.$$eval('joomla-alert[type="warning"]', (a) => a.map((x) => x.textContent)).catch(() => []);
    return { status: res.status(), broken: /undefined method|Wystąpił błąd|An error has occurred|strlen\(\)/.test(html), told: warning.some((t) => /OPcache/.test(t) && /MerTools/.test(t)) };
  };
  const noBoxes = 's/public function boxes\\(\\)/public function boxesNotYet()/';

  // 1. the mix of 1.13.1: Compress.php without boxes()
  const before = logSize();
  await changed(page, COMPRESS, noBoxes, async () => {
    const pages = [ADMIN + 'index.php', ADMIN + 'index.php?option=com_gridbox&view=pages', ADMIN + 'index.php?option=com_mertools', ADMIN + 'index.php'];
    let told = false;
    for (const url of pages) {
      const r = await open(url);
      check('administrator opens with the old Compress.php: ' + url.replace(ADMIN, ''), r.status === 200 && !r.broken, JSON.stringify(r));
      told = told || r.told;
    }
    check('the administrator tells why MerTools paused (OPcache)', told);
    const site = await guest('/?mtstale=' + Date.now());
    check('the site opens with the old Compress.php', site.status === 200 && !/undefined method/.test(site.html) && /<\/html>/i.test(site.html), String(site.status));
    check('nothing told on the site', !/OPcache/.test(site.html));
    // the upload watcher fails before the guard of the sorting: the guard still takes sort-by out
    const sorted = await guest('/index.php?option=com_gridbox&view=page&id=1&sort-by=evil');
    check('the guard of Gridbox\'s sorting still works (301 without sort-by)', sorted.status === 301 && !/sort-by/.test(sorted.location), sorted.status + ' ' + sorted.location);
    const log = sh(`[ -f ${LOG} ] && tail -c +${before + 1} ${LOG} || true`);
    check('the error in the log of MerTools', /Files of two versions of MerTools/.test(log) && /Compress::boxes\(\)/.test(log), log.slice(-400));
  });

  // 2. a mix in onBeforeCompileHead: the page made without it is not kept for the next visitors
  await changed(page, LAYOUT, 's/public static function css\\(\\)/public static function cssNotYet()/', async () => {
    emptyPageCache();
    const first = await guest('/');
    const second = await guest('/');
    check('the site opens with the old Layout.php', first.status === 200 && second.status === 200 && /<\/html>/i.test(second.html), first.status + ' ' + second.status);
    check('a page made with the old Layout.php is not kept', second.cache !== 'HIT', 'second: ' + second.cache);
  });
  emptyPageCache();
  const kept1 = await guest('/');
  const kept2 = await guest('/');
  check('the right Layout.php back: the page is kept again', kept2.cache === 'HIT', kept1.cache + ' → ' + kept2.cache);

  // 3. any other error still breaks the page
  await changed(page, COMPRESS, 's/(public function uploadWatch\\(\\): void\\n    \\{)/$1 strlen([]);/', async () => {
    const r = await open(ADMIN + 'index.php');
    check('another error (TypeError) is not passed over', r.status === 500 && r.broken, JSON.stringify(r));
  });

  // 4. with Joomla's debug on, a mix of versions shows as before
  sh(`chmod u+w ${CONFIG} && sed -i 's/public \\$debug = false;/public $debug = true;/' ${CONFIG}`);
  try {
    await changed(page, COMPRESS, noBoxes, async () => {
      const r = await open(ADMIN + 'index.php');
      check('with debug on the mix of versions shows', r.status === 500 && r.broken, JSON.stringify(r));
    });
  } finally {
    sh(`sed -i 's/public \\$debug = true;/public $debug = false;/' ${CONFIG} && chmod 444 ${CONFIG}`);
  }
  check('debug off again', /public \$debug = false;/.test(sh(`grep debug ${CONFIG}`)));
  await settle(page);

  // a message the last page left for the next one may still come once
  await open(ADMIN + 'index.php');
  const r = await open(ADMIN + 'index.php');
  check('the right files back: no message', r.status === 200 && !r.broken && !r.told, JSON.stringify(r));
});
