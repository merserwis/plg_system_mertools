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
 *  - An upload: a POST with files (Gridbox's uploaders, Balbooa's apps) or Joomla's media manager, by a
 *    logged-in user. When the request ends, the JPEG and PNG pictures of the pictures' folder that came
 *    in during it are compressed, after the response where PHP can close it first; what is left waits
 *    and is done after a later page (or when the panel is opened).
 *  - Copies of the originals older than the days chosen are removed once a day (and when the panel is
 *    opened).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\CompressStore;
use Merserwis\Plugin\System\MerTools\Tool\ImageCompressor;
use Merserwis\Plugin\System\MerTools\Tool\Tick;

final class Compress extends Feature
{
    /** a picture tried in the panel is at most this heavy */
    private const TEST_MAX_BYTES = 26214400;

    /** Compress the pictures of an upload (the switch). */
    public function uploadsOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('cmp_upload', 0);
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
        if (!$this->uploadsOn() || $input->getMethod() !== 'POST' || $input->getCmd('mertools_action', '') !== ''
            || !ImageCompressor::gd()) {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest || (empty($_FILES) && $input->getCmd('option') !== 'com_media')) {
            return;
        }
        $since = (int) ($_SERVER['REQUEST_TIME'] ?? time());
        register_shutdown_function(function () use ($since) {
            try {
                $this->afterUpload($since);
            } catch (\Throwable $e) {
                // the pictures stay as they were uploaded
            }
        });
    }

    /**
     * The end of an upload: the response closed first where PHP can (the look for the new pictures goes
     * through the whole folder), then its new pictures noted and compressed.
     */
    private function afterUpload(int $since): void
    {
        ignore_user_abort(true);
        // what the uploader has written goes out first, then the response is closed where PHP can
        while (ob_get_level() > 0 && @ob_end_flush()) {
        }
        $finished = $this->plugin->finishResponse();
        @set_time_limit(120);
        $store = $this->store();
        if ($store->noteNew($since) === 0) {
            return;
        }
        // while the editor still waits (mod_php): a few seconds, the rest after a later page or in the panel
        $store->work($this->compressor(), $finished ? 30.0 : 4.0);
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
                if ($store->state()['pending'] && $this->plugin->finishResponse()) {
                    @set_time_limit(60);
                    $store->work($this->compressor(), 8.0);
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
                'cmp_restore_all' => ['success' => true, 'restore' => $store->restoreAll(15.0)] + $this->figures($store),
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
        if ($gd && $store->state()['pending']) {
            $store->work($this->compressor(), 8.0);
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
