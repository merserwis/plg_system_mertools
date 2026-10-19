<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * A tool of the plugin: the work of one setting tab, called by the plugin's event listeners
 * (Extension\MerTools). One instance per request, made when first needed; it reaches the settings,
 * the application and the shared helpers through the plugin.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Merserwis\Plugin\System\MerTools\Extension\MerTools;

abstract class Feature
{
    public function __construct(protected MerTools $plugin)
    {
    }
}
