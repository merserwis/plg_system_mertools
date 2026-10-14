<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * The edit history in the plugin settings: every change of Gridbox pages, with filters (mertools-history.js).
 * Self-contained on purpose: right after an update PHP may still run the previous version of the
 * plugin's other classes from OPcache, so this field calls none of their methods.
 */
final class MthistoryField extends FormField
{
    protected $type = 'Mthistory';

    private const UI = ['TITLE', 'WHEN', 'WHO', 'WHAT', 'CHANGES', 'NONE', 'LOADING', 'ERROR', 'CLOSE', 'MORE', 'SEARCH', 'ALL_USERS', 'ALL_ACTIONS',
        'FROM', 'TO', 'FILTER', 'PAGE', 'TOTAL', 'OPEN', 'CLIENT_SITE', 'CLIENT_ADMIN'];

    private const ACTIONS = ['created', 'duplicated', 'edited', 'saved', 'published', 'unpublished', 'trashed', 'restored', 'deleted'];

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.history', 'plg_system_mertools/mertools-history.js', [], ['defer' => true]);
        $texts = [];
        foreach (self::UI as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_UI_' . $key);
        }
        foreach (self::ACTIONS as $action) {
            $texts['A_' . strtoupper($action)] = Text::_('PLG_SYSTEM_MERTOOLS_HISTORY_A_' . strtoupper($action));
        }
        $data = ['url' => Uri::base(true) . '/index.php?mertools_action=history_list', 'token' => Session::getFormToken(), 'texts' => $texts];

        return '<div class="mt-history" data-mt-history-panel="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
