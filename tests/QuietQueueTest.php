<?php

// QuietQueue notes the ids of the rows Gridbox inserts into its queue of delayed e-mails in one request,
// and only those (Joomla's interfaces stubbed: only what QuietQueue uses)
namespace Joomla\Database {
    interface QueryMonitorInterface
    {
        public function startQuery(string $sql, ?array $boundParams = null): void;
        public function stopQuery(): void;
    }
    interface DatabaseInterface
    {
        public function insertid();
    }
}

namespace {
    define('_JEXEC', 1);
    require __DIR__ . '/../plg_system_mertools/src/Tool/QuietQueue.php';

    use Joomla\Database\DatabaseInterface;
    use Merserwis\Plugin\System\MerTools\Tool\QuietQueue;

    $fail  = 0;
    $check = function (string $label, $got, $exp) use (&$fail) {
        $ok = $got === $exp;
        $fail += $ok ? 0 : 1;
        printf("%s  %-60s%s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : ' got ' . var_export($got, true));
    };
    $db = new class implements DatabaseInterface {
        public int $last = 0;
        public function insertid()
        {
            return $this->last;
        }
    };
    $q = new QuietQueue($db, 'jos_gridbox_email_delay');
    $run = function (string $sql, int $id) use ($q, $db) {
        $q->startQuery($sql);
        $db->last = $id;
        $q->stopQuery();
    };
    $run('INSERT INTO `jos_gridbox_email_delay` (`order_id`,`notification`,`created`) VALUES (5,\'1\',\'2026-11-17\')', 41);
    $run('INSERT INTO `jos_gridbox_store_orders_status_history` (`order_id`) VALUES (5)', 77);
    $run('UPDATE `jos_gridbox_email_delay` SET status = 1', 78);
    $run('INSERT INTO `jos_gridbox_email_delay_x` (`a`) VALUES (1)', 79);
    $run("insert into jos_gridbox_email_delay(order_id) values (6)", 42);
    $check('only the inserts into the queue noted', $q->ids, [41, 42]);
    $q->startQuery('SELECT 1');
    $q->stopQuery();
    $check('…not after another query', $q->ids, [41, 42]);

    echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
    exit($fail ? 1 : 0);
}
