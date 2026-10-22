<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox lists in the administrator: each user's own columns of the product, post and page lists, prices,
 * sale prices, stock, SKU and badges edited in the list, and the items other folders hold (greyed out).
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
use Merserwis\Plugin\System\MerTools\Tool\History;
use Merserwis\Plugin\System\MerTools\Tool\ListColumns;

final class GridboxLists extends Feature
{
    /** The list of this request: "apps.<id>" (an app's products or posts) or "pages" (single pages); null elsewhere. */
    private function gridboxList(): ?string
    {
        $input = $this->plugin->app()->getInput();
        if ($input->getCmd('option') !== 'com_gridbox' || $input->getCmd('format', 'html') !== 'html') {
            return null;
        }
        $view = $input->getCmd('view', '');
        if ($view === 'apps' && $input->getInt('id', 0) > 0) {
            return 'apps.' . $input->getInt('id', 0);
        }

        return $view === 'pages' ? 'pages' : null;
    }

    private function listProfileKey(string $list): string
    {
        return 'mertools.listcols.' . $list;
    }

    /** @return array{order: string[], known: string[]}|null the user's saved columns of a list */
    private function listPrefs(int $userId, string $list): ?array
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $raw = $db->setQuery('SELECT profile_value FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . $userId . ' AND profile_key = '
            . $db->quote($this->listProfileKey($list)))->loadResult();
        $prefs = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($prefs) && isset($prefs['order']) && \is_array($prefs['order'])
            ? ['order' => array_map('strval', $prefs['order']), 'known' => array_map('strval', (array) ($prefs['known'] ?? []))] : null;
    }

    /**
     * The Gridbox list as the user arranged it: Gridbox's columns in the chosen order (hidden ones out),
     * the added columns, and the cells of price, sale price, stock and SKU editable in place. In a
     * folder, the products (posts) added to it whose main folder is another one are listed after its
     * own, greyed out: Gridbox lists an item only in its main folder.
     */
    public function listColumns(): void
    {
        $list = $this->gridboxList();
        if ($list === null) {
            return;
        }
        $app       = $this->plugin->app();
        $input     = $app->getInput();
        $columnsOn = (int) $this->plugin->params->get('list_columns', 1) === 1;
        $category  = str_starts_with($list, 'apps.') && $input->getCmd('layout', '') !== 'modal' ? $input->getInt('category', 0) : 0;
        $ghostsOn  = $category > 0 && (int) $this->plugin->params->get('list_ghosts', 1) === 1;
        if (!$columnsOn && !$ghostsOn) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage', 'com_gridbox')) {
            return;
        }
        $body   = (string) $app->getBody();
        $bounds = ListColumns::tableBounds($body);
        if (!$bounds) {
            return;
        }
        [$start, $end] = $bounds;
        $table = substr($body, $start, $end - $start);
        $head  = ListColumns::headCells($table);
        if (!$head) {
            return;
        }
        $ids     = preg_match_all('/name="cid\[\]" value="(\d+)"/', $table, $m) ? array_map('intval', $m[1]) : [];
        $builtin = array_column($head, 'key');
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $this->plugin->loadLanguage();

        try {
            $appId   = str_starts_with($list, 'apps.') ? (int) substr($list, 5) : 0;
            $appType = $appId ? (string) $db->setQuery('SELECT type FROM ' . $db->quoteName('#__gridbox_app') . ' WHERE id = ' . $appId)->loadResult() : 'single';
            $product = $appType === 'products';

            // the items of other folders added to this one: listed on the last page of the list
            $ghosts   = $ghostsOn ? $this->ghostItems($appId, $appType, $category) : [];
            $lastPage = ListColumns::lastPage(substr($body, $end, 6000));
            if ((!$ids && !$ghosts) || (!$columnsOn && !$ghosts)) {
                return;
            }

            // the columns MerTools can add to this list
            $extra = [];
            if (!$columnsOn) {
                // only the ghosts: Gridbox's columns as they are
            } elseif ($product) {
                $extra['sale_price'] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_SALE_PRICE');
                $extra['min']        = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_MIN');
                $extra['badges']     = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_BADGES');
                $extra['variants']   = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_VARIANTS');
            }
            // the Joomla user who made the page: in Gridbox's column "Author" where the list has it (blogs), else as a column of its own
            if ($columnsOn && !\in_array('author', $builtin, true)) {
                $extra['creator'] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_CREATOR');
            }
            foreach ($columnsOn ? ['created' => 'CREATED', 'end_publishing' => 'END_PUBLISHING'] : [] as $key => $label) {
                if (!\in_array($key, $builtin, true)) {
                    $extra[$key] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_COL_' . $label);
                }
            }
            $fields       = [];
            $hiddenFields = self::hiddenFields((string) $this->plugin->params->get('list_fields_hidden', self::LIST_FIELDS_HIDDEN));
            if ($appId && $columnsOn) {
                foreach ($db->setQuery('SELECT id, label, field_type, options FROM ' . $db->quoteName('#__gridbox_fields') . ' WHERE app_id = ' . $appId
                    . ' AND field_type IN (\'text\', \'textarea\', \'select\', \'radio\', \'checkbox\', \'url\', \'email\', \'number\', \'date\', \'price\', \'time\')'
                    . ' ORDER BY order_list, id')->loadAssocList() ?: [] as $field) {
                    // a field without a real name (empty, "." …) or hidden in the settings is not offered
                    if (!self::fieldOffered($field, $hiddenFields)) {
                        continue;
                    }
                    $fields[(int) $field['id']] = $field;
                    $extra['field_' . (int) $field['id']] = trim((string) $field['label']) ?: '#' . (int) $field['id'];
                }
            }

            $prefs   = $columnsOn ? $this->listPrefs((int) $user->id, $list) : null;
            $visible = ListColumns::visible($builtin, array_keys($extra), $prefs['order'] ?? null, $prefs['known'] ?? []);
            $shown   = array_flip($visible);

            // the data of the shown rows, only for the columns shown (and the editable cells)
            $ghostIds = $lastPage ? array_keys($ghosts) : [];
            $in       = implode(',', array_merge($ids, $ghostIds) ?: [0]);
            $pages = [];
            foreach ($db->setQuery('SELECT id, created, end_publishing, featured, language, user_id FROM ' . $db->quoteName('#__gridbox_pages') . ' WHERE id IN (' . $in . ')')->loadAssocList() ?: [] as $row) {
                $pages[(int) $row['id']] = $row;
            }
            $creators = (isset($shown['author']) || isset($shown['creator'])) && $columnsOn ? $this->creators($pages) : [];
            $products = [];
            if ($product) {
                foreach ($db->setQuery('SELECT product_id, price, sale_price, stock, sku, min, variations FROM ' . $db->quoteName('#__gridbox_store_product_data')
                    . ' WHERE product_id IN (' . $in . ') ORDER BY id')->loadAssocList() ?: [] as $row) {
                    $products[(int) $row['product_id']] ??= $row;
                }
            }
            $badges    = [];
            $allBadges = [];
            if ($product) {
                foreach ($db->setQuery('SELECT id, title, color, type FROM ' . $db->quoteName('#__gridbox_store_badges') . ' ORDER BY id')->loadAssocList() ?: [] as $badge) {
                    $allBadges[(int) $badge['id']] = $badge;
                }
            }
            if (isset($shown['badges'])) {
                foreach ($db->setQuery('SELECT product_id, badge_id FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id IN (' . $in
                    . ') ORDER BY order_list, id')->loadRowList() ?: [] as [$pid, $bid]) {
                    if (isset($allBadges[(int) $bid])) {
                        $badges[(int) $pid][] = (int) $bid;
                    }
                }
            }
            $values = [];
            $wanted = array_filter(array_keys($fields), static fn ($id) => isset($shown['field_' . $id]));
            if ($wanted) {
                foreach ($db->setQuery('SELECT page_id, field_id, value FROM ' . $db->quoteName('#__gridbox_page_fields') . ' WHERE page_id IN (' . $in . ') AND field_id IN ('
                    . implode(',', $wanted) . ')')->loadRowList() ?: [] as [$pid, $fid, $value]) {
                    $values[(int) $pid][(int) $fid] = (string) $value;
                }
            }
        } catch (\Throwable $e) {
            return;
        }

        $currency = $this->plugin->storeCurrency();
        $edit     = (int) $this->plugin->params->get('list_quickedit', 1) === 1 && $user->authorise('core.edit', 'com_gridbox');
        $esc      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $date     = static fn ($v) => ($v === '' || $v === null || str_starts_with((string) $v, '0000')) ? '' : HTMLHelper::_('date', (string) $v, 'd.m.Y H:i', false);

        $creator   = static fn (array $c) => '<span class="mt-author' . ($c['known'] ? '' : ' mt-author-guess') . '" title="' . $esc($c['tip']) . '">' . $esc($c['name']) . '</span>';
        $extraCell = function (string $key, int $id) use ($pages, $products, $badges, $allBadges, $values, $fields, $currency, $esc, $date, $creators, $creator): string {
            $page = $pages[$id] ?? [];
            switch ($key) {
                case 'creator':
                    return isset($creators[$id]) ? $creator($creators[$id]) : '';
                case 'sale_price':
                    return $esc(ListColumns::money((string) ($products[$id]['sale_price'] ?? ''), $currency));
                case 'min':
                    return $esc((string) ($products[$id]['min'] ?? ''));
                case 'created':
                case 'end_publishing':
                    return $esc($date($page[$key] ?? ''));
                case 'badges':
                    return self::badgesHtml($badges[$id] ?? [], $allBadges, $products[$id] ?? []);
                case 'variants':
                    if (!isset($products[$id])) {
                        return '';
                    }

                    return $esc(Text::_(self::hasVariants((string) $products[$id]['variations']) ? 'JYES' : 'JNO'));
            }
            if (str_starts_with($key, 'field_')) {
                $fid = (int) substr($key, 6);

                return $esc(self::fieldText($fields[$fid] ?? [], $values[$id][$fid] ?? ''));
            }

            return '';
        };
        $ghostSet = array_flip($ghostIds);
        $decorate = function (string $key, string $cell, int $id) use ($products, $badges, $edit, $ghostSet, $creators, $creator): string {
            if ($key === 'author' && isset($creators[$id])) {
                // after Gridbox's own authors (avatars only): the Joomla user who made the page
                return preg_replace('#</td>$#', $creator($creators[$id]) . '</td>', $cell, 1) ?? $cell;
            }
            if (isset($ghostSet[$id])) {
                return $cell;
            }
            if ($edit && $key === 'badges' && isset($products[$id])) {
                return ListColumns::withAttributes($cell, ['data-mt-badges' => $id, 'data-mt-raw' => implode(',', $badges[$id] ?? []), 'tabindex' => '0']);
            }
            if (!$edit || !isset(ListColumns::EDITABLE[$key]) || !isset($products[$id])) {
                return $cell;
            }
            $row  = $products[$id];
            $attr = ['data-mt-edit' => $key, 'data-mt-id' => $id, 'data-mt-raw' => (string) ($row[$key] ?? ''), 'tabindex' => '0'];
            if ($key === 'price' && self::hasVariants((string) $row['variations'])) {
                $attr['data-mt-variants'] = '1';
            }

            return ListColumns::withAttributes($cell, $attr);
        };
        if ($ghosts) {
            $rows = '';
            foreach ($ghostIds as $gid) {
                $rows .= '<tr class="mt-ghost">' . implode('', array_map(fn ($key) => $this->ghostCell($key, $ghosts[$gid], $products[$gid] ?? [], $appId, $currency),
                    $builtin)) . '</tr>';
            }
            $note  = Text::sprintf($lastPage ? 'PLG_SYSTEM_MERTOOLS_LIST_GHOSTS_HEAD' : 'PLG_SYSTEM_MERTOOLS_LIST_GHOSTS_LATER', \count($ghosts));
            $rows  = '<tr class="mt-ghost-head"><td colspan="' . \count($visible) . '">' . $esc($note) . '</td></tr>' . $rows;
            $close = strrpos($table, '</table>');
            $table = substr($table, 0, $close) . '<tbody class="mt-ghosts">' . $rows . '</tbody>' . substr($table, $close);
        }
        if ($columnsOn) {
            $table = ListColumns::apply($table, $visible, $extra, $extraCell, $decorate);
        }

        // the columns window: Gridbox's columns (labels from its head), then the added ones
        $columns = [];
        $labels  = array_column($head, 'label', 'key');
        foreach (array_merge($builtin, array_keys($extra)) as $key) {
            if ($key === 'select') {
                continue;
            }
            $columns[] = ['key' => $key, 'label' => $labels[$key] ?? $extra[$key] ?? $key, 'visible' => isset($shown[$key]), 'locked' => $key === 'title',
                'added' => isset($extra[$key])];
        }
        usort($columns, static function ($a, $b) use ($visible) {
            $ia = array_search($a['key'], $visible, true);
            $ib = array_search($b['key'], $visible, true);

            return ($ia === false ? 1000 : $ia) <=> ($ib === false ? 1000 : $ib);
        });
        $texts = [];
        foreach (['BUTTON', 'TITLE', 'HINT', 'SAVE', 'RESET', 'CANCEL', 'UP', 'DOWN', 'ADDED', 'SAVING', 'ERROR', 'EDIT_HINT', 'VARIANTS', 'SAVED', 'EMPTY_SALE',
            'BADGES_TITLE', 'BADGES_NONE'] as $k) {
            $texts[$k] = Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_' . $k);
        }
        $edit   = $edit && $columnsOn;
        $config = ['url' => Uri::base(true) . '/index.php', 'token' => Session::getFormToken(), 'list' => $list, 'builtin' => array_values(array_diff($builtin, ['select'])),
            'columns' => $columns, 'edit' => $edit, 'texts' => $texts,
            'badges' => array_values(array_map(static fn ($b) => ['id' => (int) $b['id'], 'label' => self::badgeLabel($b), 'color' => (string) $b['color']], $allBadges))];
        $tools  = !$columnsOn ? '' : '<div class="mt-list-tools"><button type="button" class="mt-cols-btn" data-mt-columns><span aria-hidden="true">☰</span> ' . $esc($texts['BUTTON']) . '</button>'
            . ($edit && $product ? '<span class="mt-list-hint">' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_EDIT_HINT_BADGES')) . '</span>' : '') . '</div>';
        $body = substr($body, 0, $start) . $tools . $table . substr($body, $end);
        $tail = '<style>' . self::LIST_CSS . ($ghosts ? self::GHOST_CSS : '') . '</style><script type="application/json" id="mertools-lists-config">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . Uri::root(true)
            . '/media/plg_system_mertools/js/mertools-lists' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    /**
     * Gridbox's media manager (in the panel and in the editor): files dropped on it from the computer
     * are uploaded to the folder open in it, through Gridbox's own upload field.
     */
    public function mediaDropScript(): void
    {
        $app = $this->plugin->app();
        if (!(int) $this->plugin->params->get('media_drop', 1) || $app->getInput()->getCmd('option') !== 'com_gridbox') {
            return;
        }
        $user = $app->getIdentity();
        $body = (string) $app->getBody();
        if (!$user || $user->guest || !str_contains($body, 'com_gridbox/assets/js/ba-uploader.js')) {
            return;
        }
        $this->plugin->loadLanguage();
        $cfg  = ['texts' => ['DROP' => Text::_('PLG_SYSTEM_MERTOOLS_MEDIA_DROP'), 'FOLDER' => Text::_('PLG_SYSTEM_MERTOOLS_MEDIA_DROP_FOLDER')]];
        $tail = '<script type="application/json" id="mertools-media-config">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script><script src="' . Uri::root(true) . '/media/plg_system_mertools/js/mertools-media' . (JDEBUG ? '' : '.min') . '.js?' . MerTools::VERSION . '" defer></script>';
        $pos  = strripos($body, '</body>');
        $app->setBody($pos === false ? $body . $tail : substr($body, 0, $pos) . $tail . substr($body, $pos));
    }

    public const LIST_CSS = '.mt-list-tools{display:flex;align-items:center;gap:12px;margin:0 0 8px}.mt-cols-btn{border:1px solid #d1d5db;background:#fff;border-radius:6px;'
        . 'padding:6px 12px;cursor:pointer;font-size:13px}.mt-cols-btn:hover{border-color:#1d4ed8;color:#1d4ed8}.mt-list-hint{font-size:12px;color:#6b7280}'
        . '[data-mt-edit]{cursor:pointer;position:relative}[data-mt-edit]:hover{outline:1px dashed #93c5fd;outline-offset:-3px}[data-mt-edit].mt-saving{opacity:.5}'
        . '[data-mt-edit].mt-saved{background:#dcfce7!important;transition:background 1.5s}[data-mt-edit] input{width:100%;min-width:80px;box-sizing:border-box;'
        . 'padding:3px 6px;font:inherit;border:1px solid #1d4ed8;border-radius:4px}[data-mt-variants]::after{content:"ⓘ";font-size:11px;color:#b45309;margin-left:4px}'
        . '[data-mt-badges]{cursor:pointer;min-width:60px}[data-mt-badges]:hover{outline:1px dashed #93c5fd;outline-offset:-3px}'
        . '.mt-badge{display:inline-block;color:#fff;font-size:11px;font-weight:600;padding:1px 7px;border-radius:10px;margin:1px 2px 1px 0;white-space:nowrap}'
        . '.mt-col{font-size:13px}.mt-col small{color:#6b7280}.mt-author{display:inline-block;vertical-align:middle;margin-left:4px;font-size:13px}'
        . '.mt-author:first-child{margin-left:0}.mt-author-guess{color:#9ca3af;font-style:italic}';

    public const GHOST_CSS = 'tbody.mt-ghosts tr.mt-ghost td{color:#9ca3af!important;background:#fafafa}tbody.mt-ghosts tr.mt-ghost .post-intro-image{filter:grayscale(1);opacity:.45}'
        . 'tbody.mt-ghosts tr.mt-ghost .mt-badge{opacity:.5}.mt-ghost-title{display:flex;align-items:center;line-height:18px;font-style:italic}'
        . '.mt-ghost-title .post-intro-image{display:block;flex:0 0 50px;width:50px;height:50px;margin-right:15px;background-size:cover;background-position:center;border-radius:3px}'
        . '.mt-ghost-note{margin:4px 0 0 65px;font-size:12px;line-height:16px;color:#6b7280}'
        . '.mt-ghost-note a{display:inline!important;color:#1d4ed8!important;text-decoration:underline}.mt-ghost-mark{font-size:15px;color:#9ca3af}'
        . 'tbody.mt-ghosts tr.mt-ghost-head td{background:#f3f4f6;color:#4b5563;font-size:12px;font-weight:600;padding:8px 14px;text-align:left!important;border-top:2px dashed #d1d5db}';

    /**
     * The Joomla user who made each page, for the column "Author" (Gridbox shows there only its own
     * authors, which most pages have none of): the user who sent the page from the site (Gridbox's
     * user_id), else the history of MerTools — its entry "created"/"duplicated", or for a page older
     * than the history its first change, marked as a guess.
     *
     * @param array<int, array<string, mixed>> $pages  id => row with user_id
     *
     * @return array<int, array{name: string, tip: string, known: bool}>
     */
    private function creators(array $pages): array
    {
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        try {
            $history = (new History($db))->creators(array_keys($pages));
        } catch (\Throwable $e) {
            $history = [];
        }
        $userIds = array_unique(array_filter(array_map('intval', array_merge(array_column($pages, 'user_id'), array_column($history, 'user_id')))));
        $names   = $userIds ? ($db->setQuery('SELECT id, name FROM ' . $db->quoteName('#__users') . ' WHERE id IN (' . implode(',', $userIds) . ')')
            ->loadAssocList('id', 'name') ?: []) : [];
        $out = [];
        foreach ($pages as $id => $page) {
            $userId = (int) ($page['user_id'] ?? 0);
            if ($userId > 0 && isset($names[$userId])) {
                $out[$id] = ['name' => (string) $names[$userId], 'tip' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_AUTHOR_SUBMITTED', $names[$userId]), 'known' => true];
                continue;
            }
            if (!isset($history[$id])) {
                continue;
            }
            $entry = $history[$id];
            // the name the user has now; a deleted user by the name kept in the history ("Name (username)")
            $name = (string) ($names[$entry['user_id']] ?? preg_replace('/\s*\([^()]*\)$/', '', $entry['user_name']));
            if ($name === '') {
                continue;
            }
            $when     = HTMLHelper::_('date', $entry['created'], Text::_('DATE_FORMAT_LC4'));
            $out[$id] = ['name' => $name, 'tip' => Text::sprintf($entry['known'] ? 'PLG_SYSTEM_MERTOOLS_LIST_AUTHOR_MADE' : 'PLG_SYSTEM_MERTOOLS_LIST_AUTHOR_GUESS', $name, $when),
                'known' => $entry['known']];
        }

        return $out;
    }

    /**
     * The items of other folders added to this one (Gridbox keeps the extra folders of an item in
     * #__gridbox_category_page_map, the main one in page_category), with the list's filters applied.
     *
     * @return array<int, array<string, mixed>>  id => item
     */
    private function ghostItems(int $appId, string $appType, int $category): array
    {
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $app     = $this->plugin->app();
        $context = 'com_gridbox.apps.' . strtolower($appType);
        $query   = 'SELECT DISTINCT p.id, p.title, p.published, p.intro_image, p.page_category, p.hits, p.created, p.language, c.title AS main_title'
            . ' FROM ' . $db->quoteName('#__gridbox_category_page_map') . ' AS m JOIN ' . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = m.page_id'
            . ' LEFT JOIN ' . $db->quoteName('#__gridbox_categories') . ' AS c ON c.id = p.page_category'
            . ' WHERE m.category_id = ' . $category . ' AND p.app_id = ' . $appId . ' AND p.page_category <> ' . $db->quote('trashed')
            . ' AND p.page_category <> ' . $db->quote((string) $category);
        // the filters of the list (as Gridbox applies them to its own rows)
        $search = trim((string) $app->getUserState($context . '.filter.search', ''));
        if ($search !== '') {
            $query .= ' AND p.title LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false);
        }
        $state = (string) $app->getUserState($context . '.filter.state', '');
        $query .= is_numeric($state) ? ' AND p.published = ' . (int) $state : ' AND p.published IN (0, 1)';
        if ((int) $app->getUserState($context . '.filter.theme', 0)) {
            $query .= ' AND p.theme = ' . (int) $app->getUserState($context . '.filter.theme', 0);
        }
        if ((int) $app->getUserState($context . '.filter.author', 0)) {
            $query .= ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__gridbox_authors_map') . ' AS a WHERE a.page_id = p.id AND a.author_id = '
                . (int) $app->getUserState($context . '.filter.author', 0) . ')';
        }
        foreach (['access' => 'page_access', 'language' => 'language'] as $filter => $column) {
            $value = (string) $app->getUserState($context . '.filter.' . $filter, '');
            if ($value !== '') {
                $query .= ' AND p.' . $column . ' = ' . $db->quote($value);
            }
        }
        $items = [];
        foreach ($db->setQuery($query . ' ORDER BY c.title, p.title LIMIT 500')->loadAssocList() ?: [] as $row) {
            $items[(int) $row['id']] = $row;
        }

        return $items;
    }

    /** A cell of a ghost row, for a column of Gridbox's list: shown only, nothing to click but the main folder. */
    private function ghostCell(string $key, array $item, array $product, int $appId, array $currency): string
    {
        $esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        switch ($key) {
            case 'select':
                // no tick box: Gridbox's bulk actions and its row menu are for the items of this folder
                return '<td class="select-td" data-mt-ghost="' . (int) $item['id'] . '"><span class="mt-ghost-mark" aria-hidden="true">↪</span></td>';
            case 'published':
                $on = (int) $item['published'] === 1;

                return '<td class="status-td"><i class="zmdi zmdi-' . ($on ? 'eye' : 'eye-off') . ' ba-icon-md" title="' . $esc(Text::_($on ? 'JPUBLISHED' : 'JUNPUBLISHED')) . '"></i></td>';
            case 'title':
                $image = trim((string) $item['intro_image']);
                if ($image !== '' && !preg_match('#^(https?:)?//#i', $image)) {
                    $image = Uri::root() . ltrim($image, '/');
                }
                $folder = '<a href="' . $esc('index.php?option=com_gridbox&view=apps&id=' . $appId . '&category=' . (int) $item['page_category']) . '">'
                    . $esc((string) ($item['main_title'] ?? '') !== '' ? $item['main_title'] : '#' . $item['page_category']) . '</a>';

                return '<td class="title-cell" title="' . $esc(Text::_('PLG_SYSTEM_MERTOOLS_LIST_GHOST_TIP')) . '"><span class="mt-ghost-title">'
                    . ($image !== '' ? '<span class="post-intro-image" style="background-image: url(' . $esc($image) . ');"></span>' : '')
                    . $esc($item['title']) . '</span><div class="mt-ghost-note"' . ($image === '' ? ' style="margin-left:0"' : '') . '>' . Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_GHOST_NOTE', $folder) . '</div></td>';
            case 'price':
                return '<td class="price-cell">' . $esc(isset($product['price']) ? ListColumns::money((string) $product['price'], $currency) : '') . '</td>';
            case 'stock':
            case 'sku':
                return '<td class="' . $key . '-cell">' . $esc($product[$key] ?? '') . '</td>';
            case 'page_category':
                return '<td class="category-cell">' . $esc($item['main_title'] ?? '') . '</td>';
            case 'hits':
                return '<td class="hits-cell">' . (int) $item['hits'] . '</td>';
            case 'created':
                return '<td>' . $esc(str_starts_with((string) $item['created'], '0000') ? '' : HTMLHelper::_('date', (string) $item['created'], Text::_('DATE_FORMAT_LC4'), false)) . '</td>';
            case 'language':
                return '<td>' . $esc($item['language'] === '*' ? Text::_('JALL') : $item['language']) . '</td>';
            case 'id':
                return '<td>' . (int) $item['id'] . '</td>';
        }

        return '<td></td>';
    }

    /** The value of a Gridbox field as text: the title of a chosen option, the label of a link, a short text. */
    private static function fieldText(array $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $type    = (string) ($field['field_type'] ?? '');
        $options = [];
        $json    = json_decode((string) ($field['options'] ?? ''), true);
        foreach ((array) ($json['items'] ?? []) as $item) {
            if (\is_array($item) && isset($item['key'])) {
                $options[(string) $item['key']] = (string) ($item['title'] ?? $item['key']);
            }
        }
        if (\in_array($type, ['select', 'radio'], true)) {
            return $options[$value] ?? $value;
        }
        if ($type === 'checkbox') {
            $keys = json_decode($value, true);

            return implode(', ', array_map(static fn ($k) => $options[(string) $k] ?? (string) $k, \is_array($keys) ? $keys : [$value]));
        }
        if ($type === 'url') {
            $link = json_decode($value, true);

            return \is_array($link) ? (string) ($link['label'] ?? $link['link'] ?? '') : $value;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }

    /** Fields hidden from the columns window by default (a link to a manual is no column for a list). */
    public const LIST_FIELDS_HIDDEN = 'Instrukcja obsługi Metrel';

    /** @return array<string, true> the hidden fields of the setting: names (lower case, without a final ":") and ids */
    private static function hiddenFields(string $setting): array
    {
        $out = [];
        foreach (preg_split('/\R|,/', $setting) ?: [] as $line) {
            $line = mb_strtolower(rtrim(trim($line), ':'));
            if ($line !== '') {
                $out[$line] = true;
            }
        }

        return $out;
    }

    /** A field offered as a column: it has a real name (letters or digits) and is not hidden by name or id. */
    private static function fieldOffered(array $field, array $hidden): bool
    {
        $label = trim((string) ($field['label'] ?? ''));
        if (!preg_match('/[\p{L}\p{N}]/u', $label)) {
            return false;
        }

        return !isset($hidden[mb_strtolower(rtrim($label, ':'))]) && !isset($hidden[(string) (int) ($field['id'] ?? 0)]);
    }

    /** The product has versions or sets (Gridbox options with their own prices). */
    private static function hasVariants(string $variations): bool
    {
        $data = json_decode($variations, true);

        return \is_array($data) && $data !== [];
    }

    /** The name of a badge: its title; the discount badge of Gridbox ("sale", no title) as "Discount −%". */
    private static function badgeLabel(array $badge): string
    {
        $title = trim((string) ($badge['title'] ?? ''));

        return ($badge['type'] ?? '') === 'sale' && $title === '' ? Text::_('PLG_SYSTEM_MERTOOLS_LIST_BADGE_SALE') : ($title !== '' ? $title : '#' . (int) $badge['id']);
    }

    /** The badges of a product as Gridbox shows them (the discount with its percentage). */
    private static function badgesHtml(array $ids, array $all, array $product): string
    {
        $out = [];
        foreach ($ids as $id) {
            $badge = $all[$id] ?? null;
            if (!$badge) {
                continue;
            }
            $label = self::badgeLabel($badge);
            if (($badge['type'] ?? '') === 'sale' && trim((string) $badge['title']) === '' && isset($product['price'])) {
                $price = (float) $product['price'];
                $sale  = (string) $product['sale_price'] === '' ? $price : (float) $product['sale_price'];
                $label = '- ' . ($price == 0.0 ? 0 : (int) round(100 - $sale * 100 / $price)) . '%';
            }
            $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $badge['color']) ? (string) $badge['color'] : '#6b7280';
            $out[] = '<span class="mt-badge" style="background:' . $color . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return implode(' ', $out);
    }

    /**
     * The badges of a product chosen in the list: the map of Gridbox replaced (in the chosen order), noted
     * in the edit history. Refused while the product is open in the Gridbox editor (it saves its badges).
     */
    public function listBadges(): array
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $id    = $input->getInt('id', 0);
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $row   = $id > 0 ? $db->setQuery('SELECT d.price, d.sale_price, p.title, p.app_id FROM ' . $db->quoteName('#__gridbox_store_product_data') . ' AS d INNER JOIN '
            . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = d.product_id WHERE d.product_id = ' . $id . ' ORDER BY d.id LIMIT 1')->loadAssoc() : null;
        if (!$row) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_NOT_PRODUCT')];
        }
        if ($this->plugin->locks()->lockOn()) {
            $lock = $this->plugin->locks()->editLocks()->get(EditLock::key('', $id));
            if (EditLock::held($lock, time(), $this->plugin->locks()->lockIdle())) {
                return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_UI_LOCKED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']))];
            }
        }
        $all = [];
        foreach ($db->setQuery('SELECT id, title, color, type FROM ' . $db->quoteName('#__gridbox_store_badges'))->loadAssocList() ?: [] as $badge) {
            $all[(int) $badge['id']] = $badge;
        }
        $chosen = json_decode($input->getString('badges', '[]'), true);
        $chosen = array_values(array_unique(array_filter(array_map('intval', \is_array($chosen) ? $chosen : []), static fn ($b) => isset($all[$b]))));
        $old    = array_map('intval', $db->setQuery('SELECT badge_id FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id = ' . $id
            . ' ORDER BY order_list, id')->loadColumn() ?: []);
        if ($old !== $chosen) {
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__gridbox_store_badges_map') . ' WHERE product_id = ' . $id)->execute();
            foreach ($chosen as $i => $badgeId) {
                $db->setQuery('INSERT INTO ' . $db->quoteName('#__gridbox_store_badges_map') . ' (product_id, badge_id, order_list) VALUES (' . $id . ', ' . $badgeId . ', ' . $i . ')')->execute();
            }
            $names = fn (array $ids) => implode(', ', array_map(fn ($b) => isset($all[$b]) ? self::badgeLabel($all[$b]) : '#' . $b, $ids));
            if ($this->plugin->history()->historyOn()) {
                try {
                    $history = new History($db);
                    $history->ensureTables();
                    $history->record($id, (int) $row['app_id'], (string) $row['title'], (int) $user->id, MerTools::userLabel($user), 'edited',
                        [['f' => 'badges', 'from' => $names($old), 'to' => $names($chosen)]], 'admin', gmdate('Y-m-d H:i:s'));
                    if ((int) $this->plugin->params->get('history_actionlog', 1) === 1) {
                        $this->plugin->history()->historyActionLog('edited', $id, (string) $row['title'], (int) $user->id, (string) $user->username);
                    }
                } catch (\Throwable $e) {
                }
            }
            $this->plugin->speed()->purgeKeptPages();
        }

        return ['success' => true, 'raw' => implode(',', $chosen), 'html' => self::badgesHtml($chosen, $all, $row)];
    }

    /** The columns window saved: the order of the shown columns (empty = Gridbox's own again). */
    public function listColumnsSave(): array
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $list  = $input->getString('list', '');
        if (!preg_match('/^(apps\.\d{1,9}|pages)$/', $list)) {
            return ['success' => false, 'message' => 'list'];
        }
        $clean = static fn ($v) => array_values(array_filter(array_map('strval', \is_array($v) ? $v : []), static fn ($k) => preg_match('/^[a-z_0-9]{1,40}$/', $k)));
        $order = $clean(json_decode($input->getString('order', '[]'), true));
        $known = $clean(json_decode($input->getString('known', '[]'), true));
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $key   = $db->quote($this->listProfileKey($list));
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__user_profiles') . ' WHERE user_id = ' . (int) $user->id . ' AND profile_key = ' . $key)->execute();
        if ($order) {
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__user_profiles') . ' (user_id, profile_key, profile_value, ordering) VALUES (' . (int) $user->id . ', ' . $key
                . ', ' . $db->quote(json_encode(['order' => \array_slice($order, 0, 60), 'known' => \array_slice($known, 0, 60)])) . ', 0)')->execute();
        }

        return ['success' => true];
    }

    /**
     * A price, sale price, stock or SKU changed in the list: checked, kept as Gridbox keeps it, noted in
     * the edit history, kept pages emptied. Refused while the product is open in the Gridbox editor
     * (its next save would put the old value back).
     */
    public function listEdit(): array
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        $id    = $input->getInt('id', 0);
        $field = $input->getCmd('field', '');
        if ($id <= 0 || !isset(ListColumns::EDITABLE[$field])) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_ERROR')];
        }
        $typed   = (string) $input->post->get('value', '', 'raw');
        $percent = $field === 'sale_price' ? ListColumns::percent($typed) : null;
        $value   = $percent === null ? ListColumns::clean(ListColumns::EDITABLE[$field], $typed) : '';
        if ($value === null || ($field === 'price' && $value === '')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_BAD_' . strtoupper(ListColumns::EDITABLE[$field]))];
        }
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery('SELECT d.id, d.' . $db->quoteName($field) . ' AS value, d.price AS base_price, p.title, p.app_id FROM ' . $db->quoteName('#__gridbox_store_product_data')
            . ' AS d INNER JOIN ' . $db->quoteName('#__gridbox_pages') . ' AS p ON p.id = d.product_id WHERE d.product_id = ' . $id . ' ORDER BY d.id LIMIT 1')->loadAssoc();
        if (!$row) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_LIST_UI_NOT_PRODUCT')];
        }
        if ($this->plugin->locks()->lockOn()) {
            $lock = $this->plugin->locks()->editLocks()->get(EditLock::key('', $id));
            if (EditLock::held($lock, time(), $this->plugin->locks()->lockIdle())) {
                return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_MERTOOLS_LIST_UI_LOCKED', preg_replace('/\s*\([^()]*\)$/', '', (string) $lock['user_name']))];
            }
        }
        // "10%" in the sale price: the regular price minus 10 % (0 % = no sale price)
        if ($percent !== null) {
            $value = $percent > 0 && (float) $row['base_price'] > 0
                ? (string) ListColumns::clean('money', number_format((float) $row['base_price'] * (1 - $percent / 100), max(0, min(4, (int) ($this->plugin->storeCurrency()['decimals'] ?? 2))), '.', ''))
                : '';
        }
        $old = (string) $row['value'];
        if ($old !== $value) {
            $db->setQuery('UPDATE ' . $db->quoteName('#__gridbox_store_product_data') . ' SET ' . $db->quoteName($field) . ' = ' . $db->quote($value)
                . ' WHERE id = ' . (int) $row['id'])->execute();
            if ($this->plugin->history()->historyOn()) {
                try {
                    $history = new History($db);
                    $history->ensureTables();
                    $history->record($id, (int) $row['app_id'], (string) $row['title'], (int) $user->id, MerTools::userLabel($user), 'edited',
                        [['f' => 'product_' . $field, 'from' => $old, 'to' => $value]], 'admin', gmdate('Y-m-d H:i:s'));
                    if ((int) $this->plugin->params->get('history_actionlog', 1) === 1) {
                        $this->plugin->history()->historyActionLog('edited', $id, (string) $row['title'], (int) $user->id, (string) $user->username);
                    }
                } catch (\Throwable $e) {
                }
            }
            $this->plugin->speed()->purgeKeptPages();
        }
        $kind = ListColumns::EDITABLE[$field];

        return ['success' => true, 'raw' => $value, 'display' => $kind === 'money' ? ListColumns::money($value, $this->plugin->storeCurrency()) : $value];
    }
}
