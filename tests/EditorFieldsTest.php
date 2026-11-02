<?php

/*
 * Fields filled in the editor: which group of settings (pages, blog posts, products) means a page of an app type,
 * and the texts put in — only the fields with a text set in the group, by the name of the field in the Gridbox editor.
 */

define('_JEXEC', 1);
eval('namespace Merserwis\\Plugin\\System\\MerTools\\Extension; class MerTools { public function __construct(public object $params) {} }');
require __DIR__ . '/../plg_system_mertools/src/Feature/Feature.php';
require __DIR__ . '/../plg_system_mertools/src/Feature/EditorFields.php';

use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Feature\EditorFields;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$params = fn (array $d) => new class ($d) { public function __construct(private array $d) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } };
$fields = fn (array $d) => new EditorFields(new MerTools($params($d)));

$check('group: a page', EditorFields::group('single'), 'pages');
$check('group: a page of no app type', EditorFields::group(''), 'pages');
$check('group: a blog post', EditorFields::group('blog'), 'blog');
$check('group: a product', EditorFields::group('products'), 'products');
$check('group: a post of a booking app has none', EditorFields::group('booking'), null);

$check('a page of no app (app_id 0: the join finds no app) is a page', EditorFields::pageType((object) ['id' => 5, 'type' => null]), '');
$check('…and so it is in the pages group', EditorFields::group(EditorFields::pageType((object) ['id' => 5, 'type' => null])), 'pages');
$check('a blog post', EditorFields::pageType((object) ['id' => 6, 'type' => 'blog']), 'blog');
$check('no such page', EditorFields::pageType(null), null);

$check('off by default', $fields([])->enabled(), false);
$check('on', $fields(['editor_fill' => '1'])->enabled(), true);
$check('texts: the ones set in the group, by field name', $fields(['editor_fill_blog_meta_title' => ' [Page Title] | [Site Name] ', 'editor_fill_blog_meta_description' => '',
    'editor_fill_blog_share_title' => '[Page Title]'])->texts('blog'), ['page_meta_title' => '[Page Title] | [Site Name]', 'share_title' => '[Page Title]']);
$check('texts: keywords are plain text of the group', $fields(['editor_fill_products_meta_keywords' => 'merserwis, sklep'])->texts('products'), ['page_meta_keywords' => 'merserwis, sklep']);
$check('texts: a group does not see another one', $fields(['editor_fill_products_meta_title' => 'X'])->texts('pages'), []);
$check('texts: none set', $fields(['editor_fill_pages_meta_title' => '', 'editor_fill_pages_meta_description' => ''])->texts('pages'), []);
$check('texts: no more than 300 characters', mb_strlen($fields(['editor_fill_pages_meta_title' => str_repeat('ż', 400)])->texts('pages')['page_meta_title']), 300);
$check('every group and field has its setting in the manifest', (function () {
    $xml = file_get_contents(__DIR__ . '/../plg_system_mertools/mertools.xml');
    $miss = [];
    foreach (array_keys(EditorFields::GROUPS) as $g) {
        foreach (array_keys(EditorFields::FIELDS) as $f) {
            if (!str_contains($xml, 'name="editor_fill_' . $g . '_' . $f . '"')) {
                $miss[] = $g . '_' . $f;
            }
        }
    }
    return $miss;
})(), []);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
