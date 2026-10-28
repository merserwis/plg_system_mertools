<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog;

/**
 * The pages of MerTools: the control panel (default), a tool or the configuration (view=page&page=…)
 * and the quick setup. Who may manage the plugins may see them (the settings are the plugin's).
 */
class DisplayController extends BaseController
{
    protected $default_view = 'cpanel';

    public function display($cachable = false, $urlparams = [])
    {
        if (!$this->app->getIdentity()?->authorise('core.manage', 'com_plugins')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php', false));

            return $this;
        }
        $view = $this->input->getCmd('view', $this->default_view);
        if (!\in_array($view, ['cpanel', 'page', 'setup'], true)) {
            $this->input->set('view', $this->default_view);
        }
        if ($view === 'page' && !Catalog::page($this->input->getCmd('page', ''))) {
            $this->setRedirect(Route::_('index.php?option=com_mertools', false), Text::_('COM_MERTOOLS_ERROR_PAGE'), 'error');

            return $this;
        }

        return parent::display(false, []);
    }
}
