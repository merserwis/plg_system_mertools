<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Article data (JSON-LD): the BlogPosting block of a Gridbox blog post built from the post itself, in
 * place of the one Gridbox fills from a schema template.
 *
 * Gridbox writes the publishing date of its template in words of the site's language ("12 czerwiec
 * 2020"), which no search engine reads as a date, and puts the values in unescaped; a schema copied by
 * hand into a post can be broken (a whole <script> pasted into "@id"). Here the block is made with
 * json_encode: the dates in ISO 8601 with the time zone, the date of the last change of the content when
 * MerTools knows it, the post's authors (people) or else the organization, the publisher with the @id of
 * the site's organization (the node of the home page), the language, the introduction as the
 * description. Keys this tool does not make are kept from the post's block when it could be read.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class ArticleSchema
{
    /** the types of block replaced on a post */
    public const TYPES = ['BlogPosting', 'Article', 'NewsArticle'];

    /** the keys made here; the others of the post's block are kept */
    public const OWN = ['@context', '@type', 'mainEntityOfPage', 'url', 'headline', 'image', 'description', 'author', 'publisher', 'datePublished',
        'dateModified', 'inLanguage'];

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('blog_jsonld', 0);
    }

    /** A date of Gridbox (the site's local time) in ISO 8601 with the time zone; null for none */
    public static function iso(?string $local, \DateTimeZone $zone): ?string
    {
        $local = trim((string) $local);
        if ($local === '' || str_starts_with($local, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($local, $zone))->format('c');
        } catch (\Exception $e) {
            return null;
        }
    }

    /** A time in UTC (unix) in ISO 8601 in the site's zone */
    public static function isoTime(?int $time, \DateTimeZone $zone): ?string
    {
        return $time ? (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('c') : null;
    }

    /**
     * The block of a post.
     *
     * @param array      $d        ['url', 'headline', 'images', 'description', 'published' (ISO), 'modified' (ISO or null), 'authors' (names),
     *                             'organization' => ['id', 'name', 'url', 'logo'], 'language']
     * @param array|null $original the post's block, when it could be read
     */
    public static function build(array $d, ?array $original): array
    {
        $org  = $d['organization'];
        $type = \in_array($original['@type'] ?? null, self::TYPES, true) ? $original['@type'] : 'BlogPosting';
        $post = ['@context' => 'https://schema.org', '@type' => $type, 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => (string) $d['url']],
            'url' => (string) $d['url'], 'headline' => (string) $d['headline']];
        $images = array_values(array_unique(array_filter((array) ($d['images'] ?? []))));
        if ($images) {
            $post['image'] = \count($images) === 1 ? $images[0] : $images;
        }
        if ((string) ($d['description'] ?? '') !== '') {
            $post['description'] = (string) $d['description'];
        }
        $node = ['@type' => 'Organization', '@id' => (string) $org['id'], 'name' => (string) $org['name'], 'url' => (string) $org['url']];
        $authors = array_values(array_filter(array_map('trim', (array) ($d['authors'] ?? []))));
        $post['author'] = $authors ? array_map(static fn (string $n): array => ['@type' => 'Person', 'name' => $n], $authors) : $node;
        if (\count($authors) === 1) {
            $post['author'] = $post['author'][0];
        }
        $post['publisher'] = $node + ((string) ($org['logo'] ?? '') !== '' ? ['logo' => ['@type' => 'ImageObject', 'url' => (string) $org['logo']]] : []);
        if (!empty($d['published'])) {
            $post['datePublished'] = (string) $d['published'];
        }
        // a change before the publishing date (a post published later than written) is not a change
        if (!empty($d['modified']) && (empty($d['published']) || strtotime($d['modified']) >= strtotime($d['published']))) {
            $post['dateModified'] = (string) $d['modified'];
        }
        if ((string) ($d['language'] ?? '') !== '') {
            $post['inLanguage'] = (string) $d['language'];
        }
        foreach ((array) $original as $key => $value) {
            if (!\in_array($key, self::OWN, true)) {
                $post[$key] = $value;
            }
        }

        return $post;
    }

    /** The logo of the post's block (publisher.logo, a string or an ImageObject), '' for none */
    public static function originalLogo(?array $original): string
    {
        $logo = $original['publisher']['logo'] ?? '';

        return \is_array($logo) ? (string) ($logo['url'] ?? '') : (string) $logo;
    }
}
