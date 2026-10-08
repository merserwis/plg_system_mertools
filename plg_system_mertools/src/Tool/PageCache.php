<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Page cache for guests. Gridbox builds every page anew on every visit (2–4 s on merserwis.pl),
 * although every guest gets the same page. The finished page of one guest is kept in a file and
 * the next guests get it at once; the form token and the CSP nonce are put back for each visitor.
 *
 * Never kept or served: logged-in users, anything but GET/HEAD, addresses with parameters (search,
 * filters; campaign tags like utm_* do not count), pages with a message, the Markdown version of a
 * page, visitors whose page differs (a cart, a wishlist, another currency, a pending order, a comment
 * author), pages with checkout, login, wishlist or submission forms, answers other than 200 and
 * answers that set a cookie MerTools does not know. Product pages with "Recently viewed products"
 * go only to visitors who have not viewed another product (the list would not be theirs), and
 * Gridbox's cookie is set as Gridbox sets it.
 *
 * Also the two requests Gridbox's script makes on every page before the page can be shown: the
 * page items (task=editor.getItems) and its texts (task=editor.loadModule&module=gridboxLanguage).
 * Their addresses carry the time of the last change of the page, so they are kept by address.
 *
 * Emptied whenever a logged-in user saves or deletes something (Joomla or the Gridbox editor), when
 * an order, a payment, a comment or a review comes in, with the button in the settings; entries
 * also expire after the set time. A file "purged" keeps the time of the last emptying: an entry
 * older than that is never served, and a page that started before it is not stored.
 *
 * Files: <cache>/mertools_page/<2 chars>/<sha1>.bin — one line of JSON (time, type, headers…),
 * then the page compressed with deflate.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class PageCache
{
    public const DIR = 'mertools_page';

    /** campaign and click tags: the page is the same with or without them */
    public const IGNORED_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid',
        'dclid', 'fbclid', 'msclkid', 'yclid', 'srsltid', '_ga', '_gl', 'mc_cid', 'mc_eid'];

    /** Gridbox cookies that change what the page shows */
    public const SKIP_COOKIES = ['gridbox_store_cart', 'gridbox_store_wishlist', 'gridbox_store_order', 'gridbox-currency', 'gridbox-comments-user'];

    /** Gridbox elements of a page that belongs to one visitor */
    public const PRIVATE_ITEMS = ['checkout-form', 'checkout-order-form', 'login', 'submission-form', 'wishlist'];

    /** response headers of a page that are sent again with the kept copy */
    public const REPLAY = ['link', 'vary', 'content-language', 'x-robots-tag'];

    private const TOKEN = '__MERTOOLS_TOKEN__';
    private const NONCE = '__MERTOOLS_NONCE__';
    private const MAX_BYTES = 8388608;

    private ?float $purged = null;

    public function __construct(private string $dir)
    {
    }

    // ---------------------------------------------------------------- pure helpers (unit tested)

    /** The query string without campaign tags; '' when nothing else is left. */
    public static function cleanQuery(string $query): string
    {
        $keep = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (!\in_array($name, self::IGNORED_PARAMS, true) && !str_starts_with($name, 'utm_')) {
                $keep[] = $pair;
            }
        }

        return implode('&', $keep);
    }

    /** Does the visitor have one of these cookies (also as an array, e.g. name[0])? */
    public static function hasCookie(array $cookies, array $names): bool
    {
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            // PHP turns dots and spaces of cookie names into underscores
            $php = strtr($name, ['.' => '_', ' ' => '_']);
            foreach ([$name, $php] as $n) {
                if (isset($cookies[$n]) && $cookies[$n] !== '' && $cookies[$n] !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Has the visitor viewed products other than this one (Gridbox's "recently viewed" cookie)? */
    public static function viewedOthers($viewed, int $id): bool
    {
        foreach ((array) $viewed as $value) {
            if ((int) $value !== $id && (int) $value > 0) {
                return true;
            }
        }

        return false;
    }

    /** May this page be kept: a whole HTML page without the elements that belong to one visitor. */
    public static function storable(string $html): bool
    {
        if ($html === '' || \strlen($html) > self::MAX_BYTES || stripos($html, '</html>') === false || str_starts_with($html, "\x1f\x8b")) {
            return false;
        }
        foreach (self::PRIVATE_ITEMS as $item) {
            if (str_contains($html, 'ba-item-' . $item . ' ba-item')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The cookies the page set (Set-Cookie headers): null when one is unknown (the page may depend
     * on the visitor), else the product of Gridbox's "recently viewed" cookie (0 when none). A page
     * that set the cookie for more than one product showed someone's own list.
     *
     * @param string[] $headers  headers_list()
     */
    public static function cookieCheck(array $headers, string $session): ?int
    {
        $viewed = 0;
        foreach ($headers as $header) {
            if (!preg_match('/^set-cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $m)) {
                continue;
            }
            $name = urldecode($m[1]);
            if ($name === $session) {
                continue;
            }
            if ($name === 'gridbox_viewed_products[0]' && ctype_digit($m[2])) {
                $viewed = (int) $m[2];
                continue;
            }

            return null;
        }

        return $viewed;
    }

    /** The headers of a page to send again with its copy: [[name, value], …] */
    public static function replayHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2) + [1 => '']);
            if (\in_array(strtolower($name), self::REPLAY, true) && $value !== '' && \count($out) < 10) {
                $out[] = [$name, mb_substr($value, 0, 2000)];
            }
        }

        return $out;
    }

    /** The Content-Type the page was sent with (text/html when not known). */
    public static function contentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (preg_match('/^content-type:\s*(text\/html[^\r\n]*)$/i', $header, $m)) {
                return trim($m[1]);
            }
        }

        return 'text/html; charset=utf-8';
    }

    /** The visitor's form token and nonce taken out of the page that is kept… */
    public static function forStore(string $html, string $token, ?string $nonce): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token)) {
            $html = str_replace($token, self::TOKEN, $html);
        }
        if ($nonce !== null && $nonce !== '' && \strlen($nonce) >= 16) {
            $html = str_replace('nonce="' . $nonce . '"', 'nonce="' . self::NONCE . '"', $html);
        }

        return $html;
    }

    /** …and the next visitor's put in. */
    public static function forServe(string $html, string $token, ?string $nonce): string
    {
        $html = str_replace(self::TOKEN, $token, $html);

        return str_replace(self::NONCE, htmlspecialchars((string) $nonce, ENT_QUOTES), $html);
    }

    /**
     * Does this request change what pages show, so the kept pages must go? A logged-in user saving,
     * deleting, publishing… anything (Joomla or the Gridbox editor), and from anybody: an order or a
     * payment in the Gridbox shop (stock), a comment or a review.
     */
    public static function changes(string $method, bool $guest, string $option, string $task): bool
    {
        $method = strtoupper($method);
        $post   = !\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($post && !$guest && preg_match('/save|apply|delete|remove|trash|publish|archive|featured|order|import|copy|duplicate|move|rename|create|update|batch|rebuild|clean|purge|install|enable|disable|restore|upload|checkin|reset/i', $task)) {
            return true;
        }
        if ($option !== 'com_gridbox') {
            return false;
        }
        if (preg_match('/^store\.(createOrder|setOrder|updateOrder|payAuthorize|stripeCharges|\w+Callback|submit\w*)$/i', $task)) {
            return true;
        }

        return $post && (bool) preg_match('/^(comments|reviews)\./i', $task);
    }

    /** Which Gridbox data request this is ('items', 'language') or null. */
    public static function dataRequest(string $option, string $task, string $module): ?string
    {
        if ($option !== 'com_gridbox') {
            return null;
        }
        if ($task === 'editor.getItems') {
            return 'items';
        }

        return $task === 'editor.loadModule' && $module === 'gridboxLanguage' ? 'language' : null;
    }

    /** Is this a complete answer of that Gridbox request (nothing else is kept)? */
    public static function validData(string $kind, string $body): bool
    {
        if ($body === '' || \strlen($body) > self::MAX_BYTES) {
            return false;
        }

        return str_starts_with(ltrim($body), $kind === 'items' ? 'var gridboxItems' : 'var gridboxLanguage');
    }

    // ---------------------------------------------------------------- files

    public function key(string $kind, string $address): string
    {
        return sha1($kind . '|' . $address);
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . substr($key, 0, 2) . '/' . $key . '.bin';
    }

    /** The time of the last emptying (0 when never). */
    public function purgedAt(): float
    {
        if ($this->purged === null) {
            $raw          = @file_get_contents($this->dir . '/purged');
            $this->purged = $raw === false ? 0.0 : (float) $raw;
        }

        return $this->purged;
    }

    /**
     * A kept entry younger than $ttl seconds and than the last emptying: ['meta' => …, 'body' => …].
     */
    public function read(string $key, int $ttl, float $now): ?array
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        $nl   = strpos($raw, "\n");
        $meta = $nl === false ? null : json_decode(substr($raw, 0, $nl), true);
        if (!\is_array($meta) || !isset($meta['time']) || (float) $meta['time'] < $now - $ttl || (float) $meta['time'] <= $this->purgedAt()) {
            return null;
        }
        $body = @gzinflate(substr($raw, $nl + 1));

        return $body === false ? null : ['meta' => $meta, 'body' => $body];
    }

    /** Keep an entry; not when the cache was emptied after the request began ($started). */
    public function write(string $key, array $meta, string $body, float $started): bool
    {
        if ($this->purgedAt() >= $started || !$this->ensureDir(\dirname($this->path($key)))) {
            return false;
        }
        $data = gzdeflate($body, 6);
        if ($data === false) {
            return false;
        }
        $meta['time'] = $meta['time'] ?? microtime(true);
        $file         = $this->path($key);
        $tmp          = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $data) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** Empty the cache: the time first (no older entry is served from now on), then the files. */
    public function purge(): void
    {
        if (!$this->ensureDir($this->dir)) {
            return;
        }
        $now = microtime(true);
        @file_put_contents($this->dir . '/purged', sprintf('%.6F', $now), LOCK_EX);
        $this->purged = $now;
        foreach ($this->files() as $file) {
            @unlink($file);
        }
    }

    /** Remove entries older than $ttl, and the oldest beyond $max. */
    public function prune(int $ttl, int $max, float $now): int
    {
        $left    = [];
        $removed = 0;
        foreach ($this->files() as $file) {
            $time = (int) @filemtime($file);
            if ($time < $now - $ttl || (str_ends_with($file, '.tmp') && $time < $now - 600)) {
                $removed += @unlink($file) ? 1 : 0;
            } elseif (str_ends_with($file, '.bin')) {
                $left[$file] = $time;
            }
        }
        if (\count($left) > $max) {
            asort($left);
            foreach (\array_slice(array_keys($left), 0, \count($left) - $max) as $file) {
                $removed += @unlink($file) ? 1 : 0;
            }
        }

        return $removed;
    }

    /** Pages and Gridbox requests kept now, their size and the time of the last emptying. */
    public function stats(int $ttl, float $now): array
    {
        $out = ['pages' => 0, 'data' => 0, 'size' => 0, 'oldest' => 0, 'purged' => (int) $this->purgedAt()];
        foreach ($this->files() as $file) {
            if (!str_ends_with($file, '.bin')) {
                continue;
            }
            $handle = @fopen($file, 'rb');
            $meta   = $handle ? json_decode((string) fgets($handle, 65536), true) : null;
            if ($handle) {
                fclose($handle);
            }
            $time = \is_array($meta) ? (float) ($meta['time'] ?? 0) : 0.0;
            if ($time < $now - $ttl || $time <= $this->purgedAt()) {
                continue;
            }
            $out[($meta['kind'] ?? '') === 'data' ? 'data' : 'pages']++;
            $out['size'] += (int) @filesize($file);
            $out['oldest'] = (int) ($out['oldest'] ? min($out['oldest'], $time) : $time);
        }

        return $out;
    }

    /** @return string[] */
    private function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        return glob($this->dir . '/[0-9a-f][0-9a-f]/*') ?: [];
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_file($this->dir . '/index.html')) {
            @file_put_contents($this->dir . '/index.html', '<!DOCTYPE html><title></title>');
        }

        return true;
    }
}
