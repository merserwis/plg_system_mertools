<?php

/*
 * Fields filled in the editor: which pages the setting means (pages and blog posts, blog posts only, all),
 * and the texts put in — only the fields with a text set, by the name of the field in the Gridbox editor.
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

$check('pages and blog: a page', EditorFields::covers('pages_blog', 'single'), true);
$check('pages and blog: a page with no app type', EditorFields::covers('pages_blog', ''), true);
$check('pages and blog: a blog post', EditorFields::covers('pages_blog', 'blog'), true);
$check('pages and blog: not a product', EditorFields::covers('pages_blog', 'products'), false);
$check('pages and blog: not a post of another app', EditorFields::covers('pages_blog', 'portfolio'), false);
$check('blog only: a blog post', EditorFields::covers('blog', 'blog'), true);
$check('blog only: not a page', EditorFields::covers('blog', 'single'), false);
$check('all: a product', EditorFields::covers('all', 'products'), true);
$check('an unknown value is the default', EditorFields::covers('x', 'products'), false);

$check('a page of no app (app_id 0: the join finds no app) is a page', EditorFields::pageType((object) ['id' => 5, 'type' => null]), '');
$check('…and so the default setting covers it', EditorFields::covers('pages_blog', EditorFields::pageType((object) ['id' => 5, 'type' => null])), true);
$check('a blog post', EditorFields::pageType((object) ['id' => 6, 'type' => 'blog']), 'blog');
$check('no such page', EditorFields::pageType(null), null);

$check('off by default', $fields([])->enabled(), false);
$check('on', $fields(['editor_fill' => '1'])->enabled(), true);
$check('texts: the ones set, by field name', $fields(['editor_fill_meta_title' => ' [Page Title] | [Site Name] ', 'editor_fill_meta_description' => '',
    'editor_fill_share_title' => '[Page Title]'])->texts(), ['page_meta_title' => '[Page Title] | [Site Name]', 'share_title' => '[Page Title]']);
$check('texts: none set', $fields(['editor_fill_meta_title' => '', 'editor_fill_meta_description' => ''])->texts(), []);
$check('texts: no more than 300 characters', mb_strlen($fields(['editor_fill_meta_title' => str_repeat('ż', 400)])->texts()['page_meta_title']), 300);
$check('texts: keywords are not a field of it', in_array('page_meta_keywords', EditorFields::FIELDS, true), false);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
