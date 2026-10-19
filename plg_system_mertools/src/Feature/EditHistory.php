<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Edit history: who changed which Gridbox page or product and when, from the editor's saves and the actions of
 * the Gridbox lists; under the titles of the Gridbox lists, in a window per page, on the tab "Edit history" and
 * (optionally) in Joomla's User Actions Log.
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
use Merserwis\Plugin\System\MerTools\Tool\History;

final class EditHistory extends Feature
{
    public function historyOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('history_enabled', 1);
    }

    /**
     * A Gridbox save or list action of a logged-in user: the pages it names are read now and again
     * once the request has finished (Gridbox ends with exit, so at shutdown), and what changed is kept.
     */
    public function trackHistory(): void
    {
        if (!$this->historyOn()) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $task  = strtolower((string) $input->getString('task', ''));
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !History::tracked($task)) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return;
        }
        // the editor's save sends its page as JSON: the whole request body (editor.gridboxSave, what
        // Gridbox uses) or the form field obj (editor.gridboxAjaxSave, its fallback)
        $obj = str_starts_with($task, 'editor.gridbox') ? $this->plugin->editorSaveObj($task) : '';
        $ids = History::requestIds([
            'obj'          => $obj,
            'id'           => $input->getInt('id', 0),
            'context-item' => $input->getInt('context-item', 0),
            'ba_id'        => $input->getInt('ba_id', 0),
            'cid'          => $input->get('cid', [], 'array'),
        ]);
        $creates = History::creates($task);
        if (!$ids && !$creates) {
            return;
        }
        try {
            $history = new History(Factory::getContainer()->get(DatabaseInterface::class));
            $history->ensureTables();
            $before = $history->snapshot($ids);
            $max    = $creates ? $history->maxPageId() : 0;
        } catch (\Throwable $e) {
            return;
        }
        $editor   = str_starts_with($task, 'editor.gridbox');
        $client   = $app->isClient('administrator') ? 'admin' : 'site';
        $userId   = (int) $user->id;
        $userName = trim((string) $user->name) . ' (' . (string) $user->username . ')';
        $username = (string) $user->username;
        $toLog    = (int) $this->plugin->params->get('history_actionlog', 1) === 1;
        $days     = max(0, (int) $this->plugin->params->get('history_days', 365));
        register_shutdown_function(function () use ($history, $ids, $before, $creates, $max, $editor, $client, $userId, $userName, $username, $toLog, $days): void {
            try {
                $new   = $creates ? $history->newPages($max) : [];
                $after = $history->snapshot(array_merge($ids, $new));
                $now   = gmdate('Y-m-d H:i:s');
                foreach (array_values(array_unique(array_merge($ids, $new))) as $id) {
                    $diff = History::diff($before[$id] ?? null, $after[$id] ?? null, $editor);
                    if (!$diff) {
                        continue;
                    }
                    if (\in_array($id, $new, true)) {
                        $diff = ['action' => 'duplicated', 'changes' => \count($ids) === 1 ? [['f' => 'source', 'to' => (string) $ids[0]]] : []];
                    }
                    $row   = $after[$id] ?? $before[$id];
                    $title = (string) ($row['title'] ?? '');
                    $history->record($id, (int) ($row['app_id'] ?? 0), $title, $userId, $userName, $diff['action'], $diff['changes'], $client, $now);
                    if ($toLog) {
                        $this->historyActionLog($diff['action'], $id, $title, $userId, $username);
                    }
                }
                // once a day: entries older than the setting go
                $gate = MerTools::cacheBase() . '/mertools-history-tick';
                if ($days > 0 && (!is_file($gate) || (int) @filemtime($gate) < time() - 86400) && @touch($gate)) {
                    $history->prune($days, $now);
                }
            } catch (\Throwable $e) {
                // the edit itself is never affected
            }
        });
    }

    /** The same entry in Joomla's User Actions Log (Users → User Actions Log), with links to the page and the user. */
    public function historyActionLog(string $action, int $id, string $title, int $userId, string $username): void
    {
        try {
            $model = $this->plugin->app()->bootComponent('com_actionlogs')->getMVCFactory()
                ->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
            $model->addLog([[
                'action'      => $action,
                'id'          => $id,
                'title'       => $title,
                'itemlink'    => 'index.php?option=com_gridbox&task=gridbox.edit&id=' . $id,
                'userid'      => $userId,
                'username'    => $username,
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
            ]], 'PLG_SYSTEM_MERTOOLS_ACTIONLOG_' . strtoupper($action), 'plg_system_mertools.history', $userId);
        } catch (\Throwable $e) {
        }
    }

    /**
     * The Gridbox lists (apps, pages, trash): under the title of each page its last change - when and
     * who - as a link to the whole history of the page (a window, mertools-history.js).
     */
    public function historyInLists(): void
    {
        $showHistory = $this->historyOn() && (int) $this->plugin->params->get('history_lists', 1);
        if (!$showHistory && !$this->plugin->locks()->lockOn()) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || !\in_array($input->getCmd('view', ''), ['apps', 'pages', 'trashed'], true)
            || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage', 'com_gridbox')) {
            return;
        }
        $body = (string) $app->getBody();
        if (!preg_match_all('/name="cid\[\]" value="(\d+)"/', $body, $m)) {
            return;
        }
        $latest = [];
        $locked = [];
        try {
            if ($showHistory) {
                $history = new History(Factory::getContainer()->get(DatabaseInterface::class));
                $history->ensureTables();
                $latest = $history->latest(array_map('intval', $m[1]));
            }
            if ($this->plugin->locks()->lockOn()) {
                $locked = $this->plugin->locks()->editLocks()->onPages(array_map('intval', $m[1]), time(), $this->plugin->locks()->lockIdle());
            }
        } catch (\Throwable $e) {
            return;
        }
        if (!$latest && !$locked) {
            return;
        }
        $this->plugin->loadLanguage();
        $body = preg_replace_callback('#<tr\b[^>]*>.*?</tr>#s', function (array $row) use ($latest, $locked): string {
            if (!preg_match('/name="cid\[\]" value="(\d+)"/', $row[0], $id) || (!isset($latest[(int) $id[1]]) && !isset($locked[(int) $id[1]]))) {
                return $row[0];
            }
            $lockHtml = '';
            if (isset($locked[(int) $id[1]])) {
                $lock     = $locked[(int) $id[1]];
                $lockHtml = '<div class="mt-lock-mark">' . htmlspecialchars('🔒 ' . Text::sprintf('PLG_SYSTEM_MERTOOLS_LOCK_LIST',
                    preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']), HTMLHelper::_('date', (string) $lock['since'], 'H:i')), ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if (!isset($latest[(int) $id[1]])) {
                $cell = strpos($row[0], 'class="title-cell"');
                $end  = $cell === false ? false : strpos($row[0], '</td>', $cell);

                return $end === false ? $row[0] : substr($row[0], 0, $end) . $lockHtml . substr($row[0], $end);
            }
            $cell = strpos($row[0], 'class="title-cell"');
            $end  = $cell === false ? false : strpos($row[0], '</td>', $cell);
            if ($end === false) {
                return $row[0];
            }
            $last  = $latest[(int) $id[1]];
            $label = '✎ ' . HTMLHelper::_('date', (string) $last['created'], 'd.m.Y H:i') . ' · ' . preg_replace('/\s*\([^()]*\)$/', '', (string) $last['user_name']);
            $html  = '<div class="mt-hist-last"><a href="#" data-mt-history="' . (int) $id[1] . '" title="'
                . htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_SHOW') . ': ' . Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_A_' . strtoupper((string) $last['action'])), ENT_QUOTES, 'UTF-8')
                . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></div>';

            return substr($row[0], 0, $end) . $lockHtml . $html . substr($row[0], $end);
        }, $body) ?? $body;
        $config = ['url' => Uri::base(true) . '/index.php?mertools_action=history_list', 'token' => Session::getFormToken(), 'texts' => self::historyTexts()];
        $tail   = '<style>' . self::HISTORY_CSS . '</style><script type="application/json" id="mertools-history-config">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . Uri::root(true)
            . '/media/plg_system_mertools/js/mertools-history' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /** The look of the history in the Gridbox lists and its window (Gridbox's admin is not Atum). */
    public const HISTORY_CSS = '.mt-lock-mark{margin-top:4px;font-size:12px;line-height:1.3;color:#b45309;font-weight:600}.mt-hist-last{margin-top:4px;font-size:12px;line-height:1.3}.mt-hist-last a{color:#6b7280;text-decoration:none}'
        . '.mt-hist-last a:hover{color:#1d4ed8;text-decoration:underline}dialog.mt-hist-dlg{border:0;border-radius:8px;padding:0;width:min(920px,94vw);'
        . 'max-height:86vh;box-shadow:0 20px 60px rgba(0,0,0,.35);font:14px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2328}'
        . 'dialog.mt-hist-dlg::backdrop{background:rgba(0,0,0,.4)}.mt-hist-head{display:flex;align-items:center;gap:12px;padding:14px 18px;'
        . 'border-bottom:1px solid #e5e7eb}.mt-hist-head h3{margin:0;font-size:17px;flex:1}.mt-hist-head button{border:0;background:none;font-size:22px;'
        . 'cursor:pointer;line-height:1}.mt-hist-body{padding:6px 18px 16px;overflow:auto;max-height:calc(86vh - 60px)}';

    /** @return array<string, string> texts of the history window and panel */
    public static function historyTexts(): array
    {
        $texts = [];
        foreach (['TITLE', 'WHEN', 'WHO', 'WHAT', 'CHANGES', 'NONE', 'LOADING', 'ERROR', 'CLOSE', 'MORE', 'SEARCH', 'ALL_USERS', 'ALL_ACTIONS', 'FROM', 'TO',
            'FILTER', 'PAGE', 'TOTAL', 'OPEN', 'CLIENT_SITE', 'CLIENT_ADMIN'] as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_UI_' . $key);
        }
        foreach (History::ACTIONS as $action) {
            $texts['A_' . strtoupper($action)] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_A_' . strtoupper($action));
        }

        return $texts;
    }

    /**
     * Entries of the history for the window (one page) or the panel (filters), with the changes put
     * in words: "Title: “A” → “B”", "Content changed"; category numbers as their names.
     */
    public function historyList(): array
    {
        $input   = $this->plugin->app()->getInput();
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $history = new History($db);
        $history->ensureTables();
        $limit  = max(1, min(200, $input->getInt('limit', 50)));
        $found  = $history->find([
            'page'   => $input->getInt('page', 0),
            'user'   => $input->getInt('user', 0),
            'q'      => mb_substr(trim($input->getString('q', '')), 0, 100),
            'from'   => $input->getString('from', ''),
            'to'     => $input->getString('to', ''),
            'action' => $input->getCmd('filter_action', ''),
        ], $limit, max(0, $input->getInt('offset', 0)));

        // category numbers -> names (the main category of a page is a number, or "trashed")
        $cats = [];
        foreach ($found['rows'] as $row) {
            foreach ((array) json_decode((string) $row['changes'], true) as $change) {
                if (($change['f'] ?? '') === 'page_category') {
                    foreach (['from', 'to'] as $k) {
                        if (ctype_digit((string) ($change[$k] ?? ''))) {
                            $cats[(int) $change[$k]] = true;
                        }
                    }
                }
            }
        }
        $names = [];
        if ($cats) {
            try {
                foreach ($db->setQuery('SELECT id, title FROM ' . $db->quoteName('#__gridbox_categories') . ' WHERE id IN (' . implode(',', array_keys($cats)) . ')')->loadRowList() ?: [] as [$cid, $ctitle]) {
                    $names[(int) $cid] = (string) $ctitle;
                }
            } catch (\Throwable $e) {
            }
        }

        $rows = [];
        foreach ($found['rows'] as $row) {
            $lines = [];
            foreach ((array) json_decode((string) $row['changes'], true) as $change) {
                $lines[] = $this->historyChange((array) $change, $names);
            }
            $rows[] = [
                'id'      => (int) $row['id'],
                'page'    => (int) $row['page_id'],
                'title'   => (string) $row['title'],
                'when'    => HTMLHelper::_('date', (string) $row['created'], 'd.m.Y H:i:s'),
                'who'     => (string) $row['user_name'],
                'action'  => (string) $row['action'],
                'client'  => (string) $row['client'],
                'changes' => $lines,
                'edit'    => 'index.php?option=com_gridbox&task=gridbox.edit&id=' . (int) $row['page_id'],
            ];
        }

        return ['rows' => $rows, 'total' => $found['total'], 'users' => $input->getInt('with_users', 0) ? $history->users() : null];
    }

    /** One change in words, in the administrator's language. */
    private function historyChange(array $change, array $categories): string
    {
        $field = (string) ($change['f'] ?? '');
        $key   = 'PLG_SYSTEM_MERTOOLS_HISTORY_F_' . strtoupper($field);
        $label = Text::_($key);
        $label = $label === $key ? $field : $label;
        if (!\array_key_exists('to', $change)) {
            return Text::sprintf('PLG_SYSTEM_MERTOOLS_HISTORY_CHANGED', $label);
        }
        $show = function ($value) use ($field, $categories): string {
            $value = (string) $value;
            if ($field === 'published' || $field === 'featured') {
                return Text::_($value === '1' ? 'JYES' : 'JNO');
            }
            if ($field === 'page_category') {
                return $value === 'trashed' ? Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_TRASH') : ($categories[(int) $value] ?? $value);
            }

            return $value === '' ? '—' : '„' . $value . '”';
        };
        if ($field === 'source') {
            return Text::sprintf('PLG_SYSTEM_MERTOOLS_HISTORY_SOURCE', (string) $change['to']);
        }
        if (!\array_key_exists('from', $change)) {
            return $label . ': ' . $show($change['to']);
        }

        return $label . ': ' . $show($change['from']) . ' → ' . $show($change['to']);
    }
}
