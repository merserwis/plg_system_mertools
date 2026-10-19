<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The log of missing addresses in the plugin settings: each address with its visits, where the last
 * visitor came from and what happens to it now, a redirect to type (with a suggestion) — and the old
 * aliases of renamed pages and categories. The work is done by media/js/mertools-notfound.js through
 * the mertools_action requests of the plugin (MerTools::handleNotFoundAction).
 * Self-contained on purpose: right after an update PHP may still run the previous version of the
 * plugin's other classes from OPcache, so this field calls none of their methods.
 */

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

final class MtnotfoundField extends FormField
{
    protected $type = 'Mtnotfound';

    private const UI = ['ADDRESS', 'VISITS', 'LAST', 'FIRST', 'FROM', 'NOW', 'REDIRECT', 'SAVE', 'REMOVE', 'DELETE', 'USE', 'SUGGEST', 'NONE', 'LOADING',
        'ERROR', 'MORE', 'SEARCH', 'SHOW', 'ORDER', 'SHOW_ALL', 'SHOW_OPEN', 'SHOW_REDIRECTED', 'ORDER_LAST', 'ORDER_HITS', 'REFRESH', 'CLEAR', 'CLEAR_CONFIRM', 'CLEARED',
        'TOTAL', 'SAVED', 'R_MOVED', 'R_MANUAL', 'R_FALLBACK', 'R_NONE', 'R_TYPED_NEXT', 'HINT', 'MOVED_TITLE', 'MOVED_HINT', 'MOVED_OLD', 'MOVED_PAGE',
        'MOVED_AT', 'MOVED_WHEN', 'MOVED_NONE', 'MOVED_HIDDEN', 'MOVED_CATEGORY', 'EDIT', 'DELETE_CONFIRM'];

    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_system_mertools.notfound', 'plg_system_mertools/mertools-notfound.js', [], ['defer' => true]);
        $texts = [];
        foreach (self::UI as $key) {
            $texts[$key] = Text::_('PLG_SYSTEM_MERTOOLS_NF_UI_' . $key);
        }
        $data = ['url' => Uri::base(true) . '/index.php', 'token' => Session::getFormToken(), 'texts' => $texts];

        return '<div class="mt-notfound" data-mt-notfound="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
