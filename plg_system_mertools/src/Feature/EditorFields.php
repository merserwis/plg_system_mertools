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
    /** Where the texts are set: the group of the settings => the app types of Gridbox it means ('' is a page of no app). */
    public const GROUPS = [
        'pages'    => ['', 'single'],
        'blog'     => ['blog'],
        'products' => ['products'],
    ];

    /** The end of the setting's name => the name of the field in the Gridbox editor. */
    public const FIELDS = [
        'meta_title'        => 'page_meta_title',
        'meta_description'  => 'page_meta_description',
        'meta_keywords'     => 'page_meta_keywords',
        'share_title'       => 'share_title',
        'share_description' => 'share_description',
    ];

    public function enabled(): bool
    {
        return (bool) (int) $this->plugin->params->get('editor_fill', 0);
    }

    /** The texts of one group (settings editor_fill_<group>_<field>) by the name of the field; fields with no text are left out. */
    public function texts(string $group): array
    {
        $texts = [];
        foreach (self::FIELDS as $suffix => $name) {
            $text = trim((string) $this->plugin->params->get('editor_fill_' . $group . '_' . $suffix, ''));
            if ($text !== '') {
                $texts[$name] = mb_substr($text, 0, 300);
            }
        }

        return $texts;
    }

    /** The group of settings that means a page of this app type (null: none does, e.g. a post of a booking app). */
    public static function group(string $type): ?string
    {
        foreach (self::GROUPS as $group => $types) {
            if (\in_array($type, $types, true)) {
                return $group;
            }
        }

        return null;
    }

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
        if (!$user || $user->guest) {
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
        $group = $type === null ? null : self::group($type);
        if ($group === null || !($texts = $this->texts($group))) {
            return;
        }
        $this->plugin->editorScript('fields', ['fields' => $texts]);
    }
}
