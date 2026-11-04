<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;

/**
 * The lighter originals in the plugin settings (Images → Compression): figures, a try on one picture,
 * "Compress all pictures" with its log, the copies of the originals (mertools-compress.js).
 */
final class MtcompressField extends FormField
{
    protected $type = 'Mtcompress';

    private const TEXTS = ['ENGINES', 'ENGINE_GD', 'ENGINE_GD_PNG', 'ENGINE_GD_LOSSLESS', 'ENGINE_PHP_ONLY', 'NO_GD', 'NO_EXEC', 'NO_PROGRAMS', 'NO_FINISH',
        'UPLOADS', 'UPLOADS_ON', 'UPLOADS_OFF', 'COMPRESSED', 'BACKUPS', 'BACKUPS_DAYS', 'BACKUPS_OLDEST', 'PENDING',
        'RUN', 'RUN_RESUME', 'RUN_CONFIRM', 'RUN_UNSAVED', 'RUN_TITLE', 'STARTED', 'COUNTS', 'C_OK', 'C_SKIP', 'C_FAIL', 'SAVED', 'SHOW_SKIPS',
        'STOP', 'GO_ON', 'STOPPED', 'DONE', 'BUSY', 'CLOSE', 'CANCEL', 'CANCEL_CONFIRM', 'CANCELLED', 'METHOD', 'SETTINGS_LINE', 'UNSAVED',
        'TEST', 'TEST_INTRO', 'TEST_NO_FILE', 'TEST_TOO_BIG_SIZE', 'TEST_WORKING', 'TEST_OK', 'TEST_BEFORE', 'TEST_AFTER', 'TEST_SLIDER', 'TEST_HOW', 'TEST_DOWNLOAD',
        'RESTORE', 'RESTORE_CONFIRM', 'RESTORE_ok', 'RESTORE_gone', 'RESTORE_changed', 'RESTORE_fail',
        'RESTORE_ALL', 'RESTORE_ALL_CONFIRM', 'RESTORE_ALL_MORE', 'RESTORE_ALL_DONE', 'RESTORE_ALL_CHANGED', 'RESTORE_ALL_FAILED', 'RESTORE_ALL_LEFT',
        'LIST', 'LIST_TITLE', 'LIST_FILTER', 'LIST_INFO', 'LIST_UNTIL', 'LOADING', 'ERROR',
        'REASON_small', 'REASON_large', 'REASON_type', 'REASON_marked', 'REASON_animated', 'REASON_cmyk', 'REASON_memory', 'REASON_gain',
        'REASON_gain_test', 'REASON_read', 'REASON_write', 'REASON_tried', 'REASON_restored', 'REASON_changed', 'REASON_backup', 'REASON_state', 'REASON_gone'];

    private const CSS = '.mt-cmp-compare{position:relative;display:inline-block;max-width:100%;line-height:0;border:1px solid var(--template-bg-dark-20,#ccc);'
        . 'background:repeating-conic-gradient(#ddd 0 25%,#fff 0 50%) 0 0/16px 16px}.mt-cmp-compare>img{display:block;max-width:100%;max-height:70vh;height:auto}'
        . '.mt-cmp-clip{position:absolute;inset:0 auto 0 0;overflow:hidden;border-right:2px solid #e11d48}.mt-cmp-clip img{display:block;height:100%;width:auto;max-width:none}'
        . '.mt-cmp-labels{display:flex;justify-content:space-between;font-size:.8rem;max-width:100%}.mt-cmp-try{max-width:960px}'
        . 'dialog.mt-cmp-dlg{border:0;border-radius:8px;padding:0;width:min(980px,96vw);max-height:90vh;background:var(--body-bg,#fff);color:var(--body-color,#212529)}'
        . 'dialog.mt-cmp-dlg[open]{display:flex;flex-direction:column}dialog.mt-cmp-dlg::backdrop{background:rgba(0,0,0,.45)}'
        . '.mt-cmp-head{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid rgba(128,128,128,.3)}.mt-cmp-head h3{margin:0;font-size:1.15rem;flex:1}'
        . '.mt-cmp-body{padding:14px 18px;overflow:auto;flex:1;min-height:0;display:flex;flex-direction:column}'
        . '.mt-cmp-foot{display:flex;gap:8px;justify-content:flex-end;padding:10px 18px;border-top:1px solid rgba(128,128,128,.3)}'
        . '.mt-cmp-log{font-size:.85rem;overflow:auto;min-height:200px;max-height:55vh;border:1px solid rgba(128,128,128,.3);border-radius:4px;padding:6px 8px}'
        . '.mt-cmp-log li{padding:2px 0;border-bottom:1px dotted rgba(128,128,128,.25);word-break:break-all}.mt-cmp-log code{font-size:.8rem}'
        . '.mt-cmp-mark{display:inline-block;width:1.2em;font-weight:700}.mt-cmp-ok .mt-cmp-mark{color:#198754}.mt-cmp-fail .mt-cmp-mark{color:#dc3545}'
        . '.mt-cmp-dlg .mt-cmp-skip{display:none}.mt-cmp-dlg.mt-cmp-skips-on .mt-cmp-skip{display:list-item}.mt-cmp-note{font-style:italic;padding:4px 0}';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.compress', 'plg_system_mertools/mertools-compress.js', [], ['defer' => true]);
        $wa->addInlineStyle(self::CSS);

