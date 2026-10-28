<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * The structured data of the site itself (not of a product or a post): the organization with its address, hours
 * and accreditation, the WebSite with the search box, the BreadcrumbList of a page with the page itself at the end,
 * the Service of the pages of a service. Every function takes what it works on and returns the result; nothing
 * here reads the request or the settings of the site except enabled().
 */
final class SiteSchema
{
    /** the types of the block the home page has for its organization; the new one replaces them */
    public const ORG_TYPES = ['Organization', 'LocalBusiness', 'ProfessionalService', 'Store', 'Corporation', 'OnlineStore', 'ElectronicsStore'];

    /** what the setting offers as the type of the organization */
    public const ORG_CHOICES = ['Organization', 'LocalBusiness', 'ProfessionalService', 'Store'];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const DAY_CODES = ['mo' => 0, 'pn' => 0, 'tu' => 1, 'wt' => 1, 'we' => 2, 'sr' => 2, 'śr' => 2, 'th' => 3, 'cz' => 3, 'fr' => 4, 'pt' => 4,
        'sa' => 5, 'so' => 5, 'su' => 6, 'nd' => 6];

    public static function enabled(Registry $params): bool
    {
        return (bool) (int) $params->get('schema_on', 0);
    }

    /** The non-empty lines of a textarea; a line that starts with # is a note of the editor. */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** A line of cells separated by | , trimmed and padded with empty strings to $n cells. */
    public static function cells(string $line, int $n): array
    {
        return array_pad(array_map('trim', explode('|', $line, $n)), $n, '');
    }

    /** One value or a list of them, the way JSON-LD writes them. */
    private static function one(array $list)
    {
        return \count($list) === 1 ? $list[0] : $list;
    }

    // ------------------------------------------------------------------------------------------ addresses

