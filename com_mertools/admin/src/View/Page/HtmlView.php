<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\View\Page;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog;
use Merserwis\Component\MerTools\Administrator\Helper\Settings;
use Merserwis\Plugin\System\MerTools\Field\MtadminField;

/**
 * A page of settings: a tool (its overview first, then its settings) or the configuration (a vertical
 * tab for each tool that is set once). The fields are the plugin's own, so their help ("?"), their
 * panels and their scripts work as they did in the plugin's settings.
 */
class HtmlView extends BaseHtmlView
{
    protected string $page = '';

    protected ?Form $form = null;

    /** @var array<string, string> fieldset => label */
    protected array $fieldsets = [];

    protected string $active = '';

    protected string $state = '';

    protected ?object $plugin = null;

    protected bool $canSave = false;

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display, setup.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $app             = Factory::getApplication();
        $this->page      = $app->getInput()->getCmd('page', '');
        $this->plugin    = Settings::plugin();
        $this->form      = Settings::form($this->page);
        $this->fieldsets = Settings::fieldsets($this->page);
        $tab             = $app->getInput()->getCmd('tab', '');
        $this->active    = isset($this->fieldsets[$tab]) ? $tab : '';
        $params          = Settings::params();
        $this->state     = Catalog::state(Catalog::page($this->page)['on'] ?? [], $params, Settings::defaults());
        $this->canSave   = $this->getCurrentUser()->authorise('core.edit', 'com_plugins');

        if ($this->form) {
            // values typed before a save that did not pass come back
            $kept = $app->getUserState('com_mertools.page.' . $this->page);
            $this->form->bind(['params' => \is_array($kept) ? array_replace($params->toArray(), $kept) : $params->toArray()]);
        }

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_mertools');
        $wa->useStyle('com_mertools.admin')->useScript('keepalive')->useScript('form.validate');
        if (class_exists(MtadminField::class)) {
            MtadminField::assets($this->getDocument());
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $icon = Catalog::page($this->page)['icon'] ?? 'tools';
        ToolbarHelper::title(Text::_('COM_MERTOOLS') . ': ' . Text::_('COM_MERTOOLS_PAGE_' . strtoupper($this->page)), $icon);
        $toolbar = $this->getDocument()->getToolbar();
        if ($this->canSave && $this->form) {
            $toolbar->apply('page.apply');
            $toolbar->save('page.save');
        }
        $toolbar->cancel('page.cancel', 'JTOOLBAR_CLOSE');
    }
}
