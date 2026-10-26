<?php

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Merserwis\Plugin\System\MerTools\Tool\JsonLd;
use Merserwis\Plugin\System\MerTools\Tool\SiteSchema as SchemaTool;

/**
 * Structured data of the site itself (tab Structured data): the organization and the WebSite on the home page (and the
 * organization again on the pages named in the settings, e.g. the contact page), the Service of the pages of a service and
 * the BreadcrumbList of every other page.
 */
final class SiteSchema extends Feature
{
    public function html(string $html): string
    {
        $params = $this->plugin->params;
        if (!SchemaTool::enabled($params) || (function_exists('http_response_code') && (int) http_response_code() >= 400)) {
            return $html;
        }
        try {
            $root    = rtrim(Uri::root(), '/');
            $current = Uri::current();
            $path    = SchemaTool::path($current, $root);
            $home    = $this->isHome($path);
            $orgId   = $root . '/#organization';

            if ((int) $params->get('schema_org', 1)) {
                $own = SchemaTool::lines((string) $params->get('schema_org_pages', ''));
                $own = array_map(static fn (string $p): string => SchemaTool::path($p, $root), $own);
                if ($home || \in_array($path, $own, true)) {
                    $original = JsonLd::find($html, SchemaTool::ORG_TYPES)[0][1] ?? null;
                    $node     = SchemaTool::organization($this->organizationData($root, $orgId), $original);
                    $nodes    = [$node];
                    $types    = SchemaTool::ORG_TYPES;
                    if ($home && (int) $params->get('schema_website', 1)) {
                        $nodes[] = SchemaTool::website($this->websiteData($root, $orgId));
                        $types[] = 'WebSite';
                    }
                    $block = \count($nodes) === 1 ? ['@context' => 'https://schema.org'] + $nodes[0] : ['@context' => 'https://schema.org', '@graph' => $nodes];
                    $html  = JsonLd::replace($html, JsonLd::script($block), $types);
                }
            } elseif ($home && (int) $params->get('schema_website', 1)) {
                $html = JsonLd::replace($html, JsonLd::script(['@context' => 'https://schema.org'] + SchemaTool::website($this->websiteData($root, $orgId))), ['WebSite']);
            }

            $html = $this->service($html, $root, $current, $path, $orgId);

            if (!$home && (int) $params->get('schema_breadcrumbs', 1)) {
                $html = $this->breadcrumbs($html, $root, $current);
            }
        } catch (\Throwable $e) {
        }

        return $html;
    }

    private function isHome(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        $default = $this->plugin->app()->getMenu()->getDefault();

        return $default && $path === strtolower(trim((string) $default->route, '/')) && !$this->plugin->app()->getInput()->getInt('id', 0);
    }

    private function text(string $key, string $default = ''): string
    {
        return trim((string) $this->plugin->params->get($key, $default));
    }

    private function media(string $value, string $root): string
    {
        $value = trim((string) preg_replace('/#.*$/', '', $value));

        return $value === '' ? '' : SchemaTool::absolute($value, $root . '/');
    }

    private function organizationData(string $root, string $orgId): array
    {
        $p    = $this->plugin->params;
        $line = static fn (string $v): array => SchemaTool::lines($v);

        return [
            'id'          => $orgId, 'url' => $root . '/',
            'type'        => $this->text('schema_org_type', 'LocalBusiness'),
            'name'        => $this->text('schema_org_name') ?: $this->text('blog_jsonld_org'),
            'legalName'   => $this->text('schema_org_legal'),
            'description' => $this->text('schema_org_description'),
            'logo'        => $this->media($this->text('schema_org_logo') ?: $this->text('blog_jsonld_logo'), $root),
            'image'       => $this->media($this->text('schema_org_image'), $root),
            'telephone'   => $this->text('schema_org_phone'),
            'email'       => $this->text('schema_org_email'),
            'street'      => $this->text('schema_addr_street'), 'postal' => $this->text('schema_addr_postal'), 'city' => $this->text('schema_addr_city'),
            'region'      => $this->text('schema_addr_region'), 'country' => $this->text('schema_addr_country'),
            'lat'         => $this->text('schema_geo_lat'), 'lng' => $this->text('schema_geo_lng'), 'map' => $this->text('schema_org_map'),
            'vat'         => $this->text('schema_org_vat'), 'krs' => $this->text('schema_org_krs'), 'founded' => $this->text('schema_org_founded'),
            'hours'       => $line((string) $p->get('schema_hours', '')),
            'contacts'    => $line((string) $p->get('schema_contacts', '')),
            'credentials' => $line((string) $p->get('schema_credentials', '')),
            'members'     => $line((string) $p->get('schema_members', '')),
            'knows'       => $line((string) $p->get('schema_knows', '')),
            'sameAs'      => $line((string) $p->get('schema_same_as', '')),
        ];
    }

    private function websiteData(string $root, string $orgId): array
    {
        $app = $this->plugin->app();

        return ['id' => $root . '/#website', 'url' => $root . '/', 'root' => $root . '/',
            'name' => $this->text('schema_site_name') ?: $this->text('schema_org_name') ?: (string) $app->get('sitename', ''),
            'language' => $app->getLanguage()->getTag(), 'publisher' => (int) $this->plugin->params->get('schema_org', 1) ? $orgId : '',
            'search' => $this->text('schema_search')];
    }

    private function service(string $html, string $root, string $current, string $path, string $orgId): string
    {
        $list = SchemaTool::services(SchemaTool::lines((string) $this->plugin->params->get('schema_services', '')), $root);
        if (!$list || !isset($list[$path])) {
            return $html;
        }
        $here    = $list[$path];
        $hub     = SchemaTool::path($this->text('schema_services_hub'), $root);
        $catalog = [];
        if ($hub !== '' && $hub === $path) {
            foreach ($list as $other => $row) {
                if ($other !== $path && $row['name'] !== '') {
                    $catalog[] = [$row['name'], $root . '/' . $other];
                }
            }
        }
        $node = SchemaTool::service([
            'url'         => $current,
            'name'        => $here['name'] ?: SchemaTool::currentName($html),
            'type'        => $here['type'] ?: $this->text('schema_services_type'),
            'description' => $here['description'] ?: SchemaTool::metaDescription($html),
            'provider'    => $orgId, 'area' => $this->text('schema_services_area'), 'audience' => $this->text('schema_services_audience'),
            'catalog'     => $catalog, 'catalogName' => $this->text('schema_services_catalog'),
        ]);

        return JsonLd::replace($html, JsonLd::script(['@context' => 'https://schema.org'] + $node), ['Service']);
    }

    private function breadcrumbs(string $html, string $root, string $current): string
    {
        $trail = SchemaTool::crumbsFromMicrodata($html) ?: SchemaTool::crumbsFromJsonLd($html);
        if (!$trail) {
            foreach ((array) $this->plugin->app()->getPathway()->getPathway() as $item) {
                $link    = trim((string) ($item->link ?? ''));
                $trail[] = [(string) ($item->name ?? ''), $link === '' ? '' : (string) Route::_($link, false, Route::TLS_IGNORE, true)];
            }
        }
        $list = SchemaTool::breadcrumbs($trail, $root, $this->text('schema_home_name') ?: (string) ($this->plugin->app()->getMenu()->getDefault()->title ?? ''), SchemaTool::currentName($html), $current);

        return $list === null ? $html : JsonLd::replace($html, JsonLd::script($list), ['BreadcrumbList']);
    }
}
