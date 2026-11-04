<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox's list of single pages with MerTools' order: see OrdersList.
 */

namespace Merserwis\Plugin\System\MerTools\Gridbox;

\defined('_JEXEC') or die;

class PagesModel extends \Balbooa\Component\Gridbox\Administrator\Model\PagesModel
{
    use OrdersList;

    /** Gridbox's name and component: its states, paths and context are found by them, not by this class */
    protected $name = 'pages';

    protected $option = 'com_gridbox';
}
