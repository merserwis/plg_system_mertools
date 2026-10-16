<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Feed;

/**
 * The feeds in the plugin settings: each blog with a feed and the addresses of its feeds (as saved).
 * Plain addresses, which work with and without search engine friendly URLs; on the site the head of
 * the blog pages links the friendly ones.
 */
final class MtfeedField extends FormField
{
    protected $type = 'Mtfeed';

    protected function getInput()
    {
        $e      = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $params = new Registry((array) ($this->form ? $this->form->getData()->get('params') : []));
        try {
            $apps = (new Feed(Factory::getContainer()->get(DatabaseInterface::class)))->feedApps();
        } catch (\Throwable $ex) {
            return '<div class="alert alert-warning mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_FEED_UI_ERROR')) . '</div>';
        }
        $chosen = Feed::apps($params);
        $apps   = array_filter($apps, static fn (array $app, $id): bool => Feed::allowed($chosen, (int) $id, (string) $app['type']), ARRAY_FILTER_USE_BOTH);
        if (!$apps) {
            return '<p class="mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_FEED_UI_EMPTY')) . '</p>';
        }
        $root = rtrim(Uri::root(), '/');
        $html = '<table class="table table-sm mb-1"><thead><tr><th scope="col">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_FEED_UI_BLOG')) . '</th><th scope="col">'
            . $e(Text::_('PLG_SYSTEM_MERTOOLS_FEED_UI_ADDRESS')) . '</th></tr></thead><tbody>';
        foreach ($apps as $id => $app) {
            $links = [];
            foreach (Feed::formats($params) as $format) {
                $url     = $root . '/index.php?option=com_gridbox&view=blog&app=' . (int) $id . '&id=0&format=feed&type=' . $format;
                $links[] = '<a href="' . $e($url) . '" target="_blank" rel="noopener">' . ($format === 'atom' ? 'Atom' : 'RSS') . '</a> <code class="small">' . $e($url) . '</code>';
            }
            $off   = (int) $app['published'] ? '' : ' <span class="badge bg-secondary">' . $e(Text::_('JUNPUBLISHED')) . '</span>';
            $html .= '<tr><td>' . $e($app['title']) . $off . '</td><td>' . implode('<br>', $links) . '</td></tr>';
        }

        return '<div class="mt-feed-panel">' . $html . '</tbody></table><p class="small text-muted mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_FEED_UI_HINT')) . '</p></div>';
    }
}
