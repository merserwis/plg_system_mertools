<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

/**
 * Cards of Gridbox's lists (a slider of recent posts, a grid of posts or products) whose title has a given word in it,
 * such as "[ZAKOŃCZONE]" of a finished promotion: taken out of the list, while the page itself stays where it is
 * (open, in the sitemap, found in a search). Pure: takes the HTML and returns it changed.
 */
final class EndedItems
{
    /** the longest card looked at (a card that does not end within it is left alone) */
    private const MAX_CARD = 30000;

    /** @return string[] the words, one per line of the setting, without empty ones */
    public static function words(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), fn (string $w) => $w !== ''));
    }

    /**
     * @param string[] $words  a card is taken out when its title has one of them in it (any letter case)
     */
    public static function hide(string $html, array $words): string
    {
        if ($words === [] || !str_contains($html, 'ba-blog-post-title')) {
            return $html;
        }
        // <div class="ba-blog-post " data-id="1"> (grid) and <li class="item  active" data-id="1"> (slider)
        $start = '#<(div|li)\b(?=[^>]*\bdata-id=)[^>]*\bclass="(?:ba-blog-post|item)(?=[\s"])[^"]*"[^>]*>#i';
        $out   = '';
        $at    = 0;
        while (preg_match($start, $html, $m, PREG_OFFSET_CAPTURE, $at)) {
            [$tag, $pos] = $m[0];
            $end         = self::closing($html, strtolower($m[1][0]), $pos + \strlen($tag));
            $title       = $end === null ? null : self::title(substr($html, $pos, $end - $pos));
            if ($title === null) {
                // not a card (it has no title, several, or does not close in time): the tag is passed over,
                // what is inside is looked at
                $out .= substr($html, $at, $pos + \strlen($tag) - $at);
                $at   = $pos + \strlen($tag);
            } elseif (self::has($title, $words)) {
                // the card and the white space after it
                $out .= substr($html, $at, $pos - $at);
                preg_match('/\G\s*/', $html, $white, 0, $end);
                $at = $end + \strlen($white[0]);
            } else {
                // a card that stays: its inside is not looked at again
                $out .= substr($html, $at, $end - $at);
                $at   = $end;
            }
        }

        return $out . substr($html, $at);
    }

    /** The title of a card as text; null when the element is not a card of a list */
    public static function title(string $card): ?string
    {
        // one title: a wrapper of several cards is not a card
        if (preg_match_all('#class="ba-blog-post-title[\s"]#', $card) !== 1
            || !preg_match('#class="ba-blog-post-title[\s"][^>]*>(.*?)</h[1-6]>#is', $card, $m)) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function has(string $title, array $words): bool
    {
        foreach ($words as $word) {
            if (mb_stripos($title, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The offset after the tag that closes the one opened just before $from; null when it does not close in time */
    private static function closing(string $html, string $name, int $from): ?int
    {
        $depth = 1;
        $limit = min(\strlen($html), $from + self::MAX_CARD);
        $pos   = $from;
        while ($pos < $limit && preg_match('#<(/?)' . $name . '\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            if ($m[0][1] >= $limit) {
                return null;
            }
            $depth += $m[1][0] === '/' ? -1 : 1;
            $pos    = $m[0][1] + \strlen($m[0][0]);
            if ($depth === 0) {
                return $pos;
            }
        }

        return null;
    }
}
