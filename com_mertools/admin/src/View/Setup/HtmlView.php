<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\View\Setup;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog;
use Merserwis\Component\MerTools\Administrator\Helper\Settings;

/**
 * Quick setup: a few switches in sections, each with what it does in a sentence and whether most sites
 * should have it on ("recommended"), and a button that turns on all the recommended ones at once.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, array<string, array{value: int, recommended: int}>> section => switch => state */
    protected array $sections = [];

    protected bool $canSave = false;

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display, setup.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $params   = Settings::params();
        $defaults = Settings::defaults();
        $store    = Settings::hasStore();
        foreach (Catalog::SETUP as $section => $items) {
            if ($section === 'shop' && !$store) {
                continue;
            }
            foreach ($items as $switch => $item) {
                $this->sections[$section][$switch] = ['value' => Catalog::setupValue($item, $params, $defaults), 'recommended' => (int) $item['recommended']];
            }
        }
        $this->canSave = $this->getCurrentUser()->authorise('core.edit', 'com_plugins');

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_mertools');
        $wa->useStyle('com_mertools.admin')->useStyle('switcher')->useScript('com_mertools.setup')->useScript('keepalive')->useScript('form.validate');

        ToolbarHelper::title(Text::_('COM_MERTOOLS') . ': ' . Text::_('COM_MERTOOLS_MENU_SETUP'), 'wand');
        $toolbar = $this->getDocument()->getToolbar();
        if ($this->canSave) {
            $toolbar->save('setup.save');
        }
        $toolbar->cancel('setup.cancel', 'JTOOLBAR_CLOSE');

        parent::display($tpl);
    }
}
