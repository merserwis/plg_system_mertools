<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Tool\OmnibusStore;

/**
 * Omnibus in the plugin settings: what the price history holds, Gridbox's own sales and whether the
 * line follows them, and the products on sale with the lowest price shown for them and where that
 * price came from (to check it before the plugin goes).
 */
final class MtomnibusField extends FormField
{
    protected $type = 'Mtomnibus';

    protected function getInput()
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        try {
            try {
                $levels = array_values(array_map('intval', Access::getAuthorisedViewLevels(0))) ?: [1];
                $zone   = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC') ?: 'UTC');
            } catch (\Throwable $ex) {
                [$levels, $zone] = [[1], new \DateTimeZone('UTC')];
            }
            $store = new OmnibusStore(Factory::getContainer()->get(DatabaseInterface::class), $levels, $zone);
            $stats = $store->stats();
            $list  = $stats['on_sale'] ? $store->onSaleList() : [];
            $sales = $store->salesOverview(time());
        } catch (\Throwable $ex) {
            return '<div class="alert alert-warning mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_ERROR')) . '</div>';
        }
        $salesHtml = $sales ? self::sales($sales, $e) : '';
        if (!$stats['items']) {
            return '<p class="mb-0">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_EMPTY')) . '</p>' . $salesHtml;
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
        $html .= '</tbody></table>' . $salesHtml;
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

    /** Gridbox's own sales: discount, for what, dates, state and whether the Omnibus line follows them. */
    private static function sales(array $sales, \Closure $e): string
    {
        $date = static function ($local): string {
            $local = trim((string) $local);

            return $local === '' || str_starts_with($local, '0000') || str_starts_with($local, '1000-01-01') ? '' : substr($local, 0, 16);
        };
        $html = '<h4 class="h6 mt-3 mb-1">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES')) . '</h4><p class="small text-muted mb-1">'
            . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_HINT')) . '</p><div class="table-responsive"><table class="table table-sm mb-2"><thead><tr>';
        foreach (['TITLE', 'DISCOUNT', 'APPLIES', 'DATES', 'STATE', 'OMNIBUS'] as $key) {
            $html .= '<th scope="col">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_COL_' . $key)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($sales as $sale) {
            $applies = (string) $sale['applies_to'];
            $for     = match ($applies) {
                '*'                 => Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_APPLIES_ALL'),
                'category'          => Text::sprintf('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_APPLIES_CATEGORY', \count($sale['map'])),
                'product'           => Text::sprintf('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_APPLIES_PRODUCT', \count($sale['map'])),
                'cart'              => Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_APPLIES_CART'),
                default             => $applies,
            };
            $from  = $date($sale['publish_up'] ?? '');
            $to    = $date($sale['publish_down'] ?? '');
            $dates = $from === '' && $to === '' ? Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_ALWAYS') : ($from ?: '…') . ' – ' . ($to ?: '…');
            $state = (string) $sale['state'];
            $badge = ['running' => 'bg-success', 'planned' => 'bg-info', 'ended' => 'bg-secondary', 'off' => 'bg-secondary'][$state] ?? 'bg-secondary';
            $line  = $sale['covered'] ? '<span class="text-success">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_COVERED')) . '</span>'
                : '<span class="text-danger">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_NOT_' . strtoupper((string) $sale['reason']))) . '</span>';
            $html .= '<tr><td>' . $e($sale['title'] !== '' ? $sale['title'] : '#' . $sale['id']) . '</td><td>' . $e($sale['discount'] . ' ' . ((string) $sale['unit'] === '%' ? '%' : $sale['unit']))
                . '</td><td>' . $e($for) . '</td><td>' . $e($dates) . '</td><td><span class="badge ' . $badge . '">'
                . $e(Text::_('PLG_SYSTEM_MERTOOLS_OMNIBUS_UI_SALES_STATE_' . strtoupper($state))) . '</span></td><td>' . $line . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }
}
