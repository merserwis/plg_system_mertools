<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 */

namespace Merserwis\Component\MerTools\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Merserwis\Component\MerTools\Administrator\Helper\Settings;
use Merserwis\Component\MerTools\Administrator\Helper\SettingsFile;

/**
 * The settings in a file: export (a download of every setting, without secrets) and import (a file
 * of another site or an earlier copy, its known settings saved as the pages save them). Both need
 * the right to edit plugins and the form's token, as saving a page does. Extends the display
 * controller, so settings.display is checked as the plain display is.
 */
class SettingsController extends DisplayController
{
    public function export(): void
    {
        $this->checkToken();
        if (!$this->app->getIdentity()?->authorise('core.edit', 'com_plugins') || !Settings::plugin() || !Settings::manifest()) {
            $this->setRedirect(Route::_('index.php?option=com_mertools', false), Text::_('JERROR_ALERTNOAUTHOR'), 'error');

            return;
        }
        $host = (string) Uri::getInstance()->getHost();
        $now  = Factory::getDate();
        $json = SettingsFile::export(Settings::params()->toArray(), SettingsFile::fields(Settings::manifest()->config),
            (string) Settings::manifest()->version, $host, $now->toISO8601());
        $name = 'mertools-settings-' . (preg_replace('/[^a-z0-9.-]+/i', '-', $host) ?: 'site') . '-' . $now->format('Y-m-d') . '.json';
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->sendHeaders();
        echo $json;
        $this->app->close();
    }

    public function import(): void
    {
        $this->checkToken();
        $back = Route::_('index.php?option=com_mertools', false);
        if (!$this->app->getIdentity()?->authorise('core.edit', 'com_plugins') || !Settings::manifest()) {
            $this->setRedirect($back, Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'), 'error');

            return;
        }
        Settings::loadLanguage();
        $file = $this->input->files->get('mt_settings_file', [], 'raw');
        $tmp  = \is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        if ($tmp === '' || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            $this->setRedirect($back, Text::_('COM_MERTOOLS_IMPORT_NO_FILE'), 'warning');

            return;
        }
        $read = (int) ($file['size'] ?? 0) > SettingsFile::MAX_BYTES
            ? ['error' => 'size'] : SettingsFile::read((string) file_get_contents($tmp, false, null, 0, SettingsFile::MAX_BYTES + 1));
        if ($read['error'] !== null) {
            $this->setRedirect($back, Text::_('COM_MERTOOLS_IMPORT_BAD_' . strtoupper($read['error'])), 'error');

            return;
        }
        $fields = SettingsFile::fields(Settings::manifest()->config);
        $pick   = SettingsFile::pick($read['settings'], $fields);
        $result = Settings::import($pick['take'], $fields);
        foreach ($result['errors'] as $error) {
            $this->app->enqueueMessage($error, 'warning');
        }
        if ($pick['unknown'] || $pick['bad']) {
            $this->app->enqueueMessage(Text::sprintf('COM_MERTOOLS_IMPORT_SKIPPED', implode(', ', array_merge($pick['unknown'], $pick['bad']))), 'notice');
        }
        if ($result['count'] > 0) {
            $this->app->enqueueMessage(Text::sprintf('COM_MERTOOLS_IMPORT_DONE', $result['count'], $read['site'] !== '' ? $read['site'] : '?', $read['version'] !== '' ? $read['version'] : '?'));
            $this->app->enqueueMessage(Text::_('COM_MERTOOLS_CHECK_SITE'), 'warning');
        } elseif (!$result['errors']) {
            $this->app->enqueueMessage(Text::_('COM_MERTOOLS_IMPORT_NOTHING'), 'warning');
        }
        $this->setRedirect($back);
    }
}
