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

    /** Every PHP file of the plugin and its menu component dropped from OPcache (compiled again at the next request). */
    private function refreshOpcache(): void
    {
        if (!\function_exists('opcache_invalidate')) {
            return;
        }
        foreach ([JPATH_PLUGINS . '/system/mertools', JPATH_ADMINISTRATOR . '/components/com_mertools'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->getExtension() === 'php') {
                        @opcache_invalidate($file->getPathname(), true);
                    }
                }
            } catch (\Throwable $e) {
            }
        }
    }

    /** Uninstalling removes the tables of MerTools (cart clean-up, speed measurements, edit history, edit locks); Gridbox's own tables stay as they are. */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            // the redirects (#__mertools_moved, #__mertools_notfound) stay, like the price history: links out there still need them
            foreach (['#__mertools_cart_seen', '#__mertools_cart_marks', '#__mertools_cart_queue', '#__mertools_state', '#__mertools_speed', '#__mertools_history', '#__mertools_locks',
                '#__mertools_aliases'] as $table) {
                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
            }
        } catch (\Throwable $e) {
        }
        // the lighter copies of the site's pictures (media/mertools-img), the kept pages and the
        // plugin's lists and time stamps in Joomla's cache folder
        $dirs = [JPATH_ROOT . '/media/mertools-img'];
        try {
            $dirs[] = self::cacheBase() . '/mertools_page';
        } catch (\Throwable $e) {
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        try {
            foreach (glob(self::cacheBase() . '/mertools-*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
        }

        return true;
    }

    /** Joomla's cache folder as an absolute path (as the plugin's PageCache::absolutePath works it out). */
    private static function cacheBase(): string
    {
        $path = (string) Factory::getApplication()->get('cache_path', '');
        if ($path === '') {
            $path = \defined('JPATH_CACHE') ? JPATH_CACHE : JPATH_ADMINISTRATOR . '/cache';
        }
        if (!preg_match('#^(/|\\\\|[A-Za-z]:[\\\\/])#', $path)) {
            $path = rtrim(JPATH_ROOT, '/\\') . '/' . ltrim(preg_replace('#^\./#', '', $path), '/\\');
        }

        return rtrim($path, '/\\');
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // PHP may keep the previous version of the plugin's classes in OPcache (on hosts that check
        // file times rarely or never), next to the new files: a mix that breaks (1.0.3: "Call to
        // undefined method …historyTexts()" in the settings). The new files are compiled again now.
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->refreshOpcache();
        }
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
                if (!class_exists(\Merserwis\Plugin\System\MerTools\Tool\Speed::class) && is_file(__DIR__ . '/src/Tool/Speed.php')) {
                    require_once __DIR__ . '/src/Tool/Speed.php';
                }
                (new \Merserwis\Plugin\System\MerTools\Tool\Speed(Factory::getContainer()->get(DatabaseInterface::class)))->ensureTables();
            } catch (\Throwable $e) {
                // created later, at the first use
            }
            // the indexes of Gridbox's tables (also checked daily and after Gridbox updates)
            try {
                // the aliases of Gridbox's pages and categories noted at once (and the alias changes of the edit history taken over),
                // so a page renamed from now on is followed
                foreach (['History', 'Redirects'] as $tool) {
                    if (!class_exists('Merserwis\\Plugin\\System\\MerTools\\Tool\\' . $tool) && is_file(__DIR__ . '/src/Tool/' . $tool . '.php')) {
                        require_once __DIR__ . '/src/Tool/' . $tool . '.php';
                    }
                }
                $db       = Factory::getContainer()->get(DatabaseInterface::class);
                $settings = json_decode((string) $db->setQuery('SELECT params FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin')
                    . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('mertools'))->loadResult(), true);
                if ((string) ($settings['notfound_moved'] ?? '1') !== '0' && \in_array($db->replacePrefix('#__gridbox_pages'), $db->getTableList(), true)) {
                    (new \Merserwis\Plugin\System\MerTools\Tool\Redirects($db))->sync(gmdate('Y-m-d H:i:s'));
                }
            } catch (\Throwable $e) {
            }
            try {
                if (!class_exists(\Merserwis\Plugin\System\MerTools\Tool\DbIndexes::class) && is_file(__DIR__ . '/src/Tool/DbIndexes.php')) {
                    require_once __DIR__ . '/src/Tool/DbIndexes.php';
                }
                (new \Merserwis\Plugin\System\MerTools\Tool\DbIndexes(Factory::getContainer()->get(DatabaseInterface::class)))->ensure();
            } catch (\Throwable $e) {
                // done by the daily check
            }
        }
        // a "<" in the introductions that Gridbox's lists lose with an old libxml (1.0.11): kept as &lt;
        // at once, unless switched off (the pages kept in the caches are emptied just below)
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            try {
                $db     = Factory::getContainer()->get(DatabaseInterface::class);
                $params = json_decode((string) $db->setQuery('SELECT params FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin')
                    . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('mertools'))->loadResult(), true);
                if ((string) ($params['intro_lt'] ?? '1') !== '0') {
                    if (!class_exists(\Merserwis\Plugin\System\MerTools\Tool\IntroText::class) && is_file(__DIR__ . '/src/Tool/IntroText.php')) {
                        require_once __DIR__ . '/src/Tool/IntroText.php';
                    }
                    \Merserwis\Plugin\System\MerTools\Tool\IntroText::repair($db);
                }
            } catch (\Throwable $e) {
                // done by the daily check
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
            // and the pages kept by MerTools itself: the new version may change the pages it serves
            try {
                $dir = self::cacheBase() . '/mertools_page';
                if (is_dir($dir)) {
                    @file_put_contents($dir . '/purged', sprintf('%.6F', microtime(true)), LOCK_EX);
                }
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
