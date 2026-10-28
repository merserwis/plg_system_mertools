<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/JsonLd.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/SiteSchema.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\SiteSchema as S;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$root = 'https://x.pl';

$check('off by default', S::enabled(new Registry()), false);
$check('on', S::enabled(new Registry(['schema_on' => '1'])), true);
$check('lines: blanks and # notes out', S::lines("a\n\n# note\r\n  b  \n"), ['a', 'b']);
$check('cells padded', S::cells('a | b', 4), ['a', 'b', '', '']);
$check('absolute: relative', S::absolute('oferta/a', $root), 'https://x.pl/oferta/a');
$check('absolute: root-relative', S::absolute('/oferta', 'https://x.pl/sub'), 'https://x.pl/oferta');
$check('absolute: full stays', S::absolute('https://y.pl/a', $root), 'https://y.pl/a');
$check('absolute: entities', S::absolute('/a?b=1&amp;c=2', $root), 'https://x.pl/a?b=1&c=2');
$check('path: below the root', S::path('https://x.pl/Firma/Kontakt/?a=1#b', $root), 'firma/kontakt');
$check('path: index.php/', S::path('https://x.pl/index.php/blog', $root), 'blog');
$check('path: home', S::path('https://x.pl/', $root), '');
$check('path: bare', S::path('/firma/kontakt/', $root), 'firma/kontakt');

// hours
$check('hours: range', S::hours(['Mo-Fr 8:00-16:00']), [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '08:00', 'closes' => '16:00']]);
$check('hours: Polish, list and one', S::hours(['Pn,Śr 09:00-17:30', 'So 10:00–14:00']), [
    ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Wednesday'], 'opens' => '09:00', 'closes' => '17:30'],
    ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Saturday', 'opens' => '10:00', 'closes' => '14:00']]);
$check('hours: wrap Fr-Mo', S::hours(['Fr-Mo 08:00-10:00'])[0]['dayOfWeek'], ['Friday', 'Saturday', 'Sunday', 'Monday']);
$check('hours: bad lines out', S::hours(['Xx 08:00-16:00', 'Mo 25:00-26:00', 'Mo 8-16', 'nonsense']), []);

// contacts, credentials, members
$c = S::contacts(['+48 22 000 00 00 | sales | pl,en | a@x.pl', '+48 22 111 11 11', '| | |']);
$check('contacts: two, third out', count($c), 2);
$check('contacts: full', $c[0], ['@type' => 'ContactPoint', 'contactType' => 'sales', 'telephone' => '+48 22 000 00 00', 'email' => 'a@x.pl', 'availableLanguage' => ['pl', 'en']]);
$check('contacts: default kind', $c[1]['contactType'], 'customer service');
$check('credential', S::credentials(['AP 1 | PCA | https://www.pca.gov.pl/']), [['@type' => 'EducationalOccupationalCredential', 'name' => 'AP 1', 'credentialCategory' => 'certificate',
    'recognizedBy' => ['@type' => 'Organization', 'name' => 'PCA', 'url' => 'https://www.pca.gov.pl/']]]);
$check('credential: no issuer', isset(S::credentials(['AP 1'])[0]['recognizedBy']), false);
$check('members', S::members(['POLLAB', 'X | notaurl']), [['@type' => 'Organization', 'name' => 'POLLAB'], ['@type' => 'Organization', 'name' => 'X']]);

// organization
$base = ['id' => 'https://x.pl/#organization', 'url' => 'https://x.pl/'];
$check('organization: nothing given, nothing invented', S::organization($base, null), ['@type' => 'Organization', '@id' => 'https://x.pl/#organization', 'url' => 'https://x.pl/']);
$d = $base + ['type' => 'LocalBusiness', 'name' => 'Firma', 'logo' => 'https://x.pl/l.png', 'street' => 'Andersa 1', 'city' => 'Warszawa', 'postal' => '00-001', 'countryFallback' => 'PL',
    'lat' => '52,2297', 'lng' => '21.0122', 'vat' => 'PL123', 'krs' => '0000000001', 'founded' => '1990', 'map' => 'https://maps.google.com/?cid=1',
    'sameAs' => ['https://facebook.com/f', 'javascript:x', 'twitter'], 'knows' => ['a', ' ', 'b']];
$o = S::organization($d, null);
$check('organization: type and id', [$o['@type'], $o['@id']], ['LocalBusiness', 'https://x.pl/#organization']);
$check('organization: logo as image object', $o['logo'], ['@type' => 'ImageObject', 'url' => 'https://x.pl/l.png']);
$check('organization: address with the country of the site language', $o['address'], ['@type' => 'PostalAddress', 'streetAddress' => 'Andersa 1', 'postalCode' => '00-001', 'addressLocality' => 'Warszawa', 'addressCountry' => 'PL']);
$check('organization: no country typed and none from the language: none invented', S::organization(['street' => 'Main St 1', 'city' => 'Leeds'] + $base, null)['address'],
    ['@type' => 'PostalAddress', 'streetAddress' => 'Main St 1', 'addressLocality' => 'Leeds']);
$check('organization: a typed country wins', S::organization(['street' => 'Main St 1', 'country' => 'GB', 'countryFallback' => 'PL'] + $base, null)['address']['addressCountry'], 'GB');
$check('organization: geo (comma accepted)', $o['geo'], ['@type' => 'GeoCoordinates', 'latitude' => 52.2297, 'longitude' => 21.0122]);
$check('organization: ids', [$o['vatID'], $o['identifier'], $o['foundingDate']], ['PL123', ['@type' => 'PropertyValue', 'propertyID' => 'KRS', 'value' => '0000000001'], '1990']);
$check('organization: sameAs only addresses', $o['sameAs'], ['https://facebook.com/f']);
$check('organization: knowsAbout', $o['knowsAbout'], ['a', 'b']);
$check('organization: a bad type -> Organization', S::organization($base + ['type' => 'Evil'], null)['@type'], 'Organization');
$check('organization: bad founded/geo left out', array_intersect_key(S::organization($base + ['founded' => 'yesterday', 'lat' => '99', 'lng' => '1'], null), ['foundingDate' => 1, 'geo' => 1]), []);
$orig = ['@context' => 'https://schema.org', '@type' => 'Organization', '@id' => 'https://x.pl/#organization', 'name' => 'Stara', 'logo' => 'https://x.pl/dead.png',
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Stara 5', 'addressLocality' => 'Łódź', 'postalCode' => '90-001'], 'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 1, 'longitude' => 2],
    'contactPoint' => [['@type' => 'ContactPoint', 'telephone' => '1']]];
$m = S::organization($base + ['name' => 'Nowa', 'city' => 'Warszawa'], $orig);
$check('merge: setting wins', [$m['name'], $m['address']['addressLocality']], ['Nowa', 'Warszawa']);
$check('merge: what the settings do not say stays', [$m['logo'], $m['address']['streetAddress'], $m['address']['postalCode'], $m['geo']['latitude'], $m['contactPoint'][0]['telephone']], ['https://x.pl/dead.png', 'Stara 5', '90-001', 1, '1']);
$check('merge: no @context inside', isset($m['@context']), false);
$check('merge: foreign block is not taken', isset(S::organization($base, ['@type' => 'Product', 'name' => 'P'])['name']), false);
$check('merge: contact points replaced', S::organization($base + ['contacts' => ['+48 1']], $orig)['contactPoint']['telephone'], '+48 1');

// website
$w = S::website($base + ['id' => 'https://x.pl/#website', 'root' => 'https://x.pl/', 'name' => 'Sklep', 'language' => 'pl-PL', 'publisher' => 'https://x.pl/#organization', 'search' => '/szukaj?query={search_term_string}']);
$check('website: search action', $w['potentialAction'], ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'https://x.pl/szukaj?query={search_term_string}'], 'query-input' => 'required name=search_term_string']);
$check('website: publisher by @id', $w['publisher'], ['@id' => 'https://x.pl/#organization']);
$check('website: no placeholder, no search', isset(S::website(['id' => 'a', 'url' => 'b', 'root' => 'c', 'search' => '/szukaj'])['potentialAction']), false);

