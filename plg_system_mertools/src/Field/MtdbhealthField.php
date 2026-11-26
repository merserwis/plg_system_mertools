<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/** The tables of the site (Database tab): character sets, leftover asset rows, sizes and free space, with their repair. */
final class MtdbhealthField extends FormField
{
    protected $type = 'Mtdbhealth';

    public const UI = ['SUMMARY', 'CHARSET', 'CHARSET_OK', 'CHARSET_INFO', 'TABLE', 'COLLATION', 'SIZE', 'STATE', 'CONVERT', 'CONVERT_ALL', 'CONVERT_ASK',
        'MANUAL', 'COLUMNS', 'CONVERTED', 'ASSETS', 'ASSETS_OK', 'ASSETS_INFO', 'KIND', 'COUNT', 'EXAMPLES', 'ASSETS_DELETE', 'ASSETS_ASK', 'ASSETS_DONE',
        'K_MODULES', 'K_ARTICLES', 'K_CATEGORIES', 'K_GRIDBOX_PAGES', 'K_GRIDBOX_CATEGORIES', 'K_GRIDBOX_APPS', 'TABLES', 'TABLES_INFO', 'ROWS', 'FREE',
        'OPTIMIZE', 'OPTIMIZE_ALL', 'OPTIMIZE_ASK', 'OPTIMIZED', 'NOTHING_FREE', 'ADMIN_ONLY', 'WORKING', 'FAILED', 'LOADING', 'ERROR'];

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.dbhealth', 'plg_system_mertools/mertools-dbhealth.js', [], ['defer' => true]);
        $texts = [];
        foreach (self::UI as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_DBH_' . $key);
        }
        $data = htmlspecialchars(json_encode(['token' => Session::getFormToken(), 'texts' => $texts], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return '<div class="mt-dbhealth" data-mt-dbhealth="' . $data . '"><div class="mt-dbh-body" role="status" aria-live="polite">'
            . htmlspecialchars($texts['LOADING'], ENT_QUOTES, 'UTF-8') . '</div></div>';
    }
}
