<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Finished items left out of the lists by Gridbox itself. A page with a word of the setting in its title
 * (e.g. [ZAKOŃCZONE]) gets an end of publishing of now: Gridbox then leaves it out of every list it
 * builds (a blog, a category, recent posts, a slideshow of posts, related posts) and takes the next item
 * in its place, so no card is missing; the page itself still opens, as Gridbox's page view does not look
 * at that date. The dates MerTools set are kept (#__mertools_ended): when the word leaves the title, or
 * the setting is emptied, the date is taken away again — only when it is still the one MerTools set, a
 * date typed in Gridbox afterwards is the user's.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class EndedPages
{
    public const TABLE = '#__mertools_ended';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * What has to be done. $pages: [id => [title, end_publishing, in the trash]] of the pages concerned; $kept:
     * [id => the date MerTools set]; $null: the database's empty date. A page in the trash is left as it is
     * (and stays kept): it may come back, and its date must then still be known as MerTools'.
     *
     * @return array{end: int[], back: int[], forget: int[]} given a date / the date taken away / no longer kept
     */
    public static function plan(array $pages, array $kept, array $words, string $null): array
    {
        $end = $back = $forget = [];
        foreach ($pages as $id => $page) {
            [$title, $date] = $page;
            if (!empty($page[2])) {
                continue;
            }
            if (self::empty((string) $date, $null) && !isset($kept[$id]) && EndedItems::has((string) $title, $words)) {
                $end[] = (int) $id;
            }
        }
        foreach ($kept as $id => $date) {
            if (!isset($pages[$id])) {
                // deleted
                $forget[] = (int) $id;
            } elseif (!empty($pages[$id][2])) {
                continue;
            } elseif ((string) $pages[$id][1] !== (string) $date) {
                // the date was changed in Gridbox (or taken away there): it is the user's now
                $forget[] = (int) $id;
            } elseif (!EndedItems::has((string) $pages[$id][0], $words)) {
                $back[] = (int) $id;
            }
        }

        return ['end' => $end, 'back' => $back, 'forget' => $forget];
    }

    private static function empty(string $date, string $null): bool
    {
        return $date === '' || $date === $null || str_starts_with($date, '0000-00-00');
    }

    /** The ids of the pages that have the date MerTools set (for the sitemap: such a page is still there). */
    public function keptIds(): array
    {
        try {
            $this->ensureTable();

            return array_map('intval', (array) $this->db->setQuery('SELECT ' . $this->db->quoteName('page_id') . ' FROM '
                . $this->db->quoteName(self::TABLE))->loadColumn());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Gives the date to the pages with a word in the title and takes it away where the word is gone.
     * $now: the site's local time, as Gridbox keeps its dates.
     *
     * @return int how many pages changed
     */
    public function sync(array $words, string $now): int
    {
        $db = $this->db;
        $this->ensureTable();
        $kept = [];
        foreach ((array) $db->setQuery('SELECT page_id, ended FROM ' . $db->quoteName(self::TABLE))->loadRowList() as [$id, $date]) {
            $kept[(int) $id] = (string) $date;
        }
        if ($words === [] && $kept === []) {
            return 0;
        }
        $where = [];
        foreach ($words as $word) {
            $where[] = $db->quoteName('title') . ' LIKE ' . $db->quote('%' . addcslashes($word, '\\%_') . '%');
        }
        if ($kept !== []) {
            $where[] = $db->quoteName('id') . ' IN (' . implode(',', array_keys($kept)) . ')';
        }
        $pages = [];
        // the trash too: a kept page there must not look deleted (plan())
        $query = 'SELECT id, title, end_publishing, page_category FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE ' . implode(' OR ', $where);
        foreach ((array) $db->setQuery($query)->loadRowList() as [$id, $title, $date, $category]) {
            $pages[(int) $id] = [(string) $title, (string) $date, (string) $category === 'trashed'];
        }
        $null = $db->getNullDate();
        $plan = self::plan($pages, $kept, $words, $null);
        foreach ($plan['end'] as $id) {
            // noted first, the date after: stopped in between, the next run finds a note whose date the page does
            // not have and forgets it (plan()), never a date of MerTools taken for the user's
            $db->setQuery('REPLACE INTO ' . $db->quoteName(self::TABLE) . ' (page_id, ended) VALUES (' . $id . ', ' . $db->quote($now) . ')')->execute();
            $db->setQuery('UPDATE ' . $db->quoteName('#__gridbox_pages') . ' SET end_publishing = ' . $db->quote($now)
                . ' WHERE id = ' . $id . ' AND (end_publishing IS NULL OR end_publishing = ' . $db->quote($null) . ')')->execute();
            if ($db->getAffectedRows() < 1) {
                $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE page_id = ' . $id)->execute();
            }
        }
        foreach ($plan['back'] as $id) {
            $db->setQuery('UPDATE ' . $db->quoteName('#__gridbox_pages') . ' SET end_publishing = ' . $db->quote($null)
                . ' WHERE id = ' . $id . ' AND end_publishing = ' . $db->quote($kept[$id]))->execute();
        }
        $gone = array_merge($plan['back'], $plan['forget']);
        if ($gone !== []) {
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE page_id IN (' . implode(',', $gone) . ')')->execute();
        }

        return \count($plan['end']) + \count($plan['back']);
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS ' . $this->db->quoteName(self::TABLE) . ' (`page_id` INT UNSIGNED NOT NULL,'
            . ' `ended` DATETIME NOT NULL, PRIMARY KEY (`page_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci')->execute();
        $done = true;
    }
}
