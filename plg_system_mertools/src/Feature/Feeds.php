<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Blog feeds (RSS / Atom): the feed of a Gridbox blog page (?format=feed&type=rss|atom), sent in onAfterRoute
 * before Gridbox (which has none), and the links to the feeds in the head of the pages (onBeforeCompileHead).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Tool\Feed;

final class Feeds extends Feature
{
    /**
     * A feed of a Gridbox blog page (?format=feed&type=rss|atom): sent here, before Gridbox, which has
     * no feed view and would answer with an error. A blog without a feed (not chosen, not public, a
     * format not offered) gets a 404; with the tool off, Gridbox answers as before.
     */
    public function serveFeed(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!Feed::enabled($this->plugin->params) || $input->getCmd('format') !== 'feed' || $input->getCmd('option') !== 'com_gridbox'
            || $input->getCmd('view') !== 'blog' || !\in_array(strtoupper((string) $input->getMethod()), ['GET', 'HEAD'], true)) {
            return;
        }
        $appId  = $input->getInt('app', 0);
        $catId  = $input->getInt('id', 0);
        $tagId  = $input->getInt('tag', 0);
        $author = $input->getInt('author', 0);
        $format = Feed::requested($this->plugin->params, (string) $input->getCmd('type', ''));
        $store  = new Feed(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            $blog = $store->app($appId);
            $cat  = $blog && $catId ? $store->category($appId, $catId) : null;
            $by   = $tagId ? $store->filter('tag', $tagId) : ($author ? $store->filter('author', $author) : null);
        } catch (\Throwable $e) {
            return;
        }
        if ($format === null || !$blog || !(int) $blog['published'] || (int) $blog['access'] !== 1
            || !Feed::allowed(Feed::apps($this->plugin->params), $appId, (string) $blog['type'])
            || ($catId && !$cat) || (($tagId || $author) && !$by)) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'));
        }

        $zone     = new \DateTimeZone((string) $app->get('offset', 'UTC') ?: 'UTC');
        $now      = (new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');
        $language = Multilanguage::isEnabled() ? $app->getLanguage()->getTag() : null;
        try {
            $posts      = $store->posts($appId, $cat ? $cat['ids'] : [], $tagId, $author, $now, $language, Feed::limit($this->plugin->params));
            $authors    = $store->authorsOf(array_column($posts, 'id'));
            $categories = $store->categoryTitles(array_column($posts, 'page_category'));
        } catch (\Throwable $e) {
            return;
        }

        $mode   = Feed::textMode($this->plugin->params);
        $images = Feed::images($this->plugin->params);
        $attach = Feed::enclosure($this->plugin->params);
        $this->plugin->loadLanguage();
        $more   = Feed::more($this->plugin->params, $this->plugin->translate('PLG_SYSTEM_MERTOOLS_FEED_MORE_DEFAULT'));
        $root   = rtrim(Uri::root(), '/');
        $items  = [];
        $latest = 0;
        foreach ($posts as $post) {
            $published = Feed::time($post['created'], $zone);
            $updated   = max((int) $published, (int) Feed::time($post['saved_time'], $zone)) ?: null;
            $latest    = max($latest, (int) $updated);
            $category  = (int) $post['page_category'];
            $image     = null;
            if ($images && trim((string) $post['intro_image']) !== '') {
                $url   = Feed::absolute((string) $post['intro_image'], $root);
                $image = ['url' => $url, 'alt' => (string) ($post['image_alt'] ?: $post['title']), 'type' => Feed::imageType($url), 'length' => 0, 'enclose' => $attach];
                // the size of a picture of this site, for the RSS enclosure
                if (str_starts_with($url, $root . '/')) {
                    $file = JPATH_ROOT . '/' . rawurldecode((string) parse_url(substr($url, \strlen($root) + 1), PHP_URL_PATH));
                    if (!str_contains($file, '..') && is_file($file)) {
                        $image['length'] = (int) filesize($file);
                    }
                }
            }
            $items[] = [
                'title'      => (string) $post['title'],
                'link'       => $this->plugin->gridboxLink('getGridboxPageLinks', [(int) $post['id'], (string) $blog['type'], $appId, $category],
                    'index.php?option=com_gridbox&view=page&blog=' . $appId . '&category=' . $category . '&id=' . (int) $post['id']),
                'summary'    => Feed::summary($post, $mode),
                'more'       => $more,
                'published'  => $published,
                'updated'    => $updated,
                'authors'    => $authors[(int) $post['id']] ?? [],
                'categories' => isset($categories[$category]) ? [$categories[$category]] : [],
                'image'      => $image,
            ];
        }

        $site  = (string) $app->get('sitename', '');
        $title = array_values(array_unique(array_filter([trim((string) ($cat['title'] ?? $by['title'] ?? '')), trim((string) $blog['title']), trim($site)])));
        $about = '';
        foreach ([$cat['description'] ?? '', $cat['meta_description'] ?? '', $by['description'] ?? '', $blog['description'] ?? '', $blog['meta_description'] ?? ''] as $text) {
            $about = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($about !== '') {
                break;
            }
        }
        $self = Uri::getInstance()->toString();
        $page = clone Uri::getInstance();
        $page->delVar('format');
        $page->delVar('type');
        $channel = [
            'title'       => implode(' – ', $title) ?: $root,
            'link'        => $page->toString(),
            'self'        => $self,
            'description' => mb_substr($about, 0, 500),
            'language'    => $app->getLanguage()->getTag(),
            'updated'     => $latest ?: time(),
            'ttl'         => Feed::ttl($this->plugin->params),
            'author'      => $site,
        ];
        $xml  = $format === 'atom' ? Feed::atom($channel, $items) : Feed::rss($channel, $items);
        $etag = '"' . md5($xml) . '"';
        $ttl  = Feed::ttl($this->plugin->params);

        $app->setHeader('Content-Type', Feed::TYPES[$format] . '; charset=utf-8', true);
        $app->setHeader('ETag', $etag, true);
        $app->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $channel['updated']) . ' GMT', true);
        $app->setHeader('Cache-Control', $ttl ? 'public, max-age=' . ($ttl * 60) : 'no-cache', true);
        $app->allowCache(true);
        $unchanged = Feed::notModified($etag, (string) $input->server->getString('HTTP_IF_NONE_MATCH', ''));
        if ($unchanged) {
            $app->setHeader('Status', '304', true);
        }
        $app->setBody($unchanged || strtoupper((string) $input->getMethod()) === 'HEAD' ? '' : $xml);
        echo $app->toString(!$unchanged && (bool) $app->get('gzip'));
        $app->close();
    }

    /**
     * <link rel="alternate"> to the feeds in the head: on a blog page its own feed (a category, tag or
     * author page: that one), on a post the feed of its blog; with "every page", the feeds of all the
     * blogs on every page.
     */
    public function feedHeadLinks($document): void
    {
        $mode  = Feed::headMode($this->plugin->params);
        $input = $this->plugin->app()->getInput();
        $store = new Feed(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            $apps = $store->feedApps();
        } catch (\Throwable $e) {
            return;
        }
        $chosen = Feed::apps($this->plugin->params);
        $ok     = static fn (int $id): bool => isset($apps[$id]) && (int) $apps[$id]['published'] && Feed::allowed($chosen, $id, (string) $apps[$id]['type']);
        $links  = [];
        if ($input->getCmd('option') === 'com_gridbox') {
            $view  = $input->getCmd('view');
            $appId = 0;
            if ($view === 'blog') {
                $appId = $input->getInt('app', 0);
                [$cat, $tag, $author] = [$input->getInt('id', 0), $input->getInt('tag', 0), $input->getInt('author', 0)];
                if ($ok($appId) && ($cat || $tag || $author)) {
                    [$method, $args] = $tag ? ['getGridboxTagLinks', [$tag, $appId]] : ($author ? ['getGridboxAuthorLinks', [$author, $appId]] : ['getGridboxCategoryLinks', [$cat, $appId]]);
                    $plain = 'index.php?option=com_gridbox&view=blog&app=' . $appId . '&id=' . ($tag || $author ? 0 : $cat) . ($tag ? '&tag=' . $tag : '') . ($author ? '&author=' . $author : '');
                    $links[] = [$method, $args, $plain, trim((string) $document->getTitle()) ?: (string) $apps[$appId]['title']];
                }
            } elseif ($view === 'page') {
                $appId = $input->getInt('blog', 0);
                if (!$appId && $input->getInt('id', 0)) {
                    try {
                        $appId = $store->appOfPage($input->getInt('id', 0));
                    } catch (\Throwable $e) {
                        $appId = 0;
                    }
                }
            }
            if ($ok($appId)) {
                $links[] = ['getGridboxCategoryLinks', [0, $appId], 'index.php?option=com_gridbox&view=blog&app=' . $appId . '&id=0', (string) $apps[$appId]['title']];
            }
        }
        if ($mode === 'all') {
            foreach ($apps as $id => $row) {
                if ($ok((int) $id)) {
                    $links[] = ['getGridboxCategoryLinks', [0, (int) $id], 'index.php?option=com_gridbox&view=blog&app=' . (int) $id . '&id=0', (string) $row['title']];
                }
            }
        }
        $done    = [];
        $formats = Feed::formats($this->plugin->params);
        foreach ($links as [$method, $args, $plain, $title]) {
            if (isset($done[$plain])) {
                continue;
            }
            $done[$plain] = true;
            foreach ($formats as $format) {
                // absolute, as feed readers and browser extensions expect (a relative one is not found by all of them)
                $url   = $this->plugin->gridboxLink($method, $args, $plain, '&format=feed&type=' . $format);
                $label = $title . (\count($formats) > 1 ? ($format === 'atom' ? ' (Atom)' : ' (RSS)') : '');
                $document->addHeadLink(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'alternate', 'rel',
                    ['type' => Feed::TYPES[$format], 'title' => htmlspecialchars($label, ENT_QUOTES, 'UTF-8')]);
            }
        }
    }
}