        $texts = [];
        foreach (self::TEXTS as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_CMP_UI_' . strtoupper($key));
        }
        $plugin = PluginHelper::getPlugin('system', 'mertools');
        $params = new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');
        $saved  = [
            'cmp_jpeg'   => (string) (int) $params->get('cmp_jpeg', 82),
            'cmp_png'    => (string) (int) $params->get('cmp_png', 70),
            'cmp_min'    => (string) (int) $params->get('cmp_min', 10),
            'cmp_engine' => $params->get('cmp_engine', 'auto') === 'php' ? 'php' : 'auto',
            'cmp_keep'   => (string) (int) $params->get('cmp_keep', 30),
        ];
        $data = htmlspecialchars(json_encode([
            'token'     => Session::getFormToken(),
            'texts'     => $texts,
            'saved'     => $saved,
            'folder'    => (string) ComponentHelper::getParams('com_media')->get('image_path', 'images'),
            'maxUpload' => min(26214400, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')),
        ]), ENT_QUOTES, 'UTF-8');
        $esc = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<div class="mt-cmp" data-mt-cmp="' . $data . '">'
            . '<dl class="mt-cmp-figures row mb-2"></dl>'
            . '<div class="d-flex flex-wrap gap-2 mb-2">'
            . '<button type="button" class="btn btn-primary" data-mt-cmp-run><span class="icon-images" aria-hidden="true"></span> ' . $esc($texts['RUN']) . '</button>'
            . '<button type="button" class="btn btn-outline-secondary" data-mt-cmp-cancel hidden>' . $esc($texts['CANCEL']) . '</button>'
            . '<button type="button" class="btn btn-secondary" data-mt-cmp-list><span class="icon-list" aria-hidden="true"></span> ' . $esc($texts['LIST']) . '</button>'
            . '<button type="button" class="btn btn-outline-danger" data-mt-cmp-restore-all><span class="icon-undo" aria-hidden="true"></span> ' . $esc($texts['RESTORE_ALL']) . '</button>'
            . '</div>'
            . '<div class="mt-cmp-result mb-3" role="status" aria-live="polite"></div>'
            . '<div class="mt-cmp-try card card-body">'
            . '<h4 class="h6">' . $esc($texts['TEST']) . '</h4>'
            . '<p class="small mb-1">' . $esc($texts['TEST_INTRO']) . '</p>'
            . '<p class="small text-muted mb-2 mt-cmp-try-settings"></p>'
            . '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">'
            . '<input type="file" class="form-control w-auto" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-mt-cmp-file aria-label="' . $esc($texts['TEST']) . '">'
            . '<button type="button" class="btn btn-secondary" data-mt-cmp-try>' . $esc($texts['TEST']) . '</button>'
            . '</div>'
            . '<div class="mt-cmp-try-out" role="status" aria-live="polite"></div>'
            . '</div>'
            . '</div>';
    }

    /** A php.ini size in bytes ("8M"); a limit that is not set counts as large. */
    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }

        return (int) $v * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($v, -1))] ?? 1);
    }
}
