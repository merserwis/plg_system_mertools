<?php

/*
 * Even grid of the lists of posts: on only when switched on (off by default), and the style overrules the
 * masonry of Gridbox (rows with no height, the span the script sets inline) for the Blog posts element only.
 */

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/BlogGrid.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\BlogGrid;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$check('enabled: default is off', BlogGrid::enabled(new Registry([])), false);
$check('enabled: on', BlogGrid::enabled(new Registry(['blog_grid' => '1'])), true);
$check('enabled: off', BlogGrid::enabled(new Registry(['blog_grid' => '0'])), false);

$css = BlogGrid::css();
$check('css: rows get the height of the highest post', str_contains($css, 'grid-auto-rows:auto!important'), true);
$check('css: the span of the script is overruled', str_contains($css, 'grid-row-end:auto!important'), true);
$check('css: only the Blog posts element', substr_count($css, '.ba-item-blog-posts .ba-blog-posts-wrapper.ba-masonry-layout') === substr_count($css, '{'), true);
$check('css: braces balance', substr_count($css, '{') === substr_count($css, '}'), true);

exit($fail ? 1 : 0);
