<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Merserwis\Component\MerTools\Administrator\Helper\Catalog;
use Merserwis\Component\MerTools\Administrator\Helper\Settings;

/**
 * Saving a page of settings (page.apply stays on the page, page.save goes back to the control panel),
 * closing it, and turning the plugin on. The right to edit plugins is needed, as in Joomla's plugin
 * manager, and the form's token. Extends the display controller, so page.display is checked as the
 * plain display is.
 */
class PageController extends DisplayController
{
    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);
        $this->registerTask('apply', 'save');
    }

    public function save(): void
    {
        $this->checkToken();
        $page = $this->input->getCmd('page', '');
        $back = 'index.php?option=com_mertools&view=page&page=' . $page;
        if (!Catalog::page($page)) {
            $this->setRedirect(Route::_('index.php?option=com_mertools', false), Text::_('COM_MERTOOLS_ERROR_PAGE'), 'error');

            return;
        }
        if (!$this->app->getIdentity()?->authorise('core.edit', 'com_plugins')) {
            $this->setRedirect(Route::_($back, false), Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'), 'error');

            return;
        }
        Settings::loadLanguage();
        $posted = $this->input->post->get('jform', [], 'array');
        $errors = Settings::savePage($page, (array) ($posted['params'] ?? []));
        if ($errors) {
            // the values typed stay in the form
            $this->app->setUserState('com_mertools.page.' . $page, $posted['params'] ?? []);
            foreach ($errors as $error) {
                $this->app->enqueueMessage($error, 'warning');
            }
            $this->setRedirect(Route::_($back, false));

            return;
        }
        $this->app->setUserState('com_mertools.page.' . $page, null);
        $this->app->enqueueMessage(Text::_('COM_MERTOOLS_SAVED'));
        $this->app->enqueueMessage(Text::_('COM_MERTOOLS_CHECK_SITE'), 'warning');
        $this->setRedirect(Route::_($this->getTask() === 'apply' ? $back : 'index.php?option=com_mertools', false));
    }

    public function cancel(): void
    {
        $this->checkToken();
        $this->app->setUserState('com_mertools.page.' . $this->input->getCmd('page', ''), null);
        $this->setRedirect(Route::_('index.php?option=com_mertools', false));
    }

    /** the plugin was off: on (the control panel offers it) */
    public function enable(): void
    {
        $this->checkToken();
        if (!$this->app->getIdentity()?->authorise('core.edit.state', 'com_plugins')) {
            $this->setRedirect(Route::_('index.php?option=com_mertools', false), Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');

            return;
        }
        $errors = Settings::enable();
        $this->setRedirect(Route::_('index.php?option=com_mertools', false), $errors ? implode(' ', $errors) : Text::_('COM_MERTOOLS_ENABLED'), $errors ? 'error' : 'message');
    }
}
