<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Categories.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Categories;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$check('off by default', Categories::enabled(new Registry()), false);
$check('on when set', Categories::enabled(new Registry(['layout_categories' => '1'])), true);

// the block as it is in the shop's category layout on merserwis.pl (#__gridbox_app)
$page = "<div class=\"intro-post-info\">\n                                <div class=\"intro-category-description\">Category Description</div>\n                            </div>"
    . '<meta name="description" content="Category Description">';
$check('placeholder found', Categories::hasPlaceholder($page), true);
$desc = '<h2 style="text-align: center;">Kamery akustyczne</h2><p>Opis &amp; więcej</p>';
$out  = Categories::description($page, $desc);
$check('description in the block, as HTML', str_contains($out, '<div class="intro-category-description">' . $desc . '</div>'), true);
$check('only the block (not other text)', str_contains($out, 'content="Category Description"'), true);
$check('a category without description: empty block', str_contains(Categories::description($page, ''), '<div class="intro-category-description"></div>'), true);
$check('$ and \\ in a description kept', str_contains(Categories::description($page, 'Cena $1 \\0 100%'), '>Cena $1 \\0 100%</div>'), true);
$check('a filled block is left alone', Categories::hasPlaceholder('<div class="intro-category-description"><p>Opis</p></div>'), false);
$css = Categories::css();
$check('the plugin rules for category pages', str_contains($css, '.com_gridbox.blog .intro-post-info{margin-top:25px}') && str_contains($css, '.com_gridbox.blog .ba-overlay{pointer-events:none}')
    && str_contains($css, '.ba-app-sub-category.collapse-categories-list:hover a{color:var(--accent)!important}'), true);
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
