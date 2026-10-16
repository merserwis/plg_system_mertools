<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Feed.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Feed;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

// settings
$check('off by default', Feed::enabled(new Registry()), false);
$check('both formats by default', Feed::formats(new Registry()), ['rss', 'atom']);
$check('one format', Feed::formats(new Registry(['feed_format' => 'atom'])), ['atom']);
$check('no type: the first offered', Feed::requested(new Registry(), ''), 'rss');
$check('type atom', Feed::requested(new Registry(), 'ATOM'), 'atom');
$check('a format not offered', Feed::requested(new Registry(['feed_format' => 'rss']), 'atom'), null);
$check('an unknown type', Feed::requested(new Registry(), 'json'), null);
$check('apps: none chosen', Feed::apps(new Registry()), []);
$check('apps: chosen (strings, repeated, empty)', Feed::apps(new Registry(['feed_apps' => ['3', '7', '3', '', '0']])), [3, 7]);
$check('apps: a comma list', Feed::apps(new Registry(['feed_apps' => '5,6'])), [5, 6]);
$check('none chosen: every blog', Feed::allowed([], 2, 'blog'), true);
$check('none chosen: not a portfolio', Feed::allowed([], 2, 'portfolio'), false);
$check('chosen: a portfolio too', Feed::allowed([2], 2, 'portfolio'), true);
$check('chosen: another blog not', Feed::allowed([2], 4, 'blog'), false);
$check('never single pages', Feed::allowed([1], 1, 'single'), false);
$check('limit default 20', Feed::limit(new Registry()), 20);
$check('limit clamped', [Feed::limit(new Registry(['feed_limit' => 0])), Feed::limit(new Registry(['feed_limit' => 500]))], [1, 100]);
$check('text mode: unknown → intro', Feed::textMode(new Registry(['feed_text' => 'x'])), 'intro');
$check('head mode default blog', Feed::headMode(new Registry()), 'blog');
$check('ttl default 60', Feed::ttl(new Registry()), 60);

// dates: Gridbox keeps the site's local time
$waw = new DateTimeZone('Europe/Warsaw');
$check('local summer time to UTC', gmdate('c', Feed::time('2026-07-01 12:00:00', $waw)), '2026-07-01T10:00:00+00:00');
$check('saved_time format', gmdate('c', Feed::time('2026-01-15-08-30-00', $waw)), '2026-01-15T07:30:00+00:00');
$check('zero date', Feed::time('0000-00-00 00:00:00', $waw), null);
$check('empty date', Feed::time('', $waw), null);
$check('garbage', Feed::time('soon', $waw), null);

// addresses
$check('relative image', Feed::absolute('images/blog/a b.jpg', 'https://x.pl/'), 'https://x.pl/images/blog/a b.jpg');
$check('rooted image', Feed::absolute('/images/a.jpg', 'https://x.pl'), 'https://x.pl/images/a.jpg');
$check('subfolder site, path with it', Feed::absolute('/shop/images/a.jpg', 'https://x.pl/shop'), 'https://x.pl/shop/images/a.jpg');
$check('absolute kept', Feed::absolute('https://cdn.x.pl/a.png', 'https://x.pl'), 'https://cdn.x.pl/a.png');
$check('protocol-relative', Feed::absolute('//cdn.x.pl/a.png', 'https://x.pl'), 'https://cdn.x.pl/a.png');
$check('image types', [Feed::imageType('a.JPG'), Feed::imageType('a.webp?v=2'), Feed::imageType('a.pdf')], ['image/jpeg', 'image/webp', '']);

// categories: a category and everything under it
$check('subtree', Feed::subtree(1, [1 => 0, 2 => 1, 3 => 2, 4 => 0, 5 => 1]), [1, 2, 5, 3]);
$check('subtree of a leaf', Feed::subtree(4, [1 => 0, 4 => 0]), [4]);
$check('subtree survives a loop', Feed::subtree(1, [1 => 2, 2 => 1]), [1, 2]);

