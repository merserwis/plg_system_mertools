<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\Database\DatabaseInterface;

/** The fields of the products of the Gridbox shop apps that hold a text, for "Availability from the field". */
final class MtproductfieldsField extends ListField
{
    protected $type = 'Mtproductfields';

    private const TYPES = ['text', 'textarea', 'url', 'select', 'radio'];

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = (array) $db->setQuery('SELECT f.id, f.field_type, f.label, f.options, a.title FROM ' . $db->quoteName('#__gridbox_fields', 'f')
                . ' JOIN ' . $db->quoteName('#__gridbox_app', 'a') . ' ON a.id = f.app_id WHERE a.type = ' . $db->quote('products')
                . ' ORDER BY a.title, f.order_list, f.id')->loadAssocList();
        } catch (\Throwable $e) {
            return $options;
        }
        foreach ($rows as $row) {
            if (!\in_array((string) $row['field_type'], self::TYPES, true)) {
                continue;
            }
            // the label shown on the page ("Termin dostawy:") and the field's own name ("Dostawa"), when they differ
            $shown = trim(rtrim((string) $row['label'], ': .'));
            $own   = trim((string) ((json_decode((string) $row['options'], true) ?: [])['label'] ?? ''));
            $name  = $shown === '' ? $own : ($own !== '' && strcasecmp($own, $shown) !== 0 ? $shown . ' / ' . $own : $shown);
            $options[] = (object) ['value' => (string) $row['id'], 'text' => $name . ' (' . $row['title'] . ', ' . $row['field_type'] . ', #' . (int) $row['id'] . ')', 'disable' => false];
        }

        return $options;
    }
}
