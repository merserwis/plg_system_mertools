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

    /**
     * The ISO code of the store's default currency (the one marked default in store.currencies.list,
     * else the first, else the single store.currency of older stores), or '' when the store has none.
     *
     * @param array $store the store settings decoded as an array
     */
    public static function code(array $store): string
    {
        $list = \is_array($store['currencies']['list'] ?? null) ? $store['currencies']['list'] : [];
        foreach ($list as $item) {
            if (\is_array($item) && !empty($item['default']) && \is_string($item['code'] ?? null) && $item['code'] !== '') {
                return strtoupper($item['code']);
            }
        }
        foreach ([$list[0]['code'] ?? null, $store['currency']['code'] ?? null] as $code) {
            if (\is_string($code) && $code !== '') {
                return strtoupper($code);
            }
        }

        return '';
    }

    /**
     * The ISO code of the currency a visitor is shown prices in, as Gridbox's StoreHelper::setCurrency
     * picks it on the site: the default; on a multilingual site (language associations on) the one of
     * the page's language; over both, the one chosen in the currency switcher (cookie gridbox-currency).
     *
     * @param array   $store    the store settings decoded as an array
     * @param string  $chosen   the code of the cookie gridbox-currency ('' none)
     * @param ?string $language the tag of the page's language when associations are on, else null
     */
    public static function activeCode(array $store, string $chosen, ?string $language): string
    {
        $active = self::code($store);
        $list   = \is_array($store['currencies']['list'] ?? null) ? $store['currencies']['list'] : [];
        foreach ([['language', (string) $language], ['code', $chosen]] as [$key, $value]) {
            if ($value === '') {
                continue;
            }
            foreach ($list as $item) {
                if (\is_array($item) && (string) ($item[$key] ?? '') === $value && \is_string($item['code'] ?? null) && $item['code'] !== '') {
                    $active = strtoupper($item['code']);
                    break;
                }
            }
        }

        return $active;
    }
}