// summaries
$post = ['intro_text' => 'Pomiar <strong>RE &lt; 0,5 Ω</strong>', 'meta_description' => 'Opis "meta" & więcej'];
$check('intro as HTML', Feed::summary($post, 'intro'), 'Pomiar <strong>RE &lt; 0,5 Ω</strong>');
$check('meta escaped', Feed::summary($post, 'meta'), 'Opis &quot;meta&quot; &amp; więcej');
$check('intro empty: meta', Feed::summary(['intro_text' => ' ', 'meta_description' => 'M'], 'intro'), 'M');
$check('meta empty: intro', Feed::summary(['intro_text' => 'I', 'meta_description' => ''], 'meta'), 'I');
$check('none', Feed::summary($post, 'none'), '');
$check('XML text: control chars out, escaped', Feed::esc("a\x01b<c>&\"d"), 'ab&lt;c&gt;&amp;&quot;d');

// the feeds
$channel = ['title' => 'Blog & nowości – Merserwis', 'link' => 'https://x.pl/blog', 'self' => 'https://x.pl/blog?format=feed&type=rss',
    'description' => '', 'language' => 'pl-PL', 'updated' => 1784000000, 'ttl' => 60, 'author' => 'Merserwis'];
$items = [[
    'title' => 'Kamera <akustyczna>', 'link' => 'https://x.pl/blog/kamera?a=1&b=2', 'summary' => '<p>Tekst &amp; więcej</p>',
    'published' => 1783000000, 'updated' => 1783500000, 'authors' => ['Jan Kowalski'], 'categories' => ['Porady'],
    'image' => ['url' => 'https://x.pl/images/k.jpg', 'alt' => 'Kamera', 'type' => 'image/jpeg', 'length' => 12345],
], [
    'title' => 'Bez obrazka', 'link' => 'https://x.pl/blog/b', 'summary' => '', 'published' => null, 'updated' => null,
    'authors' => [], 'categories' => [], 'image' => null,
]];
$rss = Feed::rss($channel, $items);
$doc = new DOMDocument();
$check('RSS is well-formed XML', $doc->loadXML($rss), true);
$x = new DOMXPath($doc);
$x->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
$x->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
$x->registerNamespace('media', 'http://search.yahoo.com/mrss/');
$check('RSS title', $x->evaluate('string(/rss/channel/title)'), 'Blog & nowości – Merserwis');
$check('RSS self link', $x->evaluate('string(/rss/channel/atom:link[@rel="self"]/@href)'), 'https://x.pl/blog?format=feed&type=rss');
$check('RSS description falls back to the title', $x->evaluate('string(/rss/channel/description)'), 'Blog & nowości – Merserwis');
$check('RSS language lower case', $x->evaluate('string(/rss/channel/language)'), 'pl-pl');
$check('RSS two items', (int) $x->evaluate('count(/rss/channel/item)'), 2);
$check('RSS item title', $x->evaluate('string(/rss/channel/item[1]/title)'), 'Kamera <akustyczna>');
$check('RSS item link with &', $x->evaluate('string(/rss/channel/item[1]/link)'), 'https://x.pl/blog/kamera?a=1&b=2');
$check('RSS item HTML: image then text', $x->evaluate('string(/rss/channel/item[1]/description)'), '<p><img src="https://x.pl/images/k.jpg" alt="Kamera"></p><p>Tekst &amp; więcej</p>');
$check('RSS pubDate (RFC 822)', $x->evaluate('string(/rss/channel/item[1]/pubDate)'), gmdate(DATE_RSS, 1783000000));
$check('RSS dc:creator', $x->evaluate('string(/rss/channel/item[1]/dc:creator)'), 'Jan Kowalski');
$check('RSS category', $x->evaluate('string(/rss/channel/item[1]/category)'), 'Porady');
$check('RSS enclosure', $x->evaluate('string(/rss/channel/item[1]/enclosure/@length)') . ' ' . $x->evaluate('string(/rss/channel/item[1]/enclosure/@type)'), '12345 image/jpeg');
$check('RSS media:content', $x->evaluate('string(/rss/channel/item[1]/media:content/@url)'), 'https://x.pl/images/k.jpg');
$check('RSS item without text: no description', (int) $x->evaluate('count(/rss/channel/item[2]/description)'), 0);
$check('RSS item without date: no pubDate', (int) $x->evaluate('count(/rss/channel/item[2]/pubDate)'), 0);
$noLen = $items[0];
$noLen['image']['length'] = 0;
$check('RSS no enclosure without a length', str_contains(Feed::rss($channel, [$noLen]), '<enclosure'), false);

