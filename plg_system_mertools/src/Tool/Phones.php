<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Click to call: phone numbers written as plain text in Gridbox pages (contact rows, footers, product
 * pages) become tel: links, so a tap on a phone dials them. The numbers are found by
 * media/js/mertools-tel.js in the browser, once the page is idle; the HTML of the pages and their
 * content in Gridbox are not changed.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Phones
{
    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('tel_enabled', 1);
    }

    /** The links look like the text around them (or like the site's links), and a number never breaks. */
    public static function css(Registry $params): string
    {
        $css = '.mertools-tel{white-space:nowrap}';
        if ((string) $params->get('tel_style', 'text') !== 'link') {
            $css .= '.mertools-tel{color:inherit!important;text-decoration:none!important;font:inherit}'
                . '.mertools-tel:hover,.mertools-tel:focus-visible{text-decoration:underline!important}';
        }

        return $css;
    }

    public static function jsConfig(Registry $params): array
    {
        return [
            'cc'      => preg_replace('/\D/', '', (string) $params->get('tel_country', '48')),
            'touch'   => (string) $params->get('tel_devices', 'all') === 'touch',
            'exclude' => trim(str_replace(['{', '}', '<', '>', ';'], '', (string) $params->get('tel_exclude', ''))),
        ];
    }
}
