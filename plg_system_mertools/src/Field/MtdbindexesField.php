<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/** The indexes of Gridbox's tables in the plugin settings: their state and the button that creates the missing ones. */
final class MtdbindexesField extends FormField
{
    protected $type = 'Mtdbindexes';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.dbidx', 'plg_system_mertools/mertools-dbidx.js', [], ['defer' => true]);

        $texts = [];
        foreach (['TABLE', 'COLUMN', 'STATE', 'OK', 'OK_OTHER', 'MISSING', 'NO_TABLE', 'FIX', 'FIXED', 'NOTHING', 'FAILED', 'LOADING', 'ERROR'] as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_DBIDX_UI_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts]), ENT_QUOTES, 'UTF-8');

        return '<div class="mt-dbidx" data-mt-dbidx="' . $data . '">'
            . '<table class="table table-sm mt-dbidx-table mb-2"><thead><tr><th scope="col">' . htmlspecialchars($texts['TABLE'], ENT_QUOTES, 'UTF-8')
            . '</th><th scope="col">' . htmlspecialchars($texts['COLUMN'], ENT_QUOTES, 'UTF-8') . '</th><th scope="col">'
            . htmlspecialchars($texts['STATE'], ENT_QUOTES, 'UTF-8') . '</th></tr></thead><tbody></tbody></table>'
            . '<button type="button" class="btn btn-secondary" data-mt-dbidx-fix><span class="icon-database" aria-hidden="true"></span> '
            . htmlspecialchars($texts['FIX'], ENT_QUOTES, 'UTF-8') . '</button>'
            . '<div class="mt-dbidx-result mt-2" role="status" aria-live="polite"></div>'
            . '</div>';
    }
}