$atom = Feed::atom(['self' => 'https://x.pl/blog?format=feed&type=atom'] + $channel, $items);
$doc  = new DOMDocument();
$check('Atom is well-formed XML', $doc->loadXML($atom), true);
$x = new DOMXPath($doc);
$x->registerNamespace('a', 'http://www.w3.org/2005/Atom');
$check('Atom id = self', $x->evaluate('string(/a:feed/a:id)'), 'https://x.pl/blog?format=feed&type=atom');
$check('Atom updated (RFC 3339)', $x->evaluate('string(/a:feed/a:updated)'), gmdate('Y-m-d\TH:i:s\Z', 1784000000));
$check('Atom feed author = site', $x->evaluate('string(/a:feed/a:author/a:name)'), 'Merserwis');
$check('Atom xml:lang', $doc->documentElement->getAttribute('xml:lang'), 'pl-PL');
$check('Atom two entries', (int) $x->evaluate('count(/a:feed/a:entry)'), 2);
$check('Atom entry published / updated', $x->evaluate('string(/a:feed/a:entry[1]/a:published)') . ' ' . $x->evaluate('string(/a:feed/a:entry[1]/a:updated)'),
    gmdate('Y-m-d\TH:i:s\Z', 1783000000) . ' ' . gmdate('Y-m-d\TH:i:s\Z', 1783500000));
$check('Atom entry without date: updated = feed', $x->evaluate('string(/a:feed/a:entry[2]/a:updated)'), gmdate('Y-m-d\TH:i:s\Z', 1784000000));
$check('Atom summary type html', $x->evaluate('string(/a:feed/a:entry[1]/a:summary/@type)'), 'html');
$check('Atom category term', $x->evaluate('string(/a:feed/a:entry[1]/a:category/@term)'), 'Porady');
$check('Atom enclosure', $x->evaluate('string(/a:feed/a:entry[1]/a:link[@rel="enclosure"]/@href)'), 'https://x.pl/images/k.jpg');
$check('Atom entry author', $x->evaluate('string(/a:feed/a:entry[1]/a:author/a:name)'), 'Jan Kowalski');
$check('invalid UTF-8 still well-formed', (new DOMDocument())->loadXML(Feed::rss($channel, [['title' => "Z\xC3\x28le", 'link' => 'https://x.pl/z'] + $items[1]])), true);

// the link to the full article and the attachment
$check('more: on by default, language text', Feed::more(new Registry(), 'Przeczytaj cały artykuł'), 'Przeczytaj cały artykuł');
$check('more: own text', Feed::more(new Registry(['feed_more_text' => ' Czytaj dalej ']), 'x'), 'Czytaj dalej');
$check('more: off', Feed::more(new Registry(['feed_more' => '0']), 'x'), '');
$check('enclosure off by default', Feed::enclosure(new Registry()), false);
$it = ['more' => 'Przeczytaj cały artykuł', 'image' => ['enclose' => false] + $items[0]['image']] + $items[0];
$rss = Feed::rss($channel, [$it]);
$doc = new DOMDocument();
$doc->loadXML($rss);
$x = new DOMXPath($doc);
$x->registerNamespace('media', 'http://search.yahoo.com/mrss/');
$desc = $x->evaluate('string(/rss/channel/item[1]/description)');
$check('more: a link to the post after the text', str_ends_with($desc, '<p>Tekst &amp; więcej</p><p><a href="https://x.pl/blog/kamera?a=1&amp;b=2" style="display:inline-block;padding:6px 14px;border:1px solid;border-radius:4px;text-decoration:none">Przeczytaj cały artykuł &rarr;</a></p>'), true);
$check('image still above the text', str_starts_with($desc, '<p><img src="https://x.pl/images/k.jpg"'), true);
$check('no enclosure, no media:content', (int) $x->evaluate('count(/rss/channel/item[1]/enclosure)') + (int) $x->evaluate('count(/rss/channel/item[1]/media:content)'), 0);
$check('Atom: no enclosure link either', str_contains(Feed::atom($channel, [$it]), 'rel="enclosure"'), false);
$check('Atom: the link in the summary', str_contains(Feed::atom($channel, [$it]), 'Przeczytaj cały artykuł &amp;rarr;'), true);

// conditional requests
$check('ETag matches', Feed::notModified('"abc"', '"abc"'), true);
$check('weak ETag in a list', Feed::notModified('"abc"', '"x", W/"abc"'), true);
$check('ETag differs', Feed::notModified('"abc"', '"abd"'), false);
$check('no If-None-Match', Feed::notModified('"abc"', ''), false);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
