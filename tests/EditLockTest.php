<?php

define('_JEXEC', 1);
if (!interface_exists('Joomla\Database\DatabaseInterface')) {
    eval('namespace Joomla\Database; interface DatabaseInterface {}');
}
require __DIR__ . '/../plg_system_mertools/src/Tool/EditLock.php';

use Merserwis\Plugin\System\MerTools\Tool\EditLock;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};
$now = strtotime('2026-10-14 10:00:00 UTC');
$at  = fn (int $ago) => gmdate('Y-m-d H:i:s', $now - $ago);

$check('key page', EditLock::key('', 12), 'page:12');
$check('key layout', EditLock::key('post-layout', 3), 'post-layout:3');
$check('key cleaned', EditLock::key('Head"er<x>', 5), 'headerx:5');
$check('key negative id', EditLock::key('', -4), 'page:0');

$row = ['user_id' => 7, 'seen' => $at(10), 'active' => $at(60)];
$check('fresh lock held', EditLock::held($row, $now, 1800), true);
$check('no row not held', EditLock::held(null, $now, 1800), false);
$check('not confirmed for 3 min = free', EditLock::held(['seen' => $at(180)] + $row, $now, 1800), false);
$check('confirmed 119 s ago still held', EditLock::held(['seen' => $at(119)] + $row, $now, 1800), true);
$check('idle 31 min = free', EditLock::held(['active' => $at(1860)] + $row, $now, 1800), false);
$check('idle 31 min, idle off = held', EditLock::held(['active' => $at(1860)] + $row, $now, 0), true);
$check('blocks another user', EditLock::blocks($row, 8, $now, 1800), true);
$check('does not block the holder', EditLock::blocks($row, 7, $now, 1800), false);
$check('stale lock blocks nobody', EditLock::blocks(['seen' => $at(500)] + $row, 8, $now, 1800), false);
$check('bad date = free', EditLock::held(['seen' => 'x'] + $row, $now, 1800), false);

// keys sent by the editor's script
$check('parse page', EditLock::parse('page:12'), ['', 12]);
$check('parse layout', EditLock::parse('post-layout:3'), ['post-layout', 3]);
$check('parse = key back', EditLock::parse(EditLock::key('system', 7)), ['system', 7]);
$check('parse id 0', EditLock::parse('page:0'), null);
$check('parse upper case', EditLock::parse('Page:12'), null);
$check('parse junk', EditLock::parse('page:12;drop'), null);
$check('parse no id', EditLock::parse('page'), null);

// taking the lock against a database (SQLite in memory): two editors at the same moment get one lock
if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE t_mertools_locks (lock_key TEXT PRIMARY KEY, page_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL,'
        . ' user_name TEXT NOT NULL DEFAULT \'\', tab TEXT NOT NULL DEFAULT \'\', since TEXT NOT NULL, seen TEXT NOT NULL, active TEXT NOT NULL)');
    $db = new class ($pdo) implements Joomla\Database\DatabaseInterface {
        /** runs once right after the next SELECT: what another request does in between */
        public ?Closure $between = null;
        private string $sql = '';
        private int $affected = 0;

        public function __construct(private PDO $pdo)
        {
        }

        public function quoteName($name)
        {
            return '`' . str_replace('#__', 't_', $name) . '`';
        }

        public function quote($text)
        {
            return $this->pdo->quote((string) $text);
        }

        public function setQuery($sql)
        {
            $this->sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', (string) $sql);

            return $this;
        }

        public function execute()
        {
            $this->affected = (int) $this->pdo->exec($this->sql);

            return true;
        }

        public function getAffectedRows()
        {
            return $this->affected;
        }

        public function loadAssoc()
        {
            $row = $this->pdo->query($this->sql)->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($this->between) {
                $other = $this->between;
                $this->between = null;
                $other();
            }

            return $row;
        }
    };
    $locks = new EditLock($db);
    $who   = fn (string $key) => (int) ($locks->get($key)['user_id'] ?? 0);

    $check('free lock taken', $locks->acquire('page:1', 1, 7, 'Ala', 'aaaa', $now, 1800), null);
    $check('held by 7', $who('page:1'), 7);
    $check('8 gets the holder', (int) ($locks->acquire('page:1', 1, 8, 'Bob', 'bbbb', $now, 1800)['user_id'] ?? 0), 7);
    $check('still held by 7', $who('page:1'), 7);
    $since = $locks->get('page:1')['since'];
    $check('7 opens it again (reload): since kept', [$locks->acquire('page:1', 1, 7, 'Ala', 'cccc', $now + 60, 1800), $locks->get('page:1')['since'], $locks->get('page:1')['tab']], [null, $since, 'cccc']);
    $check('8 takes it over', $locks->acquire('page:1', 1, 8, 'Bob', 'dddd', $now + 61, 1800, true), null);
    $check('held by 8', $who('page:1'), 8);

    // both read "free"; 8 writes first: 7 must not overwrite it
    $db->between = function () use ($locks, $now, $check) {
        $check('8 takes the free lock first', $locks->acquire('page:2', 2, 8, 'Bob', 'bbbb', $now, 1800), null);
    };
    $got = $locks->acquire('page:2', 2, 7, 'Ala', 'aaaa', $now, 1800);
    $check('7 is told 8 has it', (int) ($got['user_id'] ?? 0), 8);
    $check('the lock stays with 8', $who('page:2'), 8);

    // an expired lock of 9: both read it as free; 8 writes first
    $pdo->exec("INSERT INTO t_mertools_locks VALUES ('page:3', 3, 9, 'Ewa', 'eeee', '" . $at(900) . "', '" . $at(900) . "', '" . $at(900) . "')");
    $db->between = function () use ($locks, $now, $check) {
        $check('8 takes the expired lock first', $locks->acquire('page:3', 3, 8, 'Bob', 'bbbb', $now, 1800), null);
    };
    $got = $locks->acquire('page:3', 3, 7, 'Ala', 'aaaa', $now, 1800);
    $check('7 is told 8 has it now', (int) ($got['user_id'] ?? 0), 8);
    $check('the expired lock went to 8 only', [$who('page:3'), $locks->get('page:3')['tab']], [8, 'bbbb']);

    // the editor's confirmation: a free lock is taken back by a working editor only
    $check('ping keeps the own lock', $locks->ping('page:3', 3, 8, 'Bob', 'bbbb', true, $now + 30, 1800), null);
    $check('ping of 7 on the lock of 8: lost', (int) ($locks->ping('page:3', 3, 7, 'Ala', 'aaaa', true, $now + 30, 1800)['user_id'] ?? 0), 8);
    $check('ping on a free lock, idle editor: not taken', [$locks->ping('page:4', 4, 7, 'Ala', 'aaaa', false, $now, 1800), $locks->get('page:4')], [null, null]);
    $check('ping on a free lock, working editor: taken', [$locks->ping('page:4', 4, 7, 'Ala', 'aaaa', true, $now, 1800), $who('page:4')], [null, 7]);
} else {
    echo "(the database part needs pdo_sqlite: skipped)\n";
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