// services
$sv = S::services(['wzorcowanie | Wzorcowanie | Usługa | Opis', 'https://x.pl/Kalibracja/ ', '| bez adresu'], $root);
$check('services: by path', array_keys($sv), ['wzorcowanie', 'kalibracja']);
$check('services: cells', $sv['wzorcowanie'], ['name' => 'Wzorcowanie', 'type' => 'Usługa', 'description' => 'Opis']);
$n = S::service(['url' => 'https://x.pl/w', 'name' => 'W', 'type' => 'T', 'description' => 'D', 'provider' => 'https://x.pl/#organization', 'area' => 'Polska', 'audience' => 'Firmy',
    'catalog' => [['A', 'https://x.pl/a'], ['', 'https://x.pl/b']]]);
$check('service: keys', array_keys($n), ['@type', '@id', 'name', 'serviceType', 'description', 'url', 'provider', 'areaServed', 'audience', 'hasOfferCatalog']);
$check('service: provider by @id, no price', [$n['provider'], isset($n['offers'])], [['@id' => 'https://x.pl/#organization'], false]);
$check('service: catalog skips nameless', count($n['hasOfferCatalog']['itemListElement']), 1);
$check('service: minimal', array_keys(S::service(['url' => 'https://x.pl/w', 'name' => 'W', 'provider' => 'p'])), ['@type', '@id', 'name', 'url', 'provider']);

// breadcrumbs
$gb = '<ul itemscope itemtype="https://schema.org/BreadcrumbList"><li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class=""><a itemprop="item" href="/"><meta itemprop="name" content="Start"><meta itemprop="position" content="1"></a></li>'
    . '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="/oferta"><span itemprop="name">Oferta &amp; usługi</span><i class="sep"></i></a><meta itemprop="position" content="2"></li>'
    . '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class="active"><span itemprop="name">Mierniki</span><meta itemprop="position" content="3"></li></ul>';
