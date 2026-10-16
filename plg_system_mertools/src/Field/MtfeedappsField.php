<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Tool\Feed;

/** The Gridbox apps that can have a feed (blogs first), for the choice of the blogs with a feed. */
final class MtfeedappsField extends ListField
{
    protected $type = 'Mtfeedapps';

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $apps = (new Feed(Factory::getContainer()->get(DatabaseInterface::class)))->feedApps();
        } catch (\Throwable $e) {
            // Gridbox not installed: nothing to choose
            return $options;
        }
        uasort($apps, static fn (array $a, array $b): int => [(string) $a['type'] !== 'blog', (string) $a['title']] <=> [(string) $b['type'] !== 'blog', (string) $b['title']]);
        foreach ($apps as $id => $app) {
            $text = (string) $app['title'] . ((string) $app['type'] !== 'blog' ? ' (' . $app['type'] . ')' : '');
            $options[] = (object) ['value' => (string) $id, 'text' => $text, 'disable' => false];
        }

        return $options;
    }
}
