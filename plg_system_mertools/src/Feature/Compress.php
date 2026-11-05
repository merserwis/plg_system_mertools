<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The lighter originals (Images → Compression): the pictures uploaded by an editor compressed on this
 * server right after the upload, a run over every picture of the site from the panel, a try on one
 * picture before that, and the copies of the originals with their return (ImageCompressor makes the
 * lighter file, CompressStore keeps the copies and the notes).
 *
 *  - An upload: a POST with a JPEG or PNG file (Gridbox's uploaders, Balbooa's apps) or Joomla's media
 *    manager, by a logged-in user. Its answer is read as it goes out (nothing in it changed): the
 *    pictures of the pictures' folder it names that came in during the request are compressed when it
 *    ends, after the response where PHP can close it first. An answer that names none (another
 *    uploader) leaves a look through the folder for the pictures changed during it, done where nobody
 *    waits: right after a response PHP could close, else after a later page or when the panel is
 *    opened. What is left waits the same way.
 *  - Size on upload: a picture uploaded larger than the frame of its place — a product of the shop, a
 *    post of the blog, anything else — is made smaller to fit it (the place: the page of Gridbox the
 *    upload came from, by the address of its editor or panel: the Referer, or the one the media
 *    manager adds to the upload where a site sends no Referer), also with Compress on upload off;
 *    its original is kept as a copy like that of a compressed picture.
 *  - Copies of the originals older than the days chosen are removed once a day (and when the panel is
 *    opened).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\CompressStore;
use Merserwis\Plugin\System\MerTools\Tool\ImageCompressor;
use Merserwis\Plugin\System\MerTools\Tool\Tick;

final class Compress extends Feature
{
    /** a picture tried in the panel is at most this heavy */
    private const TEST_MAX_BYTES = 26214400;

    /** the answer of an upload read at most this far for the paths of its pictures */
    private const SAID_MAX_BYTES = 1048576;

    /** the places of an upload and the frame each has by default (width, height) */
    public const PLACES = ['shop' => [1600, 1600], 'blog' => [1920, 1920], 'other' => [2560, 2560]];

    /** what the upload answered so far (see uploadWatch()) */
    private string $said = '';

    /** the place the upload came from (see uploadPlace()) */
    private string $place = 'other';

