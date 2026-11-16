<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The checkout page of Gridbox's store, two things its settings cannot change:
 *  - the drop-down fields of the checkout form: Gridbox takes their inner space at the sides away
 *    (padding-left/right: 0 !important), so their text touches the frame while the text fields keep
 *    theirs; they get the same space as the text fields (--field-padding-left of the form), and are as
 *    high and as wide as them;
 *  - the totals: every price there is written with the store's currency, so a store whose currency is
 *    "zł netto" (prices without tax) showed the total with tax as "5 897,85 zł netto". The rows of the
 *    total (total, paying now, left to pay) say "brutto" instead; the rows of the tax drop the word —
 *    a tax is neither net nor gross. Only a currency with the word "netto" is changed.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class Checkout
{
    /** the rows of the order form whose amount has the tax in it */
    private const GROSS = ['total', 'paying-now', 'pay-leter'];

    /** the rows of the order form whose amount is a tax */
    private const TAX = ['tax', 'shipping-tax', 'includes-tax', 'shipping-includes-tax'];

    /**
     * The browser draws its own arrow of a drop-down field at the frame, whatever the inner space: it is
     * replaced by a chevron of two lines in the colour of the field's text (also in dark mode), as far
     * from the frame as the text is on the left (in high contrast, which shows no background images, the
     * browser's own arrow).
     */
    public const CSS = 'body .ba-item-checkout-form .ba-checkout-form-field-wrapper select{box-sizing:border-box;-webkit-appearance:none;appearance:none;'
        . 'padding-left:var(--field-padding-left)!important;padding-right:calc(var(--field-padding-left) + 20px)!important;'
        . 'height:calc(var(--field-line-height) + var(--field-padding-top) + var(--field-padding-bottom)'
        . ' + (var(--border-top) * var(--border-width)) + (var(--border-bottom) * var(--border-width)));'
        . 'background-image:linear-gradient(45deg,transparent calc(50% - 1px),currentColor calc(50% - 1px),currentColor calc(50% + .5px),transparent calc(50% + .5px)),'
        . 'linear-gradient(-45deg,transparent calc(50% - 1px),currentColor calc(50% - 1px),currentColor calc(50% + .5px),transparent calc(50% + .5px))!important;'
        . 'background-size:6px 6px!important;background-repeat:no-repeat!important;'
        . 'background-position:right calc(var(--field-padding-left) + 5px) center,right var(--field-padding-left) center!important}'
        . 'body .ba-item-checkout-form .ba-checkout-form-field-wrapper select::-ms-expand{display:none}'
        // high contrast (forced colours) drops background images: the browser's own arrow there
        . '@media (forced-colors:active){body .ba-item-checkout-form .ba-checkout-form-field-wrapper select{-webkit-appearance:auto;appearance:auto}}';

    public static function enabled($params): bool
    {
        return (bool) (int) $params->get('shop_checkout_fix', 1);
    }

    /** The checkout page with both changes; any other page as it is. */
    public static function apply(string $html, string $nonce = ''): string
    {
        if (str_contains($html, 'ba-item-checkout-form') && !str_contains($html, 'id="mertools-checkout"')) {
            $head = stripos($html, '</head>');
            if ($head !== false) {
                $attr = $nonce === '' ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"';
                $html = substr_replace($html, '<style id="mertools-checkout"' . $attr . '>' . self::CSS . '</style>', $head, 0);
            }
        }

        return self::currencyWords($html);
    }

    /** The currency of the rows of the total and of the tax (see above). */
    public static function currencyWords(string $html): string
    {
        if (!str_contains($html, 'ba-checkout-order-form-total-wrapper') || stripos($html, 'netto') === false) {
            return $html;
        }
        $rows = implode('|', array_map('preg_quote', array_merge(self::GROSS, self::TAX)));
        $new  = preg_replace_callback(
            '#(<div class="ba-checkout-order-form-row ba-checkout-order-form-(' . $rows . ')"[^>]*>(?:(?!</div>)[^<]|<(?!/div>))*?'
            . '<span class="ba-checkout-order-price-currency"[^>]*>)([^<]*)#',
            static function (array $m): string {
                if (\in_array($m[2], self::GROSS, true)) {
                    $text = preg_replace_callback('#\bnetto\b#iu', static fn (array $w): string => self::gross($w[0]), $m[3]);
                } else {
                    $text = preg_replace('#\s*\bnetto\b#iu', '', $m[3]);
                    // a currency that is only the word keeps it: something has to stand by the amount
                    $text = trim((string) $text) === '' ? $m[3] : $text;
                }

                return $m[1] . ($text ?? $m[3]);
            },
            $html
        );

        return $new ?? $html;
    }

    /** "brutto" written as the word it replaces was: NETTO → BRUTTO, Netto → Brutto. */
    private static function gross(string $word): string
    {
        if ($word === strtoupper($word)) {
            return 'BRUTTO';
        }

        return $word[0] === 'N' ? 'Brutto' : 'brutto';
    }
}
