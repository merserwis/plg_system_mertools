<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Nothing on screen: loads the help tooltips of the settings ("?" beside every option name, its
 * explanation on hover, keyboard focus or a click), and sends "Close" and "Save & Close" back to the
 * Gridbox panel (Joomla's form controller follows a "return" address posted with the form).
 */
final class MtadminField extends FormField
{
    protected $type = 'Mtadmin';

    /** the explanation sits next to its "?" and is shown by CSS: no positioning script, so no administrator template can push it away */
    private const CSS = '.mt-help-wrap{position:relative;display:inline-block;vertical-align:middle;margin-inline-start:.35rem;line-height:1}'
        . '.control-label .mt-help,.mt-help{display:inline-flex;align-items:center;justify-content:center;width:1.15rem;height:1.15rem;margin:0;padding:0;border:1px solid var(--template-bg-dark-30,#adb5bd);border-radius:50%;background:transparent;color:var(--bs-secondary-color,#6c757d);font-size:.72rem;font-weight:700;line-height:1;cursor:help}'
        . '.mt-help:hover,.mt-help:focus-visible,.mt-help-wrap.is-open .mt-help{border-color:var(--template-link-color,#2a69b8);color:var(--template-link-color,#2a69b8);outline:0}'
        . '.mt-help-tip{display:none;position:absolute;z-index:1100;inset-inline-start:-.5rem;top:calc(100% + 6px);width:22rem!important;max-width:calc(100vw - 2rem)!important;min-width:0!important;box-sizing:border-box;padding:.55rem .7rem;border-radius:.375rem;background:#1f2328;color:#fff;font-size:.82rem;font-weight:400;line-height:1.45;text-align:start;white-space:normal;box-shadow:0 .5rem 1.25rem rgba(0,0,0,.25)}'
        . '.mt-help-wrap:hover .mt-help-tip,.mt-help-wrap:focus-within .mt-help-tip,.mt-help-wrap.is-open .mt-help-tip{display:block}'
        . '.mt-help-tip p{margin:0 0 .35rem}.mt-help-tip p:last-child{margin:0}.mt-help-tip a{color:#9ec5fe}.mt-help-tip code{color:#ffd8a8}'
        . '.control-group.mt-has-help .controls>[id$="-desc"]{display:none}';

    /** the help tooltips of the settings, also on the pages of MerTools' administrator (com_mertools) */
    public static function assets(Document $doc): void
    {
        $wa = $doc->getWebAssetManager();
        $wa->addInlineStyle(self::CSS);
        $wa->registerAndUseScript('plg_system_mertools.admin', 'plg_system_mertools/mertools-admin.js', [], ['defer' => true]);
        $doc->addScriptOptions('plg_system_mertools.admin', ['help' => Text::_('PLG_SYSTEM_MERTOOLS_HELP')]);
    }

    public function renderField($options = [])
    {
        self::assets(Factory::getApplication()->getDocument());

        // closing the settings opens the Gridbox panel (when Gridbox is there)
        if (ComponentHelper::isInstalled('com_gridbox') && ComponentHelper::isEnabled('com_gridbox')) {
            return '<input type="hidden" name="return" value="' . base64_encode('index.php?option=com_gridbox') . '">';
        }

        return '';
    }

    protected function getInput()
    {
        return '';
    }
}
