<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page speed panel: PageSpeed Insights measurements of chosen pages, kept with the MerTools version
 * and the tools that were on, so a change can be compared with the state before it (the baseline).
 *
 * The measuring itself runs in the administrator's browser (media/js/mertools-speed.js calls the
 * PageSpeed Insights API directly: one test takes 20–45 s, longer than PHP may run on many hosts).
 * The browser sends back a short summary of each page (the median of the runs); this class checks
 * it field by field and keeps it in #__mertools_speed. Nothing of the API answer is trusted as is.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class Speed
{
    /** lab metrics of a measurement: name => [min, max] */
    public const METRICS = ['score' => [0, 100], 'fcp' => [0, 600000], 'lcp' => [0, 600000], 'tbt' => [0, 600000],
        'cls' => [0, 100], 'si' => [0, 600000], 'ttfb' => [0, 600000]];

    /** real-user metrics (Chrome UX Report): name in the summary => kept */
    public const FIELD = ['lcp', 'inp', 'cls', 'fcp', 'ttfb'];

    /** the MerTools settings that switch a tool on: snapshot kept with every measurement */
    public const TOOLS = ['url_collapse_slashes' => 'url', 'notfound_redirect' => 'notfound', 'layout_clip_x' => 'layout',
        'shop_option_links' => 'optionlinks', 'cart_enabled' => 'carts', 'tel_enabled' => 'tel', 'dark_enabled' => 'dark'];

    private const KEEP_PER_PAGE = 60;

    public function __construct(private DatabaseInterface $db)
    {
    }

    // ---------------------------------------------------------------- pure helpers (unit tested)

    /**
     * The pages to measure from the setting: one per line, a path on this site ("/oferta") or a full
     * http(s) address (also of another site, e.g. to compare); anything else is left out; at most 10.
     *
     * @return string[] absolute URLs
     */
    public static function urls(string $setting, string $siteRoot): array
    {
        $root = rtrim($siteRoot, '/');
        $base = preg_replace('#^(https?://[^/]+).*$#i', '$1', $root);
        $out  = [];
        foreach (preg_split('/\R/', $setting) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/[\s"<>\\\\]/', $line)) {
                continue;
            }
            if ($line[0] === '/' && !str_starts_with($line, '//')) {
                $url = $base . $line;
            } elseif (preg_match('#^https?://[^/]+#i', $line)) {
                $url = $line;
            } else {
                continue;
            }
            $out[$url] = true;
            if (\count($out) >= 10) {
                break;
            }
        }

        return array_keys($out ?: [$root . '/' => true]);
    }

    /**
     * A measurement summary sent by the browser, checked field by field; null when unusable.
     *
     * @param array $in  url, strategy, runs, score, fcp, lcp, tbt, cls, si, ttfb, field, audits
     */
    public static function clean(array $in): ?array
    {
        $url = (string) ($in['url'] ?? '');
        if (\strlen($url) > 512 || !preg_match('#^https?://[^\s"<>]+$#i', $url)) {
            return null;
        }
        $strategy = (string) ($in['strategy'] ?? '');
        if (!\in_array($strategy, ['mobile', 'desktop'], true)) {
            return null;
        }
        $out = ['url' => $url, 'strategy' => $strategy, 'runs' => max(1, min(10, (int) ($in['runs'] ?? 1)))];
        foreach (self::METRICS as $name => [$min, $max]) {
            if (!isset($in[$name]) || !is_numeric($in[$name])) {
                return null;
            }
            $value      = (float) $in[$name];
            $out[$name] = $name === 'cls' ? round(max($min, min($max, $value)), 3) : (int) round(max($min, min($max, $value)));
        }

        // real users (Chrome UX Report), for the page and for the whole site: p75 and the category
        $field = [];
        foreach (['page', 'origin'] as $scope) {
            foreach (self::FIELD as $metric) {
                $item = $in['field'][$scope][$metric] ?? null;
                if (\is_array($item) && is_numeric($item[0] ?? null) && \in_array($item[1] ?? '', ['FAST', 'AVERAGE', 'SLOW'], true)) {
                    $field[$scope][$metric] = [$metric === 'cls' ? round((float) $item[0], 3) : (int) $item[0], $item[1]];
                }
            }
        }
        $out['field'] = $field;

        // the biggest opportunities of the lab test (id, title, estimated saving)
        $audits = [];
        foreach (\array_slice((array) ($in['audits'] ?? []), 0, 8) as $audit) {
            $id = (string) ($audit['id'] ?? '');
            if (!preg_match('/^[a-z0-9-]{1,64}$/', $id)) {
                continue;
            }
            $audits[] = ['id' => $id, 'title' => mb_substr(trim(strip_tags((string) ($audit['title'] ?? ''))), 0, 160),
                'ms' => max(0, min(600000, (int) ($audit['ms'] ?? 0))), 'kb' => max(0, min(1000000, (int) ($audit['kb'] ?? 0)))];
        }
        $out['audits'] = $audits;

        return $out;
    }

    /**
     * The tools switched on in the settings (short codes), kept with a measurement.
     *
     * @param array<string, mixed> $params
     *
     * @return string[]
     */
    public static function enabledTools(array $params, array $defaults = []): array
    {
        $on = [];
        foreach (self::TOOLS as $key => $code) {
            if ((int) ($params[$key] ?? ($defaults[$key] ?? 0))) {
                $on[] = $code;
            }
        }

        return $on;
    }

    // ---------------------------------------------------------------- database

    public function ensureTables(): void
    {
        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `#__mertools_speed` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `url` VARCHAR(512) NOT NULL, `strategy` VARCHAR(8) NOT NULL, `measured` DATETIME NOT NULL, `runs` TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . ' `score` TINYINT UNSIGNED NOT NULL, `fcp` INT UNSIGNED NOT NULL, `lcp` INT UNSIGNED NOT NULL, `tbt` INT UNSIGNED NOT NULL,'
            . ' `cls` DECIMAL(6,3) NOT NULL, `si` INT UNSIGNED NOT NULL, `ttfb` INT UNSIGNED NOT NULL, `field` MEDIUMTEXT NOT NULL,'
            . ' `audits` MEDIUMTEXT NOT NULL, `version` VARCHAR(16) NOT NULL, `tools` VARCHAR(512) NOT NULL, `baseline` TINYINT NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (`id`), KEY `idx_page` (`url`(191), `strategy`, `measured`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
    }

    /** Keep a checked measurement; the oldest beyond 60 per page and device go (never a baseline). */
    public function save(array $m, string $version, array $tools, int $now): int
    {
        $this->ensureTables();
        $db  = $this->db;
        $row = (object) ['url' => $m['url'], 'strategy' => $m['strategy'], 'measured' => gmdate('Y-m-d H:i:s', $now), 'runs' => $m['runs'],
            'score' => $m['score'], 'fcp' => $m['fcp'], 'lcp' => $m['lcp'], 'tbt' => $m['tbt'], 'cls' => $m['cls'], 'si' => $m['si'],
            'ttfb' => $m['ttfb'], 'field' => json_encode($m['field']), 'audits' => json_encode($m['audits'], JSON_UNESCAPED_UNICODE),
            'version' => mb_substr($version, 0, 16), 'tools' => implode(',', $tools), 'baseline' => 0];
        $db->insertObject('#__mertools_speed', $row, 'id');

        // the first measurement of a page becomes its baseline
        $where = ' WHERE `url` = ' . $db->quote($m['url']) . ' AND `strategy` = ' . $db->quote($m['strategy']);
        if (!(int) $db->setQuery('SELECT COUNT(*) FROM `#__mertools_speed`' . $where . ' AND `baseline` = 1')->loadResult()) {
            $db->setQuery('UPDATE `#__mertools_speed` SET `baseline` = 1 WHERE `id` = ' . (int) $row->id)->execute();
        }
        $old = $db->setQuery('SELECT `id` FROM `#__mertools_speed`' . $where . ' AND `baseline` = 0 ORDER BY `measured` DESC, `id` DESC LIMIT 1000 OFFSET '
            . self::KEEP_PER_PAGE)->loadColumn();
        if ($old) {
            $db->setQuery('DELETE FROM `#__mertools_speed` WHERE `id` IN (' . implode(',', array_map('intval', $old)) . ')')->execute();
        }

        return (int) $row->id;
    }

    /** Every measurement, newest first, numbers as numbers. */
    public function all(): array
    {
        $this->ensureTables();
        $rows = $this->db->setQuery('SELECT * FROM `#__mertools_speed` ORDER BY `measured` DESC, `id` DESC LIMIT 2000')->loadAssocList();
        foreach ($rows as &$r) {
            foreach (['id', 'runs', 'score', 'fcp', 'lcp', 'tbt', 'si', 'ttfb', 'baseline'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['cls']      = (float) $r['cls'];
            $r['measured'] = strtotime($r['measured'] . ' UTC');
            $r['field']    = json_decode($r['field'], true) ?: [];
            $r['audits']   = json_decode($r['audits'], true) ?: [];
            $r['tools']    = $r['tools'] === '' ? [] : explode(',', $r['tools']);
        }

        return $rows;
    }

    /** Make a measurement the baseline of its page and device (the one before the changes). */
    public function setBaseline(int $id): bool
    {
        $this->ensureTables();
        $row = $this->db->setQuery('SELECT `url`, `strategy` FROM `#__mertools_speed` WHERE `id` = ' . $id)->loadAssoc();
        if (!$row) {
            return false;
        }
        $this->db->setQuery('UPDATE `#__mertools_speed` SET `baseline` = (`id` = ' . $id . ') WHERE `url` = ' . $this->db->quote($row['url'])
            . ' AND `strategy` = ' . $this->db->quote($row['strategy']))->execute();

        return true;
    }

    public function delete(int $id): void
    {
        $this->ensureTables();
        $this->db->setQuery('DELETE FROM `#__mertools_speed` WHERE `id` = ' . $id)->execute();
    }
}
