<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\Database\DatabaseInterface;

/** The apps of the Gridbox dashboard, for "Apps that may be deleted" and the apps left out of the sitemap. */
final class MtappsField extends ListField
{
    protected $type = 'Mtapps';

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $apps = (array) $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', 'title', 'type']))->from($db->quoteName('#__gridbox_app'))
                ->order($db->quoteName('title')))->loadAssocList();
        } catch (\Throwable $e) {
            return $options;
        }
        // skip="system_apps": types not listed (Gridbox's own apps, never in the sitemap)
        $skip = array_filter(array_map('trim', explode(',', (string) ($this->element['skip'] ?? ''))));
        foreach ($apps as $app) {
            if (\in_array((string) $app['type'], $skip, true)) {
                continue;
            }
            $options[] = (object) ['value' => (string) $app['id'], 'text' => (string) $app['title'] . ' (' . $app['type'] . ', #' . (int) $app['id'] . ')', 'disable' => false];
        }

        return $options;
    }
}
