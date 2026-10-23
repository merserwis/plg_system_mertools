<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * JSON-LD blocks of a page: found by their @type (also the ones that cannot be read, by the type named
 * in their text), and replaced by one block made by MerTools. Used by the product and the article data.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class JsonLd
{
    /** A block as it goes into the page (no "</" can end the script early) */
    public static function script(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);

        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }

    /**
     * The blocks of a page of these types: [whole <script> tag, its data or null when it could not be
     * read]. An unreadable block counts when one of the types is named as its "@type" in its text.
     *
     * @param string[] $types
     *
     * @return array<int, array{0: string, 1: ?array}>
     */
    public static function find(string $html, array $types): array
    {
        preg_match_all('#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m, PREG_SET_ORDER);
        $named = '/"@type"\s*:\s*"(' . implode('|', array_map('preg_quote', $types)) . ')"/';
        $found = [];
        foreach ($m as [$tag, $json]) {
            $data = json_decode(trim($json), true);
            if (\is_array($data)) {
                if (\in_array($data['@type'] ?? null, $types, true)) {
                    $found[] = [$tag, $data];
                }
            } elseif (preg_match($named, $json)) {
                $found[] = [$tag, null];
            }
        }

        return $found;
    }

    /** The page with its blocks of these types replaced by $script (added before </head> when it had none) */
    public static function replace(string $html, string $script, array $types): string
    {
        $blocks = self::find($html, $types);
        if (!$blocks) {
            $pos = stripos($html, '</head>');

            return $pos === false ? $html : substr_replace($html, $script . "\n", $pos, 0);
        }
        $first = true;
        foreach ($blocks as [$tag]) {
            $pos = strpos($html, $tag);
            if ($pos !== false) {
                $html  = substr_replace($html, $first ? $script : '', $pos, \strlen($tag));
                $first = false;
            }
        }

        return $html;
    }
}
