<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Sitemap: /sitemap.xml (an index) and /sitemap-{map}.xml answered in onAfterInitialise, before the
 * router, from files built into Joomla's cache folder; when they are out of date the request still gets
 * them and the build goes after its response, so a request never waits for it, except the very first one.
 *
 * Out of date: older than the refresh time, older than the last change (a POST of a logged-in user to
 * Gridbox or to Joomla's menus, a save of the MerTools settings), robots.txt or the settings changed.
 * Gridbox's static sitemap.xml, while it exists, is sent by the server before PHP: only the other maps
 * answer then (see the panel and Take over).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\Sitemap;
use Merserwis\Plugin\System\MerTools\Tool\SitemapSource;

final class Sitemaps extends Feature
{
    public const DIR = 'mertools_sitemap';

    /** Where Take over keeps Gridbox's sitemap.xml (a folder robots.txt disallows) */
    public const COPY = '/tmp/mertools-sitemap-gridbox.xml';

    /** The maps are out of date: built again after the response is sent */
    private bool $rebuild = false;

    /** Why the last build failed (for the panel) */
    private string $error = '';

    /** A sitemap address (GET or HEAD): the kept file, 304, or a real 404 for a map that does not exist. */
    public function serveSitemap(): void
    {
        $params = $this->plugin->params;
        if (!Sitemap::enabled($params)) {
            return;
        }
        $app    = $this->plugin->app();
        $input  = $app->getInput();
        $method = strtoupper((string) $input->getMethod());
        $path   = substr((string) Uri::getInstance()->getPath(), \strlen(rtrim(Uri::root(true), '/')));
        $name   = Sitemap::requested($path);
        if ($name === null || !\in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $meta = $this->meta();
        if ($meta === null) {
            // nothing built yet: built now, while this request waits (once)
            $meta = $this->build() ?? $this->waitForBuild();
            if ($meta === null) {
                $this->send(503, '', ['Retry-After' => '60']);
            }
        } elseif ($this->stale($meta)) {
            // after the response when PHP can close it first, or when the answer has a body (its length
            // sent, the visitor has it all); an answer without a body (304, HEAD) would only go out at the
            // end, so then the maps are built first and the answer compares with the new ones
            $server = $input->server;
            $quiet  = $method === 'HEAD' || ($server->getString('HTTP_IF_NONE_MATCH', '') . $server->getString('HTTP_IF_MODIFIED_SINCE', '')) !== '';
            if ($quiet && !\function_exists('fastcgi_finish_request') && !\function_exists('litespeed_finish_request')) {
                $meta = $this->build() ?? $meta;
            } else {
                $this->rebuild = true;
            }
        }
        $file = self::dir() . '/' . $name;
        if (!isset($meta['files'][$name]) || !is_file($file)) {
            $this->send(404, '<?xml version="1.0" encoding="UTF-8"?>' . "\n<error>Not Found</error>\n", ['X-Robots-Tag' => 'noindex']);
        }
        $etag  = '"' . $meta['files'][$name]['etag'] . '"';
        $built = (int) $meta['built'];
        $headers = ['ETag' => $etag, 'Last-Modified' => gmdate('D, d M Y H:i:s', $built) . ' GMT', 'Cache-Control' => 'public, max-age=3600'];
        if (Sitemap::notModified($etag, $built, (string) $input->server->getString('HTTP_IF_NONE_MATCH', ''),
            (string) $input->server->getString('HTTP_IF_MODIFIED_SINCE', ''))) {
            $this->send(304, '', $headers);
        }
        $this->send(200, $method === 'HEAD' ? '' : (string) file_get_contents($file), $headers + ['Content-Length' => (string) filesize($file)]);
    }

    /** A request that may change what is in the maps: they are out of date from now. */
    public function watchChanges(): void
    {
        $app  = $this->plugin->app();
        $user = $app->getIdentity();
        if (!$user || $user->guest || strtoupper((string) $app->getInput()->getMethod()) !== 'POST'
            || !\in_array($app->getInput()->getCmd('option', ''), ['com_gridbox', 'com_menus'], true)) {
            return;
        }
        self::changed();
    }

    /** Marks the maps out of date (a change of the content, the menus or the settings). */
    public static function changed(): void
    {
        $dir = self::dir();
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @touch($dir . '/changed');
        }
    }

    /**
     * Builds all the maps into the cache folder: the addresses (SitemapSource), the filters (Sitemap),
     * the dates, the files. Null when another request is building them now.
     *
     * @return array|null what was built: time, length, addresses, reasons, files
     */
    public function build(): ?array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        $lock = @fopen($dir . '/build.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            @set_time_limit(300);
            $started = microtime(true);
            $params  = $this->plugin->params;
            $app     = $this->plugin->app();
            $zone    = new \DateTimeZone((string) $app->get('offset', 'UTC') ?: 'UTC');
            $source  = new SitemapSource(Factory::getContainer()->get(DatabaseInterface::class));
            $site    = $source->website();
            $domain  = $site['domain'] !== '' ? $site['domain'] : rtrim(Uri::root(), '/');
            $robots  = self::robotsTxt();
            $rules   = Sitemap::rules($params, $robots['text']);
            $items   = $source->collect((new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s'), (string) $app->get('robots', ''));

            $seen     = [];
            $reasons  = [];
            $kept     = [];
            $excluded = [];
            foreach ($items as $item) {
                $item['loc'] = Sitemap::loc($item['route'], $domain, $site['slash']);
                $reason      = Sitemap::reason($item, $rules, $seen);
                if ($reason === null) {
                    $kept[] = $item;
                } else {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    $excluded[]       = [$item['loc'], $reason, $item['type'], $item['title']];
                }
            }
            $kept   = $source->dates($kept, time(), Sitemap::countPrices($params), Sitemap::unknownDate($params), $zone);
            $groups = [];
            foreach ($kept as $item) {
                $groups[$item['group']][] = [$item['loc'], $item['lastmod']];
            }
            $layout = Sitemap::layout($groups, Sitemap::split($params), Sitemap::partLimit($params));

            // the files: written under new names, then renamed, so a request never reads half a file
            $files = [];
            $index = [];
            foreach ($layout['files'] as $name => $entries) {
                $xml          = Sitemap::urlset($entries);
                $newest       = Sitemap::newest(array_column($entries, 1));
                $files[$name] = ['etag' => md5($xml), 'count' => \count($entries), 'newest' => $newest, 'size' => \strlen($xml)];
                $index[]      = [$domain . '/' . $name, $newest];
                self::write($dir . '/' . $name, $xml);
            }
            if ($layout['index']) {
                $xml                  = Sitemap::index($index);
                $files['sitemap.xml'] = ['etag' => md5($xml), 'count' => \count($index), 'newest' => Sitemap::newest(array_column($index, 1)), 'size' => \strlen($xml)];
                self::write($dir . '/sitemap.xml', $xml);
            }
            foreach (glob($dir . '/sitemap*.xml') ?: [] as $old) {
                if (!isset($files[basename($old)])) {
                    @unlink($old);
                }
            }
            $dates = array_column($kept, 'lastmod');
            $meta  = [
                'built'    => time(),
                'seconds'  => round(microtime(true) - $started, 2),
                'urls'     => \count($kept),
                'reasons'  => $reasons,
                'files'    => $files,
                'mass'     => Sitemap::massDates($dates, time()),
                'robots'   => $robots['mtime'],
                'settings' => self::settingsKey($params),
            ];
            // what is left out, for the panel (the reasons, Why is it not in the map, Compare)
            self::write($dir . '/excluded.json', json_encode($excluded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            self::write($dir . '/build.json', json_encode($meta, JSON_UNESCAPED_SLASHES));
            $this->error = '';

            return $meta;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ---------------------------------------------------------------- the panel

    /**
     * The requests of the panel (tab Sitemap): the state, Rebuild now, Take over from Gridbox, Undo,
     * Compare with Gridbox's sitemap, Why is this address not in the map. Answered with JSON.
     */
    public function handleAction(string $action): void
    {
        $this->plugin->loadLanguage();
        try {
            if ($action === 'sm_rebuild') {
                $this->requireEnabled();
                if ($this->build() === null) {
                    throw new \RuntimeException($this->error !== '' ? $this->error : Text::_('PLG_SYSTEM_MERTOOLS_SM_UI_BUSY'));
                }
            }
            if ($action === 'sm_takeover') {
                $this->plugin->sendJson(['success' => true] + $this->takeOver() + $this->status());
            }
            if ($action === 'sm_undo') {
                $this->plugin->sendJson(['success' => true] + $this->undo() + $this->status());
            }
            if ($action === 'sm_compare') {
                $this->plugin->sendJson(['success' => true] + $this->compare());
            }
            if ($action === 'sm_why') {
                $this->plugin->sendJson(['success' => true, 'found' => $this->why((string) $this->plugin->app()->getInput()->post->getString('q', ''))]);
            }
            $this->plugin->sendJson(['success' => true] + $this->status());
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The state for the panel: Gridbox's flag, its static file, who answers /sitemap.xml, the last
     * build (time, length, addresses, files, the reasons), whether the dates look changed in bulk.
     */
    public function status(): array
    {
        $site    = (new SitemapSource(Factory::getContainer()->get(DatabaseInterface::class)))->website();
        $enabled = Sitemap::enabled($this->plugin->params);
        $static  = is_file(JPATH_ROOT . '/sitemap.xml');
        $meta    = $this->meta();
        $robots  = self::robotsTxt();
        $root    = rtrim(Uri::root(), '/');
        $files   = [];
        foreach ($meta['files'] ?? [] as $name => $file) {
            $files[] = ['name' => $name, 'url' => $root . '/' . $name, 'count' => (int) $file['count'], 'newest' => $file['newest'], 'size' => (int) $file['size']];
        }

        return [
            'enabled'  => $enabled,
            'gridbox'  => $site['enabled'],
            'static'   => $static,
            'copy'     => is_file(JPATH_ROOT . self::COPY),
            // the server sends a file that exists before PHP runs
            'answers'  => $static ? 'gridbox' : ($enabled ? 'mertools' : 'none'),
            'line'     => $robots['text'] !== null && (bool) preg_match('#^\s*sitemap:\s*\S+/sitemap\.xml\s*$#im', $robots['text']),
            'built'    => $meta['built'] ?? null,
            'seconds'  => $meta['seconds'] ?? null,
            'urls'     => $meta['urls'] ?? null,
            'mass'     => (bool) ($meta['mass'] ?? false),
            'stale'    => $meta !== null && $this->stale($meta),
            'reasons'  => $meta['reasons'] ?? [],
            'files'    => $files,
            'excluded' => $this->excluded(),
        ];
    }

    /**
     * Take over from Gridbox: its sitemap.xml copied aside, its sitemap switched off (so it is not written
     * again), the file deleted, the maps built and /sitemap.xml asked for. A file that cannot be deleted
     * is reported, nothing is undone.
     */
    private function takeOver(): array
    {
        $this->requireEnabled();
        $file = JPATH_ROOT . '/sitemap.xml';
        $copy = JPATH_ROOT . self::COPY;
        if (is_file($file) && ((!is_dir(\dirname($copy)) && !@mkdir(\dirname($copy), 0755, true)) || !@copy($file, $copy))) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_SM_UI_COPY_FAILED'));
        }
        $this->gridboxFlag(0);
        $removed = !is_file($file) || @unlink($file);
        $meta    = $this->build();

        return ['removed' => $removed, 'check' => $removed && $meta ? $this->selfCheck($meta) : 'unknown'];
    }

    /** Undo: Gridbox's sitemap switched on again and its file put back from the copy. */
    private function undo(): array
    {
        $this->gridboxFlag(1);
        $file     = JPATH_ROOT . '/sitemap.xml';
        $copy     = JPATH_ROOT . self::COPY;
        $restored = is_file($file) || (is_file($copy) && @copy($copy, $file));

        return ['restored' => $restored];
    }

    /**
     * The addresses of Gridbox's sitemap (the copy kept by Take over) that are not in the maps, each with
     * the reason; one without a reason is a fault of the tool.
     */
    private function compare(): array
    {
        $copy = JPATH_ROOT . self::COPY;
        if (!is_file($copy)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_SM_UI_NO_COPY'));
        }
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#s', (string) file_get_contents($copy), $m);
        $old  = array_unique(array_map(static fn (string $l): string => html_entity_decode($l, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]));
        $in   = array_flip($this->locs());
        $why  = [];
        foreach ($this->excluded() as [$loc, $reason]) {
            $why[$loc] ??= $reason;
        }
        $missing = [];
        foreach ($old as $loc) {
            if (!isset($in[$loc])) {
                $missing[] = ['loc' => $loc, 'reason' => $why[$loc] ?? ''];
            }
        }

        return [
            'total'       => \count($old),
            'missing'     => \count($missing),
            'unexplained' => \count(array_filter($missing, static fn (array $r): bool => $r['reason'] === '')),
            'added'       => \count(array_diff_key($in, array_flip($old))),
            'list'        => \array_slice($missing, 0, 2000),
        ];
    }

    /**
     * Why is an address not in the map: the addresses in the maps and the ones left out that contain
     * the text (an address, a path or a part of it; a title for the ones left out).
     */
    private function why(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $q)) {
            $q = (string) parse_url($q, PHP_URL_PATH) . (($query = parse_url($q, PHP_URL_QUERY)) ? '?' . $query : '');
        }
        $q     = $q === '/' ? $q : rtrim($q, '/');
        $found = [];
        foreach ($this->locs(true) as $loc => $file) {
            if (self::contains($loc, $q)) {
                $found[] = ['loc' => $loc, 'map' => $file];
            }
        }
        // a title is searched only for a text that is not a path
        $byTitle = !str_contains($q, '/');
        foreach ($this->excluded() as [$loc, $reason, $type, $title]) {
            if (self::contains($loc, $q) || ($byTitle && mb_stripos((string) $title, $q) !== false)) {
                $found[] = ['loc' => $loc, 'reason' => $reason, 'type' => $type, 'title' => $title];
            }
        }

        return \array_slice($found, 0, 50);
    }

    /** An address contains the text; "/" only the root, a path ending the address matches it whole */
    private static function contains(string $loc, string $q): bool
    {
        $path = Sitemap::path($loc);

        return $q === '/' ? $path === '/' : stripos($loc, $q) !== false;
    }

    /**
     * The addresses in the maps (not the index).
     *
     * @return string[]|array<string, string> the addresses, with $withFile address => file
     */
    private function locs(bool $withFile = false): array
    {
        $locs = [];
        foreach (array_keys($this->meta()['files'] ?? []) as $name) {
            $xml = (string) @file_get_contents(self::dir() . '/' . $name);
            if (str_contains($xml, '<sitemapindex')) {
                continue;
            }
            preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);
            foreach ($m[1] as $loc) {
                $locs[html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8')] = $name;
            }
        }

        return $withFile ? $locs : array_keys($locs);
    }

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> the addresses left out: address, reason, type, title */
    private function excluded(): array
    {
        $list = json_decode((string) @file_get_contents(self::dir() . '/excluded.json'), true);

        return \is_array($list) ? $list : [];
    }

    /** /sitemap.xml asked for over HTTP: answered by MerTools (the ETag of the built index), something else, or unknown */
    private function selfCheck(array $meta): string
    {
        $etag = (string) ($meta['files']['sitemap.xml']['etag'] ?? '');
        try {
            $response = HttpFactory::getHttp()->get(rtrim(Uri::root(), '/') . '/sitemap.xml?mertools=' . time(), [], 10);
            $header   = $response->getHeaderLine('ETag');
        } catch (\Throwable $e) {
            return 'unknown';
        }

        return $etag !== '' && str_contains($header, $etag) ? 'mertools' : 'other';
    }

    /** Gridbox's own sitemap (#__gridbox_website.enable_sitemap) switched on or off */
    private function gridboxFlag(int $on): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery($db->getQuery(true)
            ->update($db->quoteName('#__gridbox_website'))
            ->set($db->quoteName('enable_sitemap') . ' = ' . $on))->execute();
    }

    private function requireEnabled(): void
    {
        if (!Sitemap::enabled($this->plugin->params)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_MERTOOLS_SM_UI_OFF_ERROR'));
        }
    }

    /** What the last build left: time, files with their ETag…; null when nothing was built yet */
    public function meta(): ?array
    {
        $file = self::dir() . '/build.json';
        $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($meta) && isset($meta['built'], $meta['files']) ? $meta : null;
    }

    /** Out of date: older than the refresh time or the last change, or robots.txt or the settings changed */
    private function stale(array $meta): bool
    {
        $built   = (int) $meta['built'];
        $changed = @filemtime(self::dir() . '/changed');

        return $built + 60 * Sitemap::ttl($this->plugin->params) < time()
            || ($changed !== false && $changed >= $built)
            || ($meta['robots'] ?? null) !== self::robotsTxt()['mtime']
            || ($meta['settings'] ?? '') !== self::settingsKey($this->plugin->params);
    }

    /** Another request is building the first maps: their result, waited for up to 20 s */
    private function waitForBuild(): ?array
    {
        for ($i = 0; $i < 40; $i++) {
            usleep(500000);
            clearstatcache();
            if ($meta = $this->meta()) {
                return $meta;
            }
        }

        return null;
    }

    private function send(int $status, string $body, array $headers): void
    {
        $app = $this->plugin->app();
        if ($status !== 200) {
            $app->setHeader('Status', (string) $status, true);
        }
        $app->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        foreach ($headers as $name => $value) {
            $app->setHeader($name, $value, true);
        }
        $app->allowCache(true);
        $app->setBody($body);
        echo $app->toString(false);
        // out of date: built now that the answer is out (close() ends the request, onAfterRespond never comes)
        if ($this->rebuild) {
            $this->rebuild = false;
            ignore_user_abort(true);
            if (!$this->plugin->finishResponse()) {
                while (ob_get_level() > 0 && @ob_end_flush()) {
                }
                flush();
            }
            $this->build();
        }
        $app->close();
    }

    public static function dir(): string
    {
        return MerTools::cacheBase() . '/' . self::DIR;
    }

    /** @return array{text: ?string, mtime: ?int} the site's robots.txt (null: none) */
    private static function robotsTxt(): array
    {
        $file = JPATH_ROOT . '/robots.txt';
        clearstatcache(true, $file);

        return is_file($file) ? ['text' => (string) file_get_contents($file), 'mtime' => (int) filemtime($file)] : ['text' => null, 'mtime' => null];
    }

    /** The settings that change the maps, as one key: their change makes the maps out of date */
    private static function settingsKey($params): string
    {
        $keys = [];
        foreach ($params->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'sitemap_')) {
                $keys[$key] = $value;
            }
        }
        ksort($keys);

        return md5(json_encode($keys));
    }

    private static function write(string $file, string $data): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $data) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . basename($file));
        }
    }
}
