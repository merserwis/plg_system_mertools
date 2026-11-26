/**
 * Integration test of the panel "Tables of the site" (Database tab), on the docker Joomla + Gridbox, as a Super User:
 *  - MerTools' own tables in utf8mb4_unicode_ci after the update (before 1.20.0 they took the server's default);
 *  - a table in utf8mb3_general_ci listed and converted by its button (a text with Polish letters kept), one in latin1
 *    listed for a conversion by hand, without a button;
 *  - the leftover asset rows counted by kind and deleted; the tree of assets still right (lft/rgt of a nested set)
 *    and every module, article and Gridbox page still with its row;
 *  - OPTIMIZE of a table with free space.
 * The test tables dropped and #__assets put back as it was afterwards.
 *
 *   node tests/integration/db-health.test.js [--install]
 */
'use strict';
const { URL_, check, sql, run, install } = require('./lib');

// the site's prefix (sql() puts it in place of #__, also in a string)
const PREFIX = sql("SELECT REPLACE('#__x', 'x', '')")[0][0];
const MB3 = PREFIX + 'mt_test_mb3';
const L1 = PREFIX + 'mt_test_latin1';
const BIN = PREFIX + 'mt_test_token';
const BAK = 'mt_test_assets_' + process.pid;
const collation = (t) => (sql(`SHOW TABLE STATUS LIKE '${t}'`)[0] || [])[14];
const treeRight = () => {
  const [n, root, bad, dup] = sql(`SELECT COUNT(*), (SELECT rgt FROM #__assets WHERE parent_id = 0 AND lft = 0 LIMIT 1),
    (SELECT COUNT(*) FROM #__assets WHERE lft >= rgt), (SELECT COUNT(*) - COUNT(DISTINCT lft) FROM #__assets) FROM #__assets`)[0].map(Number);
  return root === 2 * n - 1 && bad === 0 && dup === 0;
};

