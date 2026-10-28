<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\View\Cpanel;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog;
use Merserwis\Component\MerTools\Administrator\Helper\Settings;

/**
 * The control panel: the tools as tiles in sections (with a dot that says whether each is on), the
 * configuration as a section of its own, the quick setup offered first on a fresh site, and on the side
 * the state of MerTools and the buttons used most.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, list<array>> */
    protected array $tiles = [];

    protected ?object $plugin = null;

    protected bool $isNew = false;

    protected bool $gridbox = false;

    protected bool $store = false;

    protected int $on = 0;

    protected int $total = 0;

    protected string $version = '';

    protected bool $canEdit = false;

    protected bool $canClear = false;

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display, setup.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $this->plugin  = Settings::plugin();
        $params        = Settings::params();
        $defaults      = Settings::defaults();
        $this->store   = Settings::hasStore();
        $this->gridbox = Settings::hasGridbox();
        $this->tiles   = Catalog::tiles($params, $defaults, $this->store);
        $this->isNew   = Settings::isNew($params);
        foreach ($this->tiles as $tiles) {
            foreach ($tiles as $tile) {
                if ($tile['state'] !== '') {
                    $this->total++;
                    $this->on += $tile['state'] === 'on' ? 1 : 0;
                }
            }
        }
        $this->version  = (string) (Settings::manifest()?->version ?? '');
        $user           = $this->getCurrentUser();
        $this->canEdit  = $user->authorise('core.edit.state', 'com_plugins');
        // the button is answered by the plugin: not offered while it is off
        $this->canClear = $user->authorise('core.manage', 'com_cache') && $this->plugin && (int) $this->plugin->enabled;

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_mertools');
        $wa->useStyle('com_mertools.admin');
        if ($this->canClear) {
            // the button "clear the caches" works as the one of the top bar (the plugin's action)
            $this->getDocument()->addScriptOptions('plg_system_mertools.header', [
                'url'   => Uri::base(true) . '/index.php?mertools_action=cache_clear_all',
                'token' => Session::getFormToken(),
                'error' => Text::_('PLG_SYSTEM_MERTOOLS_HEADER_ERROR'),
            ]);
            $wa->registerAndUseScript('plg_system_mertools.header', 'plg_system_mertools/mertools-header.js', [], ['defer' => true]);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_MERTOOLS') . ': ' . Text::_('COM_MERTOOLS_MENU_CPANEL'), 'tools');
        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('wand', 'COM_MERTOOLS_MENU_SETUP')->url('index.php?option=com_mertools&view=setup');
        $toolbar->linkButton('cog', 'COM_MERTOOLS_MENU_CONFIG')->url('index.php?option=com_mertools&view=page&page=config');
    }
}
