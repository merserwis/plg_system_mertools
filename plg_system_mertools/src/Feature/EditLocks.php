<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Edit lock: one person at a time in the Gridbox editor of a page; the others see who edits it (and may take it
 * over, by the setting); a save of an editor that lost the lock is refused.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\EditLock;

final class EditLocks extends Feature
{
    /** the edit lock this editor page holds (key, page, tab), for its script */
    public ?array $editorLock = null;

    /**
     * The Settings window of a page in the Gridbox lists (gridbox.updateParams) while somebody else has
     * the page open in the editor: refused, as their next save would undo it.
     */
    public function adminLockGuard(): void
    {
        if (!$this->lockOn()) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || strtolower((string) $input->getString('task', '')) !== 'gridbox.updateparams'
            || strtoupper((string) $input->getMethod()) !== 'POST') {
            return;
        }
        $user = $app->getIdentity();
        $id   = $input->getInt('ba_id', 0);
        if (!$user || $user->guest || $id <= 0) {
            return;
        }
        try {
            $lock = $this->editLocks()->get(EditLock::key('', $id));
            if (!EditLock::blocks($lock, (int) $user->id, time(), $this->lockIdle())) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        $this->plugin->loadLanguage();
        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SETTINGS_REFUSED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name'])), 'error');
        $back = (string) $input->server->getString('HTTP_REFERER', '');
        $app->redirect($back !== '' && Uri::isInternal($back) ? $back : 'index.php?option=com_gridbox');
    }