run(async ({ login }) => {
  const admin = await login('light');
  if (process.argv.includes('--install')) await install(admin);
  try {
    const own = sql(`SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '${PREFIX}mertools\\_%'`);
    check('MerTools\' own tables in utf8mb4_unicode_ci', own.length > 5 && own.every((r) => r[1] === 'utf8mb4_unicode_ci'), JSON.stringify(own.filter((r) => r[1] !== 'utf8mb4_unicode_ci')));

    sql(`CREATE TABLE ${MB3} (id INT PRIMARY KEY, t VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci`);
    sql(`INSERT INTO ${MB3} VALUES (1, 'Zażółć gęślą jaźń')`);
    sql(`CREATE TABLE ${L1} (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=latin1`);
    // a token column compared with its case: CONVERT TO would give it the table's collation
    sql(`CREATE TABLE ${BIN} (id INT PRIMARY KEY, token VARCHAR(20) COLLATE utf8mb4_bin) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`);
    sql(`CREATE TABLE ${BAK} AS SELECT * FROM #__assets`);
    const orphans = Number(sql(`SELECT COUNT(*) FROM #__assets a LEFT JOIN #__modules m ON m.id = CAST(SUBSTRING(a.name, 20) AS UNSIGNED) WHERE a.name LIKE 'com\\_modules.module.%' AND m.id IS NULL`)[0][0]);
    check('the copy has leftover asset rows of modules to delete', orphans > 0, String(orphans));

    admin.on('dialog', (d) => d.accept());
    await admin.goto(URL_ + '/administrator/index.php?option=com_mertools&view=page&page=database', { waitUntil: 'load' });
    await admin.waitForSelector('.mt-dbhealth table', { timeout: 30000 });
    const rowOf = (t) => admin.evaluate((name) => {
      const tr = [...document.querySelectorAll('.mt-dbhealth tbody tr')].find((r) => r.cells[0].textContent === name);
      return tr ? { text: tr.textContent, button: !!tr.querySelector('button') } : null;
    }, t);
    const mb3 = await rowOf(MB3);
    const l1 = await rowOf(L1);
    check('utf8mb3 listed with a Convert button', !!mb3 && /utf8mb3_general_ci/.test(mb3.text) && mb3.button, JSON.stringify(mb3));
    check('latin1 listed for a conversion by hand, no button', !!l1 && /latin1/.test(l1.text) && !l1.button, JSON.stringify(l1));
    const bin = await rowOf(BIN);
    check('a table with a column of its own collation: by hand, the column named, no button', !!bin && /token utf8mb4_bin/.test(bin.text) && !bin.button, JSON.stringify(bin));
    const kinds = await admin.evaluate(() => [...document.querySelectorAll('.mt-dbhealth h4')].map((h) => h.textContent).join('|'));
    check('three parts: character set, assets, tables', (kinds.match(/\|/g) || []).length === 2, kinds);

    // convert the one table by its own button
    await admin.evaluate((name) => [...document.querySelectorAll('.mt-dbhealth tbody tr')].find((r) => r.cells[0].textContent === name).querySelector('[data-mt-dbh="convert"]').click(), MB3);
    await admin.waitForFunction(() => /alert-(success|danger)/.test(document.querySelector('.mt-dbhealth').innerHTML), null, { timeout: 60000 });
    check('converted: utf8mb4_unicode_ci, its text kept', collation(MB3) === 'utf8mb4_unicode_ci' && sql(`SELECT t FROM ${MB3}`)[0][0] === 'Zażółć gęślą jaźń', collation(MB3));
    check('latin1 not touched', /^latin1/.test(collation(L1)), collation(L1));
    const tokenCol = () => sql(`SHOW FULL COLUMNS FROM ${BIN}`).find((c) => c[0] === 'token')[2];
    check('the table with the token column not converted, the column as it was', collation(BIN) === 'utf8mb4_general_ci' && tokenCol() === 'utf8mb4_bin', collation(BIN) + ' ' + tokenCol());

    // the leftover assets
    const before = Number(sql('SELECT COUNT(*) FROM #__assets')[0][0]);
    await admin.evaluate(() => document.querySelector('.mt-dbhealth [data-mt-dbh="assets"]').click());
    await admin.waitForFunction(() => /alert-(success|danger)/.test(document.querySelector('.mt-dbhealth').innerHTML) && !/(Trwa|Working):/.test(document.querySelector('.mt-dbhealth').textContent), null, { timeout: 120000 });
    const said = await admin.evaluate(() => document.querySelector('.mt-dbhealth .alert').textContent);
    const after = Number(sql('SELECT COUNT(*) FROM #__assets')[0][0]);
    const left = Number(sql(`SELECT COUNT(*) FROM #__assets a LEFT JOIN #__modules m ON m.id = CAST(SUBSTRING(a.name, 20) AS UNSIGNED) WHERE a.name LIKE 'com\\_modules.module.%' AND m.id IS NULL`)[0][0]);
    check('leftover rows deleted, none of modules left', after < before && left === 0, `${before} -> ${after}, left ${left}; ${said}`);
    check('the tree of assets still right (lft/rgt)', treeRight());
    const missing = sql(`SELECT
      (SELECT COUNT(*) FROM #__modules m LEFT JOIN #__assets a ON a.name = CONCAT('com_modules.module.', m.id) WHERE m.asset_id > 0 AND a.id IS NULL),
      (SELECT COUNT(*) FROM #__gridbox_pages p LEFT JOIN #__assets a ON a.name = CONCAT('com_gridbox.page.', p.id) WHERE a.id IS NULL),
      (SELECT COUNT(*) FROM #__content c LEFT JOIN #__assets a ON a.name = CONCAT('com_content.article.', c.id) WHERE a.id IS NULL)`)[0].map(Number);
    check('every module, Gridbox page and article keeps its row', missing.every((x) => x === 0), JSON.stringify(missing));
    check('the panel says no leftover rows now', await admin.evaluate(() => /(Brak wierszy|No permission rows)/.test(document.querySelector('.mt-dbhealth').textContent)));

    // OPTIMIZE of a table with free space (its own button)
    const target = await admin.evaluate(() => {
      const tr = [...document.querySelectorAll('.mt-dbhealth tbody tr')].find((r) => r.querySelector('[data-mt-dbh="optimize"]'));
      if (!tr) return null;
      tr.querySelector('button').click();
      return tr.cells[0].textContent;
    });
    check('a table with free space has Optimize', !!target, String(target));
    if (target) {
      await admin.waitForFunction(() => !/(Trwa|Working):/.test(document.querySelector('.mt-dbhealth').textContent) && /alert-(success|danger)/.test(document.querySelector('.mt-dbhealth').innerHTML), null, { timeout: 180000 });
      const msg = await admin.evaluate(() => document.querySelector('.mt-dbhealth .alert').className + ' ' + document.querySelector('.mt-dbhealth .alert').textContent);
      check('optimized: said so', /alert-success/.test(msg), msg);
    }
  } finally {
    sql(`DROP TABLE IF EXISTS ${MB3}`);
    sql(`DROP TABLE IF EXISTS ${L1}`);
    sql(`DROP TABLE IF EXISTS ${BIN}`);
    if (sql(`SHOW TABLES LIKE '${BAK}'`).length) {
      sql('DELETE FROM #__assets');
      sql(`INSERT INTO #__assets SELECT * FROM ${BAK}`);
      sql(`DROP TABLE ${BAK}`);
    }
  }
});