$check('microdata: trail', S::crumbsFromMicrodata('<nav>' . $gb . '</nav>'), [['Start', '/'], ['Oferta & usługi', '/oferta'], ['Mierniki', '']]);
$check('microdata: none', S::crumbsFromMicrodata('<ul><li>x</li></ul>'), []);
$ld = '<script type="application/ld+json">{"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":2,"item":{"@id":"https://x.pl/a","name":"A"}},{"@type":"ListItem","position":1,"name":"Start","item":"https://x.pl/"}]}</script>';
$check('json-ld: trail ordered by position', S::crumbsFromJsonLd($ld), [['Start', 'https://x.pl/'], ['A', 'https://x.pl/a']]);
$check('current name: h1 first', S::currentName('<title>T | Site</title><h1 class="x">  Moja <b>strona</b> </h1>'), 'Moja strona');
$check('current name: og:title', S::currentName('<title>T</title><meta property="og:title" content="OG &amp; co">'), 'OG & co');
$check('current name: title', S::currentName('<title>T | Site</title>'), 'T | Site');
$check('meta description', S::metaDescription('<meta name="description" content="Opis &quot;x&quot;">'), 'Opis "x"');

$cur = 'https://x.pl/oferta/mierniki';
$b = S::breadcrumbs(S::crumbsFromMicrodata($gb), $root, 'Start', 'Mierniki', $cur);
$check('breadcrumbs: absolute, last without item', array_map(fn ($i) => [$i['position'], $i['name'], $i['item'] ?? null], $b['itemListElement']),
    [[1, 'Start', 'https://x.pl/'], [2, 'Oferta & usługi', 'https://x.pl/oferta'], [3, 'Mierniki', null]]);
$check('breadcrumbs: header', [$b['@type'], $b['@id']], ['BreadcrumbList', $cur . '#breadcrumb']);
$b = S::breadcrumbs([['Start', '/'], ['Oferta', '/oferta']], $root, 'Start', 'Mierniki', $cur);
$check('breadcrumbs: current page added (Gridbox leaves it out)', array_map(fn ($i) => [$i['name'], $i['item'] ?? null], $b['itemListElement']),
    [['Start', 'https://x.pl/'], ['Oferta', 'https://x.pl/oferta'], ['Mierniki', null]]);
$b = S::breadcrumbs([['Oferta', '/oferta'], ['Mierniki', '/oferta/mierniki']], $root, 'Strona główna', 'Mierniki', $cur);
$check('breadcrumbs: home added, current link dropped', array_map(fn ($i) => [$i['name'], $i['item'] ?? null], $b['itemListElement']),
    [['Strona główna', 'https://x.pl/'], ['Oferta', 'https://x.pl/oferta'], ['Mierniki', null]]);
$b = S::breadcrumbs([['Start', '/'], ['Start', '/'], ['Blog', '/blog']], $root, 'Start', 'Wpis', 'https://x.pl/blog/wpis');
$check('breadcrumbs: duplicate neighbours merged', array_column($b['itemListElement'], 'name'), ['Start', 'Blog', 'Wpis']);
$check('breadcrumbs: from the pathway (no trail)', array_column(S::breadcrumbs([], $root, 'Start', 'Kontakt', 'https://x.pl/kontakt')['itemListElement'], 'name'), ['Start', 'Kontakt']);
$check('breadcrumbs: home page -> none', S::breadcrumbs([], $root, 'Start', 'Start', 'https://x.pl/'), null);
$check('breadcrumbs: no name for the page -> none', S::breadcrumbs([], $root, 'Start', '', 'https://x.pl/kontakt'), null);
$b = S::breadcrumbs([['Start', '/'], ['Bez adresu', ''], ['Blog', '/blog']], $root, 'Start', 'Wpis', 'https://x.pl/blog/wpis');
$check('breadcrumbs: a middle item with no address out', array_column($b['itemListElement'], 'name'), ['Start', 'Blog', 'Wpis']);
$check('breadcrumbs: positions', array_column($b['itemListElement'], 'position'), [1, 2, 3]);
$b = S::breadcrumbs([['Firma', '#'], ['Kontakt', '']], $root, 'Start', 'Kontakt', 'https://x.pl/firma/kontakt');
$check('breadcrumbs: a heading of the menu ("#") is not the home page', array_map(fn ($i) => [$i['name'], $i['item'] ?? null], $b['itemListElement']),
    [['Start', 'https://x.pl/'], ['Kontakt', null]]);
$b = S::breadcrumbs([['Start', '/'], ['Firma', 'javascript:void(0)'], ['Kontakt', '']], $root, 'Start', 'Kontakt', 'https://x.pl/firma/kontakt');
$check('breadcrumbs: a javascript: address is no address', array_column($b['itemListElement'], 'name'), ['Start', 'Kontakt']);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
