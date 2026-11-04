<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The compressed originals of the site's pictures (ImageCompressor makes the lighter file): which
 * pictures to compress, the replacing of a picture by its lighter version, the copies of the originals
 * and their return.
 *
 *  - Before a picture is replaced, its original is copied to media/mertools-originals/<key>/<its path>,
 *    <key> a random name made once (the web server of the site may serve the folder: nginx does not read
 *    .htaccess, and the copies keep the EXIF data the compressed picture lost); the copy can bring it
 *    back (restore()) until it is removed, the given number of days later (expire()). It is brought
 *    back only over the very file the compression made (its MD5 noted), never over a picture uploaded
 *    again since. A picture brought back is not compressed again by itself (only after it changes).
 *  - The notes are written before anything counts as done: when they cannot be written, a picture just
 *    replaced gets its original back at once.
 *  - The replacing is done under a lock and only when the picture is still the one that was compressed
 *    (same size and time, no mark): a picture uploaded again in the meantime is left as it is.
 *  - What a picture gave when it was not worth it (too little saving, animated…) is noted with its
 *    size, time and the settings, so the next run does not try it again with the same settings.
 *  - "Compress all": the list of the pictures of the folder is made once (jobStart()), then worked
 *    through a few seconds at a time (jobRun()), so a stop or a closed window can go on later.
 *  - Uploads: the pictures that came in during an upload are noted (noteNew()) and compressed at once
 *    or a little later (work()).
 *
 * The list of copies and the notes are in state.php of that folder (JSON after a line that stops PHP,
 * so the web server never shows it), the list of a run in job.php.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class CompressStore
{
    /** the folder of the copies of the originals and of the notes, under the site root */
    public const DIR = 'media/mertools-originals';

    /** the line before the JSON of the notes */
    private const GUARD = "<?php exit; ?>\n";

    /** uploaded pictures waiting at most */
    private const MAX_PENDING = 2000;

    /** the reasons of a skip worth noting (finding them again costs reading the whole picture) */
    private const NOTED = ['gain', 'animated', 'memory', 'read'];

    private string $root;

    private string $dir;

    /** @var string[] relative paths (from the site root) of folders left out, without slashes at the ends */
    private array $exclude = [];

    /**
     * @param string   $root    site root (JPATH_ROOT)
     * @param string   $folder  the pictures' folder under the root ("images")
     * @param string[] $exclude folders left out: paths from the site root or from $folder
     */
    public function __construct(string $root, private string $folder = 'images', array $exclude = [], private ?int $now = null)
    {
        $this->root   = rtrim(str_replace('\\', '/', $root), '/');
        $this->folder = trim(str_replace('\\', '/', $folder), '/') ?: 'images';
        $this->dir    = $this->root . '/' . self::DIR;
        foreach ($exclude as $line) {
            $line = trim(str_replace('\\', '/', (string) $line), "/ \t");
            if ($line === '' || str_contains($line, '..')) {
                continue;
            }
            $this->exclude[] = $line;
            if (!str_starts_with($line . '/', $this->folder . '/')) {
                $this->exclude[] = $this->folder . '/' . $line;
            }
        }
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    // ---------------------------------------------------------------- which pictures

    /** Is the relative path in a folder left out? */
    public function excluded(string $rel): bool
    {
        foreach ($this->exclude as $ex) {
            if ($rel === $ex || str_starts_with($rel, $ex . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The JPEG and PNG pictures of the folder (relative to the site root), sorted; with $since only those
     * changed since then. Hidden files and folders (a dot), folders left out and names that are not UTF-8
     * are not listed.
     *
     * @return string[]
     */
    public function candidates(?int $since = null): array
    {
        $base = $this->root . '/' . $this->folder;
        if (!is_dir($base)) {
            return [];
        }
        $root = $this->root;
        $out  = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            function (\SplFileInfo $f) use ($root): bool {
                $name = $f->getFilename();
                if ($name === '' || $name[0] === '.') {
                    return false;
                }
                $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), \strlen($root)), '/');
                if ($this->excluded($rel)) {
                    return false;
                }

                return $f->isDir() ? !$f->isLink() : (bool) preg_match('#\.(jpe?g|png)$#i', $name);
            }
        ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($iter as $f) {
            if (!$f->isFile() || ($since !== null && $f->getMTime() < $since)) {
                continue;
            }
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), \strlen($root)), '/');
            if (preg_match('//u', $rel)) {
                $out[] = $rel;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    // ---------------------------------------------------------------- one picture

    /**
     * Compress one picture in place, its original kept as a copy.
     *
     * @return array{rel:string, status:string, reason:string, before:int, after:int, method:string, lossy:bool}
     *   reason also tried (not worth it with these settings before), changed (changed while compressed),
     *   backup (the copy of the original could not be made)
     */
    public function process(ImageCompressor $c, string $rel, ?array &$state = null): array
    {
        $entry = ['rel' => $rel, 'status' => 'skip', 'reason' => '', 'before' => 0, 'after' => 0, 'method' => '', 'lossy' => false];
        $path  = $this->root . '/' . $rel;
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!$stat || !is_file($path) || !self::safe($rel)) {
            return ['status' => 'fail', 'reason' => 'read'] + $entry;
        }
        $entry['before'] = $entry['after'] = (int) $stat['size'];
        $sig   = $stat['size'] . ':' . $stat['mtime'] . ':' . $c->signature();
        $state ??= $this->state();
        $noted = (string) ($state['skipped'][$rel] ?? '');
        if ($noted !== '' && ($noted === $sig || $noted === $stat['size'] . ':' . $stat['mtime'] . ':restored')) {
            return ['reason' => str_ends_with($noted, ':restored') ? 'restored' : 'tried'] + $entry;
        }
        $tmp = \dirname($path) . '/.mt-' . getmypid() . '-' . basename($path);
        $res = $c->compress($path, $tmp);
        $entry = ['status' => $res['status'], 'reason' => $res['reason'], 'after' => $res['after'], 'method' => $res['method'], 'lossy' => $res['lossy']] + $entry;
        if ($res['status'] !== 'ok') {
            @unlink($tmp);
            if (\in_array($res['reason'], self::NOTED, true)) {
                $this->update(function (array &$s) use ($rel, $sig) {
                    $s['skipped'][$rel] = $sig;
                }, $state);
            }

            return $entry;
        }
        // a failure unless the replacing below is done (the notes cannot be locked: nothing is replaced)
        $done     = $entry;
        $out      = ['status' => 'fail', 'reason' => 'backup', 'after' => $entry['before']] + $entry;
        $replaced = '';
        $written  = $this->update(function (array &$s) use ($rel, $path, $tmp, $stat, $done, &$out, &$replaced) {
            $out = $done;
            clearstatcache(true, $path);
            $now = @stat($path);
            if (!$now || $now['size'] !== $stat['size'] || $now['mtime'] !== $stat['mtime'] || ImageCompressor::marked($path)) {
                $out = ['status' => 'skip', 'reason' => 'changed', 'after' => $out['before']] + $out;

                return;
            }
            $copy = $this->copyPath($rel, true);
            if ($copy === '' || !$this->prepareDir(\dirname($copy)) || !@copy($path, $copy . '.tmp') || !@rename($copy . '.tmp', $copy)) {
                @unlink($copy . '.tmp');
                $out = ['status' => 'fail', 'reason' => 'backup', 'after' => $out['before']] + $out;

                return;
            }
            $mode = fileperms($path) & 0777;
            if (!@rename($tmp, $path)) {
                @unlink($copy);
                $out = ['status' => 'fail', 'reason' => 'write', 'after' => $out['before']] + $out;

                return;
            }
            @chmod($path, $mode ?: 0644);
            $replaced = $copy;
            // h: the file the compression made — the original is brought back only over it
            $s['backups'][$rel] = ['t' => $this->now(), 'b' => $out['before'], 'a' => $out['after'], 'h' => (string) @md5_file($path)];
            $s['total']['n']    = (int) ($s['total']['n'] ?? 0) + 1;
            $s['total']['b']    = (int) ($s['total']['b'] ?? 0) + $out['before'];
            $s['total']['a']    = (int) ($s['total']['a'] ?? 0) + $out['after'];
            unset($s['skipped'][$rel]);
        }, $state);
        @unlink($tmp);
        if (!$written && $replaced !== '') {
            // the notes could not be written (a full disk): no copy the panel could find — the original back now
            $back = \dirname($path) . '/.mt-' . getmypid() . '-back-' . basename($path);
            if (@copy($replaced, $back) && @rename($back, $path)) {
                @unlink($replaced);
            }
            @unlink($back);
            $out = ['status' => 'fail', 'reason' => 'state', 'after' => $out['before']] + $out;
        }

        return $out;
    }

    /**
     * The original of a picture back from its copy. ok, gone (no copy), changed (the picture was replaced
     * after it was compressed: left as it is, the copy stays), fail.
     */
    public function restore(string $rel, ?array &$state = null): string
    {
        if (!self::safe($rel)) {
            return 'gone';
        }
        $result = 'fail';
        $used   = '';
        $written = $this->update(function (array &$s) use ($rel, &$result, &$used) {
            $copy = $this->copyPath($rel);
            $path = $this->root . '/' . $rel;
            if (!isset($s['backups'][$rel]) || $copy === '' || !is_file($copy)) {
                unset($s['backups'][$rel]);
                $result = 'gone';

                return;
            }
            // only over the file the compression made: another picture uploaded under the name since (even one
            // compressed by MerTools elsewhere, with the mark) is left as it is
            $hash = (string) ($s['backups'][$rel]['h'] ?? '');
            if (is_file($path) && (!ImageCompressor::marked($path) || ($hash !== '' && @md5_file($path) !== $hash))) {
                $result = 'changed';

                return;
            }
            $tmp  = \dirname($path) . '/.mt-' . getmypid() . '-' . basename($path);
            $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
            if (!$this->prepareDir(\dirname($path), false) || !@copy($copy, $tmp) || !@rename($tmp, $path)) {
                @unlink($tmp);

                return;
            }
            @chmod($path, $mode ?: 0644);
            $used = $copy;
            unset($s['backups'][$rel]);
            clearstatcache(true, $path);
            // brought back on purpose: not compressed again by itself, only after it changes
            $s['skipped'][$rel] = @filesize($path) . ':' . @filemtime($path) . ':restored';
            $result = 'ok';
        }, $state);
        // the copy goes only once the notes say so (else it is still listed, and removed with its days)
        if ($written && $used !== '') {
            @unlink($used);
        }

        return $result;
    }

    /**
     * Bring back every original that still has a copy, for at most $seconds.
     *
     * @return array{restored:int, changed:int, failed:int, left:int}
     */
    public function restoreAll(float $seconds): array
    {
        $end   = microtime(true) + $seconds;
        $state = $this->state();
        $out   = ['restored' => 0, 'changed' => 0, 'failed' => 0, 'left' => 0];
        $rels  = array_keys($state['backups']);
        foreach ($rels as $i => $rel) {
            if (microtime(true) > $end) {
                $out['left'] = \count($rels) - $i;
                break;
            }
            $r = $this->restore((string) $rel, $state);
            $out['restored'] += $r === 'ok' ? 1 : 0;
            $out['changed']  += $r === 'changed' ? 1 : 0;
            $out['failed']   += $r === 'fail' ? 1 : 0;
        }

        return $out;
    }

    /**
     * The copies older than $days removed (and copies no note names any more, by their file time).
     *
     * @return int copies removed
     */
    public function expire(int $days): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $limit   = $this->now() - max(1, $days) * 86400;
        $removed = 0;
        $known   = [];
        $this->update(function (array &$s) use ($limit, &$removed, &$known) {
            foreach ($s['backups'] as $rel => $b) {
                $copy = $this->copyPath((string) $rel);
                if ((int) ($b['t'] ?? 0) < $limit || $copy === '' || !is_file($copy)) {
                    $removed += $copy !== '' && @unlink($copy) ? 1 : 0;
                    unset($s['backups'][$rel]);
                    continue;
                }
                $known[$copy] = true;
            }
            // a picture gone for good needs no note
            foreach ($s['skipped'] as $rel => $sig) {
                if (!is_file($this->root . '/' . $rel)) {
                    unset($s['skipped'][$rel]);
                }
            }
        });
        // copies without a note (a note lost, a copy left by a failed step)
        foreach ($this->copyFiles() as $file) {
            if (!isset($known[$file]) && (int) @filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }
        $this->removeEmptyDirs($this->dir);

        return $removed;
    }

    /**
     * The copies of originals, newest first: [rel, t (when compressed), b (bytes before), a (after), c
     * (bytes of the copy)]; $filter: a part of the path.
     */
    public function backups(string $filter = '', int $limit = 300): array
    {
        $state = $this->state();
        $list  = [];
        foreach ($state['backups'] as $rel => $b) {
            if ($filter !== '' && stripos((string) $rel, $filter) === false) {
                continue;
            }
            $list[] = ['rel' => (string) $rel, 't' => (int) ($b['t'] ?? 0), 'b' => (int) ($b['b'] ?? 0), 'a' => (int) ($b['a'] ?? 0)];
        }
        usort($list, fn ($x, $y) => $y['t'] <=> $x['t'] ?: strcmp($x['rel'], $y['rel']));

        return \array_slice($list, 0, max(1, $limit));
    }

    /** The figures: compressed (all time), copies kept (count, bytes, the oldest), waiting uploads, a run. */
    public function stats(): array
    {
        $state  = $this->state();
        $bytes  = 0;
        $oldest = 0;
        foreach ($state['backups'] as $rel => $b) {
            $bytes += (int) @filesize($this->copyPath((string) $rel) ?: $this->dir . '/-');
            $t      = (int) ($b['t'] ?? 0);
            $oldest = $oldest && $oldest < $t ? $oldest : $t;
        }

        return [
            'compressed'  => (int) ($state['total']['n'] ?? 0),
            'before'      => (int) ($state['total']['b'] ?? 0),
            'after'       => (int) ($state['total']['a'] ?? 0),
            'backups'     => \count($state['backups']),
            'backupBytes' => $bytes,
            'oldest'      => $oldest,
            'pending'     => \count($state['pending']),
            'job'         => $this->jobStatus(),
        ];
    }

    // ---------------------------------------------------------------- uploads

    /** Note the pictures changed since $since (an upload) as waiting; how many are waiting now. */
    public function noteNew(int $since): int
    {
        $new   = $this->candidates($since);
        $count = 0;
        $this->update(function (array &$s) use ($new, &$count) {
            foreach ($new as $rel) {
                if (\count($s['pending']) >= self::MAX_PENDING) {
                    break;
                }
                $s['pending'][$rel] = true;
            }
            $count = \count($s['pending']);
        });

        return $count;
    }

    /**
     * Compress the waiting uploaded pictures for at most $seconds (one worker at a time).
     *
     * @return array<int, array> what each gave (see process())
     */
    public function work(ImageCompressor $c, float $seconds): array
    {
        $lock = $this->lock('work');
        if (!$lock) {
            return [];
        }
        $done = [];
        try {
            $end   = microtime(true) + $seconds;
            $state = $this->state();
            foreach (array_keys($state['pending']) as $rel) {
                if (microtime(true) > $end) {
                    break;
                }
                $rel = (string) $rel;
                $this->update(function (array &$s) use ($rel) {
                    unset($s['pending'][$rel]);
                }, $state);
                if (!$this->excluded($rel) && is_file($this->root . '/' . $rel)) {
                    $done[] = $this->process($c, $rel, $state);
                }
            }
        } finally {
            $this->unlock($lock);
        }

        return $done;
    }

    // ---------------------------------------------------------------- compress all

    /** A new run over every picture of the folder; how many it has. */
    public function jobStart(): int
    {
        // under the lock of the run: a part of an older run still working cannot write it back over this one
        $lock = $this->lock('job', true);
        try {
            $list = $this->candidates();
            $this->writeFile($this->dir . '/job.php', ['list' => $list, 'pos' => 0, 't' => $this->now(),
                'sum' => ['ok' => 0, 'skip' => 0, 'fail' => 0, 'before' => 0, 'after' => 0]]);
        } finally {
            if ($lock) {
                $this->unlock($lock);
            }
        }

        return \count($list);
    }

    /** The run as it stands: [pos, total, t, sum] or null when there is none. */
    public function jobStatus(): ?array
    {
        $job = $this->readFile($this->dir . '/job.php');
        if (!isset($job['list']) || !\is_array($job['list'])) {
            return null;
        }

        return ['pos' => (int) $job['pos'], 'total' => \count($job['list']), 't' => (int) ($job['t'] ?? 0), 'sum' => (array) ($job['sum'] ?? [])];
    }

    /** The run dropped (once the part working now, if any, is done: it would write the run back). */
    public function jobCancel(): void
    {
        $lock = $this->lock('job', true);
        @unlink($this->dir . '/job.php');
        if ($lock) {
            $this->unlock($lock);
        }
    }

    /**
     * The next pictures of the run, for at most $seconds. The run is removed when it is done.
     *
     * @return array{busy?:bool, entries:array, pos:int, total:int, sum:array, done:bool}
     */
    public function jobRun(ImageCompressor $c, float $seconds): array
    {
        $lock = $this->lock('job');
        if (!$lock) {
            return ['busy' => true, 'entries' => [], 'pos' => 0, 'total' => 0, 'sum' => [], 'done' => false];
        }
        try {
            $job = $this->readFile($this->dir . '/job.php');
            if (!isset($job['list']) || !\is_array($job['list'])) {
                return ['entries' => [], 'pos' => 0, 'total' => 0, 'sum' => [], 'done' => true];
            }
            $end     = microtime(true) + $seconds;
            $total   = \count($job['list']);
            $pos     = (int) $job['pos'];
            $sum     = (array) $job['sum'];
            $entries = [];
            $state   = $this->state();
            $saved   = microtime(true);
            // one picture at the least, so every call moves on
            while ($pos < $total && ($entries === [] || microtime(true) < $end)) {
                $rel = (string) $job['list'][$pos];
                $e   = is_file($this->root . '/' . $rel) ? $this->process($c, $rel, $state)
                    : ['rel' => $rel, 'status' => 'skip', 'reason' => 'gone', 'before' => 0, 'after' => 0, 'method' => '', 'lossy' => false];
                $pos++;
                $sum[$e['status']] = (int) ($sum[$e['status']] ?? 0) + 1;
                if ($e['status'] === 'ok') {
                    $sum['before'] = (int) ($sum['before'] ?? 0) + $e['before'];
                    $sum['after']  = (int) ($sum['after'] ?? 0) + $e['after'];
                }
                $entries[] = $e;
                // the place in the run kept now and then: a request cut short goes on near where it was
                if (microtime(true) - $saved > 3) {
                    $this->writeFile($this->dir . '/job.php', ['pos' => $pos, 'sum' => $sum] + $job);
                    $saved = microtime(true);
                }
            }
            $done = $pos >= $total;
            if ($done) {
                @unlink($this->dir . '/job.php');
            } else {
                $this->writeFile($this->dir . '/job.php', ['pos' => $pos, 'sum' => $sum] + $job);
            }

            return ['entries' => $entries, 'pos' => $pos, 'total' => $total, 'sum' => $sum, 'done' => $done];
        } finally {
            $this->unlock($lock);
        }
    }

    // ---------------------------------------------------------------- the notes

    /** @return array{backups:array, skipped:array, pending:array, total:array} */
    public function state(): array
    {
        $s = $this->readFile($this->dir . '/state.php');
        foreach (['backups', 'skipped', 'pending', 'total'] as $key) {
            $s[$key] = isset($s[$key]) && \is_array($s[$key]) ? $s[$key] : [];
        }

        return $s;
    }

    /**
     * Change the notes under the lock (read again first: another request may have changed them); $copy,
     * when given, gets the notes as written. False when the notes could not be locked or written.
     */
    private function update(callable $change, ?array &$copy = null): bool
    {
        if (!$this->prepareDir($this->dir)) {
            return false;
        }
        $fh = @fopen($this->dir . '/state.lock', 'c');
        if (!$fh) {
            return false;
        }
        flock($fh, LOCK_EX);
        try {
            $state = $this->state();
            $change($state);
            if (!$this->writeFile($this->dir . '/state.php', $state)) {
                return false;
            }
            $copy = $state;

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function readFile(string $file): array
    {
        $raw = is_file($file) ? (string) @file_get_contents($file) : '';
        if (str_starts_with($raw, self::GUARD)) {
            $raw = substr($raw, \strlen(self::GUARD));
        }
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return \is_array($data) ? $data : [];
    }

    private function writeFile(string $file, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if ($json !== false && $this->prepareDir(\dirname($file)) && @file_put_contents($tmp, self::GUARD . $json) !== false && @rename($tmp, $file)) {
            return true;
        }
        @unlink($tmp);

        return false;
    }

    /** A lock only one request holds at a time (job, work), or null when another holds it ($wait: wait for it). */
    private function lock(string $name, bool $wait = false)
    {
        if (!$this->prepareDir($this->dir)) {
            return null;
        }
        $fh = @fopen($this->dir . '/' . $name . '.lock', 'c');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return null;
        }

        return $fh;
    }

    private function unlock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** A folder made when missing; the folder of the copies gets a guard the web server reads (Apache). */
    private function prepareDir(string $dir, bool $copies = true): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if ($copies && !is_file($this->dir . '/.htaccess') && is_dir($this->dir)) {
            @file_put_contents($this->dir . '/.htaccess', "# MerTools: copies of the originals of compressed pictures\nRequire all denied\n");
            @file_put_contents($this->dir . '/index.html', '<!DOCTYPE html><title></title>');
        }

        return true;
    }

    /**
     * The file of the copy of a picture: in the folder of the random key ('' when there is no key yet;
     * $create: made now — under the lock of the notes, so two requests never make two keys).
     */
    private function copyPath(string $rel, bool $create = false): string
    {
        $key = $this->key($create);

        return $key === '' ? '' : $this->dir . '/' . $key . '/' . $rel;
    }

    private function key(bool $create): string
    {
        static $keys = [];
        if (isset($keys[$this->dir])) {
            return $keys[$this->dir];
        }
        $key = (string) ($this->readFile($this->dir . '/key.php')['key'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            if (!$create) {
                return '';
            }
            $key = bin2hex(random_bytes(16));
            if (!$this->writeFile($this->dir . '/key.php', ['key' => $key])) {
                return '';
            }
        }

        return $keys[$this->dir] = $key;
    }

    /** @return string[] every file in the folders of copies (the key's and any older one) */
    private function copyFiles(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (!preg_match('#/[0-9a-f]{32}$#', $sub)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sub, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($iter as $f) {
                if ($f->isFile()) {
                    $out[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }

        return $out;
    }

    private function removeEmptyDirs(string $dir): bool
    {
        $empty = true;
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $empty = $this->removeEmptyDirs($path) && $empty;
            } else {
                $empty = false;
            }
        }

        return $dir !== $this->dir && $empty ? @rmdir($dir) : false;
    }

    /** A relative path that stays in the pictures' folder (no "..", no hidden part). */
    private static function safe(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/')) {
            return false;
        }
        foreach (explode('/', str_replace('\\', '/', $rel)) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') {
                return false;
            }
        }

        return true;
    }
}
