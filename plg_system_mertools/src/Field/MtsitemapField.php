<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Merserwis\Plugin\System\MerTools\Tool\Sitemap;

/** The panel of the tab Sitemap: the state, the maps, what is left out and why, Take over from Gridbox. */
final class MtsitemapField extends FormField
{
    protected $type = 'Mtsitemap';

    private const UI = ['TOOL', 'GRIDBOX', 'STATIC', 'ANSWERS', 'LINE', 'BUILT', 'ON', 'OFF', 'PRESENT', 'ABSENT', 'BY_GRIDBOX', 'BY_MERTOOLS', 'BY_NONE',
        'NEVER', 'SECONDS', 'URLS', 'STALE', 'W_STATIC', 'W_MASS', 'W_LINE', 'W_OFF', 'REBUILD', 'TAKEOVER', 'TAKEOVER_CONFIRM', 'UNDO', 'UNDO_CONFIRM',
        'COMPARE', 'MAPS', 'MAP', 'ADDRESSES', 'NEWEST', 'SIZE', 'EXCLUDED', 'NO_EXCLUDED', 'SEARCH', 'SHOWN', 'WHY', 'WHY_HINT', 'WHY_BUTTON', 'IN_MAP',
        'NOT_FOUND', 'LOADING', 'ERROR', 'DONE', 'REMOVED_NOT', 'CHECK_MERTOOLS', 'CHECK_OTHER', 'CHECK_UNKNOWN', 'RESTORED', 'RESTORED_NOT',
        'CMP_TOTAL', 'CMP_MISSING', 'CMP_UNEXPLAINED', 'CMP_ADDED', 'CMP_NONE', 'NO_REASON'];

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.sitemap', 'plg_system_mertools/mertools-sitemap.js', [], ['defer' => true]);
        $texts = [];
        foreach (self::UI as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_SM_UI_' . $key);
        }
        foreach (Sitemap::REASONS as $reason) {
            $texts['R_' . $reason] = Text::_('PLG_SYSTEM_MERTOOLS_SM_R_' . strtoupper(str_replace('-', '_', $reason)));
        }
        $data = ['url' => Uri::base(true) . '/index.php', 'token' => Session::getFormToken(), 'texts' => $texts];

        return '<div class="mt-sitemap" data-mt-sitemap="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
