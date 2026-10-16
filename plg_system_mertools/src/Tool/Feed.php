<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * RSS 2.0 and Atom 1.0 feeds of the Gridbox blogs: Gridbox has none. The feed of a blog page is its
 * address with Joomla's usual feed parameters — /blog?format=feed&type=rss or &type=atom — and the
 * same works on a category page (the posts of that category and its subcategories), a tag page and an
 * author page. The newest posts first, as Gridbox shows them to a guest: published, public, already
 * started and not ended, not in the trash, of the language of the page on a multilingual site.
 *
 * Each item has the title, the address, the introduction of the post (or its meta description), the
 * intro image, the date, the author(s) and the category. The blog pages and the posts get
 * <link rel="alternate"> to their feed in the head, so readers and browsers find it.
 *
 * Gridbox's own answer to format=feed would be an error page: the feed is sent before Gridbox runs.
 * Dates: Gridbox keeps them in the site's time zone (Global Configuration), the feeds give them in UTC.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Feed
{
    public const FORMATS = ['rss', 'atom'];

    public const TYPES = ['rss' => 'application/rss+xml', 'atom' => 'application/atom+xml'];

    public const GENERATOR = 'MerTools for Gridbox';

    /** Gridbox app types that never have a feed: single pages and its system pages */
    private const NO_FEED = ['single', 'system_apps'];

    public function __construct(private ?DatabaseInterface $db = null)
    {
    }

    // ---------------------------------------------------------------- settings

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('feed_enabled', 0);
    }

    /** @return string[] the formats offered: rss, atom or both */
    public static function formats(Registry $params): array
    {
        $format = (string) $params->get('feed_format', 'both');

        return \in_array($format, self::FORMATS, true) ? [$format] : self::FORMATS;
    }

    /** The format of ?type= (none: the first offered); null when that format is not offered. */
    public static function requested(Registry $params, string $type): ?string
    {
        $offered = self::formats($params);
        $type    = strtolower(trim($type));
        if ($type === '') {
            return $offered[0];
        }

        return \in_array($type, $offered, true) ? $type : null;
    }

    /** @return int[] the apps chosen in the settings; none chosen = every blog */
    public static function apps(Registry $params): array
    {
        $apps = $params->get('feed_apps', []);
        if (\is_string($apps)) {
            $apps = $apps === '' ? [] : explode(',', $apps);
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $apps), static fn (int $id): bool => $id > 0)));
    }

    /** Has this Gridbox app a feed: one of the apps chosen, or with none chosen, a blog. */
    public static function allowed(array $chosen, int $appId, string $appType): bool
    {
        if ($appId <= 0 || \in_array($appType, self::NO_FEED, true)) {
            return false;
        }

        return $chosen ? \in_array($appId, $chosen, true) : $appType === 'blog';
    }

    public static function limit(Registry $params): int
    {
        return max(1, min(100, (int) $params->get('feed_limit', 20)));
    }

    /** What an item says: the introduction (else the meta description), the meta description (else the introduction), or the title only. */
    public static function textMode(Registry $params): string
    {
        $mode = (string) $params->get('feed_text', 'intro');

        return \in_array($mode, ['intro', 'meta', 'none'], true) ? $mode : 'intro';
    }

    public static function images(Registry $params): bool
    {
        return (bool) (int) $params->get('feed_image', 1);
    }

    /** Where the head gets links to the feeds: 'blog' (the blog pages and its posts), 'all' (every page) or 'none'. */
    public static function headMode(Registry $params): string
    {
        $mode = (string) $params->get('feed_head', 'blog');

        return \in_array($mode, ['blog', 'all', 'none'], true) ? $mode : 'blog';
    }

    /** How long readers and proxies may keep a feed, in minutes (also the RSS <ttl>). */
    public static function ttl(Registry $params): int
    {
        return max(0, min(1440, (int) $params->get('feed_ttl', 60)));
    }

    // ---------------------------------------------------------------- building the feeds

    /**
     * A local date of Gridbox ("Y-m-d H:i:s", or its saved_time "Y-m-d-H-i-s") as a Unix time; null
     * for an empty or zero date.
     */
    public static function time(?string $local, \DateTimeZone $zone): ?int
    {
        $local = trim((string) $local);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})$/', $local, $m)) {
            $local = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
        }
        if ($local === '' || str_starts_with($local, '0000-00-00') || !preg_match('/^\d{4}-\d{2}-\d{2}/', $local)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($local, $zone);
        } catch (\Throwable $e) {
            return null;
        }
        $time = $date->getTimestamp();

        return $time > 0 ? $time : null;
    }

    /** An address of the site made absolute (an image path of Gridbox: "images/…" or "/images/…"). */
    public static function absolute(string $url, string $root): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
            return str_starts_with($url, '//') ? 'https:' . $url : $url;
        }
        $root = rtrim($root, '/');
        // a path already under the root's own folder (Joomla in a subfolder)
        $base = (string) parse_url($root, PHP_URL_PATH);
        if ($base !== '' && str_starts_with($url, $base . '/')) {
            $url = substr($url, \strlen($base));
        }

        return $root . '/' . ltrim($url, '/');
    }

    /** The media type of an image by its name; '' when it is not one. */
    public static function imageType(string $url): string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg'  => 'image/svg+xml',
            default => '',
        };
    }

    /**
     * The ids of a category and every category under it (Gridbox lists the posts of subcategories on
     * a category page too).
     *
     * @param array<int, int> $parents category id => parent id
     * @return int[]
     */
    public static function subtree(int $root, array $parents): array
    {
        $children = [];
        foreach ($parents as $id => $parent) {
            $children[(int) $parent][] = (int) $id;
        }
        $ids  = [$root];
        $todo = [$root];
        while ($todo) {
            foreach ($children[array_pop($todo)] ?? [] as $child) {
                if (!\in_array($child, $ids, true)) {
                    $ids[]  = $child;
                    $todo[] = $child;
                }
            }
        }

        return $ids;
    }

    /**
     * The summary of a post as HTML: Gridbox's introduction is HTML (a "<" of the text kept as &lt;),
     * its meta description plain text.
     */
    public static function summary(array $post, string $mode): string
    {
        $intro = trim((string) ($post['intro_text'] ?? ''));
        $meta  = trim((string) ($post['meta_description'] ?? ''));
        $plain = static fn (string $s): string => nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

        return match ($mode) {
            'none'  => '',
            'meta'  => $meta !== '' ? $plain($meta) : $intro,
            default => $intro !== '' ? $intro : ($meta !== '' ? $plain($meta) : ''),
        };
    }

    /** Text for XML: valid UTF-8, without the characters XML forbids, escaped. */
    public static function esc(string $s): string
    {
        $s = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    }

    /** The HTML of an item: the image first (when there is one), then the summary. */
    private static function itemHtml(array $item): string
    {
        $html = '';
        if (!empty($item['image']['url'])) {
            $html .= '<p><img src="' . htmlspecialchars($item['image']['url'], ENT_QUOTES, 'UTF-8') . '" alt="'
                . htmlspecialchars((string) ($item['image']['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></p>';
        }

        return $html . (string) ($item['summary'] ?? '');
    }

    /**
     * An RSS 2.0 feed.
     *
     * @param array $channel title, link (the page), self (the feed), description, language (pl-PL), updated (Unix time), ttl (minutes)
     * @param array $items   each: title, link, summary (HTML), published, updated (Unix times), authors (names), categories (names), image (url, alt, type, length)
     */
    public static function rss(array $channel, array $items): string
    {
        $e   = [self::class, 'esc'];
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">' . "\n"
            . "<channel>\n"
            . '<title>' . $e($channel['title']) . "</title>\n"
            . '<link>' . $e($channel['link']) . "</link>\n"
            . '<atom:link href="' . $e($channel['self']) . '" rel="self" type="' . self::TYPES['rss'] . '"/>' . "\n"
            . '<description>' . $e((string) ($channel['description'] ?? '') !== '' ? $channel['description'] : $channel['title']) . "</description>\n";
        if (!empty($channel['language'])) {
            $xml .= '<language>' . $e(strtolower($channel['language'])) . "</language>\n";
        }
        if (!empty($channel['updated'])) {
            $xml .= '<lastBuildDate>' . gmdate(DATE_RSS, (int) $channel['updated']) . "</lastBuildDate>\n";
        }
        $xml .= '<generator>' . self::GENERATOR . "</generator>\n";
        if (!empty($channel['ttl'])) {
            $xml .= '<ttl>' . (int) $channel['ttl'] . "</ttl>\n";
        }
        foreach ($items as $item) {
            $xml .= "<item>\n"
                . '<title>' . $e($item['title']) . "</title>\n"
                . '<link>' . $e($item['link']) . "</link>\n"
                . '<guid isPermaLink="true">' . $e($item['link']) . "</guid>\n";
            $html = self::itemHtml($item);
            if ($html !== '') {
                $xml .= '<description>' . $e($html) . "</description>\n";
            }
            if (!empty($item['published'])) {
                $xml .= '<pubDate>' . gmdate(DATE_RSS, (int) $item['published']) . "</pubDate>\n";
            }
            foreach ($item['authors'] ?? [] as $author) {
                $xml .= '<dc:creator>' . $e($author) . "</dc:creator>\n";
            }
            foreach ($item['categories'] ?? [] as $category) {
                $xml .= '<category>' . $e($category) . "</category>\n";
            }
            if (!empty($item['image']['url'])) {
                $image = $item['image'];
                $type  = (string) ($image['type'] ?? '');
                if (!empty($image['length']) && $type !== '') {
                    $xml .= '<enclosure url="' . $e($image['url']) . '" length="' . (int) $image['length'] . '" type="' . $e($type) . '"/>' . "\n";
                }
                $xml .= '<media:content url="' . $e($image['url']) . '" medium="image"' . ($type !== '' ? ' type="' . $e($type) . '"' : '') . '/>' . "\n";
            }
            $xml .= "</item>\n";
        }

        return $xml . "</channel>\n</rss>\n";
    }

    /** An Atom 1.0 feed (same data as rss(); channel 'author' = the name for the feed, the site name). */
    public static function atom(array $channel, array $items): string
    {
        $e    = [self::class, 'esc'];
        $date = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
        $now  = (int) ($channel['updated'] ?? 0) ?: time();
        $lang = !empty($channel['language']) ? ' xml:lang="' . $e($channel['language']) . '"' : '';
        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom"' . $lang . ">\n"
            . '<title type="text">' . $e($channel['title']) . "</title>\n";
        if ((string) ($channel['description'] ?? '') !== '') {
            $xml .= '<subtitle type="text">' . $e($channel['description']) . "</subtitle>\n";
        }
        $xml .= '<link rel="alternate" type="text/html" href="' . $e($channel['link']) . '"/>' . "\n"
            . '<link rel="self" type="' . self::TYPES['atom'] . '" href="' . $e($channel['self']) . '"/>' . "\n"
            . '<id>' . $e($channel['self']) . "</id>\n"
            . '<updated>' . $date($now) . "</updated>\n"
            . '<author><name>' . $e((string) ($channel['author'] ?? '') !== '' ? $channel['author'] : $channel['title']) . "</name></author>\n"
            . '<generator>' . self::GENERATOR . "</generator>\n";
        foreach ($items as $item) {
            $published = (int) ($item['published'] ?? 0);
            $updated   = max($published, (int) ($item['updated'] ?? 0)) ?: $now;
            $xml .= "<entry>\n"
                . '<title type="text">' . $e($item['title']) . "</title>\n"
                . '<link rel="alternate" type="text/html" href="' . $e($item['link']) . '"/>' . "\n"
                . '<id>' . $e($item['link']) . "</id>\n";
            if ($published) {
                $xml .= '<published>' . $date($published) . "</published>\n";
            }
            $xml .= '<updated>' . $date($updated) . "</updated>\n";
            foreach ($item['authors'] ?? [] as $author) {
                $xml .= '<author><name>' . $e($author) . "</name></author>\n";
            }
            foreach ($item['categories'] ?? [] as $category) {
                $xml .= '<category term="' . $e($category) . '"/>' . "\n";
            }
            $html = self::itemHtml($item);
            if ($html !== '') {
                $xml .= '<summary type="html">' . $e($html) . "</summary>\n";
            }
            if (!empty($item['image']['url'])) {
                $image = $item['image'];
                $xml  .= '<link rel="enclosure" href="' . $e($image['url']) . '"'
                    . (!empty($image['type']) ? ' type="' . $e($image['type']) . '"' : '')
                    . (!empty($image['length']) ? ' length="' . (int) $image['length'] . '"' : '') . '/>' . "\n";
            }
            $xml .= "</entry>\n";
        }

        return $xml . "</feed>\n";
    }

    /** Has the reader this answer already: its If-None-Match holds the ETag. */
    public static function notModified(string $etag, string $ifNoneMatch): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || preg_replace('#^W/#', '', $tag) === $etag) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Gridbox's data

    /** @return array{id: int, title: string, type: string, description: string, language: string, published: int, access: int}|null */
    public function app(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'type', 'description', 'meta_description', 'language', 'published', 'access']))
            ->from($db->quoteName('#__gridbox_app'))
            ->where($db->quoteName('id') . ' = ' . $id))->loadAssoc();

        return $row ? $row + ['description' => ''] : null;
    }

    /** @return array<int, array{id: int, title: string, type: string}> the apps that can have a feed, by title */
    public function feedApps(): array
    {
        $db = $this->db;

        return (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'type', 'published']))
            ->from($db->quoteName('#__gridbox_app'))
            ->where($db->quoteName('type') . ' NOT IN (' . implode(', ', array_map([$db, 'quote'], self::NO_FEED)) . ')')
            ->order($db->quoteName('title')))->loadAssocList('id');
    }

    /** The blog a post belongs to (0: none, a single page). */
    public function appOfPage(int $pageId): int
    {
        $db = $this->db;

        return (int) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName('app_id'))
            ->from($db->quoteName('#__gridbox_pages'))
            ->where($db->quoteName('id') . ' = ' . $pageId))->loadResult();
    }

    /** A published public category of the app, with the ids of its published subcategories. */
    public function category(int $appId, int $id): ?array
    {
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'parent', 'description', 'meta_description']))
            ->from($db->quoteName('#__gridbox_categories'))
            ->where($db->quoteName('app_id') . ' = ' . $appId)
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('access') . ' = 1'))->loadAssocList('id');
        if (!isset($rows[$id])) {
            return null;
        }

        return $rows[$id] + ['ids' => self::subtree($id, array_map(static fn (array $r): int => (int) $r['parent'], $rows))];
    }

    /** A published public tag, or a published author: title (and description). */
    public function filter(string $kind, int $id): ?array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'description']))
            ->from($db->quoteName($kind === 'tag' ? '#__gridbox_tags' : '#__gridbox_authors'))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('published') . ' = 1');
        if ($kind === 'tag') {
            $query->where($db->quoteName('access') . ' = 1');
        }

        return $db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * The newest posts of an app that a guest may see.
     *
     * @param int[]  $categories only these categories (none: all)
     * @param string $now        the site's local time, as Gridbox keeps dates
     * @param ?string $language  the language of the page (a multilingual site), posts of all languages included
     */
    public function posts(int $appId, array $categories, int $tag, int $author, string $now, ?string $language, int $limit): array
    {
        $db    = $this->db;
        $nowQ  = $db->quote($now);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['p.id', 'p.title', 'p.intro_text', 'p.meta_description', 'p.intro_image', 'p.image_alt', 'p.created', 'p.saved_time', 'p.page_category', 'p.language']))
            ->from($db->quoteName('#__gridbox_pages', 'p'))
            ->where($db->quoteName('p.app_id') . ' = ' . $appId)
            ->where($db->quoteName('p.page_category') . ' <> ' . $db->quote('trashed'))
            ->where($db->quoteName('p.published') . ' = 1')
            ->where($db->quoteName('p.page_access') . ' = 1')
            ->where($db->quoteName('p.created') . ' <= ' . $nowQ)
            ->where('(' . $db->quoteName('p.end_publishing') . ' IS NULL OR ' . $db->quoteName('p.end_publishing') . ' = ' . $db->quote($db->getNullDate())
                . ' OR ' . $db->quoteName('p.end_publishing') . ' >= ' . $nowQ . ')')
            ->order($db->quoteName('p.created') . ' DESC, ' . $db->quoteName('p.id') . ' DESC');
        if ($categories) {
            $query->where($db->quoteName('p.page_category') . ' IN (' . implode(', ', array_map(static fn ($id) => $db->quote((string) (int) $id), $categories)) . ')');
        }
        if ($tag > 0) {
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__gridbox_tags_map', 'tm') . ' WHERE ' . $db->quoteName('tm.page_id') . ' = ' . $db->quoteName('p.id')
                . ' AND ' . $db->quoteName('tm.tag_id') . ' = ' . $tag . ')');
        }
        if ($author > 0) {
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__gridbox_authors_map', 'am') . ' WHERE ' . $db->quoteName('am.page_id') . ' = ' . $db->quoteName('p.id')
                . ' AND ' . $db->quoteName('am.author_id') . ' = ' . $author . ')');
        }
        if ($language !== null && $language !== '') {
            $query->where($db->quoteName('p.language') . ' IN (' . $db->quote($language) . ', ' . $db->quote('*') . ')');
        }

        return (array) $db->setQuery($query, 0, $limit)->loadAssocList();
    }

    /** @return array<int, string[]> post id => names of its published authors */
    public function authorsOf(array $pageIds): array
    {
        if (!$pageIds) {
            return [];
        }
        $db   = $this->db;
        $rows = (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['m.page_id', 'a.title']))
            ->from($db->quoteName('#__gridbox_authors_map', 'm'))
            ->join('INNER', $db->quoteName('#__gridbox_authors', 'a'), $db->quoteName('a.id') . ' = ' . $db->quoteName('m.author_id'))
            ->where($db->quoteName('m.page_id') . ' IN (' . implode(', ', array_map('intval', $pageIds)) . ')')
            ->where($db->quoteName('a.published') . ' = 1')
            ->order($db->quoteName('m.id')))->loadAssocList();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['page_id']][] = (string) $row['title'];
        }

        return $out;
    }

    /** @return array<int, string> category id => title */
    public function categoryTitles(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $db = $this->db;

        return array_map('strval', (array) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__gridbox_categories'))
            ->where($db->quoteName('id') . ' IN (' . implode(', ', $ids) . ')'))->loadAssocList('id', 'title'));
    }
}
