<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * The copies of the Gridbox apps in the plugin settings: each app with a button for a copy now, the
 * copies with download, restore and delete (mertools-backups.js, requests to the plugin's
 * mertools_action handler with the form token).
 */
final class MtappsbackupField extends FormField
{
    protected $type = 'Mtappsbackup';

    private const UI = ['APP', 'PAGES', 'COPY', 'COPY_ALL', 'WHEN', 'REASON', 'CONTENT', 'SIZE', 'WHO', 'DOWNLOAD', 'RESTORE', 'DELETE', 'NONE', 'LOADING',
        'ERROR', 'WORKING', 'MADE', 'RESTORE_CONFIRM', 'DELETE_CONFIRM', 'RESTORED', 'DELETED', 'GONE', 'MISSING', 'FOLDER', 'NOT_WRITABLE', 'SKIPPED',
        'R_AUTO', 'R_MANUAL', 'R_DELETE', 'COPIES', 'APPS', 'LOCKED', 'UNLOCKED', 'CONTENT_FMT', 'UNKNOWN', 'EXPOSED'];

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.backups', 'plg_system_mertools/mertools-backups.js', [], ['defer' => true]);
        $texts = [];
        foreach (self::UI as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_APPS_UI_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return '<div class="mt-backups" data-mt-backups="' . $data . '"><div class="mt-backups-result mb-2" role="status" aria-live="polite"></div>'
            . '<div class="mt-backups-apps"></div><div class="mt-backups-copies"></div></div>';
    }
}
