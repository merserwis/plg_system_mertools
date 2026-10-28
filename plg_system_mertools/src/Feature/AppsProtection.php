<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Apps against deletion: Gridbox's X on an app card deletes the app with all its products or posts for good;
 * each app is locked (the task refused in onAfterRoute, the cards marked by a script), a copy is written before a
 * deletion that is allowed and once a day or a week (onAfterRespond); copies listed, downloaded and restored.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\AppGuard;

final class AppsProtection extends Feature
{
    private function appsProtected(): bool
    {
        return (bool) (int) $this->plugin->params->get('apps_protect', 1);
    }

    /** @return int[] the apps chosen as ones that may be deleted */
    private function appsUnlocked(): array
    {
        $ids = $this->plugin->params->get('apps_unlocked', []);
        if (\is_object($ids)) {
            $ids = (array) $ids;
        } elseif (!\is_array($ids)) {
            $ids = (string) $ids === '' ? [] : explode(',', (string) $ids);
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    private function appGuard(): AppGuard
    {
        $guard = new AppGuard(Factory::getContainer()->get(DatabaseInterface::class), JPATH_ROOT,
            AppGuard::folder((string) $this->plugin->params->get('apps_backup_path', ''), JPATH_ROOT, JPATH_ADMINISTRATOR));
        $guard->ensureTables();

        return $guard;
    }

    /**
     * Gridbox's deletion of an app (the X of its card, the trash icon in a folder of apps, Delete app in
     * the toolbar of a single page app): refused for a locked app; an app that may be deleted is copied
     * first and, by the setting, not deleted when the copy fails. Gridbox asks for no form token there,
     * so every method is checked.
     */
    public function appDeleteGuard(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || !AppGuard::isDeleteTask((string) $input->getString('task', ''))) {
            return;
        }
        $id   = $input->getInt('blog', 0);
        $user = $app->getIdentity();
        if ($id <= 0 || !$user || $user->guest) {
            return;
        }
        $this->plugin->loadLanguage();
        $title = '#' . $id;
        $guard = null;
        try {
            $guard = $this->appGuard();
            $apps  = $guard->apps();
            if (!isset($apps[$id])) {
                // no such app: Gridbox has nothing to delete
                return;
            }
            $title = $apps[$id]['title'];
        } catch (\Throwable $e) {
            // without the database the lock still holds; the copy below fails
        }
        if (!AppGuard::deletable($id, $this->appsProtected(), $this->appsUnlocked())) {
            $this->appRefuse(Text::sprintf('PLG_SYSTEM_MERTOOLS_APPS_REFUSED', $title));
        }
        if (!(int) $this->plugin->params->get('apps_backup_delete', 1)) {
            return;
        }
        $required = (bool) (int) $this->plugin->params->get('apps_backup_required', 1);
        try {
            if (!$guard) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_APPS_UI_ERROR'));
            }
            @set_time_limit(300);
            $copy = $guard->backup($id, 'delete', (int) $user->id, MerTools::userLabel($user), gmdate('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            if ($required) {
                $this->appRefuse(Text::sprintf('PLG_SYSTEM_MERTOOLS_APPS_BACKUP_FAILED', $title, $e->getMessage()));
            }

            return;
        }
        // files left out of the copy (too large, unreadable) would be gone for good: no whole copy, no deletion
        if ($required && (int) ($copy['skipped'] ?? 0) > 0) {
            $this->appRefuse(Text::sprintf('PLG_SYSTEM_MERTOOLS_APPS_BACKUP_INCOMPLETE', $title, (int) $copy['skipped']));
        }
    }

    /** The answer instead of Gridbox's: its scripts show the text of the answer as their notice. */
    private function appRefuse(string $message): void
    {
        $app = $this->plugin->app();
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        echo $message;
        $app->close();
    }

    /**
     * The Gridbox administrator: the cards of locked apps show a lock instead of their X, and a click on
     * it (or on Delete app in the toolbar) says how to unlock the app instead of opening Gridbox's
     * question (mertools-apps.js). The server refuses the deletion all the same.
     */
    public function appsScript(): void
    {
        if (!$this->appsProtected()) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('tmpl', '') === 'component') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage', 'com_gridbox')) {
            return;
        }
        $this->plugin->loadLanguage();
        $titles = [];
        try {
            foreach ($this->appGuard()->apps() as $id => $row) {
                $titles[$id] = $row['title'];
            }
        } catch (\Throwable $e) {
        }
        $cfg = [
            'unlocked' => $this->appsUnlocked(),
            'current'  => $input->getCmd('view') === 'single' ? $input->getInt('id', 0) : 0,
            'titles'   => (object) $titles,
            'texts'    => ['refused' => Text::_('PLG_SYSTEM_MERTOOLS_APPS_JS_REFUSED'), 'tip' => Text::_('PLG_SYSTEM_MERTOOLS_APPS_JS_TIP')],
        ];
        $tail = '<script type="application/json" id="mertools-apps-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-apps' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /** The panel of the copies (MtappsbackupField): list, copy now, restore, delete, download. */
    public function handleAppsAction(string $action): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        try {
            $guard = $this->appGuard();
            if ($action === 'apps_download') {
                $file = $input->post->getString('file', '');
                $path = $guard->path($file);
                if (!$path) {
                    throw new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_APPS_UI_NO_FILE'));
                }
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                $app->setHeader('Content-Type', 'application/gzip', true);
                $app->setHeader('Content-Disposition', 'attachment; filename="' . $file . '"', true);
                $app->setHeader('Content-Length', (string) filesize($path), true);
                $app->setHeader('Cache-Control', 'no-store', true);
                $app->sendHeaders();
                readfile($path);
                $app->close();
            }
            $extra = [];
            if ($action === 'apps_backup') {
                @set_time_limit(600);
                $one  = $input->post->getInt('app', 0);
                $ids  = $one > 0 ? [$one] : array_keys($guard->apps());
                $now  = gmdate('Y-m-d H:i:s');
                $made = 0;
                foreach ($ids as $id) {
                    $guard->backup((int) $id, 'manual', (int) $user->id, MerTools::userLabel($user), $now);
                    $made++;
                }
                $extra = ['made' => $made];
            } elseif ($action === 'apps_restore') {
                @set_time_limit(600);
                $extra = ['result' => $guard->restore($input->post->getString('file', ''))];
                // the pages kept for guests and Joomla's cache would not show what came back
                $this->plugin->speed()->purgeKeptPages();
            } elseif ($action === 'apps_delete') {
                $guard->delete($input->post->getString('file', ''));
            }
            $folder = AppGuard::folder((string) $this->plugin->params->get('apps_backup_path', ''), JPATH_ROOT, JPATH_ADMINISTRATOR);
            // a folder not made yet: what decides is the first folder above it that is there
            $probe = $folder;
            while (!is_dir($probe) && \dirname($probe) !== $probe) {
                $probe = \dirname($probe);
            }
            $this->plugin->sendJson(['success' => true, 'apps' => array_values($guard->apps()), 'copies' => $guard->copies(),
                'folder' => $folder, 'writable' => is_writable($probe),
                'exposed' => AppGuard::exposed($folder, JPATH_ROOT, (string) $input->server->getString('SERVER_SOFTWARE', '')),
                'protect' => $this->appsProtected(), 'unlocked' => $this->appsUnlocked()] + $extra);
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The regular copies: checked at most once an hour after a site response; each app whose last copy
     * is older than a day (or a week) gets one, an unchanged app only a mark on its last copy. Only
     * the newest copies of the setting stay.
     */
    public function appBackupTick(): void
    {
        $every = (string) $this->plugin->params->get('apps_backup_auto', 'daily');
        if ($every !== 'daily' && $every !== 'weekly') {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-apps-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        if (!@touch($gate)) {
            return;
        }
        // where the visitor has to wait for it, one app per hour
        $finished = $this->plugin->finishResponse();
        try {
            $guard = $this->appGuard();
            $stamp = gmdate('Y-m-d H:i:s', $now);
            $start = microtime(true);
            @set_time_limit(300);
            foreach ($guard->due($every === 'weekly' ? 7 * 86400 : 86400, $stamp) as $id) {
                $guard->backup($id, 'auto', 0, '', $stamp);
                if (!$finished || microtime(true) - $start > 30.0) {
                    break;
                }
            }
            $guard->prune(max(1, (int) $this->plugin->params->get('apps_backup_keep', 7)));
        } catch (\Throwable $e) {
            // tried again in an hour
        }
    }
}
