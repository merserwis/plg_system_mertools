<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Pagination of the Gridbox lists (a category, an app's home page, a tag, an author: ?page=N): Gridbox
 * gives every page of a list the canonical of the first one and the same title and description, so
 * search engines take pages 2, 3… for copies of page 1 and the products on them are found only through
 * the sitemap; a page past the last one (?page=999) answers 200 with an empty list and links up to page
 * 1000, and ?page=0 or ?page=abc repeat page 1.
 *
 * Here: page N ≥ 2 gets its own canonical (…?page=N) and "– page N" in its title and description; a
 * page whose list came out empty answers 404 with noindex; a page number that is not a whole number
 * from 2 on (0, 1, 01, abc) is sent with a 301 to the address without it.
 *
 * And a guard (on by default): Gridbox puts the sort-by of a list into its database query without
 * checking it, and its editor's task editor.getBlogPosts (which takes an order too) checks no rights; a
 * sorting other than the ones Gridbox offers is refused, and that task needs the right to edit in Gridbox.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Pagination
{
    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('url_pagination', 0);
    }

    /** The sorting of the lists Gridbox offers (GridboxHelper::getBlogPostsSortingList and the default) */
    public const SORTING = ['order_list', 'price-low-high', 'price-high-low', 'newest', 'highest-rated', 'most-reviewed', 'popular'];

    /** The guard of Gridbox's sorting (on by default: a security fix) */
    public static function guarded(Registry $params): bool
    {
        return (bool) (int) $params->get('url_sort_guard', 1);
    }

    /**
     * Whether a request to Gridbox has a sorting it must not get: Gridbox puts sort-by of a list into the
     * ORDER BY of its query without checking it. The tasks of comments and reviews sort by a fixed list of
     * their own and are left alone.
     */
    public static function badSort(?string $sortBy, string $task): bool
    {
        if ($sortBy === null || $sortBy === '' || preg_match('/^(comments|reviews)\./i', $task)) {
            return false;
        }

        return !\in_array($sortBy, self::SORTING, true);
    }

    /** The page of ?page=: a whole number from 2 on, written plainly; 1 for none; null for a value to send away (0, 1, 01, abc) */
    public static function page(?string $raw): ?int
    {
        if ($raw === null) {
            return 1;
        }

        return preg_match('/^[1-9]\d{0,8}$/', $raw) && (int) $raw >= 2 ? (int) $raw : null;
    }

    /** The address without its page parameter (the other parameters kept, in their order) */
    public static function withoutPage(string $path, string $query): string
    {
        $parts = array_filter(explode('&', $query), static fn (string $p): bool => $p !== '' && !preg_match('/^page(=|$)/', $p));

        return $path . ($parts ? '?' . implode('&', $parts) : '');
    }

    /** The canonical of page N: the one of the list (page 1) with ?page=N (or &page=N) */
    public static function canonical(string $html, int $page): string
    {
        return preg_replace_callback('#<link\b[^>]*\brel=["\']canonical["\'][^>]*>#i', static function (array $m) use ($page): string {
            return preg_replace_callback('#\bhref=(["\'])([^"\']*)\1#i', static function (array $h) use ($page): string {
                $url = preg_replace('/([?&])page=[^&#]*(&|$)/', '$1', $h[2]);
                $url = rtrim($url, '?&');

                return 'href=' . $h[1] . $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page . $h[1];
            }, $m[0], 1);
        }, $html, 1);
    }

    /** The title and the meta description with the page number ($format: "%1$s – page %2$d") */
    public static function texts(string $html, int $page, string $format): string
    {
        $add  = static fn (string $text): string => sprintf($format, $text, $page);
        $html = preg_replace_callback('#(<title>)(.*?)(</title>)#is', static fn (array $m): string => $m[1] . $add(trim($m[2])) . $m[3], $html, 1);

        return preg_replace_callback('#(<meta\b[^>]*\bname=["\']description["\'][^>]*\bcontent=")([^"]*)(")#i',
            static fn (array $m): string => $m[1] . ($m[2] === '' ? '' : $add($m[2])) . $m[3], $html, 1);
    }

    /** Whether the page has a list of Gridbox posts and every one of them came out empty */
    public static function emptyList(string $html): bool
    {
        if (!preg_match_all('#class="ba-item-blog-posts\b#', $html, $items, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        foreach ($items[0] as [, $offset]) {
            if (!preg_match('#class="ba-blog-posts-wrapper\b[^"]*">(\s*)(</div>)?#', $html, $w, 0, $offset) || !isset($w[2])) {
                return false;
            }
        }

        return true;
    }

    /** A page that is not there: its robots set to noindex (the one there replaced, or one added) */
    public static function noindex(string $html): string
    {
        $tag = '<meta name="robots" content="noindex, follow">';
        $new = preg_replace('#<meta\b[^>]*\bname=["\']robots["\'][^>]*>#i', $tag, $html, 1, $count);

        return $count ? $new : preg_replace('#</title>#i', '</title>' . "\n\t" . $tag, $html, 1);
    }
}
