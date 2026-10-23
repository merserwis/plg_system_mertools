<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Recommended settings of Gridbox, Joomla and the server for MerTools: each check gets a state
 * (ok, bad, warn, info) and the current value; the texts (what, why, where) are in the language
 * files (PLG_SYSTEM_MERTOOLS_CHK_<ID>_*). Cloudflare's Rocket Loader is checked in the browser
 * (media/js/mertools-checks.js), from the HTML a guest gets.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Checks
{
    public const OK = 'ok';

    public const BAD = 'bad';

    public const WARN = 'warn';

    public const INFO = 'info';

    /**
     * @param array|null $gb     the row of #__gridbox_website (null: Gridbox missing)
     * @param array      $joomla ['platform_prefix' => bool, 'page_cache_plugin' => bool, 'caching' => 0|1|2 (System Cache)]
     * @param array      $mt     MerTools parameters
     * @param array      $server ['php' => '8.5.11', 'webp' => bool, 'opcache' => ['enabled' => bool, 'status' => ?array, 'ini' => array]]
     *
     * @return array<int, array{id: string, group: string, state: string, value: array}> value = [language key suffix, sprintf args…]
     */
    public static function evaluate(?array $gb, array $joomla, array $mt, array $server): array
    {
        $on   = fn ($v) => (int) $v === 1;
        $mtOn = fn (string $k, int $d) => (int) ($mt[$k] ?? $d) === 1;
        $out  = [];
        $add  = function (string $id, string $group, string $state, array $value) use (&$out) {
            $out[] = ['id' => $id, 'group' => $group, 'state' => $state, 'value' => $value];
        };

        if ($gb !== null) {
            $defer = $on($gb['defer_loading'] ?? 0);
            $add('GB_DEFER', 'gridbox', $defer ? ($mtOn('speed_show', 1) ? self::OK : self::BAD) : self::WARN, [$defer ? 'ON' : 'OFF']);
            $add('GB_PAGECACHE', 'gridbox', $on($gb['page_cache'] ?? 0) ? self::BAD : self::OK, [$on($gb['page_cache'] ?? 0) ? 'ON' : 'OFF']);
            $add('GB_LAZY', 'gridbox', $on($gb['images_lazy_load'] ?? 0) ? self::OK : self::INFO, [$on($gb['images_lazy_load'] ?? 0) ? 'ON' : 'OFF']);
            $img = $on($gb['compress_images'] ?? 0) || $on($gb['adaptive_images'] ?? 0);
            $add('GB_IMAGES', 'gridbox', $img && $mtOn('speed_webp', 1) && $mtOn('speed_images', 1) ? self::INFO : self::OK, [$img ? 'ON' : 'OFF']);
            $add('GB_CSS', 'gridbox', self::INFO, [$on($gb['compress_css'] ?? 0) ? 'ON' : 'OFF']);
        }

        $both = !empty($joomla['page_cache_plugin']) && $mtOn('speed_cache', 0);
        $add('JM_PAGECACHE', 'joomla', $both ? self::BAD : self::OK, [!empty($joomla['page_cache_plugin']) ? 'ON' : 'OFF']);
        // the option is shown (and used) only while Joomla's System Cache is on
        if (isset($joomla['caching']) && (int) $joomla['caching'] === 0) {
            $add('JM_PLATFORM', 'joomla', self::OK, ['NOTUSED']);
        } else {
            $add('JM_PLATFORM', 'joomla', !empty($joomla['platform_prefix']) ? self::BAD : self::OK, [!empty($joomla['platform_prefix']) ? 'ON' : 'OFF']);
        }

        $php = (string) ($server['php'] ?? PHP_VERSION);
        $add('SRV_PHP', 'server', version_compare($php, '8.2', '>=') ? self::OK : self::BAD, ['TEXT', $php]);
        $add('SRV_WEBP', 'server', !empty($server['webp']) ? self::OK : self::WARN, [!empty($server['webp']) ? 'YES' : 'NO']);
        $add('SRV_OPCACHE', 'server', ...self::opcache($server['opcache'] ?? []));
        $add('SRV_OPC_FILES', 'server', ...self::opcacheFiles($server['opcache'] ?? []));

        $add('CF_ROCKET', 'cloudflare', self::INFO, ['CHECKING']);

        return $out;
    }

    /** @return array{0: string, 1: array} state and value of the OPcache check */
    /**
     * Whether a changed PHP file is used at once: Joomla drops a file it has just written (configuration.php
     * after a save of the Global Configuration, the files of an update) from OPcache, unless the hosting
     * forbids it (opcache.restrict_api, opcache_invalidate disabled); then only PHP's own check of the files
     * (opcache.validate_timestamps, every revalidate_freq seconds) brings the new file, or a reset of OPcache.
     *
     * @param array $o ['enabled' => bool, 'flush' => bool (Joomla may drop files; unknown: true), 'ini' => ['validate_timestamps' => int, 'revalidate_freq' => int]]
     *
     * @return array{0: string, 1: array} state and value
     */
    public static function opcacheFiles(array $o): array
    {
        if (empty($o['enabled'])) {
            return [self::OK, ['OPC_NOT_ON']];
        }
        if ($o['flush'] ?? true) {
            return [self::OK, ['OPC_FLUSH_OK']];
        }
        $ini = $o['ini'] ?? [];
        if (!(int) ($ini['validate_timestamps'] ?? 1)) {
            return [self::BAD, ['OPC_FLUSH_NEVER']];
        }
        $freq = (int) ($ini['revalidate_freq'] ?? 2);

        return [$freq <= 5 ? self::INFO : self::WARN, ['OPC_FLUSH_FREQ', $freq]];
    }

    public static function opcache(array $o): array
    {
        if (empty($o['enabled'])) {
            return [self::BAD, ['OFF']];
        }
        $ini = $o['ini'] ?? [];
        $mb  = (int) ($ini['memory_consumption'] ?? 0);
        $max = (int) ($ini['max_accelerated_files'] ?? 0);
        $st  = $o['status'] ?? null;
        if (!\is_array($st) || empty($st['memory_usage'])) {
            // details hidden by the hosting (opcache.restrict_api): the limits only
            return [$mb >= 256 && $max >= 20000 ? self::OK : self::WARN, ['OPC_LIMITS', $mb, $max]];
        }
        $mem    = $st['memory_usage'];
        $total  = ($mem['used_memory'] ?? 0) + ($mem['free_memory'] ?? 0) + ($mem['wasted_memory'] ?? 0);
        $freePc = $total > 0 ? ($mem['free_memory'] ?? 0) * 100 / $total : 0;
        $hits   = (int) ($st['opcache_statistics']['hits'] ?? 0);
        $miss   = (int) ($st['opcache_statistics']['misses'] ?? 0);
        $rate   = $hits + $miss > 0 ? $hits * 100 / ($hits + $miss) : 100;
        $files  = (int) ($st['opcache_statistics']['num_cached_scripts'] ?? 0);
        $full   = $freePc < 5 || ($max > 0 && $files >= 0.9 * $max) || !empty($st['cache_full']);
        $state  = $full || ($hits + $miss > 1000 && $rate < 90) ? self::BAD : ($mb < 256 || $max < 20000 ? self::WARN : self::OK);

        return [$state, ['OPC_STATUS', $mb, (int) round($freePc), (int) round($rate), $files, $max]];
    }
}
