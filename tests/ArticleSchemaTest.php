<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/JsonLd.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/ArticleSchema.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\ArticleSchema;
use Merserwis\Plugin\System\MerTools\Tool\JsonLd;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$waw = new DateTimeZone('Europe/Warsaw');
$check('off by default', ArticleSchema::enabled(new Registry()), false);
$check('ISO date, summer time', ArticleSchema::iso('2020-06-12 09:30:00', $waw), '2020-06-12T09:30:00+02:00');
$check('ISO date, winter time', ArticleSchema::iso('2015-12-09 00:00:00', $waw), '2015-12-09T00:00:00+01:00');
$check('no date', [ArticleSchema::iso('0000-00-00 00:00:00', $waw), ArticleSchema::iso('', $waw), ArticleSchema::iso(null, $waw), ArticleSchema::iso('soon', $waw)], [null, null, null, null]);
$check('UTC time in the site zone', ArticleSchema::isoTime(1792747694, $waw), '2026-10-23T11:28:14+02:00');

$org = ['id' => 'https://x.pl/#organization', 'name' => 'Merserwis', 'url' => 'https://x.pl/', 'logo' => 'https://x.pl/logo.png'];
$d   = ['url' => 'https://x.pl/blog/a', 'headline' => 'Hałas "na" stanowisku', 'images' => ['https://x.pl/i.jpg'], 'description' => 'Opis',
    'published' => '2020-06-12T09:30:00+02:00', 'modified' => '2026-10-20T08:00:00+02:00', 'authors' => [], 'organization' => $org, 'language' => 'pl-PL'];
$original = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'author' => ['@type' => 'Person', 'name' => 'Merserwis'], 'datePublished' => '12 czerwiec 2020', 'keywords' => 'hałas'];
$p = ArticleSchema::build($d, $original);
$check('ISO dates replace the words', [$p['datePublished'], $p['dateModified']], ['2020-06-12T09:30:00+02:00', '2026-10-20T08:00:00+02:00']);
$check('no authors: the organization by its @id', $p['author'], ['@type' => 'Organization', '@id' => 'https://x.pl/#organization', 'name' => 'Merserwis', 'url' => 'https://x.pl/']);
$check('publisher with the @id and the logo', [$p['publisher']['@id'], $p['publisher']['logo']['url']], ['https://x.pl/#organization', 'https://x.pl/logo.png']);
$check('language, url, page', [$p['inLanguage'], $p['url'], $p['mainEntityOfPage']['@id']], ['pl-PL', 'https://x.pl/blog/a', 'https://x.pl/blog/a']);
$check('a key of the post kept', $p['keywords'], 'hałas');
$check('the type of the post kept', ArticleSchema::build($d, ['@type' => 'NewsArticle'])['@type'], 'NewsArticle');
$check('unknown type: BlogPosting', ArticleSchema::build($d, ['@type' => 'Thing'])['@type'], 'BlogPosting');
$check('one author: a Person', ArticleSchema::build(['authors' => ['Jan Kowalski']] + $d, null)['author'], ['@type' => 'Person', 'name' => 'Jan Kowalski']);
$check('two authors: a list', count(ArticleSchema::build(['authors' => ['A', 'B']] + $d, null)['author']), 2);
$check('changed before published: no dateModified', isset(ArticleSchema::build(['modified' => '2019-01-01T00:00:00+01:00'] + $d, null)['dateModified']), false);
$check('no logo: none in the publisher', isset(ArticleSchema::build(['organization' => ['logo' => ''] + $org] + $d, null)['publisher']['logo']), false);
$check('logo of the post: an ImageObject or a string', [ArticleSchema::originalLogo(['publisher' => ['logo' => ['url' => 'a.png']]]), ArticleSchema::originalLogo(['publisher' => ['logo' => 'b.png']]), ArticleSchema::originalLogo(null)], ['a.png', 'b.png', '']);
$json = JsonLd::script($p);
$check('the block is valid JSON with the quotes of the title', json_decode(preg_replace('#^<script[^>]*>|</script>$#', '', $json), true)['headline'], 'Hałas "na" stanowisku');

// the two broken posts of merserwis.pl: found and replaced
$nested = "<script type=\"application/ld+json\">\n{\"@type\": \"BlogPosting\", \"mainEntityOfPage\": {\"@id\": \"<script type=\"application/ld+json\">{\"@type\": \"BlogPosting\"}";
$noQuote = "<script type=\"application/ld+json\">\n{\"@type\": \"BlogPosting\", \"mainEntityOfPage\": {\"@id\": https://x.pl/a\"}}\n</script>";
$faq = '<script type="application/ld+json">{"@type":"FAQPage"}</script>';
$check('missing quote: found as unreadable', array_map(fn ($b) => $b[1], JsonLd::find($noQuote . $faq, ArticleSchema::TYPES)), [null]);
$page = JsonLd::replace("<head>$noQuote</head><body>$faq</body>", '<script>NEW</script>', ArticleSchema::TYPES);
$check('replaced, the FAQ kept', $page, "<head><script>NEW</script></head><body>$faq</body>");
$check('nested script: the broken block found', count(JsonLd::find($nested . '</script>"}}' . "\n</script>", ArticleSchema::TYPES)) >= 1, true);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
