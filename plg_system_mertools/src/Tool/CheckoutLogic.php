<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Conditional logic of the fields of Gridbox's checkout form, as Balbooa Forms has it: a rule shows or
 * hides fields when all its conditions on other fields hold (filled, not filled, equal, not equal, greater
 * than, less than, contains, does not contain). The rules are made in the Gridbox editor of the checkout
 * page, in the settings of its checkout form (media/js/mertools-logic.js), and saved with the page in the
 * settings of that element (its key mertools_logic). Here: the rules of the checkout forms of a page,
 * sent to the page with the script that follows them (media/js/mertools-conditions.js).
 *
 * A field with a rule that shows it is hidden until one of those rules holds; a field with a rule that
 * hides it is hidden while one of those holds (hiding wins). A hidden field counts as not filled in the
 * conditions of other rules, is not required, and its value does not go into the order.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class CheckoutLogic
{
    /** the key of the rules in the settings of the checkout form element */
    public const KEY = 'mertools_logic';

    public const OPS = ['filled', 'empty', 'equal', 'not-equal', 'greater', 'less', 'contains', 'not-contains'];

    public const ACTIONS = ['show', 'hide'];

    public static function enabled($params): bool
    {
        return (bool) (int) $params->get('shop_checkout_logic', 1);
    }

    /**
     * The rules of a checkout form element (its settings as saved in the page) that can do something:
     * switched on, with at least one condition and one action on a saved field (a field has its id once
     * the page is saved), in the order they were made.
     *
     * @return list<array{when: list<array{field: int, op: string, value: string}>, then: list<array{action: string, field: int}>}>
     */
    public static function rules($item): array
    {
        $list = \is_array($item) ? ($item[self::KEY] ?? null) : null;
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $rule) {
            if (!\is_array($rule) || !empty($rule['off'])) {
                continue;
            }
            $when = [];
            foreach ((array) ($rule['when'] ?? []) as $c) {
                $field = \is_array($c) ? (int) ($c['field'] ?? 0) : 0;
                $op    = \is_array($c) ? (string) ($c['op'] ?? '') : '';
                if ($field <= 0 || !\in_array($op, self::OPS, true)) {
                    continue;
                }
                $value  = $c['value'] ?? '';
                $when[] = ['field' => $field, 'op' => $op, 'value' => \is_scalar($value) ? mb_substr((string) $value, 0, 1000) : ''];
            }
            $then = [];
            foreach ((array) ($rule['then'] ?? []) as $a) {
                $field  = \is_array($a) ? (int) ($a['field'] ?? 0) : 0;
                $action = \is_array($a) ? (string) ($a['action'] ?? '') : '';
                if ($field > 0 && \in_array($action, self::ACTIONS, true)) {
                    $then[] = ['action' => $action, 'field' => $field];
                }
            }
            if ($when && $then) {
                $out[] = ['when' => $when, 'then' => $then];
            }
        }

        return $out;
    }

    /** the ids of the checkout form elements of a page (item-…), also with classes of their own (Gridbox's "suffix") */
    public static function forms(string $html): array
    {
        preg_match_all('#<div\b[^>]*\bclass="ba-item-checkout-form\b[^"]*"[^>]*\bid="(item-\d+)"#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The page with the rules of its checkout forms: the style that keeps the fields a rule shows hidden
     * until the script has looked at the form (no flash of fields that are to be hidden), the rules and
     * the script. A page without a checkout form, or whose forms have no rules, as it is.
     *
     * @param  array  $items  the settings of the elements of the page, as Gridbox saves them (by id)
     * @param  string $src    the address of the script
     */
    public static function apply(string $html, array $items, string $src, string $nonce = ''): string
    {
        if (str_contains($html, 'id="mertools-conditions-config"')) {
            return $html;
        }
        $config = [];
        $hidden = [];
        foreach (self::forms($html) as $id) {
            $rules = self::rules($items[$id] ?? null);
            if (!$rules) {
                continue;
            }
            $config[$id] = $rules;
            foreach ($rules as $rule) {
                foreach ($rule['then'] as $a) {
                    if ($a['action'] === 'show') {
                        $hidden['#' . $id . ':not(.mt-logic) .ba-checkout-form-fields[data-name="' . $a['field'] . '"]'] = true;
                    }
                }
            }
        }
        if (!$config) {
            return $html;
        }
        $attr = $nonce === '' ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"';
        $css  = '.ba-item-checkout-form .ba-checkout-form-fields.mt-logic-hidden'
            . ($hidden ? ',' . implode(',', array_keys($hidden)) : '') . '{display:none!important}';
        $head = stripos($html, '</head>');
        if ($head !== false) {
            $html = substr_replace($html, '<style id="mertools-conditions"' . $attr . '>' . $css . '</style>', $head, 0);
        }
        $tail = '<script type="application/json" id="mertools-conditions-config">'
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE)
            . '</script><script src="' . htmlspecialchars($src, ENT_QUOTES) . '"' . $attr . ' defer></script>';
        $end  = strripos($html, '</body>');

        return $end === false ? $html . $tail : substr_replace($html, $tail, $end, 0);
    }
}
