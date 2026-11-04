<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox's list of an app (products, posts…) with MerTools' order: see OrdersList.
 */

namespace Merserwis\Plugin\System\MerTools\Gridbox;

\defined('_JEXEC') or die;

class AppsModel extends \Balbooa\Component\Gridbox\Administrator\Model\AppsModel
{
    use OrdersList;

    /** Gridbox's name and component: its states, paths and context are found by them, not by this class */
    protected $name = 'apps';

    protected $option = 'com_gridbox';
}
