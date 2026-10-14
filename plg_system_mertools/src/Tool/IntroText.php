<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * A "<" in the introduction of a page or product ("sensitivity <30 mK", "RE < 0,5 Ω").
 *
 * Gridbox writes the introduction into its lists as it is and then runs the page through phpQuery
 * (DOMDocument). libxml older than 2.9.11 (merserwis.pl: 2.9.10) takes a "<" that does not start a
 * tag for a broken tag and drops everything up to the next ">" — the rest of the text and the
 * </div> of the introduction: the next products fall into this one's card and the grid or list
 * goes to pieces. Its SEO tag [Intro Text] (strip_tags) cuts the meta description there too.
 *
 * Such a "<" is kept as &lt;: every libxml reads it, the page shows "<", and Gridbox's editor too
 * (it prints the introduction into a textarea, so the browser shows "<" and the next save sends "<"
 * again — changed back after that save). Only the meta tags need a hand: Joomla escapes their text
 * once more ("&amp;lt;"), put back to "&lt;" on the finished page.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class IntroText
{
    /** a "<" that starts no tag, comment or declaration (also one at the very end) */
    private const BARE = '/<(?![A-Za-z\/!?])/';

    /** the same in MySQL / MariaDB, to find the rows */
    private const BARE_SQL = '<[^a-zA-Z/!?]|<$';

    public static function escape(string $text): string
    {
        return preg_replace(self::BARE, '&lt;', $text) ?? $text;
    }

    public static function needsEscape(string $text): bool
    {
        return (bool) preg_match(self::BARE, $text);
    }

    /**
     * Every introduction with such a "<" in Gridbox's pages and products, made safe.
     *
     * @return int[]  the ids changed
     */
    public static function repair(DatabaseInterface $db): array
    {
        $table = $db->quoteName('#__gridbox_pages');
        $rows  = $db->setQuery('SELECT id, intro_text FROM ' . $table . ' WHERE intro_text REGEXP ' . $db->quote(self::BARE_SQL))->loadAssocList() ?: [];
        $ids   = [];
        foreach ($rows as $row) {
            $text = (string) $row['intro_text'];
            if (!self::needsEscape($text)) {
                continue;
            }
            $db->setQuery('UPDATE ' . $table . ' SET intro_text = ' . $db->quote(self::escape($text)) . ' WHERE id = ' . (int) $row['id']
                . ' AND intro_text = ' . $db->quote($text))->execute();
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /** The description meta tags of a finished page: "&amp;lt;" (escaped twice) back to "&lt;". */
    public static function fixMeta(string $html): string
    {
        $head = stripos($html, '</head>');
        if ($head === false || !str_contains(substr($html, 0, $head), '&amp;lt;')) {
            return $html;
        }
        $fixed = preg_replace_callback('#<meta\s[^>]*(?:name|property)="(?:description|og:description|twitter:description)"[^>]*>#i',
            static fn (array $m): string => str_replace(['&amp;lt;', '&amp;gt;'], ['&lt;', '&gt;'], $m[0]), substr($html, 0, $head));

        return $fixed === null ? $html : $fixed . substr($html, $head);
    }
}
