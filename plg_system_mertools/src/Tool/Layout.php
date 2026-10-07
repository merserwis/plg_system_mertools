<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page layout on phones. A Gridbox element that sticks out to the right — a row with a slide-in
 * animation or a motion effect still shifted sideways, an off-canvas menu — widens the page on
 * phones: Gridbox hides the overflow on <body>, so nobody can scroll to it, but the browser still lays
 * the page out wider than the screen. Everything fixed to the screen edges (the hamburger in a fixed
 * header, the accessibility button, floating buttons) then sits off-screen on the right or below.
 * Clipping the overflow on <html> and <body> keeps the page as wide as the screen; what is clipped was
 * not reachable anyway. "clip" (unlike "hidden") makes no scroll container, so sticky elements and
 * scrolling are not affected.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Layout
{
    public static function css(): string
    {
        return '@supports (overflow:clip){html,body{overflow-x:clip!important}}';
    }
}
