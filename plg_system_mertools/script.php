<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

class PlgSystemMertoolsInstallerScript extends InstallerScript
{
    protected $minimumPhp    = '8.2.0';
    protected $minimumJoomla = '6.0.0';

    /**
     * A fresh install enables the plugin so its tools work at once; an update leaves the
     * administrator's choice (enabled or not) as it is.
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'install') {
            return true;
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('mertools'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // leaving it disabled is harmless; the administrator can enable it by hand
        }

        return true;
    }
}
