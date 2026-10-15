<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Category pages of the Gridbox shop: what a separate Omnibus plugin did for them besides prices,
 * kept when that plugin is removed.
 *
 * - The category description. On merserwis.pl the description block of the category layout was moved
 *   out of Gridbox's category intro element, so Gridbox no longer fills it and the page holds its
 *   placeholder <div class="intro-category-description">Category Description</div>; the description
 *   of the category is put in its place (and nothing when the category has none).
 * - A category with subcategories in Gridbox's Categories element opens and closes with a click on its
 *   whole row instead of opening its page (media/js/mertools-categories.js), highlighted on hover.
 * - Spacing above the description and clicks through the background overlay of the category pages.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Categories
{
    /** the placeholder Gridbox leaves in the description block of its category layout */
    public const PLACEHOLDER = '#(<div class="intro-category-description">)\s*Category Description\s*(</div>)#';

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('layout_categories', 0);
    }

    /** The rules of the Omnibus plugin's stylesheet for category pages, as they were. */
    public static function css(): string
    {
        return '.com_gridbox.blog .intro-post-info{margin-top:25px}'
            . '.com_gridbox.blog .ba-overlay{pointer-events:none}'
            . '.ba-app-sub-category.collapse-categories-list:hover i,.ba-app-sub-category.collapse-categories-list:hover a{color:var(--accent)!important}';
    }

    public static function hasPlaceholder(string $html): bool
    {
        return (bool) preg_match(self::PLACEHOLDER, $html);
    }

    /** The placeholder replaced by the category description (HTML from Gridbox's category editor). */
    public static function description(string $html, string $description): string
    {
        return preg_replace_callback(self::PLACEHOLDER, static fn (array $m): string => $m[1] . $description . $m[2], $html) ?? $html;
    }
}
