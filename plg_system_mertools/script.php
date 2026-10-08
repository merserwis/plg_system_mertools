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
    /**
     * Since 0.0.4 the plugin ships English and Polish only: the files of the languages added by
     * 0.0.3 are removed (Joomla keeps them on an update).
     */
    private function removeDroppedLanguages(): void
    {
        foreach (['cs-CZ', 'de-DE', 'fr-FR', 'nl-NL'] as $tag) {
            foreach ([JPATH_ADMINISTRATOR . '/language/' . $tag . '/plg_system_mertools',
                JPATH_PLUGINS . '/system/mertools/language/' . $tag . '/plg_system_mertools'] as $base) {
                foreach (['.ini', '.sys.ini'] as $ext) {
                    if (is_file($base . $ext)) {
                        @unlink($base . $ext);
                    }
                }
            }
            $dir = JPATH_PLUGINS . '/system/mertools/language/' . $tag;
            if (is_dir($dir) && !(new \FilesystemIterator($dir))->valid()) {
                @rmdir($dir);
            }
        }
    }

    /** Uninstalling removes the tables of the cart clean-up; Gridbox's own tables stay as they are. */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            foreach (['#__mertools_cart_seen', '#__mertools_cart_marks', '#__mertools_cart_queue', '#__mertools_state'] as $table) {
                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
            }
        } catch (\Throwable $e) {
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'update') {
            $this->removeDroppedLanguages();
        }
        // the tables of the cart clean-up (also created when first needed)
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            try {
                // a fresh install has no autoloading for the plugin yet: the class from the package
                if (!class_exists(\Merserwis\Plugin\System\MerTools\Tool\CartCleaner::class) && is_file(__DIR__ . '/src/Tool/CartCleaner.php')) {
                    require_once __DIR__ . '/src/Tool/CartCleaner.php';
                }
                (new \Merserwis\Plugin\System\MerTools\Tool\CartCleaner(Factory::getContainer()->get(DatabaseInterface::class)))->ensureTables();
            } catch (\Throwable $e) {
                // created later, at the first use
            }
        }
        // pages kept by Joomla's page cache still point to the previous script version and settings
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            try {
                Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', ['defaultgroup' => 'page',
                        'cachebase' => ((string) Factory::getApplication()->get('cache_path', '')) ?: (\defined('JPATH_CACHE') ? JPATH_CACHE : JPATH_ADMINISTRATOR . '/cache')])
                    ->clean('page');
            } catch (\Throwable $e) {
            }
        }
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
