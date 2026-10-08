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
 * The option value has its own trap: Gridbox reads the address through Joomla's input filter, which
 * turns HTML entities into characters. An option saved as "GPT-12002 &#128308;" (a red dot) arrives as
 * "GPT-12002 🔴" and never matches either, so the product opens with its default option.
 *
 * And one in Gridbox's script: when the page opens, it reads the chosen options from their
 * data-value attribute, which an option shown as a radio button does not have. A radio button the
 * server selected from the link is then taken as nothing chosen, so the default option replaces it,
 * or "Add to cart" does nothing. markChosenRadios() gives that one radio button its data-value.
 *
 * This reads the raw query string and gives back the parameters under their real names, so Gridbox's
 * own selection code finds them, with "&" in a value written as "&amp;" so that the filter gives back
 * exactly the value of the link. A parameter PHP already has is only touched when its value has an
 * "&" (the same value, written so that the filter keeps it); anything else is left as it is.
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
     * The query-string parameters to give Gridbox: those PHP stored under a different name (under
     * their real names), and those whose value has an "&", each value written for Joomla's filter.
     *
     * @param string $query the raw query string ($_SERVER['QUERY_STRING'], without "?")
     * @param array  $get   what PHP made of it ($_GET)
     *
     * @return array<string, string> real name => value as it has to be stored in the input
     */
    public static function restoredParams(string $query, array $get): array
    {
        // only a name with a space ("+" or %20), a dot or "[" is renamed by PHP; an "&" in a value is %26
        if ($query === '' || !preg_match('/[+.\[]|%20|%2E|%5B|%26/i', $query)) {
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
                || preg_match('/[\x00-\x1F\x7F]/', $name . $value)) {
                continue;
            }
            $renamed = preg_match('/[ .\[]/', $name) && !\array_key_exists($name, $get);
            // a name PHP kept: only a plain value with an "&" (an array or another value is not ours)
            $entity  = !$renamed && str_contains($value, '&') && \is_string($get[$name] ?? null);
            if (!$renamed && !$entity) {
                continue;
            }
            // Joomla's filter decodes HTML entities once: "&amp;#128308;" comes back as "&#128308;".
            // A repeated name: the last one wins, as in PHP.
            $restored[$name] = str_replace('&', '&amp;', $value);
        }

        return $restored;
    }

    /**
     * The page with each radio-button option that the server selected given a data-value, so that
     * Gridbox's script sees it as chosen. Nothing else is changed; a page without one is returned as is.
     *
     * @param string $html the rendered page
     *
     * @return string
     */
    public static function markChosenRadios(string $html): string
    {
        if (!str_contains($html, 'name="variation-')) {
            return $html;
        }

        $marked = preg_replace(
            '#<input(?=[^>]*\btype="radio")(?=[^>]*\bname="variation-\d+")(?=[^>]*\bclass="active")(?![^>]*\bdata-value=)([^>]*?)\bvalue="(\d+)"#',
            '<input$1value="$2" data-value="$2"',
            $html
        );

        return $marked ?? $html;
    }
}
