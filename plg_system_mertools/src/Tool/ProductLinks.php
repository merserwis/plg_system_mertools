<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Links to a product option: when a customer picks an option of a Gridbox product, Gridbox adds it
 * to the address, e.g. "?Zestawy+Metrel+MI+3155=MI+3155+EurotestXD+ST%2B+…", and on the server it
 * selects the option named in the address (price, SKU, images). That never works when the option
 * group name has a space, a dot or square brackets — which is nearly always: PHP stores such names
 * in $_GET under a different key ("Zestawy_Metrel_MI_3155"; "Długość [m]" even becomes an array),
 * so Gridbox looks for "Zestawy Metrel MI 3155", finds nothing, and the product opens with no option
 * selected.
 *
 * This reads the raw query string and gives back the parameters under their real names, so Gridbox's
 * own selection code finds them. Names PHP keeps unchanged are skipped, and nothing already in $_GET
 * is overwritten.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class ProductLinks
{
    /** more pairs than any product option link has; a longer query string is not a product link */
    private const MAX_PAIRS = 50;

    private const MAX_NAME = 255;

    private const MAX_VALUE = 2000;

    /**
     * The query-string parameters that PHP stored under a different name, with their real names.
     *
     * @param string $query the raw query string ($_SERVER['QUERY_STRING'], without "?")
     * @param array  $get   what PHP made of it ($_GET)
     *
     * @return array<string, string> real name => value, only for names missing from $get
     */
    public static function restoredParams(string $query, array $get): array
    {
        // only a name with a space ("+" or %20), a dot or "[" is renamed by PHP
        if ($query === '' || !preg_match('/[+.\[]|%20|%2E|%5B/i', $query)) {
            return [];
        }

        $pairs = explode('&', $query);
        if (\count($pairs) > self::MAX_PAIRS) {
            return [];
        }

        $restored = [];
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name  = urldecode($name);
            $value = urldecode($value);

            if ($name === '' || \strlen($name) > self::MAX_NAME || \strlen($value) > self::MAX_VALUE
                || !preg_match('/[ .\[]/', $name) || preg_match('/[\x00-\x1F\x7F]/', $name . $value)
                || \array_key_exists($name, $get)) {
                continue;
            }
            // a repeated name: the last one wins, as in PHP
            $restored[$name] = $value;
        }

        return $restored;
    }
}
