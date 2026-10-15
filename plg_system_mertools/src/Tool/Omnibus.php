<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Omnibus: the "lowest price of the last 30 days" line of an Omnibus plugin, shown right.
 *
 * Such a plugin prints a hidden line per product and per version (.omnibus_info, data-omnibus-id =
 * the product id or the version key) and shows them with its own script (scr_omnibus.js), which
 * showed the line of a product with versions all the time — also with no sale, and the product's
 * own line first ("0,00 zł" for a product on request) — and, until a version was chosen, looked for
 * one every half second for as long as the page was open.
 *
 * That script is taken off the page (only that file) and media/js/mertools-omnibus.js shows the
 * line of the product or of the chosen version only while it is on sale (Gridbox shows a sale price
 * then), following each change of version. The script also did one more thing, kept as it was: a
 * category with subcategories in Gridbox's Categories element opens and closes with a click on its
 * whole row instead of opening its page.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Omnibus
{
    /** the Omnibus plugin script that is replaced */
    public const SCRIPT = '/plugins/system/omnibus/assets/scr_omnibus.js';

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('shop_omnibus', 0);
    }

    /** Every line hidden unless MerTools shows it (!important: a copy of the old script could still run). */
    public static function css(): string
    {
        return '.omnibus_info{display:none!important}.omnibus_info.mt-omnibus-on{display:block!important}';
    }

    /** The <script> tag of a file of the site taken off a finished page (with the line break after it). */
    public static function dropScript(string $html, string $path): string
    {
        $pattern = '#<script\b[^>]*\ssrc="[^"]*' . preg_quote($path, '#') . '(?:\?[^"]*)?"[^>]*>\s*</script>[ \t]*\R?#i';

        return preg_replace($pattern, '', $html) ?? $html;
    }
}
