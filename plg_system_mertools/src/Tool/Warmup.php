<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page cache warm-up: the pages of the sitemap that have no kept copy (never kept, expired or emptied by
 * a change) are asked for in the background, a few at a time, so that the first visitor does not pay for
 * building them. This class holds the rules and the state file; the requests are made by Feature\PageSpeed.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Warmup
{
    /** The name the warm-up requests introduce themselves with (and are recognised by) */
    public const AGENT = 'MerTools-Warmup';

    /** Seconds between two rounds */
    public const INTERVAL = 60;

    /** The list of addresses is read from the sitemap again after this many seconds */
    public const LIST_TTL = 21600;

    /** The most addresses taken from one sitemap file and from all of them */
    public const MAX_URLS = 5000;

    private array $state = ['urls' => [], 'built' => 0, 'skip' => [], 'route' => '', 'last' => []];

    public function __construct(private string $file)
    {
        $raw = @file_get_contents($file);
        $any = $raw === false ? null : json_decode($raw, true);
        if (\is_array($any)) {
            $this->state = array_replace($this->state, array_intersect_key($any, $this->state));
        }
    }

    /**
     * The addresses of a sitemap file or of a sitemap index: the first $max (a huge map is not read
     * further than the warm-up could use).
     *
     * @return array{index: bool, locs: string[]}
     */
    public static function locs(string $xml, int $max = self::MAX_URLS): array
    {
        $index = (bool) preg_match('#<sitemapindex[\s>]#i', $xml);
        $locs  = [];
        $at    = 0;
        while (\count($locs) < $max && preg_match('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $xml, $m, PREG_OFFSET_CAPTURE, $at)) {
            $at  = $m[0][1] + \strlen($m[0][0]);
            $loc = html_entity_decode(trim($m[1][0]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match('#^https?://[^\s]+$#i', $loc)) {
                $locs[] = $loc;
            }
        }

        return ['index' => $index, 'locs' => $locs];
    }

    /**
     * The address of the site the warm-up works on, never taken from a request (its Host header is the
     * visitor's): Joomla's live_site, else the domain of Gridbox's sitemap, else the address the site had
     * when an administrator last saved the MerTools settings. The origin and the folder of the site, without
     * the last slash ("https://www.x.pl", "https://x.pl/joomla"), or null when none of them is a usable
     * http(s) address.
     */
    public static function siteBase(string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
                $candidate = 'https://' . ltrim($candidate, '/');
            }
            $parts = parse_url($candidate);
            if (!\is_array($parts) || !\in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
                continue;
            }

            return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . rtrim((string) ($parts['path'] ?? ''), '/');
        }

        return null;
    }

    /** Is the address one of the site's: http(s), the same scheme, host and port as the base (siteBase()), in its folder */
    public static function sameSite(string $url, string $base): bool
    {
        $u = parse_url($url);
        $b = parse_url($base);
        if (!\is_array($u) || !\is_array($b) || empty($u['host']) || empty($b['host'])) {
            return false;
        }
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        $port   = fn (array $p, string $s): int => (int) ($p['port'] ?? ($s === 'https' ? 443 : 80));

        if (!\in_array($scheme, ['http', 'https'], true) || strcasecmp($u['host'], $b['host']) !== 0
            || $scheme !== strtolower((string) $b['scheme']) || $port($u, $scheme) !== $port($b, $scheme)) {
            return false;
        }
        $folder = rtrim((string) ($b['path'] ?? ''), '/');
        $path   = (string) ($u['path'] ?? '');

        return $folder === '' || $path === $folder || str_starts_with($path, $folder . '/');
    }

    /**
     * The addresses to keep warm: the ones of this site only (sameSite()), no query, no fragment, no
     * repeats, the shallow pages first (the home page, the main pages), the order of the sitemap inside
     * a level. Addresses containing one of $excluded (the page cache's own exclusions) are left out: they
     * are never kept, so they would be asked for again and again.
     *
     * @param string[] $urls
     * @param string[] $excluded
     * @return string[]
     */
    public static function order(array $urls, string $base, array $excluded = []): array
    {
        $seen = [];
        $rank = [];
        foreach ($urls as $i => $url) {
            $url   = (string) preg_replace('/#.*$/s', '', $url);
            $parts = parse_url($url);
            if (!\is_array($parts) || !self::sameSite($url, $base) || isset($parts['query'])) {
                continue;
            }
            // the home page without its slash is kept under the address with it (the path of a request is never empty)
            if (($parts['path'] ?? '') === '') {
                $url .= '/';
            }
            foreach ($excluded as $part) {
                if ($part !== '' && stripos((string) parse_url($url, PHP_URL_PATH), $part) !== false) {
                    continue 2;
                }
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $path       = trim((string) ($parts['path'] ?? ''), '/');
            $rank[$url] = [$path === '' ? -1 : substr_count($path, '/'), $i];
        }
        uasort($rank, static fn (array $a, array $b) => $a <=> $b);

        return array_slice(array_keys($rank), 0, self::MAX_URLS);
    }

    /**
     * The next addresses to ask for: those without a fresh kept copy that were not tried lately.
     *
     * @param string[]                 $urls
     * @param callable(string): bool   $fresh  has the address a kept copy
     * @param array<string,int>        $skip   address => time until which it is left alone
     * @return string[]
     */
    public static function next(array $urls, callable $fresh, array $skip, int $now, int $count): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (($skip[$url] ?? 0) > $now || $fresh($url)) {
                continue;
            }
            $out[] = $url;
            if (\count($out) >= $count) {
                break;
            }
        }

        return $out;
    }

    /** @return string[] */
    public function urls(): array
    {
        return array_values(array_filter((array) $this->state['urls'], 'is_string'));
    }

    public function listDue(int $now): bool
    {
        return (int) $this->state['built'] < $now - self::LIST_TTL;
    }

    public function setUrls(array $urls, int $now): void
    {
        $this->state['urls']  = array_values($urls);
        $this->state['built'] = $now;
    }

    /**
     * The addresses left alone now: asked for and not yet expired, and asked for after the last emptying
     * of the cache (an emptying makes every kept copy gone, so the pages are due again).
     *
     * @return array<string,int> address => time until which it is left alone
     */
    public function skips(int $now, float $purgedAt = 0.0): array
    {
        $out = [];
        foreach ((array) $this->state['skip'] as $url => $entry) {
            if (\is_array($entry) && \count($entry) === 2 && (int) $entry[1] > $now && (float) $entry[0] > $purgedAt) {
                $out[(string) $url] = (int) $entry[1];
            }
        }

        return $out;
    }

    /**
     * An address was asked for: it is left alone for $seconds, whatever came of it (a page that cannot
     * be kept must not be asked for again and again), unless the cache is emptied meanwhile.
     */
    public function tried(string $url, int $now, int $seconds, float $purgedAt = 0.0): void
    {
        $keep = [];
        foreach ($this->skips($now, $purgedAt) as $address => $until) {
            $keep[$address] = $this->state['skip'][$address];
        }
        $keep[$url]          = [microtime(true), $now + $seconds];
        $this->state['skip'] = $keep;
    }

    /** The CURLOPT_RESOLVE line "host:port:address" (an IPv6 address in brackets), or null for a bad host */
    public static function resolveLine(string $host, int $port, string $addr): ?string
    {
        if ($host === '' || !preg_match('#^[a-z0-9.-]+$#i', $host) || $port < 1 || $port > 65535 || filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return strtolower($host) . ':' . $port . ':' . (str_contains($addr, ':') ? '[' . $addr . ']' : $addr);
    }

    /**
     * Did the request reach the site as it is (so the other way would give the same)? 2xx, 3xx and 404,
     * 410: yes. No answer, a refusal (401, 403, 429), a server error: no, the other way may do better.
     */
    public static function reached(int $code): bool
    {
        return ($code >= 200 && $code < 400) || $code === 404 || $code === 410;
    }

    /** How the pages were reached the last time it worked: 'direct' (this server) or 'public' ('' = not yet) */
    public function route(): string
    {
        return \in_array($this->state['route'], ['direct', 'public'], true) ? $this->state['route'] : '';
    }

    public function setRoute(string $route): void
    {
        $this->state['route'] = $route;
    }

    /**
     * What came of the last reading of the list ('list') and the last page asked for ('page'): the time,
     * the address and 'ok' or what went wrong, for the panel (an empty list must not stay a mystery).
     */
    public function note(string $what, int $now, string $url, string $result): void
    {
        $this->state['last'] = (array) $this->state['last'];
        $this->state['last'][$what] = ['time' => $now, 'url' => $url, 'result' => $result];
    }

    /** @return array{route: string, urls: int, built: int, list: ?array, page: ?array} */
    public function status(): array
    {
        $last = (array) $this->state['last'];

        return ['route' => $this->route(), 'urls' => \count($this->urls()), 'built' => (int) $this->state['built'],
            'list' => \is_array($last['list'] ?? null) ? $last['list'] : null, 'page' => \is_array($last['page'] ?? null) ? $last['page'] : null];
    }

    public function save(): bool
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return false;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
