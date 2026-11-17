<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryMonitorInterface;

/**
 * Notes the e-mails Gridbox puts in its queue of delayed ones (#__gridbox_email_delay) in this request
 * — the id of each insert, read right after it as Gridbox's own insertObject() does — so that only
 * those are taken out again, not one queued meanwhile by another request for the same order.
 */
final class QuietQueue implements QueryMonitorInterface
{
    /** @var int[] the rows of the queue inserted in this request */
    public array $ids = [];

    private bool $insert = false;

    public function __construct(private readonly DatabaseInterface $db, private readonly string $table)
    {
    }

    public function startQuery(string $sql, ?array $boundParams = null): void
    {
        $this->insert = (bool) preg_match('/^\s*INSERT\s+INTO\s+[`"]?' . preg_quote($this->table, '/') . '[`"]?[\s(]/i', $sql);
    }

    public function stopQuery(): void
    {
        if ($this->insert) {
            $this->insert = false;
            $id = (int) $this->db->insertid();
            if ($id > 0) {
                $this->ids[] = $id;
            }
        }
    }
}