    public static function absolute(string $url, string $root): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return (parse_url($root, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }
        if ($url[0] === '/') {
            return rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $root), '/') . $url;
        }

        return rtrim($root, '/') . '/' . $url;
    }

    /** The path of an address below the root of the site, lower case, without the slashes at the ends, the query and the fragment. */
    public static function path(string $url, string $root): string
    {
        $url  = (string) preg_replace('/[?#].*$/', '', trim($url));
        $root = rtrim($root, '/');
        if ($root !== '' && stripos($url, $root) === 0) {
            $url = substr($url, \strlen($root));
        } else {
            $url = (string) preg_replace('#^https?://[^/]+#i', '', $url);
        }
        $url = (string) preg_replace('#^/?index\.php(?=/|$)#i', '', $url);

        return strtolower(trim(rawurldecode($url), '/'));
    }

    // ------------------------------------------------------------------------------------------ organization

    /** "Mo-Fr 08:00-16:00" lines → OpeningHoursSpecification; days as Mo Tu We Th Fr Sa Su or Pn Wt Śr Cz Pt So Nd. */
    public static function hours(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(\S+)\s+(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})$/u', trim($line), $m)) {
                continue;
            }
            $days = [];
            foreach (explode(',', $m[1]) as $token) {
                $ends = array_map(static fn (string $d): ?int => self::DAY_CODES[mb_strtolower(trim($d))] ?? null, explode('-', $token, 2));
                if (\in_array(null, $ends, true)) {
                    continue;
                }
                for ($i = $ends[0], $n = 0; $n < 7; $i = ($i + 1) % 7, $n++) {
                    $days[] = self::DAYS[$i];
                    if ($i === $ends[\count($ends) - 1]) {
                        break;
                    }
                }
            }
            $days = array_values(array_unique($days));
            if ($days && (int) $m[2] < 24 && (int) $m[4] < 24 && (int) $m[3] < 60 && (int) $m[5] < 60) {
                $out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => self::one($days),
                    'opens' => sprintf('%02d:%02d', $m[2], $m[3]), 'closes' => sprintf('%02d:%02d', $m[4], $m[5])];
            }
        }

        return $out;
    }

    /** "telephone | type | languages | e-mail" lines → ContactPoint. */
    public static function contacts(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$phone, $type, $languages, $email] = self::cells($line, 4);
            if ($phone === '' && $email === '') {
                continue;
            }
            $point = ['@type' => 'ContactPoint', 'contactType' => $type !== '' ? $type : 'customer service'];
            if ($phone !== '') {
                $point['telephone'] = $phone;
            }
            if ($email !== '') {
                $point['email'] = $email;
            }
            $langs = array_values(array_filter(array_map('trim', explode(',', $languages))));
            if ($langs) {
                $point['availableLanguage'] = self::one($langs);
            }
            $out[] = $point;
        }

        return $out;
    }

    /** "name | issuer | issuer address" lines → EducationalOccupationalCredential (an accreditation, a certificate). */
    public static function credentials(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$name, $issuer, $url] = self::cells($line, 3);
            if ($name === '') {
                continue;
            }
            $credential = ['@type' => 'EducationalOccupationalCredential', 'name' => $name, 'credentialCategory' => 'certificate'];
            if ($issuer !== '') {
                $credential['recognizedBy'] = ['@type' => 'Organization', 'name' => $issuer] + (preg_match('#^https?://#i', $url) ? ['url' => $url] : []);
            }
            $out[] = $credential;
        }

        return $out;
    }

    /** "name | address" lines → Organization (the bodies the company belongs to). */
    public static function members(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$name, $url] = self::cells($line, 2);
            if ($name !== '') {
                $out[] = ['@type' => 'Organization', 'name' => $name] + (preg_match('#^https?://#i', $url) ? ['url' => $url] : []);
            }
        }

        return $out;
    }

    private static function coordinate(string $value, float $limit): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
    }

    /**
     * The organization of the home page. $d has the entries of the settings (empty ones are left out), $original is the block
     * the page had: what the settings do not say stays as it was in it (the address, the coordinates, the contact points…).
     */
    public static function organization(array $d, ?array $original): array
    {
        $original = \is_array($original) && \in_array($original['@type'] ?? null, self::ORG_TYPES, true) ? $original : [];
        unset($original['@context']);
        $get  = static fn (string $k): string => trim((string) ($d[$k] ?? ''));
        $type = \in_array($get('type'), self::ORG_CHOICES, true) ? $get('type') : 'Organization';
        $node = ['@type' => $type, '@id' => (string) $d['id']] + array_diff_key($original, ['@type' => 1, '@id' => 1]);
        $node['url'] = (string) $d['url'];

        $set = static function (string $key, ?string $value) use (&$node): void {
            if ($value !== null && $value !== '') {
                $node[$key] = $value;
            }
        };
        $set('name', $get('name'));
        $set('legalName', $get('legalName'));
        $set('description', $get('description'));
        $set('telephone', $get('telephone'));
        $set('email', $get('email'));
        $set('hasMap', preg_match('#^https?://#i', $get('map')) ? $get('map') : null);
        $set('vatID', $get('vat'));
        $set('foundingDate', preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $get('founded')) ? $get('founded') : null);
        if ($get('logo') !== '') {
            $node['logo'] = ['@type' => 'ImageObject', 'url' => $get('logo')];
        }
        if ($get('image') !== '') {
            $node['image'] = $get('image');
        }
        if ($get('krs') !== '') {
            $node['identifier'] = ['@type' => 'PropertyValue', 'propertyID' => 'KRS', 'value' => $get('krs')];
        }

        $address = array_filter(['streetAddress' => $get('street'), 'postalCode' => $get('postal'), 'addressLocality' => $get('city'),
            'addressRegion' => $get('region'), 'addressCountry' => $get('country')], static fn (string $v): bool => $v !== '');
        if ($address) {
            $before = \is_array($node['address'] ?? null) ? $node['address'] : [];
            $node['address'] = ['@type' => 'PostalAddress'] + $address + array_diff_key($before, ['@type' => 1]);
            // no country typed: the one of the site's language (Feature\SiteSchema), else none
            if ($get('countryFallback') !== '') {
                $node['address'] += ['addressCountry' => $get('countryFallback')];
            }
        }
        $lat = self::coordinate($get('lat'), 90.0);
        $lng = self::coordinate($get('lng'), 180.0);
        if ($lat !== null && $lng !== null) {
            $node['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
        }

        $hours = self::hours((array) ($d['hours'] ?? []));
        if ($hours) {
            $node['openingHoursSpecification'] = self::one($hours);
        }
        $contacts = self::contacts((array) ($d['contacts'] ?? []));
        if ($contacts) {
            $node['contactPoint'] = self::one($contacts);
        }
        $credentials = self::credentials((array) ($d['credentials'] ?? []));
        if ($credentials) {
            $node['hasCredential'] = self::one($credentials);
        }
        $members = self::members((array) ($d['members'] ?? []));
        if ($members) {
            $node['memberOf'] = self::one($members);
        }
        $knows = array_values(array_filter(array_map('trim', (array) ($d['knows'] ?? []))));
        if ($knows) {
            $node['knowsAbout'] = $knows;
        }
        $same = array_values(array_unique(array_filter(array_map('trim', (array) ($d['sameAs'] ?? [])), static fn (string $u): bool => (bool) preg_match('#^https?://#i', $u))));
        if ($same) {
            $node['sameAs'] = $same;
        }

        return $node;
    }

    /** The WebSite of the home page; the search box only when the address of the search is known. */
    public static function website(array $d): array
    {
        $node = ['@type' => 'WebSite', '@id' => (string) $d['id'], 'url' => (string) $d['url']];
        if (trim((string) ($d['name'] ?? '')) !== '') {
            $node['name'] = trim((string) $d['name']);
        }
        if (trim((string) ($d['language'] ?? '')) !== '') {
            $node['inLanguage'] = trim((string) $d['language']);
        }
        if (!empty($d['publisher'])) {
            $node['publisher'] = ['@id' => (string) $d['publisher']];
        }
        $search = trim((string) ($d['search'] ?? ''));
        if ($search !== '' && str_contains($search, '{search_term_string}')) {
            $node['potentialAction'] = ['@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => self::absolute($search, (string) $d['root'])],
                'query-input' => 'required name=search_term_string'];
        }

        return $node;
    }

    // ------------------------------------------------------------------------------------------ services

    /** "path | name | type of the service | description" lines → [path => [name, type, description]]. */
    public static function services(array $lines, string $root): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$path, $name, $type, $text] = self::cells($line, 4);
            $path = self::path($path, $root);
            if ($path !== '') {
                $out[$path] = ['name' => $name, 'type' => $type, 'description' => $text];
            }
        }

        return $out;
    }

    /**
     * The Service of a page. $d: url, name, type, description, provider (the @id of the organization), area, audience and,
     * for the page that lists the others, catalog = [[name, url], …].
     */
    public static function service(array $d): array
    {
        $node = ['@type' => 'Service', '@id' => (string) $d['url'] . '#service', 'name' => (string) $d['name']];
        foreach (['type' => 'serviceType', 'description' => 'description'] as $from => $to) {
            if (trim((string) ($d[$from] ?? '')) !== '') {
                $node[$to] = trim((string) $d[$from]);
            }
        }
        $node['url']      = (string) $d['url'];
        $node['provider'] = ['@id' => (string) $d['provider']];
        if (trim((string) ($d['area'] ?? '')) !== '') {
            $node['areaServed'] = ['@type' => 'Country', 'name' => trim((string) $d['area'])];
        }
        if (trim((string) ($d['audience'] ?? '')) !== '') {
            $node['audience'] = ['@type' => 'BusinessAudience', 'audienceType' => trim((string) $d['audience'])];
        }
        $catalog = [];
        foreach ((array) ($d['catalog'] ?? []) as [$name, $url]) {
            if ($name !== '' && $url !== '') {
                $catalog[] = ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $name, 'url' => $url]];
            }
        }
        if ($catalog) {
            $node['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => (string) ($d['catalogName'] ?? '') ?: (string) $d['name'], 'itemListElement' => $catalog];
        }

        return $node;
    }

    // ------------------------------------------------------------------------------------------ breadcrumbs

    private static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function attribute(string $tag, string $name): string
    {
        return preg_match('#\b' . $name . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m) ? html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    /** The trail of the breadcrumbs Gridbox writes in the page (microdata): [[name, address or ''], …]; [] when the page has none. */
    public static function crumbsFromMicrodata(string $html): array
    {
        if (!preg_match('#<(ul|ol)\b[^>]*itemtype=["\']https?://schema\.org/BreadcrumbList["\'][^>]*>(.*?)</\1>#is', $html, $list)) {
            return [];
        }
        $trail = [];
        preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $list[2], $items);
        foreach ($items[1] as $item) {
            $url = preg_match('#<a\b[^>]*>#i', $item, $a) ? self::attribute($a[0], 'href') : '';
            $name = '';
            if (preg_match('#<(?:span|a|strong)\b[^>]*itemprop=["\']name["\'][^>]*>(.*?)</(?:span|a|strong)>#is', $item, $n)) {
                $name = self::plain($n[1]);
            } elseif (preg_match('#<meta\b[^>]*itemprop=["\']name["\'][^>]*>#i', $item, $n)) {
                $name = trim(self::attribute($n[0], 'content'));
            }
            if ($name === '') {
                $name = self::plain(preg_replace('#<meta\b[^>]*>#i', '', $item) ?? '');
            }
            if ($name !== '') {
                $trail[] = [$name, $url];
            }
        }

        return $trail;
    }

    /** The trail of a BreadcrumbList block of the page (JSON-LD); the item is an address or an object with @id and name. */
    public static function crumbsFromJsonLd(string $html): array
    {
        $trail = [];
        foreach (JsonLd::find($html, ['BreadcrumbList']) as [, $data]) {
            if (!\is_array($data)) {
                continue;
            }
            $entries = (array) ($data['itemListElement'] ?? []);
            usort($entries, static fn ($a, $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
            foreach ($entries as $entry) {
                $item = $entry['item'] ?? '';
                $url  = \is_array($item) ? (string) ($item['@id'] ?? $item['url'] ?? '') : (string) $item;
                $name = (string) ($entry['name'] ?? (\is_array($item) ? ($item['name'] ?? '') : ''));
                if (trim($name) !== '') {
                    $trail[] = [trim($name), $url];
                }
            }
            if ($trail) {
                break;
            }
        }

        return $trail;
    }

    /** The name of the page itself: its first heading, else og:title, else <title>. */
    public static function currentName(string $html): string
    {
        if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m) && ($name = self::plain($m[1])) !== '') {
            return $name;
        }
        if (preg_match('#<meta\b[^>]*property=["\']og:title["\'][^>]*>#i', $html, $m) && ($name = trim(self::attribute($m[0], 'content'))) !== '') {
            return $name;
        }

        return preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) ? self::plain($m[1]) : '';
    }

    public static function metaDescription(string $html): string
    {
        return preg_match('#<meta\b[^>]*name=["\']description["\'][^>]*>#i', $html, $m) ? trim(self::attribute($m[0], 'content')) : '';
    }

    /**
     * The BreadcrumbList: the home page first, absolute addresses, the page itself last (its name, no address needed there).
     * $trail is [[name, address or ''], …] as the page or Joomla has it. Null when there would be fewer than two items.
     */
    public static function breadcrumbs(array $trail, string $root, string $homeName, string $currentName, string $currentUrl): ?array
    {
        $root    = rtrim($root, '/');
        $home    = $root . '/';
        $current = self::path($currentUrl, $root);
        $items   = [];
        foreach ($trail as [$name, $url]) {
            $name = trim((string) $name);
            $url  = trim((string) $url);
            // "#" or "javascript:" is a heading of the menu with no page of its own: an item with no address
            $url  = $url === '' || $url[0] === '#' || preg_match('#^javascript:#i', $url) ? '' : self::absolute($url, $root);
            if ($name === '') {
                continue;
            }
            $same = $items && $url !== '' && $items[\count($items) - 1][1] !== '' && self::path($url, $root) === self::path($items[\count($items) - 1][1], $root);
            if (!$same) {
                $items[] = [$name, $url];
            }
        }
        if (!$items || self::path($items[0][1], $root) !== '' || $items[0][1] === '') {
            array_unshift($items, [$homeName !== '' ? $homeName : 'Start', $home]);
        }
        $last = $items[\count($items) - 1];
        if ($current === '') {
            return null;
        }
        if ($last[1] === '' || self::path($last[1], $root) === $current) {
            $items[\count($items) - 1] = [$last[0], ''];
        } elseif ($currentName !== '' && $currentName !== $last[0]) {
            $items[] = [$currentName, ''];
        }
        // an item in the middle with no address cannot be an item of the list
        $count = \count($items);
        $items = array_values(array_filter($items, static fn (array $i, int $k): bool => $i[1] !== '' || $k === $count - 1, ARRAY_FILTER_USE_BOTH));
        if (\count($items) < 2) {
            return null;
        }
        $list = [];
        foreach ($items as $i => [$name, $url]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name] + ($url !== '' ? ['item' => $url] : []);
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', '@id' => $currentUrl . '#breadcrumb', 'itemListElement' => $list];
    }
}
