<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The currency of the Gridbox store (symbol, its position, separators, decimals), as Gridbox's own
 * StoreHelper takes it: the default currency of store.currencies.list. Only stores set up before
 * Gridbox had several currencies still have the single store.currency (merserwis.pl has the list only).
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class StoreCurrency
{
    public const DEFAULT = ['symbol' => '', 'position' => '', 'thousand' => ' ', 'separator' => ',', 'decimals' => 2];

    /** From the store settings Gridbox keeps (the JSON of #__gridbox_api, service "store"). */
    public static function from(string $json): array
    {
        $store    = json_decode($json);
        $currency = null;
        $list     = \is_object($store) && \is_object($store->currencies ?? null) && \is_array($store->currencies->list ?? null) ? $store->currencies->list : [];
        foreach ($list as $item) {
            if (\is_object($item) && !empty($item->default)) {
                $currency = $item;
                break;
            }
        }
        $currency = $currency ?? (\is_object($list[0] ?? null) ? $list[0] : null) ?? (\is_object($store->currency ?? null) ? $store->currency : null);

        return $currency ? array_merge(self::DEFAULT, array_intersect_key((array) $currency, self::DEFAULT)) : self::DEFAULT;
    }
}
