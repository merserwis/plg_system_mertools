<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * The map of MerTools' administrator: which page shows which tool, in which section of the control
 * panel, with which icon, and which of its switches say that it is on. The settings themselves are the
 * fieldsets of the plugin's manifest (plg_system_mertools/mertools.xml): a fieldset with page="x" is a
 * tab of page x, in the order of the file. Two kinds of pages:
 *
 *  - the tools used day to day: an overview first (what the tool did, its buttons), then its settings;
 *    the most used ones are in the administrator menu, with a cog that opens their settings;
 *  - the configuration: what is set once and forgotten, one page with a tab for each tool.
 *
 * Quick setup: the few switches a new site decides first, each one setting one or more settings of the
 * plugin, with the value most sites should have.
 */

namespace Merserwis\Component\MerTools\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Catalog
{
    /** the sections of the control panel, in order */
    public const GROUPS = ['speed', 'seo', 'shop', 'gridbox'];

    /**
     * The pages of the tools: group (section of the control panel), icon (Joomla's icon-*), on (the
     * switches that say the tool works, any of them), store (only shown with a Gridbox store).
     */
    public const PAGES = [
        'cache'    => ['group' => 'speed',   'icon' => 'bolt',           'on' => ['speed_cache']],
        'images'   => ['group' => 'speed',   'icon' => 'images',         'on' => ['speed_images']],
        'measure'  => ['group' => 'speed',   'icon' => 'chart',          'on' => []],
        'checks'   => ['group' => 'speed',   'icon' => 'check-circle',   'on' => []],
        'sitemap'  => ['group' => 'seo',     'icon' => 'tree-2',         'on' => ['sitemap_enabled']],
        'notfound' => ['group' => 'seo',     'icon' => 'compass',        'on' => ['notfound_log', 'notfound_moved', 'notfound_redirect']],
        'omnibus'  => ['group' => 'shop',    'icon' => 'tag',            'on' => ['shop_omnibus'], 'store' => true],
        'carts'    => ['group' => 'shop',    'icon' => 'cart',           'on' => ['cart_enabled'], 'store' => true],
        'history'  => ['group' => 'gridbox', 'icon' => 'clock',          'on' => ['history_enabled']],
        'apps'     => ['group' => 'gridbox', 'icon' => 'shield-alt',     'on' => ['apps_protect', 'apps_backup_delete', 'apps_backup_auto']],
        'database' => ['group' => 'gridbox', 'icon' => 'database',       'on' => ['db_indexes', 'intro_lt']],
        'config'   => ['group' => '',        'icon' => 'cog',            'on' => []],
    ];

    /** the tabs of the configuration page (fieldsets with page="config"), as tiles of the control panel */
    public const CONFIG = [
        'url'     => ['icon' => 'link',           'on' => ['url_collapse_slashes', 'url_sort_guard', 'url_pagination']],
        'hygiene' => ['icon' => 'filter',         'on' => ['hygiene_og_url', 'hygiene_cookies', 'hygiene_xss', 'hygiene_lang', 'hygiene_csp_mode']],
        'schema'  => ['icon' => 'code',           'on' => ['schema_on']],
        'blog'    => ['icon' => 'rss',            'on' => ['feed_enabled', 'blog_jsonld', 'blog_grid']],
        'shop'    => ['icon' => 'cart',           'on' => ['shop_option_links', 'shop_discount_field', 'shop_field_fold', 'price_request', 'shop_jsonld'], 'store' => true],
        'speed'   => ['icon' => 'tachometer-alt', 'on' => ['speed_show', 'speed_icons', 'speed_mainphoto', 'speed_slideimg', 'speed_video', 'speed_popups', 'speed_scripts']],
        'layout'  => ['icon' => 'th-large',       'on' => ['layout_post_rows', 'layout_clip_x', 'list_hide_words']],
        'tabs'    => ['icon' => 'folder-open',    'on' => ['tabs_links', 'tabs_accordion']],
        'tel'     => ['icon' => 'phone',          'on' => ['tel_enabled']],
        'dark'    => ['icon' => 'paint-brush',    'on' => ['dark_enabled']],
        'lists'   => ['icon' => 'list',           'on' => ['list_columns', 'list_ghosts', 'media_drop']],
        'lock'    => ['icon' => 'lock',           'on' => ['lock_enabled']],
        'editor'  => ['icon' => 'save',           'on' => ['editor_unsaved_warn', 'editor_autosave']],
    ];

