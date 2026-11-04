/**
 * Shared by the integration tests on the docker Joomla + Gridbox (the test copy gbx-sitemap-test):
 * a temporary Super User, queries on the copy's database with Joomla's own connection, login with
 * Playwright, installing the built package. Env: MT_CONTAINER, MT_URL, PLAYWRIGHT.
 */
'use strict';
const { execFileSync, spawnSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');
const C = process.env.MT_CONTAINER || 'gbx-sitemap-test';
const URL_ = (process.env.MT_URL || 'http://127.0.0.1:18096').replace(/\/$/, '');
const ADMIN = URL_ + '/administrator/';
const { chromium } = require(process.env.PLAYWRIGHT || '/home/administrator2/.npm/_npx/705bc6b22212b352/node_modules/playwright-core');

let fail = 0;
const check = (label, ok, info = '') => {
  if (!ok) fail++;
  console.log((ok ? 'OK   ' : 'FAIL ') + label + (ok || !info ? '' : '  ' + info));
};
const cli = (...args) => execFileSync('docker', ['exec', '-u', 'www-data', C, 'php', '/var/www/html/cli/joomla.php', ...args, '-n'], { encoding: 'utf8' });
/** a query on the copy's database, with Joomla's own connection settings (nothing secret leaves the container) */
const sql = (query) => {
  const php = '$c = new JConfig(); $p = new PDO("mysql:host=" . $c->host . ";dbname=" . $c->db . ";charset=utf8mb4", $c->user, $c->password);'
    + ' $s = $p->query(str_replace("#__", $c->dbprefix, stream_get_contents(STDIN))); echo json_encode($s->columnCount() ? $s->fetchAll(PDO::FETCH_NUM) : []);';
  const out = spawnSync('docker', ['exec', '-i', C, 'php', '-r', 'require "/var/www/html/configuration.php"; ' + php], { input: query, encoding: 'utf8' });
  if (out.status !== 0) throw new Error(out.stderr || out.stdout);
  return JSON.parse(out.stdout);
};

/** runs body(t) with a temporary Super User (t.login(scheme) gives a logged-in page), removed afterwards */
const run = async (body) => {
  const user = 'mtit' + crypto.randomBytes(3).toString('hex');
  const pass = crypto.randomBytes(12).toString('hex');
  cli('user:add', '--username=' + user, '--name=MerTools test', '--password=' + pass, '--email=' + user + '@example.invalid', '--usergroup=Super Users');
  const uid = Number(sql(`SELECT id FROM #__users WHERE username = '${user}'`)[0][0]);
  const browser = await chromium.launch();
  const login = async (scheme = 'light') => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scheme, acceptDownloads: true });
    await ctx.addCookies([{ name: 'userColorScheme', value: scheme, url: URL_ }]);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => check('no script error on ' + page.url(), false, e.message));
    await page.goto(ADMIN);
    await page.fill('#mod-login-username', user);
    await page.fill('#mod-login-password', pass);
    await Promise.all([page.waitForNavigation(), page.click('#btn-login-submit')]);
    return page;
  };
  try {
    await body({ login, uid });
  } catch (e) {
    check('test ran to the end', false, e.stack);
  } finally {
    await browser.close();
    sql(`DELETE FROM #__user_profiles WHERE user_id = ${uid}`);
    cli('user:delete', '--username=' + user);
  }
  console.log(fail ? 'FAILED: ' + fail : 'ALL PASS');
  process.exit(fail ? 1 : 0);
};

/** installs pkg_mertools-<version>.zip (./build.sh) through Joomla's installer, as an administrator does */
const install = async (page) => {
  const version = /<version>([^<]+)/.exec(fs.readFileSync(path.join(ROOT, 'pkg_mertools.xml'), 'utf8'))[1];
  // files copied in by hand keep the host's owner: the installer could not overwrite them
  execFileSync('docker', ['exec', C, 'sh', '-c', 'cd /var/www/html && find administrator/language plugins/system/mertools media/plg_system_mertools media/com_mertools administrator/components/com_mertools ! -user www-data -exec chown www-data:www-data {} +']);
  await page.goto(ADMIN + 'index.php?option=com_installer&view=install');
  await page.setInputFiles('#install_package', path.join(ROOT, 'pkg_mertools-' + version + '.zip'));
  await page.waitForSelector('joomla-alert[type="success"], joomla-alert[type="danger"]', { timeout: 60000 });
  const installed = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache, '$.version')) FROM #__extensions WHERE element = 'pkg_mertools'")[0][0];
  check('package ' + version + ' installed', installed === version, 'installed ' + installed);
};

module.exports = { ROOT, C, URL_, ADMIN, check, cli, sql, run, install };
