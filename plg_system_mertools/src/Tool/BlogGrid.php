<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Lists of posts (the Blog posts element of a category, tag or author page) in an even grid. Gridbox's
 * masonry layout makes a grid with rows of no height (grid-auto-rows:0) and lets its script set how many
 * of them each post spans (grid-row-end: span N, from the height of the post), so a post with no image, a
 * shorter title or a shorter intro lifts everything below it and the cards stand at different heights.
 *
 * Here every row is as high as its highest post, the posts of a row are as high as the row and the text of
 * a card takes the room that is left, so the cards of a row start and end on the same lines. The number of
 * columns, the gaps and the look of a card stay Gridbox's. The inline style of the script is overruled with
 * !important, so nothing is changed in the script's work and the setting can be switched off any time.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class BlogGrid
{
    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('blog_grid', 0);
    }

    public static function css(): string
    {
        $w = '.ba-item-blog-posts .ba-blog-posts-wrapper.ba-masonry-layout';

        return $w . '{grid-auto-rows:auto!important;align-items:stretch}'
            . $w . '>.ba-blog-post{grid-row-end:auto!important;height:auto!important}'
            . $w . '>.ba-blog-post>.ba-blog-post-content{flex:1 1 auto}';
    }
}