    public function lockOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('lock_enabled', 1);
    }

    /** Minutes without anything done in the editor after which its lock is free (0 = never). */
    public function lockIdle(): int
    {
        return max(0, (int) $this->plugin->params->get('lock_idle', 30)) * 60;
    }

    public function editLocks(): EditLock
    {
        $locks = new EditLock(Factory::getContainer()->get(DatabaseInterface::class));
        $locks->ensureTables();

        return $locks;
    }

    private function mayTakeOver($user): bool
    {
        switch ((string) $this->plugin->params->get('lock_takeover', 'all')) {
            case 'none':
                return false;
            case 'admins':
                return $user->authorise('core.admin', 'com_gridbox') || $user->authorise('core.admin');
            default:
                return true;
        }
    }

    /**
     * Site requests of a logged-in user: the confirmations of an open editor, the Gridbox editor
     * itself (the lock is taken, or the page of who edits it is shown) and the editor's saves.
     */
    public function editLock(): void
    {
        if (!$this->lockOn()) {
            return;
        }
        $app  = $this->plugin->app();
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        $input  = $app->getInput();
        $action = $input->getCmd('mertools_lock', '');
        if ($action !== '') {
            $this->lockEndpoint($action);
        }
        if ($input->getCmd('option') !== 'com_gridbox') {
            return;
        }
        $task = strtolower((string) $input->getString('task', ''));
        try {
            if (\in_array($task, ['editor.gridboxsave', 'editor.gridboxajaxsave'], true) && strtoupper((string) $input->getMethod()) === 'POST') {
                $this->lockSaveGuard($task);
            } elseif ($task === '' && $input->getCmd('view') === 'editor' && $input->getInt('id', 0) > 0 && strtoupper((string) $input->getMethod()) === 'GET') {
                $this->lockEditorGate();
            }
        } catch (\Throwable $e) {
            // without its table (or database trouble) the editor works as without the lock
        }
    }

    /**
     * May the user edit this item in the Gridbox editor? Gridbox's own rule (its editor and its save): a page
     * with Edit on the page, or Edit Own on its category for one of its authors; a blog or post layout with
     * Edit Layouts on the app; a system page with Edit on Gridbox. Anything that is not there: no.
     */
    public function mayEdit($user, string $editType, int $id): bool
    {
        if (!$user || $user->guest || $id <= 0) {
            return false;
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        if ($editType === 'blog' || $editType === 'post-layout') {
            return (bool) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__gridbox_app') . ' WHERE id = ' . $id . ' AND type <> ' . $db->quote('system_apps'))->loadResult()
                && $user->authorise('core.edit.layouts', 'com_gridbox.app.' . $id);
        }
        if ($editType === 'system') {
            return (bool) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__gridbox_system_pages') . ' WHERE id = ' . $id)->loadResult()
                && $user->authorise('core.edit', 'com_gridbox');
        }
        if ($editType !== '') {
            return false;
        }
        $page     = $db->setQuery('SELECT id, page_category, app_id FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE id = ' . $id)->loadAssoc();
        $category = (string) ($page['page_category'] ?? '');
        if (!$page || $category === 'trashed') {
            return false;
        }
        // Gridbox makes the permission entry of a page in its category and app when the editor first opens it;
        // until then the nearest one that is there decides, as it would
        $parents = ctype_digit($category) && (int) $category > 0 ? ['com_gridbox.category.' . (int) $category] : [];
        if ((int) $page['app_id'] > 0) {
            $parents[] = 'com_gridbox.app.' . (int) $page['app_id'];
        }
        if ($user->authorise('core.edit', $this->nearestAsset(array_merge(['com_gridbox.page.' . $id], $parents)))) {
            return true;
        }
        if (empty($category) || !$user->authorise('core.edit.own', $this->nearestAsset($parents))) {
            return false;
        }

        return (bool) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__gridbox_authors') . ' AS a INNER JOIN ' . $db->quoteName('#__gridbox_authors_map')
            . ' AS m ON m.author_id = a.id WHERE m.page_id = ' . $id . ' AND a.user_id = ' . (int) $user->id)->loadResult();
    }

    /** The first of these permission entries that exists, else Gridbox's own. */
    private function nearestAsset(array $names): string
    {
        if (!$names) {
            return 'com_gridbox';
        }
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $found = array_flip((array) $db->setQuery('SELECT name FROM ' . $db->quoteName('#__assets') . ' WHERE name IN ('
            . implode(', ', array_map([$db, 'quote'], $names)) . ')')->loadColumn());
        foreach ($names as $name) {
            if (isset($found[$name])) {
                return $name;
            }
        }

        return 'com_gridbox';
    }

    /** Opening the Gridbox editor: the lock is ours (taken, or taken over), else the page of who edits it. */
    private function lockEditorGate(): void
    {
        $app      = $this->plugin->app();
        $input    = $app->getInput();
        $user     = $app->getIdentity();
        $id       = $input->getInt('id', 0);
        $editType = $input->getCmd('edit_type', '');
        if (!$this->mayEdit($user, $editType, $id)) {
            // Gridbox shows its own login or refusal: nothing is locked for somebody who may not edit
            return;
        }
        $key      = EditLock::key($editType, $id);
        $locks    = $this->editLocks();
        $now      = time();
        $tab      = bin2hex(random_bytes(8));
        $force    = $input->getInt('mertools_takeover', 0) === 1 && Session::checkToken('get') && $this->mayTakeOver($user);
        $holder   = $locks->acquire($key, $editType === '' ? $id : 0, (int) $user->id, MerTools::userLabel($user), $tab, $now, $this->lockIdle(), $force);
        if ($holder) {
            $this->lockedPage($holder, $user);
        }
        $this->editorLock = ['key' => $key, 'page' => $editType === '' ? $id : 0, 'tab' => $tab];
        if (random_int(1, 50) === 1) {
            $locks->prune($now);
        }
    }

    /** The page instead of the editor: who edits the page since when, and what can be done. */
    private function lockedPage(array $holder, $user): void
    {
        $app = $this->plugin->app();
        $this->plugin->loadLanguage();
        $esc     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $name    = preg_replace('/\s*\([^()]*\)$/', '', (string) $holder['user_name']);
        $since   = HTMLHelper::_('date', (string) $holder['since'], 'd.m.Y H:i');
        $active  = HTMLHelper::_('date', (string) $holder['active'], 'H:i');
        $idle    = (int) round($this->lockIdle() / 60);
        $uri     = clone Uri::getInstance();
        $uri->setVar('mertools_takeover', '1');
        $uri->setVar(Session::getFormToken(), '1');
        $take    = $this->mayTakeOver($user)
            ? '<a class="btn warn" href="' . $esc($uri->toString()) . '" onclick="return confirm(' . $esc(json_encode(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_TAKE_CONFIRM', $name), JSON_UNESCAPED_UNICODE)) . ')">'
                . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TAKE')) . '</a>'
            : '';
        $html = '<!DOCTYPE html><html lang="' . $esc($app->getLanguage()->getTag()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TITLE')) . '</title><style>'
            . 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f3f4f6;color:#1f2328;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . '.box{background:#fff;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.12);padding:28px 32px;max-width:560px;margin:16px}'
            . 'h1{font-size:20px;margin:0 0 12px}p{margin:0 0 10px}.who{font-size:17px;font-weight:600}.muted{color:#6b7280;font-size:13px}'
            . '.btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-block;padding:9px 16px;border-radius:6px;text-decoration:none;border:1px solid #d1d5db;color:#1f2328;background:#fff;cursor:pointer;font:inherit}'
            . '.btn.primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}.btn.warn{border-color:#b91c1c;color:#b91c1c}</style></head><body><div class="box">'
            . '<h1>🔒 ' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_TITLE')) . '</h1>'
            . '<p class="who">' . $esc(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_WHO', $name)) . '</p>'
            . '<p>' . $esc(Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SINCE', $since, $active)) . '</p>'
            . '<p class="muted">' . $esc($idle > 0 ? Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_EXPLAIN', $idle) : Text::_('PLG_SYSTEM_MERTOOLS_LOCK_EXPLAIN_NOIDLE')) . '</p>'
            . '<div class="btns"><a class="btn primary" href="' . $esc(Uri::getInstance()->toString()) . '">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_RETRY')) . '</a>'
            . '<button type="button" class="btn" onclick="window.close();history.length>1&&history.back()">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LOCK_BACK')) . '</button>'
            . $take . '</div></div></body></html>';
        $app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex', true);
        $app->sendHeaders();
        echo $html;
        $app->close();
    }

    /** A save of the editor while somebody else holds the lock is refused (Gridbox shows the answer as its notice). */
    private function lockSaveGuard(string $task): void
    {
        $obj = json_decode($this->plugin->editorSaveObj($task));
        if (!\is_object($obj) || empty($obj->page->id)) {
            return;
        }
        $app      = $this->plugin->app();
        $user     = $app->getIdentity();
        $editType = isset($obj->edit_type) && \is_string($obj->edit_type) ? $obj->edit_type : '';
        $key      = EditLock::key($editType, (int) $obj->page->id);
        $locks    = $this->editLocks();
        $row      = $locks->get($key);
        if (!EditLock::blocks($row, (int) $user->id, time(), $this->lockIdle())) {
            return;
        }
        $this->plugin->loadLanguage();
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        echo Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_SAVE_REFUSED', preg_replace('/\s*\([^()]*\)$/', '', (string) $row['user_name']));
        $app->close();
    }

    /** The open editor: ping (confirm the lock, every 30 s) and release (closed). */
    private function lockEndpoint(string $action): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (!\in_array($action, ['ping', 'release'], true) || strtoupper((string) $input->getMethod()) !== 'POST' || !Session::checkToken('request')) {
            $this->plugin->sendJson(['ok' => false], 403);
        }
        $key    = $input->getString('key', '');
        $target = EditLock::parse($key);
        if (!$target) {
            $this->plugin->sendJson(['ok' => false], 400);
        }
        [$editType, $id] = $target;
        $tab = substr(preg_replace('/[^a-f0-9]/', '', $input->getString('tab', '')), 0, 32);
        try {
            $locks = $this->editLocks();
            if ($action === 'release') {
                // only the user's own lock goes: nothing to check
                $locks->release($key, (int) $user->id, $tab);
                $this->plugin->sendJson(['ok' => true]);
            }
            // a confirmation can take a free lock: only for whoever may edit the item
            if (!$this->mayEdit($user, $editType, $id)) {
                $this->plugin->sendJson(['ok' => false], 403);
            }
            $holder = $locks->ping($key, $editType === '' ? $id : 0, (int) $user->id, MerTools::userLabel($user), $tab,
                $input->getInt('active', 0) === 1, time(), $this->lockIdle());
            if ($holder) {
                $this->plugin->loadLanguage();
                $this->plugin->sendJson(['ok' => false, 'lost' => true, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_LOST',
                    preg_replace('/\s*\([^()]*\)$/', '', (string) $holder['user_name']), HTMLHelper::_('date', (string) $holder['since'], 'H:i'))]);
            }
            $this->plugin->sendJson(['ok' => true]);
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['ok' => false, 'error' => true]);
        }
    }

    /** The editor page that took the lock: its script confirms it and gives it back when closed. */
    public function lockScript(): void
    {
        if (!$this->editorLock) {
            return;
        }
        $app  = $this->plugin->app();
        $this->plugin->loadLanguage();
        $idle = $this->lockIdle();
        $cfg  = $this->editorLock + [
            'url'   => Uri::root(true) . '/index.php',
            'token' => Session::getFormToken(),
            'ping'  => EditLock::PING,
            'idle'  => $idle,
            'texts' => ['idle' => $idle > 0 ? Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_IDLE', (int) round($idle / 60)) : '', 'resume' => Text::_('PLG_SYSTEM_MERTOOLS_LOCK_RESUMED')],
        ];
        $tail = '<script type="application/json" id="mertools-lock-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-lock' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }
}
