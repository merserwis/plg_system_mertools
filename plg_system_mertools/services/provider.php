<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(MerTools::class, function (Container $container) {
                $plugin = new MerTools((array) PluginHelper::getPlugin('system', 'mertools'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