    /**
     * Quick setup, in sections: switch => [the settings it sets, the recommended value]. A switch of
     * several settings is on when all of them are; only a switch that was changed is saved, so a
     * setting tuned on its own page is not overwritten by a switch nobody touched.
     */
    public const SETUP = [
        'site' => [
            'url_collapse_slashes' => ['set' => ['url_collapse_slashes'], 'recommended' => 1],
            'url_sort_guard'       => ['set' => ['url_sort_guard'], 'recommended' => 1],
            'qs_hygiene'           => ['set' => ['hygiene_og_url', 'hygiene_cookies', 'hygiene_xss'], 'recommended' => 1],
        ],
        'speed' => [
            'speed_cache'  => ['set' => ['speed_cache', 'speed_warm'], 'recommended' => 1],
            'qs_firstview' => ['set' => ['speed_show', 'speed_icons', 'speed_popups', 'speed_video'], 'recommended' => 1],
            'qs_images'    => ['set' => ['speed_images', 'speed_webp'], 'recommended' => 1],
        ],
        'seo' => [
            'sitemap_enabled' => ['set' => ['sitemap_enabled'], 'recommended' => 1],
            'qs_notfound'     => ['set' => ['notfound_log', 'notfound_moved'], 'recommended' => 1],
        ],
        'gridbox' => [
            'history_enabled' => ['set' => ['history_enabled'], 'recommended' => 1],
            'lock_enabled'    => ['set' => ['lock_enabled'], 'recommended' => 1],
            'qs_apps'         => ['set' => ['apps_protect', 'apps_backup_delete'], 'recommended' => 1],
            'db_indexes'      => ['set' => ['db_indexes'], 'recommended' => 1],
        ],
        'shop' => [
            'shop_jsonld'  => ['set' => ['shop_jsonld'], 'recommended' => 1],
            'shop_omnibus' => ['set' => ['shop_omnibus'], 'recommended' => 0],
            'cart_enabled' => ['set' => ['cart_enabled'], 'recommended' => 1],
        ],
    ];

    /** the page of a name, or null */
    public static function page(string $name): ?array
    {
        return self::PAGES[$name] ?? null;
    }

    /** a setting that is on: anything but empty, 0, off or none (switches, lists such as "off / report / enforce", texts) */
    public static function isOn(Registry $params, array $defaults, string $key): bool
    {
        $value = $params->get($key, $defaults[$key] ?? '');
        if (\is_array($value) || \is_object($value)) {
            return (bool) $value;
        }

        return !\in_array(strtolower(trim((string) $value)), ['', '0', 'off', 'none', 'no'], true);
    }

    /** 'on' when any of the switches is on, 'off' when none is, '' for a page without switches */
    public static function state(array $keys, Registry $params, array $defaults): string
    {
        if (!$keys) {
            return '';
        }
        foreach ($keys as $key) {
            if (self::isOn($params, $defaults, $key)) {
                return 'on';
            }
        }

        return 'off';
    }

    /**
     * The tiles of the control panel: group => [[name, icon, state, link], …]; the tools of the store
     * only when the site has a Gridbox store, the configuration as a group of its own.
     *
     * @return array<string, list<array{name: string, icon: string, state: string, link: string, config: bool}>>
     */
    public static function tiles(Registry $params, array $defaults, bool $store): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::PAGES as $name => $page) {
            if ($page['group'] === '' || (!empty($page['store']) && !$store)) {
                continue;
            }
            $groups[$page['group']][] = ['name' => $name, 'icon' => $page['icon'], 'state' => self::state($page['on'], $params, $defaults),
                'link' => 'index.php?option=com_mertools&view=page&page=' . $name, 'config' => false];
        }
        $groups['config'] = [];
        foreach (self::CONFIG as $name => $tab) {
            if (!empty($tab['store']) && !$store) {
                continue;
            }
            $groups['config'][] = ['name' => $name, 'icon' => $tab['icon'], 'state' => self::state($tab['on'], $params, $defaults),
                'link' => 'index.php?option=com_mertools&view=page&page=config&tab=' . $name, 'config' => true];
        }

        return array_filter($groups);
    }

    /**
     * The state of a quick setup switch from the settings: on when every setting it sets is on.
     */
    public static function setupValue(array $item, Registry $params, array $defaults): int
    {
        foreach ($item['set'] as $key) {
            if (!self::isOn($params, $defaults, $key)) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * The settings a quick setup sends: only the switches whose value differs from the one shown
     * (the initial values come back with the form).
     *
     * @param array<string, mixed> $posted  switch => 0|1
     * @param array<string, mixed> $initial switch => 0|1, as the page showed it
     *
     * @return array<string, int> setting => 0|1
     */
    public static function setupChanges(array $posted, array $initial, bool $store): array
    {
        $out = [];
        foreach (self::SETUP as $section => $items) {
            if ($section === 'shop' && !$store) {
                continue;
            }
            foreach ($items as $switch => $item) {
                if (!\array_key_exists($switch, $posted)) {
                    continue;
                }
                $value = (int) $posted[$switch] === 1 ? 1 : 0;
                if (\array_key_exists($switch, $initial) && (int) $initial[$switch] === $value) {
                    continue;
                }
                foreach ($item['set'] as $key) {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }
}
