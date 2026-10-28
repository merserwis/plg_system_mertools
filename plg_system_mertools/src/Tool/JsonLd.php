<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * JSON-LD blocks of a page: found by their @type (also the ones that cannot be read, by the type named
 * in their text), and replaced by one block made by MerTools. Used by the product and the article data.
 *
 * A block may hold one thing, a list of things or a "@graph" of them (Joomla's System - Schema.org and
 * other SEO extensions write it so); a "@type" may be a list of types. Only the things of the types asked
 * for are taken out of such a block: the other ones stay where they were.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class JsonLd
{
    /** A block as it goes into the page (no "</" can end the script early) */
    public static function script(array $data): string
    {
        return '<script type="application/ld+json">' . "\n" . self::encode($data) . "\n" . '</script>';
    }

    /**
     * The things of these types in the blocks of a page: [whole <script> tag, the thing's data or null when
     * the block could not be read]. A block holding two of them counts twice (with the same tag). An unreadable
     * block counts when one of the types is named as its "@type" in its text.
     *
     * @param string[] $types
     *
     * @return array<int, array{0: string, 1: ?array}>
     */
    public static function find(string $html, array $types): array
    {
        $found = [];
        foreach (self::blocks($html) as [$tag, , $json]) {
            $data = json_decode(trim($json), true);
            if (\is_array($data)) {
                foreach (self::nodes($data) as $node) {
                    if (self::typed($node, $types)) {
                        $found[] = [$tag, $node];
                    }
                }
            } elseif (preg_match(self::named($types), $json)) {
                $found[] = [$tag, null];
            }
        }

        return $found;
    }

    /**
     * The page with the things of these types replaced by $script, at the place of the first block that had
     * one (added before </head> when none had). Another thing in the same list or @graph stays in its block.
     */
    public static function replace(string $html, string $script, array $types): string
    {
        $first = true;
        foreach (self::blocks($html) as [$tag, $open, $json]) {
            $data = json_decode(trim($json), true);
            if (\is_array($data)) {
                [$hit, $rest] = self::without($data, $types);
                if (!$hit) {
                    continue;
                }
                $keep = $rest === null ? '' : $open . "\n" . self::encode($rest) . "\n" . '</script>';
            } elseif (preg_match(self::named($types), $json)) {
                $keep = '';
            } else {
                continue;
            }
            $pos = strpos($html, $tag);
            if ($pos !== false) {
                $html  = substr_replace($html, $first ? ($keep !== '' ? $keep . "\n" : '') . $script : $keep, $pos, \strlen($tag));
                $first = false;
            }
        }
        if ($first) {
            $pos = stripos($html, '</head>');

            return $pos === false ? $html : substr_replace($html, $script . "\n", $pos, 0);
        }

        return $html;
    }

    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> the JSON-LD blocks: [whole tag, its opening tag, its text] */
    private static function blocks(string $html): array
    {
        preg_match_all('#(<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)</script>#is', $html, $m, PREG_SET_ORDER);

        return $m;
    }

    /** The things of a block: the items of a list or of its @graph, else the block itself */
    private static function nodes(array $data): array
    {
        $list = array_is_list($data) ? $data : (\is_array($data['@graph'] ?? null) ? $data['@graph'] : [$data]);

        return array_values(array_filter($list, 'is_array'));
    }

    /** Whether a thing is of one of the types (its @type one type or a list of them) */
    private static function typed(array $node, array $types): bool
    {
        return array_intersect(array_filter((array) ($node['@type'] ?? null), 'is_string'), $types) !== [];
    }

    /**
     * A block without the things of these types: [whether it had one, what is left (null: nothing)].
     *
     * @return array{0: bool, 1: ?array}
     */
    private static function without(array $data, array $types): array
    {
        $graph = !array_is_list($data) && \is_array($data['@graph'] ?? null);
        if (!$graph && !array_is_list($data)) {
            return self::typed($data, $types) ? [true, null] : [false, $data];
        }
        $list = $graph ? $data['@graph'] : $data;
        $left = array_values(array_filter($list, static fn ($node): bool => !\is_array($node) || !self::typed($node, $types)));
        if (\count($left) === \count($list)) {
            return [false, $data];
        }
        if (!$left) {
            return [true, null];
        }
        if ($graph) {
            $data['@graph'] = $left;

            return [true, $data];
        }

        return [true, $left];
    }

    /** A "@type" of one of the types in the text of a block that cannot be read (also in a list of types) */
    private static function named(array $types): string
    {
        return '/"@type"\s*:\s*(?:\[[^\]]*)?"(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $types)) . ')"/';
    }
}
