<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Live preview of the dark theme (and sepia) in the plugin settings: a small mock page whose colours
 * follow the palette, the intensity slider, the custom colours and the accent as they are changed in
 * the form, before saving. The colours are computed by media/js/mertools-preview.js with the same
 * formula as DarkMode::shade().
 */

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\MerTools\Tool\DarkMode;

final class MtpreviewField extends FormField
{
    protected $type = 'Mtpreview';

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.preview', 'plg_system_mertools/mertools-preview.js', [], ['defer' => true]);

        $t    = fn (string $k) => htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_DARK_PREVIEW_' . $k), ENT_QUOTES, 'UTF-8');
        $data = htmlspecialchars(json_encode([
            'presets'   => DarkMode::presets(),
            'shades'    => DarkMode::SHADES,
            'intensity' => Text::_('PLG_SYSTEM_MERTOOLS_DARK_PREVIEW_INTENSITY'),
        ]), ENT_QUOTES, 'UTF-8');

        $moon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M20.5 14.3a8.5 8.5 0 0 1-10.8-10.8 0.7 0.7 0 0 0-0.9-0.9 9.8 9.8 0 1 0 12.6 12.6 0.7 0.7 0 0 0-0.9-0.9z"/></svg>';

        return '<style>'
            . '.mt-pv{max-width:640px;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
            . '.mt-pv-bar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:8px}'
            . '.mt-pv-bar button{border:1px solid #c7ccd4;background:#fff;color:#1f2328;border-radius:16px;padding:3px 12px;cursor:pointer;font-size:13px}'
            . '.mt-pv-bar button[aria-pressed="true"]{background:#1f2328;color:#fff;border-color:#1f2328}'
            . '.mt-pv-int{margin-inline-start:auto;font-size:13px;opacity:.8}'
            . '.mt-pv-page{border-radius:10px;overflow:hidden;border:1px solid rgba(128,128,128,.35);background:var(--bg);color:var(--text);transition:background-color .25s,color .25s}'
            . '.mt-pv-head{display:flex;align-items:center;gap:16px;padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border)}'
            . '.mt-pv-head b{color:var(--title);letter-spacing:.08em}.mt-pv-head span{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--title)}'
            . '.mt-pv-head i{margin-inline-start:auto;display:inline-flex;width:28px;height:28px;border:2px solid var(--title);border-radius:50%;align-items:center;justify-content:center;color:var(--title)}'
            . '.mt-pv-body{padding:16px}.mt-pv-body h3{margin:0 0 6px;color:var(--title);font-size:20px}'
            . '.mt-pv-body p{margin:0 0 8px}.mt-pv-body a{color:var(--accent);text-decoration:underline}'
            . '.mt-pv-muted{color:var(--muted);font-size:12px}'
            . '.mt-pv-card{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px;padding:12px 14px;'
            . 'background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 14px var(--shadow)}'
            . '.mt-pv-card:hover{background:var(--hover)}'
            . '.mt-pv-btn{background:var(--accent);color:#1f2328;border:0;border-radius:6px;padding:6px 14px;font-weight:600}'
            . '.mt-pv-foot{padding:10px 16px;background:var(--bg-dark);color:#e8ecf3;font-size:12px}'
            . '</style>'
            . '<div class="mt-pv" data-mt-preview="' . $data . '">'
            . '<div class="mt-pv-bar">'
            . '<button type="button" data-t="dark" aria-pressed="true">' . $t('DARK') . '</button>'
            . '<button type="button" data-t="light" aria-pressed="false">' . $t('LIGHT') . '</button>'
            . '<span class="mt-pv-int"></span></div>'
            . '<div class="mt-pv-page">'
            . '<div class="mt-pv-head"><b>LOGO</b><span>Menu</span><span>Menu</span><i>' . $moon . '</i></div>'
            . '<div class="mt-pv-body"><h3>' . $t('TITLE') . '</h3><p>' . $t('TEXT') . ' <a href="#" onclick="return false">' . $t('LINK') . '</a></p>'
            . '<p class="mt-pv-muted">' . $t('MUTED') . '</p>'
            . '<div class="mt-pv-card"><span>' . $t('CARD') . '</span><button type="button" class="mt-pv-btn">' . $t('BUTTON') . '</button></div></div>'
            . '<div class="mt-pv-foot">© MerTools for Gridbox</div>'
            . '</div></div>';
    }

    protected function getLabel()
    {
        return '';
    }
}
