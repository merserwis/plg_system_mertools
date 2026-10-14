<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;

/** The edit history in the plugin settings: every change of Gridbox pages, with filters (mertools-history.js). */
final class MthistoryField extends FormField
{
    protected $type = 'Mthistory';

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.history', 'plg_system_mertools/mertools-history.js', [], ['defer' => true]);
        $data = ['url' => Uri::base(true) . '/index.php?mertools_action=history_list', 'token' => Session::getFormToken(), 'texts' => MerTools::historyTexts()];

        return '<div class="mt-history" data-mt-history-panel="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
