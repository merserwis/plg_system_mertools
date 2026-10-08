<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The page speed panel in the plugin settings: the pages and devices with their baseline and their
 * latest PageSpeed Insights result, the change between them, the history with the MerTools version
 * and tools of each measurement, and the real-user figures of Chrome. The measuring is done by
 * media/js/mertools-speed.js; it keeps its results through the plugin's mertools_action requests.
 */

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Merserwis\Plugin\System\MerTools\Tool\Speed;

final class MtspeedField extends FormField
{
    protected $type = 'Mtspeed';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.speed', 'plg_system_mertools/mertools-speed.js', [], ['defer' => true]);

        $keys = ['MEASURE_ALL', 'MEASURE', 'HISTORY', 'HIDE', 'BASELINE', 'SET_BASELINE', 'DELETE', 'CONFIRM_DELETE', 'LATEST', 'CHANGE',
            'PAGE', 'DEVICE', 'MOBILE', 'DESKTOP', 'SCORE', 'SERVER', 'SERVER_HINT', 'NOT_MEASURED', 'NO_KEY', 'WORKING', 'DONE', 'FAILED',
            'API_ERROR', 'FIELD_TITLE', 'FIELD_NONE', 'FIELD_PAGE', 'FIELD_SITE', 'OPPORTUNITIES', 'VERSION', 'TOOLS', 'RUNS', 'SAVING',
            'IS_BASELINE', 'LOADING', 'ERROR', 'HELP', 'FIELD_NOTE'];
        $texts = [];
        foreach ($keys as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_SPEED_UI_' . $key);
        }
        foreach (Speed::TOOLS as $code) {
            $texts['TOOL_' . $code] = Text::_('PLG_SYSTEM_MERTOOLS_SPEED_TOOL_' . strtoupper($code));
        }
        $data = htmlspecialchars(json_encode([
            'token' => Session::getFormToken(),
            'root'  => rtrim(Uri::root(), '/'),
            'texts' => $texts,
        ]), ENT_QUOTES, 'UTF-8');

        return '<style>'
            . '.mt-speed table{font-size:13px}.mt-speed td,.mt-speed th{vertical-align:middle;white-space:nowrap}'
            . '.mt-speed .mt-url{white-space:normal;word-break:break-word;max-width:260px}'
            . '.mt-speed .mt-good{color:#0a7a32}.mt-speed .mt-avg{color:#b35c00}.mt-speed .mt-poor{color:#c3251e}'
            . '.mt-speed .mt-score{display:inline-block;min-width:2.6em;text-align:center;font-weight:700;border-radius:1em;padding:1px 6px;border:2px solid currentColor}'
            . '.mt-speed .mt-delta{font-size:12px;margin-inline-start:4px}.mt-speed .mt-up{color:#0a7a32}.mt-speed .mt-down{color:#c3251e}'
            . '.mt-speed .mt-small{font-size:12px;opacity:.75}.mt-speed .mt-hist td{background:rgba(127,127,127,.06)}'
            . '</style>'
            . '<div class="mt-speed" data-mt-speed="' . $data . '">'
            . '<p class="mt-small mb-2">' . htmlspecialchars($texts['HELP'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">'
            . '<button type="button" class="btn btn-primary" data-mt-speed-all><span class="icon-loop" aria-hidden="true"></span> '
            . htmlspecialchars($texts['MEASURE_ALL'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '<span class="mt-speed-status" role="status" aria-live="polite"></span></div>'
            . '<div class="mt-speed-field mb-2"></div>'
            . '<div class="table-responsive"><table class="table table-sm mt-speed-table"></table></div>'
            . '</div>';
    }
}
