<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * A job done after a response at most every so many seconds, by one request at a time. The date of a
 * gate file alone is not enough: two requests at the same moment both find it old and both start. So the
 * gate is locked for the whole job, and the time of the last start is read again under the lock (from the
 * file's content: a file the lock has just made is new, but no job has run yet).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Tick
{
    /** @var resource */
    private $lock;

    private function __construct($lock)
    {
        $this->lock = $lock;
    }

    /** The job is due and this request has it (release() when done), or null: not due, or taken. */
    public static function claim(string $gate, int $every, ?int $now = null): ?self
    {
        $now ??= time();
        // the cheap check first: most requests leave here
        if (is_file($gate) && (int) @filemtime($gate) > $now - $every) {
            return null;
        }
        $lock = @fopen($gate, 'c+');
        if (!$lock) {
            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }
        $last = (int) stream_get_contents($lock);
        if ($last > $now - $every) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return null;
        }
        // noted before the job: a job that makes PHP die is not started again with every page
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, (string) $now);
        fflush($lock);
        @touch($gate, $now);

        return new self($lock);
    }

    public function release(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
