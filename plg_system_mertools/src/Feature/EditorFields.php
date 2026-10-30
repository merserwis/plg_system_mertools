<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Fields filled in the Gridbox editor: the SEO and sharing fields of a page or post that are empty when
 * the editor opens get the text set for them, as a rule with Gridbox's data tags ([Page Title],
 * [Site Name], [Intro Text]…), which Gridbox replaces on the page (media/js/mertools-fields.js).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class EditorFields extends Feature
{
    /** setting => the name of the field in the editor (Gridbox replaces data tags in these four, not in the keywords) */
    public const FIELDS = [
        'editor_fill_meta_title'        => 'page_meta_title',
        'editor_fill_meta_description'  => 'page_meta_description',
        'editor_fill_share_title'       => 'share_title',
        'editor_fill_share_description' => 'share_description',
    ];

    public function enabled(): bool
    {
        return (bool) (int) $this->plugin->params->get('editor_fill', 0);
    }

    /** the texts to put in, by the name of the field: the ones set, and none longer than the field takes */
    public function texts(): array
    {
        $texts = [];
        foreach (self::FIELDS as $setting => $name) {
            $text = trim((string) $this->plugin->params->get($setting, ''));
            if ($text !== '') {
                $texts[$name] = mb_substr($text, 0, 300);
            }
        }

        return $texts;
    }

    /** whether a page of an app of this type is one the setting means: pages and blog posts, blog posts only, or all */
    public static function covers(string $where, string $type): bool
    {
        $page = $type === '' || $type === 'single';

        return match ($where) {
            'blog'  => $type === 'blog',
            'all'   => true,
            default => $page || $type === 'blog',
        };
    }

    /** the app type of the page found: '' for a page of no app (app_id 0, nothing to join), null when there is no such page */
    public static function pageType(?object $row): ?string
    {
        return $row === null ? null : (string) ($row->type ?? '');
    }

    public function fillScript(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $id    = $input->getInt('id', 0);
        if (!$this->enabled() || $input->getCmd('option') !== 'com_gridbox' || $input->getCmd('view') !== 'editor' || $id <= 0
            || $input->getCmd('edit_type', '') !== '') {
            return;
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest || !($texts = $this->texts())) {
            return;
        }
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select(['p.id', 'a.type'])
                ->from($db->quoteName('#__gridbox_pages', 'p'))
                ->join('LEFT', $db->quoteName('#__gridbox_app', 'a'), 'a.id = p.app_id')
                ->where('p.id = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $type = self::pageType($db->setQuery($query)->loadObject());
        } catch (\Throwable $e) {
            return;
        }
        if ($type === null || !self::covers((string) $this->plugin->params->get('editor_fill_where', 'pages_blog'), $type)) {
            return;
        }
        $this->plugin->editorScript('fields', ['fields' => $texts]);
    }
}
