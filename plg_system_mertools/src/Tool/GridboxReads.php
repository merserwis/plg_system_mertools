<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

/**
 * Gridbox 2.20 checks the session's token on every POST of its controllers (HasSessionTokenCheck).
 * Its panel asks the site for the calendar of a date field (Publication: start, end; filters of
 * dates; a booking's dates) with the panel's token, which is not the site's (the two have sessions of
 * their own), so the site answers "403 Invalid token" in place of the calendar. These two tasks only
 * read (the days of a month, a product's free hours — what any visitor of the site may ask), so the
 * site's own token is put in such a request; nothing that changes anything is let through.
 */
final class GridboxReads
{
    /** Gridbox's site tasks its panel calls for reading only (lower case, as Joomla compares tasks) */
    public const TASKS = ['calendar.render', 'calendar.getsingleslots'];

    public static function needsToken(string $option, string $task, string $method): bool
    {
        return $option === 'com_gridbox' && strtoupper($method) === 'POST' && \in_array(strtolower($task), self::TASKS, true);
    }
}
