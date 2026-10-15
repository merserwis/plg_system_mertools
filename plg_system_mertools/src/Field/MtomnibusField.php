<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Tool\OmnibusStore;

/**
 * Omnibus in the plugin settings: what the price history holds, and the products on sale with the
 * lowest price shown for them and where that price came from (to check it before the plugin goes).
 */
final class MtomnibusField extends FormField
{
    protected $type = 'Mtomnibus';

    protected function getInput()
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        try {
            $store = new OmnibusStore(Factory::getContainer()->get(DatabaseInterface::class));
            $stats = $store->stats();
            $list  = $stats['on_sale'] ? $store->onSaleList() : [];
        } catch (\Throwable $ex) {
            return '<div class="alert alert-warning mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_ERROR')) . '</div>';
        }
        if (!$stats['items']) {
            return '<p class="mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_EMPTY')) . '</p>';
        }
        $date = static fn ($utc) => $utc ? HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC5')) : '–';
        $rows = [
            'ITEMS'   => $stats['items'],
            'ON_SALE' => $stats['on_sale'],
            'CHANGES' => $stats['changes'],
            'SINCE'   => $date($stats['first']),
        ];
        foreach (['history', 'import', 'regular', 'new'] as $source) {
            if (!empty($stats['sources'][$source])) {
                $rows['SOURCE_' . strtoupper($source)] = $stats['sources'][$source];
            }
        }
        $html = '<table class="table table-sm mb-2"><tbody>';
        foreach ($rows as $key => $value) {
            $html .= '<tr><th scope="row" class="fw-normal">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_' . $key)) . '</th><td>' . $e($value) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($list) {
            $html .= '<details><summary>' . $e(Text::sprintf('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_LIST', \count($list))) . '</summary>'
                . '<div class="table-responsive" style="max-height:28rem;overflow:auto"><table class="table table-sm table-striped mt-2"><thead><tr>';
            foreach (['PRODUCT', 'REGULAR', 'SALE', 'LOWEST', 'FROM', 'REDUCED'] as $key) {
                $html .= '<th scope="col">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_COL_' . $key)) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            $money = static fn ($v) => $v === null ? '–' : number_format((float) $v, 2, ',', ' ');
            foreach ($list as $row) {
                $title = (string) ($row['title'] ?? '') !== '' ? $row['title'] : '#' . $row['product_id'];
                $item  = (string) $row['item'] !== (string) $row['product_id'] ? ' <small class="text-muted">(' . $e($row['item']) . ')</small>' : '';
                $html .= '<tr><td>' . $e($title) . $item . '</td><td>' . $money($row['regular']) . '</td><td>' . $money($row['sale']) . '</td><td><strong>'
                    . $money($row['lowest']) . '</strong></td><td>' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SOURCE_' . strtoupper((string) $row['source'])))
                    . '</td><td>' . $e($date($row['promo_since'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }

        return '<div class="mt-omnibus-panel">' . $html . '</div>';
    }
}
