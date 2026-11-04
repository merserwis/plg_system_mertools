/**
 * Integration test of the Gridbox lists and the dark Gridbox panel, on a real Joomla + Gridbox in docker
 * (the test copy gbx-sitemap-test): a temporary Super User logs in with Playwright and clicks through
 * the administrator; the expected orders are read from the database with SQL written here, apart from
 * MerTools. The temporary user and its list settings are removed at the end.
 *
 *   node tests/integration/gridbox-lists.test.js            the MerTools installed on the copy
 *   node tests/integration/gridbox-lists.test.js --install  first installs pkg_mertools-<version>.zip (./build.sh)
 *
 * Env: MT_CONTAINER (gbx-sitemap-test), MT_URL (http://127.0.0.1:18096), PLAYWRIGHT (path of playwright-core).
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

(async () => {
  const user = 'mtit' + crypto.randomBytes(3).toString('hex');
  const pass = crypto.randomBytes(12).toString('hex');
  cli('user:add', '--username=' + user, '--name=MerTools test', '--password=' + pass, '--email=' + user + '@example.invalid', '--usergroup=Super Users');
  const uid = Number(sql(`SELECT id FROM #__users WHERE username = '${user}'`)[0][0]);
  const browser = await chromium.launch();
  try {
    const login = async (scheme) => {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scheme });
      await ctx.addCookies([{ name: 'userColorScheme', value: scheme, url: URL_ }]);
      const page = await ctx.newPage();
      page.on('pageerror', (e) => check('no script error on ' + page.url(), false, e.message));
      await page.goto(ADMIN);
      await page.fill('#mod-login-username', user);
      await page.fill('#mod-login-password', pass);
      await Promise.all([page.waitForNavigation(), page.click('#btn-login-submit')]);
      return page;
    };
    let page = await login('dark');

    if (process.argv.includes('--install')) {
      const version = /<version>([^<]+)/.exec(fs.readFileSync(path.join(ROOT, 'pkg_mertools.xml'), 'utf8'))[1];
      // files copied in by hand keep the host's owner: the installer could not overwrite them
      execFileSync('docker', ['exec', C, 'sh', '-c', 'cd /var/www/html && find administrator/language plugins/system/mertools media/plg_system_mertools media/com_mertools administrator/components/com_mertools ! -user www-data -exec chown www-data:www-data {} +']);
      await page.goto(ADMIN + 'index.php?option=com_installer&view=install');
      await page.setInputFiles('#install_package', path.join(ROOT, 'pkg_mertools-' + version + '.zip'));
      await page.waitForSelector('joomla-alert[type="success"], joomla-alert[type="danger"]', { timeout: 60000 });
      const installed = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(manifest_cache, '$.version')) FROM #__extensions WHERE element = 'pkg_mertools'")[0][0];
      check('package ' + version + ' installed', installed === version, 'installed ' + installed);
    }

    // ------------------------------------------------------------ the list of products and its columns
    const [appId] = sql("SELECT id FROM #__gridbox_app WHERE type = 'products' ORDER BY id LIMIT 1")[0].map(Number);
    const listUrl = ADMIN + 'index.php?option=com_gridbox&view=apps&id=' + appId;
    const post = (data) => page.evaluate(async (data) => {
      const cfg = JSON.parse(document.getElementById('mertools-lists-config').textContent);
      const one = (d) => {
        const fd = new FormData();
        Object.entries(d).forEach(([k, v]) => fd.append(k, v));
        fd.append('list', cfg.list);
        fd.append(cfg.token, '1');
        return fetch(cfg.url, { method: 'POST', body: fd }).then((r) => r.json());
      };
      return Array.isArray(data) ? Promise.all(data.map(one)) : one(data);
    }, data);
    await page.goto(listUrl);
    const builtin = await page.evaluate(() => JSON.parse(document.getElementById('mertools-lists-config').textContent).builtin);
    await post({ mertools_action: 'list_columns', order: JSON.stringify(['title', 'price', 'sale_price', 'tags', 'page_category', 'id']), known: JSON.stringify(builtin) });
    await page.goto(listUrl);
    const rowIds = () => page.evaluate(() => [...document.querySelectorAll('table tbody:not(.mt-ghosts) input[name="cid[]"]')].map((i) => Number(i.value)));
    const limit = (await rowIds()).length;
    check('tags column shown', await page.$('th.mt-col-tags[data-mt-sort="tags"]') !== null);
    const tagCells = await page.evaluate(() => [...document.querySelectorAll('table tbody tr')].slice(0, 5).map((tr) => [Number(tr.querySelector('input[name="cid[]"]').value), tr.querySelector('.mt-col-tags').textContent.trim().replace(/\s+/g, ' ')]));
    const tagsOf = (id) => (sql(`SELECT GROUP_CONCAT(t.title ORDER BY t.title SEPARATOR ' ') FROM #__gridbox_tags_map m JOIN #__gridbox_tags t ON t.id = m.tag_id WHERE m.page_id = ${id}`)[0][0] || '');
    check('tags column: the tags of each product', tagCells.every(([id, text]) => text === tagsOf(id)), JSON.stringify(tagCells));

    // the expected order, from the database: products of the app, not trashed, empty tags last, ties by id
    const expected = (dir) => sql(`SELECT p.id FROM #__gridbox_pages p LEFT JOIN (SELECT m.page_id, GROUP_CONCAT(t.title ORDER BY t.title SEPARATOR ', ') g
      FROM #__gridbox_tags_map m JOIN #__gridbox_tags t ON t.id = m.tag_id GROUP BY m.page_id) x ON x.page_id = p.id
      WHERE p.app_id = ${appId} AND p.page_category <> 'trashed' AND p.published IN (0, 1)
      ORDER BY x.g IS NULL, x.g ${dir}, p.id ${dir} LIMIT ${2 * limit}`).map((r) => Number(r[0]));

    // ------------------------------------------------------------ Gridbox's order dragged by hand, then an added column
    await page.click('.enable-custom-pages-order');
    await page.waitForTimeout(2500);
    await page.goto(listUrl);
    check('Gridbox hand order switched on', await page.evaluate(() => document.querySelector('.enable-custom-pages-order').classList.contains('active')));
    await Promise.all([page.waitForNavigation(), page.click('th[data-mt-sort="tags"]')]);
    check('an added column switches the hand order off', !(await page.evaluate(() => document.querySelector('.enable-custom-pages-order').classList.contains('active'))));
    check('the column marked as sorted ascending', await page.$('th.mt-sorted[data-mt-sort="tags"][data-mt-dir="asc"]') !== null);
    const asc = expected('ASC');
    const page1 = await rowIds();
    check('page 1 in the order of the database', JSON.stringify(page1) === JSON.stringify(asc.slice(0, limit)), JSON.stringify([page1.slice(0, 5), asc.slice(0, 5)]));
    await Promise.all([page.waitForNavigation(), page.click('.pagination__wrapper a[aria-label$=" 2"]')]);
    const page2 = await rowIds();
    check('page 2 continues the order (whole list sorted)', JSON.stringify(page2) === JSON.stringify(asc.slice(limit, 2 * limit)), JSON.stringify([page2.slice(0, 5), asc.slice(limit, limit + 5)]));
    await page.goto(listUrl);
    await Promise.all([page.waitForNavigation(), page.click('th[data-mt-sort="tags"]')]);
    const desc = expected('DESC');
    const descIds = await rowIds();
    check('second click: descending, empty tags still last', JSON.stringify(descIds) === JSON.stringify(desc.slice(0, limit)), JSON.stringify([descIds.slice(0, 5), desc.slice(0, 5)]));
    await page.click('.enable-custom-pages-order');
    await page.waitForTimeout(2500);
    await page.goto(listUrl);
    check('hand order switched on again ends the sorting', await page.$('th.mt-sorted') === null);
    await page.click('.enable-custom-pages-order');
    await page.waitForTimeout(2500);

    // ------------------------------------------------------------ Gridbox's own column brings its sorting back
    await page.goto(listUrl);
    await Promise.all([page.waitForNavigation(), page.click('th[data-mt-sort="tags"]')]);
    await page.click('span[data-sorting="id"]');
    await page.waitForTimeout(2500);
    await page.goto(listUrl);
    check("Gridbox's column ends the sorting", await page.$('th.mt-sorted') === null);

    // ------------------------------------------------------------ pins: two at the same moment, both kept, both first
    const two = (await rowIds()).slice(3, 5);
    const pinned = await post(two.map((id) => ({ mertools_action: 'list_pin', id: String(id), pin: '1' })));
    check('two pins at once: both answered', pinned.every((r) => r.success && r.pinned));
    await page.goto(listUrl);
    const top = (await rowIds()).slice(0, 2).sort((a, b) => a - b);
    check('two pins at once: both kept and listed first', JSON.stringify(top) === JSON.stringify([...two].sort((a, b) => a - b)), JSON.stringify(top));
    await post(two.map((id) => ({ mertools_action: 'list_pin', id: String(id), pin: '0' })));
    await page.goto(listUrl);
    check('unpinned: no pinned row', await page.$('.mt-pin[aria-pressed="true"]') === null);

    // ------------------------------------------------------------ the dark panel: no text too close to its background
    // (that light mode does not have too: Gridbox's own white on its light green tile is its design)
    const unreadable = (page) => page.evaluate(() => {
      const lum = (c) => { const m = c.match(/[\d.]+/g); if (!m || (m.length > 3 && Number(m[3]) === 0)) return null; return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
      const bgOf = (el) => { for (; el; el = el.parentElement) { const l = lum(getComputedStyle(el).backgroundColor); if (l !== null) return l; } return 1; };
      const shown = (el) => { for (; el; el = el.parentElement) { const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return false; } return true; };
      return [...document.querySelectorAll('body *')].filter((el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())
        && el.getBoundingClientRect().width && shown(el) && Math.abs(lum(getComputedStyle(el).color) - bgOf(el)) < 0.3)
        .map((el) => el.className + ' "' + el.textContent.trim().slice(0, 20) + '"');
    });
    const views = ['option=com_gridbox', 'option=com_gridbox&view=pages', 'option=com_gridbox&view=apps&id=' + appId, 'option=com_gridbox&view=tags',
      'option=com_gridbox&view=orders', 'option=com_gridbox&view=storesettings', 'option=com_gridbox&view=system', 'option=com_mertools'];
    const audit = async (page) => {
      const out = {};
      for (const v of views) {
        await page.goto(ADMIN + 'index.php?' + v);
        await page.mouse.move(0, 0);
        await page.waitForTimeout(500);
        out[v] = await unreadable(page);
      }
      return out;
    };
    const darkAudit = await audit(page);
    await page.goto(listUrl);
    await page.click('[data-mt-columns]');
    const pop = await page.evaluate(() => { const p = document.querySelector('.mt-cols-pop'); return [getComputedStyle(p).backgroundColor, getComputedStyle(p.querySelector('label')).color]; });
    check('dark: the columns window is dark with light text', /rgb\((\d{1,2}), (\d{1,2}), (\d{1,2})\)/.test(pop[0]) && !/rgb\(\d{1,2}, /.test(pop[1]), pop.join(' / '));
    await page.context().close();

    // ------------------------------------------------------------ light mode: Gridbox as it is
    page = await login('light');
    await page.goto(listUrl);
    const light = await page.evaluate(() => [document.documentElement.getAttribute('data-color-scheme'), getComputedStyle(document.querySelector('.main-table, table')).backgroundColor]);
    check('light: Gridbox keeps its white list', light[0] === 'light' && light[1] === 'rgb(255, 255, 255)', light.join(' '));
    const lightAudit = await audit(page);
    for (const v of views) {
      const bad = darkAudit[v].filter((t) => !lightAudit[v].includes(t));
      check('dark: every text readable in ' + v, bad.length === 0, bad.slice(0, 5).join(' | '));
    }
    await page.context().close();
  } catch (e) {
    check('test ran to the end', false, e.stack);
  } finally {
    await browser.close();
    sql(`DELETE FROM #__user_profiles WHERE user_id = ${uid}`);
    cli('user:delete', '--username=' + user);
  }
  console.log(fail ? 'FAILED: ' + fail : 'ALL PASS');
  process.exit(fail ? 1 : 0);
})();