    /** Compress the pictures of an upload (the switch). */
    public function uploadsOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('cmp_upload', 0);
    }

    /** Make the pictures of an upload fit the frame of their place (the switch). */
    public function resizeOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('rsz_upload', 0);
    }

    /**
     * The frames of the places, place => [width, height] (0: that side not limited); a place without
     * any limit and the switch off: none.
     *
     * @return array<string, int[]>
     */
    public function boxes(): array
    {
        if (!$this->resizeOn()) {
            return [];
        }
        $out = [];
        foreach (self::PLACES as $place => [$w, $h]) {
            $box = [max(0, min(20000, (int) $this->plugin->params->get('rsz_' . $place . '_w', $w))),
                max(0, min(20000, (int) $this->plugin->params->get('rsz_' . $place . '_h', $h)))];
            if ($box[0] > 0 || $box[1] > 0) {
                $out[$place] = $box;
            }
        }

        return $out;
    }

    /**
     * The place an upload came from: shop (the editor of a product, or of a page or the layouts of a
     * shop app, the panel of a shop app), blog (the same for a blog app), other (anything else), read
     * from the address of the page that sent it — mt_ref (added by MerTools to the uploads of Gridbox's
     * media manager), else the Referer.
     */
    public function uploadPlace(): string
    {
        $input = Factory::getApplication()->getInput();
        $ref   = trim($input->getString('mt_ref', '')) ?: (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $query = [];
        parse_str((string) parse_url(html_entity_decode($ref, ENT_QUOTES), PHP_URL_QUERY), $query);
        if (($query['option'] ?? '') !== 'com_gridbox' || !\is_scalar($query['id'] ?? null) || (int) $query['id'] <= 0) {
            return 'other';
        }
        $id   = (int) $query['id'];
        $view = (string) ($query['view'] ?? '');
        $edit = (string) ($query['edit_type'] ?? '');
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            if ($view === 'editor' && $edit === '') {
                $type = $db->setQuery('SELECT a.type FROM ' . $db->quoteName('#__gridbox_pages', 'p') . ' JOIN ' . $db->quoteName('#__gridbox_app', 'a')
                    . ' ON a.id = p.app_id WHERE p.id = ' . $id, 0, 1)->loadResult();
            } elseif (($view === 'editor' && \in_array($edit, ['blog', 'post-layout'], true)) || $view === 'apps') {
                // the layouts of an app and its panel: the id is the app's
                $type = $db->setQuery('SELECT type FROM ' . $db->quoteName('#__gridbox_app') . ' WHERE id = ' . $id, 0, 1)->loadResult();
            } else {
                return 'other';
            }
        } catch (\Throwable $e) {
            return 'other';
        }

        return self::placeOf((string) $type);
    }

    /** The place of a Gridbox app type: products and booking are the shop, blog the blog. */
    public static function placeOf(string $type): string
    {
        return match ($type) {
            'products', 'booking' => 'shop',
            'blog'                => 'blog',
            default               => 'other',
        };
    }

    /** The waiting uploads done for at most $seconds: compressed (the switch on) and made to fit their frames. */
    private function workUploads(CompressStore $store, float $seconds, bool $scan = true): array
    {
        return $store->work($this->compressor(), $seconds, $scan, $this->boxes(), $this->uploadsOn());
    }

    /** The compressor with the saved settings, or with $form (the panel's try: the form as it is now). */
    public function compressor(?array $form = null): ImageCompressor
    {
        $p   = $this->plugin->params;
        $get = fn (string $key, $default) => $form !== null && isset($form[$key]) ? $form[$key] : $p->get($key, $default);

        return new ImageCompressor([
            'jpeg'   => (int) $get('cmp_jpeg', 82),
            'png'    => (int) $get('cmp_png', 70),
            'min'    => (int) $get('cmp_min', 10),
            'engine' => (string) $get('cmp_engine', 'auto'),
        ]);
    }

    public function store(): CompressStore
    {
        $folder  = (string) ComponentHelper::getParams('com_media')->get('image_path', 'images');
        $exclude = preg_split('/\R/', (string) $this->plugin->params->get('cmp_exclude', '')) ?: [];

        return new CompressStore(JPATH_ROOT, $folder, $exclude);
    }

    private function keepDays(): int
    {
        return max(1, min(365, (int) $this->plugin->params->get('cmp_keep', 30)));
    }

    /**
     * After routing: a request that uploads files (a POST with files, Joomla's media manager) by a
     * logged-in user, with the switch on, gets its new pictures compressed when it ends — also when the
     * uploader ends the request itself (exit), as Gridbox's do.
     */
    public function uploadWatch(): void
    {
        $app   = Factory::getApplication();
        $input = $app->getInput();
        if ((!$this->uploadsOn() && $this->boxes() === []) || $input->getMethod() !== 'POST' || $input->getCmd('mertools_action', '') !== ''
            || !ImageCompressor::gd()) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest || !$this->bringsPicture($input)) {
            return;
        }
        $this->place = $this->uploadPlace();
        $since       = (int) ($_SERVER['REQUEST_TIME'] ?? time());
        // the answer read as it goes out, in parts as before (the uploader's own buffers and headers as they were)
        ob_start(function (string $part, int $phase): string {
            // what the page throws away (ob_clean) never went out: not read
            if (!($phase & PHP_OUTPUT_HANDLER_CLEAN) && \strlen($this->said) < self::SAID_MAX_BYTES) {
                $this->said .= substr($part, 0, self::SAID_MAX_BYTES - \strlen($this->said));
            }

            return $part;
        }, 8192);
        register_shutdown_function(function () use ($since) {
            try {
                $this->afterUpload($since);
            } catch (\Throwable $e) {
                // the pictures stay as they were uploaded
            }
        });
    }

    /** Does the request bring a JPEG or PNG file: a file of the form, or the file Joomla's media manager gets as JSON? */
    private function bringsPicture($input): bool
    {
        $isPicture = fn ($name): bool => \is_string($name) && (bool) preg_match('/\.(jpe?g|png)$/i', trim($name));
        $found     = false;
        array_walk_recursive($_FILES, function ($v, $k) use ($isPicture, &$found) {
            $found = $found || ($k === 'name' && $isPicture($v));
        });
        if ($found || $input->getCmd('option') !== 'com_media') {
            return $found;
        }
        try {
            return $isPicture($input->json->getString('name', ''));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The end of an upload: the response closed first where PHP can, then the pictures its answer names
     * noted and compressed (an answer that names none: the folder looked through, where nobody waits).
     */
    private function afterUpload(int $since): void
    {
        ignore_user_abort(true);
        // what the uploader has written goes out first (read for the paths), then the response is closed where PHP can
        while (ob_get_level() > 0 && @ob_end_flush()) {
        }
        $finished = $this->plugin->finishResponse();
        @set_time_limit(120);
        $store = $this->store();
        $paths = $store->pathsIn($this->said, $since);
        $this->said = '';
        // none named, or not all of them noted (the queue full): the folder looked through for the time of
        // the upload — after a later page or when the panel is opened, not while the editor waits
        $noted = $paths !== [] && $store->notePaths($paths, $this->place);
        if (!$noted && (!$store->noteScan($since, time() + 1) || !$finished) && $paths === []) {
            return;
        }
        // while the editor still waits (mod_php): a few seconds, the rest after a later page or in the panel
        $this->workUploads($store, $finished ? 30.0 : 4.0, $finished);
    }

    /**
     * After a page of the site, where the response could be closed first: uploaded pictures still
     * waiting (every minute at most), and once a day the copies of originals past their days removed.
     */
    public function afterRespond(): void
    {
        if (!\function_exists('fastcgi_finish_request') && !\function_exists('litespeed_finish_request')) {
            return;
        }
        if (!is_dir(JPATH_ROOT . '/' . CompressStore::DIR)) {
            return;
        }
        $base = MerTools::cacheBase();
        $tick = Tick::claim($base . '/mertools-cmp-work', 60);
        if ($tick) {
            try {
                $store = $this->store();
                if ($store->waiting() && $this->plugin->finishResponse()) {
                    @set_time_limit(60);
                    $this->workUploads($store, 8.0);
                }
            } catch (\Throwable $e) {
                // tried again after a later page
            } finally {
                $tick->release();
            }
        }
        $daily = Tick::claim($base . '/mertools-cmp-expire', 86400);
        if ($daily) {
            try {
                if ($this->plugin->finishResponse()) {
                    $this->store()->expire($this->keepDays());
                }
            } catch (\Throwable $e) {
                // tried again the next day
            } finally {
                $daily->release();
            }
        }
    }

    /** The requests of the panel (cmp_*); the router has checked the right and the token. */
    public function handleAction(string $action): void
    {
        $input = Factory::getApplication()->getInput();
        try {
            $store = $this->store();
            $gd    = ImageCompressor::gd();
            $noGd  = ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CMP_UI_NO_GD')];
            @set_time_limit(120);
            $data = match ($action) {
                'cmp_stats'       => $this->opened($store, $gd),
                'cmp_test'        => $this->test(),
                'cmp_start'       => $gd ? ['success' => true, 'count' => $store->jobStart()] + $this->figures($store) : $noGd,
                'cmp_run'         => $gd ? ['success' => true, 'run' => $store->jobRun($this->compressor(), 12.0)] : $noGd,
                'cmp_cancel'      => (function () use ($store) {
                    $store->jobCancel();

                    return ['success' => true] + $this->figures($store);
                })(),
                'cmp_list'        => ['success' => true, 'list' => $store->backups(trim($input->getString('filter', '')), 500), 'days' => $this->keepDays()],
                'cmp_restore'     => ['success' => true, 'result' => $store->restore(trim(str_replace('\\', '/', $input->getString('rel', '')), '/'))]
                    + $this->figures($store),
                // short parts: the window's bar moves
                'cmp_restore_all' => ['success' => true, 'restore' => $store->restoreAll(5.0, $input->getInt('skip', 0))] + $this->figures($store),
                default           => ['success' => false, 'message' => Text::_('JERROR_ALERTNOAUTHOR')],
            };
        } catch (\Throwable $e) {
            $data = ['success' => false, 'message' => $e->getMessage()];
        }
        $this->plugin->sendJson($data);
    }

    /** The panel opened: copies past their days removed, uploads still waiting (a server that cannot work after the response) done now. */
    private function opened(CompressStore $store, bool $gd): array
    {
        $store->expire($this->keepDays());
        if ($gd && $store->waiting()) {
            $this->workUploads($store, 8.0);
        }

        return ['success' => true] + $this->figures($store);
    }

    /** What the panel shows: the figures, the programs of the server, the run, the settings. */
    private function figures(CompressStore $store): array
    {
        return [
            'stats'   => $store->stats(),
            'engines' => ImageCompressor::engines() + ['php' => PHP_VERSION],
            'days'    => $this->keepDays(),
            'uploads' => $this->uploadsOn(),
            'resize'  => $this->boxes(),
            'finish'  => \function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request'),
        ];
    }

    /**
     * A try on one picture sent from the panel (nothing of the site is touched): the settings as they
     * are in the form now, the result and the lighter picture (data: URL) to compare.
     */
    private function test(): array
    {
        $file = $_FILES['file'] ?? null;
        if (!\is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $code = \is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;

            return ['success' => false, 'message' => Text::_(\in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'PLG_SYSTEM_MERTOOLS_CMP_UI_TEST_TOO_BIG' : 'PLG_SYSTEM_MERTOOLS_CMP_UI_TEST_NO_FILE')];
        }
        if ((int) $file['size'] > self::TEST_MAX_BYTES) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CMP_UI_TEST_TOO_BIG')];
        }
        if (!ImageCompressor::gd()) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CMP_UI_NO_GD')];
        }
        $input = Factory::getApplication()->getInput();
        $form  = [];
        foreach (['cmp_jpeg', 'cmp_png', 'cmp_min'] as $key) {
            if ($input->getString($key, '') !== '') {
                $form[$key] = $input->getInt($key);
            }
        }
        if ($input->getString('cmp_engine', '') !== '') {
            $form['cmp_engine'] = $input->getCmd('cmp_engine');
        }
        $base = MerTools::cacheBase() . '/mertools-cmp-try-' . bin2hex(random_bytes(6));
        $src  = $base . '-in';
        $dest = $base . '-out';
        try {
            if (!@move_uploaded_file((string) $file['tmp_name'], $src)) {
                return ['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CMP_UI_TEST_NO_FILE')];
            }
            @set_time_limit(90);
            $res = $this->compressor($form)->compress($src, $dest);
            $out = ['success' => true, 'result' => $res];
            if ($res['file'] && is_file($dest)) {
                $out['image'] = 'data:image/' . ($res['type'] === 'png' ? 'png' : 'jpeg') . ';base64,' . base64_encode((string) file_get_contents($dest));
                $size         = @getimagesize($dest);
                $out['size']  = \is_array($size) ? [(int) $size[0], (int) $size[1]] : null;
            }

            return $out;
        } finally {
            @unlink($src);
            @unlink($dest);
        }
    }
}
