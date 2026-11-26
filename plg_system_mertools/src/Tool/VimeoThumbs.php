<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The picture of a Vimeo film, for Speedup::videoFacade: Vimeo has no fixed address of it (YouTube's
 * i.ytimg.com/vi/ID/…), so it is asked once from Vimeo's oEmbed (vimeo.com/api/oembed.json) and kept in a small
 * file next to Joomla's cache — a film known for 30 days, one Vimeo refused (private, only on other sites) or
 * did not answer for a day. A page asks for at most a few films at a time, each for at most a few seconds; the
 * others are left as frames until a later view.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class VimeoThumbs
{
    public const KNOWN = 30 * 86400;

    public const FAILED = 86400;

    private const MAX_ENTRIES = 2000;

    /** @var array<string, array{0: string, 1: int}> "id" or "id/h" => [picture or '', when asked] */
    private array $cache = [];

    private bool $dirty = false;

    private int $asked = 0;

    /** @var array<string, array{0: string, 1: int}> what this request asked (merged into the file on save) */
    private array $new = [];

    /**
     * @param callable(string): ?string $fetch the body of a 200 answer of an address, null otherwise
     */
    public function __construct(private string $cacheFile, private $fetch, private int $now, private int $maxAsks = 3)
    {
        $data        = is_file($cacheFile) ? @json_decode((string) @file_get_contents($cacheFile), true) : null;
        $this->cache = \is_array($data) ? $data : [];
    }

    /** the picture of film $id ($hash: the h= of an unlisted one), or null: not known (yet) */
    public function get(string $id, string $hash = ''): ?string
    {
        if (!preg_match('#^\d{1,12}$#', $id) || !preg_match('#^[0-9a-f]{0,20}$#', $hash)) {
            return null;
        }
        $key = $hash === '' ? $id : $id . '/' . $hash;
        $hit = $this->cache[$key] ?? null;
        if (\is_array($hit) && \count($hit) === 2 && $this->now - (int) $hit[1] < ($hit[0] === '' ? self::FAILED : self::KNOWN)) {
            return $hit[0] === '' ? null : (string) $hit[0];
        }
        if ($this->asked >= $this->maxAsks) {
            return null;
        }
        $this->asked++;
        $body = ($this->fetch)('https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode('https://vimeo.com/' . $key));
        $pic  = self::picture($body);
        if (\count($this->cache) >= self::MAX_ENTRIES) {
            $this->cache = \array_slice($this->cache, (int) (self::MAX_ENTRIES / 2), null, true);
        }
        unset($this->cache[$key]);
        $this->cache[$key] = $this->new[$key] = [$pic ?? '', $this->now];
        $this->dirty       = true;

        return $pic;
    }

    /** the picture in an oEmbed answer, only one of Vimeo's own server */
    public static function picture(?string $body): ?string
    {
        $data = $body === null ? null : json_decode($body, true);
        $url  = \is_array($data) && \is_string($data['thumbnail_url'] ?? null) ? trim($data['thumbnail_url']) : '';

        return Speedup::vimeoPicture($url) ? $url : null;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->dirty = false;
        // read again under the lock: another request may have kept other films since this one read the file
        $fp = @fopen($this->cacheFile, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $data = json_decode((string) stream_get_contents($fp), true);
            $all  = (\is_array($data) ? $data : []);
            foreach ($this->new as $key => $entry) {
                unset($all[$key]);
                $all[$key] = $entry;
            }
            if (\count($all) > self::MAX_ENTRIES) {
                $all = \array_slice($all, -(int) (self::MAX_ENTRIES / 2), null, true);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($all, JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        $this->new = [];
    }
}
