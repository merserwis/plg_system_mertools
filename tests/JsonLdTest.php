<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/JsonLd.php';

use Merserwis\Plugin\System\MerTools\Tool\JsonLd;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$block = fn (array $data, string $open = '<script type="application/ld+json">'): string => $open . json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>';
$data  = fn (string $tag): ?array => json_decode(preg_replace('#^<script[^>]*>|</script>$#', '', $tag), true);
$blocks = function (string $html) use ($data): array {
    preg_match_all('#<script\b[^>]*>.*?</script>#s', $html, $m);

    return array_map($data, $m[0]);
};

// one thing in a block: as before
$product = $block(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'x']);
$check('a single Product found, with its @context', JsonLd::find($product, ['Product']), [[$product, ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'x']]]);
$check('a single Product replaced', JsonLd::replace("<head>$product</head>", '<s/>', ['Product']), '<head><s/></head>');

// a @graph (Joomla's System - Schema.org, SEO extensions)
$graph = $block(['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Organization', '@id' => 'https://x.pl/#org', 'name' => 'Old'],
    ['@type' => 'WebSite', '@id' => 'https://x.pl/#web'],
    ['@type' => 'WebPage', '@id' => 'https://x.pl/a#page', 'isPartOf' => ['@id' => 'https://x.pl/#web']],
]], '<script type="application/ld+json" class="joomla-schemaorg">');
$check('graph: the Organization found, without the others', JsonLd::find($graph, ['Organization']), [[$graph, ['@type' => 'Organization', '@id' => 'https://x.pl/#org', 'name' => 'Old']]]);
$check('graph: no Product', JsonLd::find($graph, ['Product']), []);
$page = JsonLd::replace("<head>$graph</head>", '<s/>', ['Organization', 'WebSite']);
$check('graph: the WebPage stays in its block (same tag), ours after it', str_starts_with($page, '<head><script type="application/ld+json" class="joomla-schemaorg">') && str_ends_with($page, "</script>\n<s/></head>"), true);
$check('graph: what is left', $blocks($page), [['@context' => 'https://schema.org', '@graph' => [['@type' => 'WebPage', '@id' => 'https://x.pl/a#page', 'isPartOf' => ['@id' => 'https://x.pl/#web']]]]]);
$only = $block(['@context' => 'https://schema.org', '@graph' => [['@type' => 'BreadcrumbList', 'itemListElement' => []]]]);
$check('graph of nothing else: the block goes', JsonLd::replace("<head>$only</head>", '<s/>', ['BreadcrumbList']), '<head><s/></head>');
$check('graph: nothing of the types, page as it was', JsonLd::replace("<head>$graph</head>", '<s/>', ['Product']), "<head>$graph<s/>\n</head>");

// a list of things, a list of types
$list = $block([['@type' => 'Product', 'name' => 'a'], ['@type' => 'FAQPage']]);
$check('list: the Product found', array_column(JsonLd::find($list, ['Product']), 1), [['@type' => 'Product', 'name' => 'a']]);
$check('list: the FAQ stays', $blocks(JsonLd::replace($list, '<s/>', ['Product'])), [[['@type' => 'FAQPage']]]);
$multi = $block(['@type' => ['Product', 'Thing'], 'name' => 'm']);
$check('a list of types', count(JsonLd::find($multi, ['Product'])), 1);
$check('a list of types, broken JSON: found by its text', JsonLd::find('<script type="application/ld+json">{"@type": ["Thing", "Product"], x}</script>', ['Product'])[0][1], null);
$check('two Products in one block: both found, one replacement', [count(JsonLd::find($block([['@type' => 'Product'], ['@type' => 'Product']]), ['Product'])),
    JsonLd::replace($block([['@type' => 'Product'], ['@type' => 'Product']]), '<s/>', ['Product'])], [2, '<s/>']);

// the first block takes ours, the next ones only lose theirs
$two = "<head>$graph</head><body>$product</body>";
$page = JsonLd::replace($two, '<s/>', ['Organization', 'Product']);
$check('two blocks: ours once, at the first', [substr_count($page, '<s/>'), strpos($page, '<s/>') < strpos($page, '</head>'), str_contains($page, '"Product"')], [1, true, false]);
$check('a nested thing is not one of the block (brand of a Product)', JsonLd::find($block(['@type' => 'Product', 'brand' => ['@type' => 'Organization', 'name' => 'B']]), ['Organization']), []);
$tricky = '<script type="application/ld+json">' . json_encode(['@graph' => [['@type' => 'Product'], ['@type' => 'WebPage', 'name' => '</script><b>']]], JSON_HEX_TAG) . '</script>';
$check('no </script> can end the kept block early', substr_count(JsonLd::replace($tricky, '<s/>', ['Product']), '</script>'), 1);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
