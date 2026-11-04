<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/ListSort.php';

use Merserwis\Plugin\System\MerTools\Tool\ListSort;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : 'got=' . var_export($got, true) . ' exp=' . var_export($exp, true));
};

/** The parts of Joomla's DatabaseQuery that ListSort uses. */
final class FakeElement
{
    public function __construct(private array $elements) {}
    public function getElements(): array { return $this->elements; }
}
final class FakeQuery
{
    public string $from;
    public ?FakeElement $order;
    public array $selects = [];
    public function __construct(string $from, array $order) { $this->from = $from; $this->order = $order ? new FakeElement($order) : null; }
    public function clear(string $what): self { if ($what === 'order') { $this->order = null; } return $this; }
    public function select($s): self { $this->selects[] = $s; return $this; }
    public function order($o): self { $this->order = new FakeElement(array_merge($this->order ? $this->order->getElements() : [], (array) $o)); return $this; }
}
$quote = static fn (string $v) => "'" . addslashes($v) . "'";

$check('sortable field', ListSort::sortable('field_20'), true);
$check('sortable tags', ListSort::sortable('tags'), true);
$check('not sortable: Gridbox column', ListSort::sortable('price'), false);
$check('not sortable: injection', ListSort::sortable('field_1;DROP'), false);

// pins and Gridbox's own order kept after them
$q = new FakeQuery('`#__gridbox_pages` AS p', ['p.id DESC']);
$check('pins applied', ListSort::apply($q, [7, 3], null, 'asc'), true);
$check('pins: first pinned has the highest FIELD number', $q->selects, ['FIELD(p.id, 3,7) AS mt_pin']);
$check('pins first, then Gridbox order', $q->order->getElements(), ['mt_pin DESC', 'p.id DESC']);

// Gridbox's pages list (lower-case "as")
$q = new FakeQuery('#__gridbox_pages as p', ['p.title ASC']);
$expr = ListSort::expression('tags', [], $quote);
$check('sort applied', ListSort::apply($q, [], $expr, 'desc'), true);
$check('sort: value, empty last, direction, tie on id', $q->order->getElements(), ['mt_empty ASC', 'mt_sort DESC', 'p.id DESC']);
$check('sort: the value selected (SELECT DISTINCT)', str_contains($q->selects[0], 'AS mt_sort') && str_contains($q->selects[1], 'IS NULL) AS mt_empty'), true);

// another query of the model (the ids of a folder) is left alone
$q = new FakeQuery('#__gridbox_pages', ['id ASC']);
$check('query without alias p untouched', ListSort::apply($q, [1], null, 'asc'), false);
$check('its order kept', $q->order->getElements(), ['id ASC']);
$q = new FakeQuery('#__gridbox_pages AS p', ['p.id DESC']);
$check('nothing to order: untouched', ListSort::apply($q, [], null, 'asc'), false);

// expressions
$check('number field: decimal', str_contains(ListSort::expression('field_5', ['field_type' => 'number'], $quote), 'AS DECIMAL(20,4)'), true);
$sel = ListSort::expression('field_9', ['field_type' => 'select', 'options' => json_encode(['items' => [['key' => 'a1', 'title' => "Zielony"], ['key' => "x'y", 'title' => 'Inny']]])], $quote);
$check('select field: by the title of the option', str_contains($sel, "WHEN 'a1' THEN 'Zielony'"), true);
$check('select field: keys quoted', str_contains($sel, "WHEN 'x\\'y'"), true);
$check('url field: by the label', str_contains(ListSort::expression('field_20', ['field_type' => 'url'], $quote), "JSON_EXTRACT"), true);
$check('field id only digits', str_contains(ListSort::expression('field_20', ['field_type' => 'text'], $quote), 'pf.field_id = 20 '), true);
$opts = json_encode(['items' => [['key' => 'a', 'title' => 'Zebra'], ['key' => 'b', 'title' => 'Alfa']]]);
$cb = ListSort::expression('field_3', ['field_type' => 'checkbox', 'options' => $opts], $quote);
$check('checkbox: by the title of the first ticked option', str_contains($cb, "JSON_EXTRACT") && str_contains($cb, "'$[0]'") && str_contains($cb, "WHEN 'b' THEN 'Alfa'"), true);
$url = ListSort::expression('field_20', ['field_type' => 'url'], $quote);
$check('url field: the address when there is no label', str_contains($url, "'$.link'"), true);
$check('unknown key: null', ListSort::expression('price', [], $quote), null);
$check('creator without history table', str_contains(ListSort::expression('creator', [], $quote), 'mertools_history'), false);
$check('creator with history table', str_contains(ListSort::expression('creator', [], $quote, true), 'mertools_history'), true);

// pins kept
$check('pin: newest first', ListSort::pin([3, 5], 9, true), [9, 3, 5]);
$check('pin again: moves first', ListSort::pin([3, 5, 9], 5, true), [5, 3, 9]);
$check('unpin', ListSort::pin([3, 5, 9], 5, false), [3, 9]);
$check('pins capped', count(ListSort::pin(range(1, 60), 100, true)), ListSort::MAX_PINS);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
