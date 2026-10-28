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

    /** the version of the plugin before this update ('' on a fresh install) */
    private string $previous = '';

    /**
     * Settings whose default changed in 1.7.0 to suit any site (the old one suited the original shop): an
     * installation updated from an older version that never saved them keeps the old value, so it works
     * as before.
     */
    private const LEGACY_DEFAULTS = ['1.7.0' => ['tel_country' => '48', 'list_fields_hidden' => 'Instrukcja obsługi Metrel']];

    public function preflight($type, $parent)
    {
        if ($type === 'update') {
            try {
                $db  = Factory::getContainer()->get(DatabaseInterface::class);
                $raw = $db->setQuery('SELECT manifest_cache FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin')
                    . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('mertools'))->loadResult();
                $this->previous = (string) ((json_decode((string) $raw, true) ?: [])['version'] ?? '');
            } catch (\Throwable $e) {
                $this->previous = '';
            }
        }

        return parent::preflight($type, $parent);
    }

    /** See LEGACY_DEFAULTS: the old value written for a setting never saved, on an update from before the change. */
    private function keepLegacyDefaults(): void
    {
        if ($this->previous === '') {
            return;
        }
        $settings = $this->savedSettings();
        $add      = [];
        foreach (self::LEGACY_DEFAULTS as $since => $values) {
            if (version_compare($this->previous, $since, '<')) {
                $add += array_diff_key($values, $settings);
            }
        }
        if (!$add) {
            return;
        }
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery('UPDATE ' . $db->quoteName('#__extensions') . ' SET params = ' . $db->quote(json_encode($settings + $add, JSON_UNESCAPED_UNICODE))
                . ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('mertools'))->execute();
        } catch (\Throwable $e) {
            // the new defaults apply
        }
    }

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

    /**
     * 1.4.0: the setting "Category pages" (category descriptions, the rows of Gridbox's Categories element)
     * moved on to Better Categories for Gridbox 1.7.0, which takes it over at its own update. Its files go
     * (Joomla keeps the files of a folder on an update); when it was on and Better Categories is missing
     * or older, a warning: until then the category pages show Gridbox's placeholder "Category Description".
     */
    private function categoryPagesMoved(): void
    {
        foreach (['/plugins/system/mertools/src/Tool/Categories.php', '/media/plg_system_mertools/js/mertools-categories.js',
            '/media/plg_system_mertools/js/mertools-categories.min.js'] as $file) {
            if (is_file(JPATH_ROOT . $file)) {
                @unlink(JPATH_ROOT . $file);
            }
        }
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = $db->setQuery('SELECT element, params, manifest_cache, enabled FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin')
                . ' AND folder = ' . $db->quote('system') . ' AND element IN (' . $db->quote('mertools') . ', ' . $db->quote('bettercategories') . ')')->loadObjectList('element');
            $own = json_decode((string) ($rows['mertools']->params ?? ''), true) ?: [];
            if ((string) ($own['layout_categories'] ?? '0') !== '1') {
                return;
            }
            $bc      = $rows['bettercategories'] ?? null;
            $version = $bc ? (string) ((json_decode((string) $bc->manifest_cache, true) ?: [])['version'] ?? '') : '';
            if (!$bc || !(int) $bc->enabled || version_compare($version, '1.7.0', '<')) {
                Factory::getApplication()->getLanguage()->load('plg_system_mertools.sys', JPATH_ADMINISTRATOR);
                Factory::getApplication()->enqueueMessage(\Joomla\CMS\Language\Text::_('PLG_SYSTEM_MERTOOLS_INSTALL_CATEGORIES_MOVED'), 'warning');
            }
        } catch (\Throwable $e) {
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
            // the redirects (#__mertools_moved, #__mertools_notfound, #__mertools_gone) stay, like the price history: links out there still need them;
            // the dates of the sitemap (#__mertools_sitemap) stay too: without them every lastmod would start again as unknown;
            // and the end dates given to finished items (#__mertools_ended): they stay in Gridbox, installed again MerTools can take them back
            foreach (['#__mertools_cart_seen', '#__mertools_cart_marks', '#__mertools_cart_queue', '#__mertools_state', '#__mertools_speed', '#__mertools_history', '#__mertools_locks',
                '#__mertools_aliases'] as $table) {
                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
            }
        } catch (\Throwable $e) {
        }
        // the lighter copies of the site's pictures (media/mertools-img), the kept pages, the built
        // sitemaps and the plugin's lists and time stamps in Joomla's cache folder
        $dirs = [JPATH_ROOT . '/media/mertools-img'];
        try {
            $dirs[] = self::cacheBase() . '/mertools_page';
            $dirs[] = self::cacheBase() . '/mertools_sitemap';
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

    /** The plugin's saved settings (empty on a fresh install). */
    private function savedSettings(): array
    {
        try {
            $db       = Factory::getContainer()->get(DatabaseInterface::class);
            $settings = json_decode((string) $db->setQuery('SELECT params FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin')
                . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('mertools'))->loadResult(), true);
        } catch (\Throwable $e) {
            return [];
        }

        return \is_array($settings) ? $settings : [];
    }

    /**
     * After an install or an update: every tool should be checked on one's own site once it is on
     * (MerTools changes the pages Gridbox sends, and every site is built differently), and a fresh
     * install is shown the way to the quick setup.
     */
    private function checkSiteMessage(string $type): void
    {
        try {
            $app  = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('plg_system_mertools.sys', JPATH_PLUGINS . '/system/mertools') || $lang->load('plg_system_mertools.sys', __DIR__)
                || $lang->load('plg_system_mertools.sys', JPATH_ADMINISTRATOR);
            $app->enqueueMessage(\Joomla\CMS\Language\Text::_('PLG_SYSTEM_MERTOOLS_INSTALL_CHECK'), 'warning');
            if ($type !== 'update') {
                $app->enqueueMessage(\Joomla\CMS\Language\Text::sprintf('PLG_SYSTEM_MERTOOLS_INSTALL_START', 'index.php?option=com_mertools&amp;view=setup'), 'info');
            }
        } catch (\Throwable $e) {
            // a message is not worth an error of the installation
        }
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
            $this->categoryPagesMoved();
            $this->keepLegacyDefaults();
            // 1.7.0: the list of the pages left out of the sitemap is kept where it cannot be downloaded
            // (excluded.php); the old one could be read from the cache folder by anyone
            try {
                @unlink(self::cacheBase() . '/mertools_sitemap/excluded.json');
            } catch (\Throwable $e) {
            }
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
            // on an update only, and only when they are wanted (1.7.0): a fresh site decides in the quick setup,
            // and indexes built on a large table hold it for a while
            try {
                if ($type === 'update' && (string) ($this->savedSettings()['db_indexes'] ?? '1') !== '0') {
                    if (!class_exists(\Merserwis\Plugin\System\MerTools\Tool\DbIndexes::class) && is_file(__DIR__ . '/src/Tool/DbIndexes.php')) {
                        require_once __DIR__ . '/src/Tool/DbIndexes.php';
                    }
                    (new \Merserwis\Plugin\System\MerTools\Tool\DbIndexes(Factory::getContainer()->get(DatabaseInterface::class)))->ensure();
                }
            } catch (\Throwable $e) {
                // done by the daily check
            }
        }
        // a "<" in the introductions that Gridbox's lists lose with an old libxml (1.0.11): kept as &lt;
        // at once, unless switched off (the pages kept in the caches are emptied just below). Only on an
        // update and only where the server's libxml needs it (1.7.0): a fresh install changes no content
        // of the site before its administrator has seen the settings.
        if ($type === 'update' && \defined('LIBXML_VERSION') && LIBXML_VERSION < 20911) {
            try {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                if ((string) ($this->savedSettings()['intro_lt'] ?? '1') !== '0') {
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
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->checkSiteMessage($type);
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
