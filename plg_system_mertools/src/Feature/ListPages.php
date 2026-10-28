<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Pages of the Gridbox lists (?page=N of a category, an app, a tag, an author): a page number that is not
 * one is sent to the list without it (after routing), page N gets its own canonical and title, and a page
 * past the last one answers 404 (on the finished page). See Tool/Pagination.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\EndedItems;
use Merserwis\Plugin\System\MerTools\Tool\EndedPages;
use Merserwis\Plugin\System\MerTools\Tool\Pagination;

final class ListPages extends Feature
{
    /**
     * The guard of Gridbox's sorting, before Gridbox runs: a sort-by it does not offer is taken out of the
     * address (301; any other request: 400), and its editor's task that lists posts needs the right to
     * edit in Gridbox (403).
     */
    public function guardSort(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        if (!Pagination::guarded($this->plugin->params) || $input->getCmd('option') !== 'com_gridbox') {
            return;
        }
        $task = strtolower((string) $input->getString('task', ''));
        if ($task === 'editor.getblogposts') {
            $user = $app->getIdentity();
            if (!$user || $user->guest || !$user->authorise('core.edit', 'com_gridbox')) {
                $this->refuse(403);
            }

            return;
        }
        $raw = $input->get('sort-by', null, 'raw');
        if (!Pagination::badSort(\is_scalar($raw) ? (string) $raw : ($raw === null ? null : 'x'), $task)) {
            return;
        }
        $uri = Uri::getInstance();
        if (strtoupper((string) $input->getMethod()) === 'GET' && $uri->getVar('sort-by') !== null) {
            // every form of it out (sort-by[]=…): one left in would be refused again after the redirect
            $app->redirect($uri->toString(['scheme', 'host', 'port']) . Pagination::without((string) $uri->getPath(), (string) $uri->getQuery(), 'sort-by'), 301);
        }
        $this->refuse(400);
    }

    private function refuse(int $code): void
    {
        $app = $this->plugin->app();
        $app->setHeader('Status', (string) $code, true);
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setBody($code === 403 ? 'Forbidden' : 'Bad Request');
        echo $app->toString(false);
        $app->close();
    }

    /** A Gridbox list asked for with ?page=0, 1, 01, abc…: a 301 to the list without the page number. */
    public function redirectPage(): void
    {
        if (!$this->isList()) {
            return;
        }
        $uri = Uri::getInstance();
        $raw = $uri->getVar('page');
        if ($raw === null || Pagination::page(\is_array($raw) ? 'x' : (string) $raw) !== null) {
            return;
        }
        $this->plugin->app()->redirect($uri->toString(['scheme', 'host', 'port']) . Pagination::withoutPage((string) $uri->getPath(), (string) $uri->getQuery()), 301);
    }

    /** Page N ≥ 2 of a list: its canonical and title; past the last page: 404 with noindex. */
    public function listPage(string $html): string
    {
        $page = $this->isList() ? Pagination::page(($raw = Uri::getInstance()->getVar('page')) === null ? null : (string) $raw) : null;
        if ($page === null || $page < 2) {
            return $html;
        }
        if (Pagination::emptyList($html)) {
            $this->plugin->app()->setHeader('Status', '404', true);

            return Pagination::noindex($html);
        }
        $this->plugin->loadLanguage();

        return Pagination::texts(Pagination::canonical($html, $page), $page, $this->plugin->translate('PLG_SYSTEM_MERTOOLS_PAGINATION_TITLE') ?: '%1$s – %2$d');
    }

    /**
     * Cards of the lists with a word of the setting in their title (e.g. "[ZAKOŃCZONE]") taken out: on the home page
     * only, or on every page. The page of such a card stays open.
     */
    public function hideEnded(string $html): string
    {
        $words = EndedItems::words((string) $this->plugin->params->get('list_hide_words', ''));
        if ($words === [] || !str_contains($html, 'ba-blog-post-title')) {
            return $html;
        }
        if ((string) $this->plugin->params->get('list_hide_where', 'home') !== 'all') {
            $active = $this->plugin->app()->getMenu()->getActive();
            if (!$active || !(int) $active->home) {
                return $html;
            }
        }
        try {
            return EndedItems::hide($html, $words);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** A page of a Gridbox list (GET or HEAD) */
    /**
     * A change sent to Gridbox (a title saved): once it is done, the finished items get their end of
     * publishing, or lose it (EndedPages). In every list only: on the home page only, the archive still lists them.
     */
    public function endedWatch(): void
    {
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !$user || $user->guest) {
            return;
        }
        register_shutdown_function(fn () => $this->syncEnded());
    }

    /** Once an hour: titles changed past Gridbox's requests (an import, the database directly). */
    public function hourlyEnded(): void
    {
        $gate = MerTools::cacheBase() . '/mertools-ended-tick';
        if ((is_file($gate) && (int) @filemtime($gate) > time() - 3600) || !@touch($gate)) {
            return;
        }
        $this->syncEnded();
    }

    /** See EndedPages::sync(); the kept pages and the sitemaps are out of date when a page changed. $params: the settings just saved. */
    public function syncEnded(?Registry $params = null): void
    {
        $params ??= $this->plugin->params;
        $words    = (string) $params->get('list_hide_where', 'home') === 'all' ? EndedItems::words((string) $params->get('list_hide_words', '')) : [];
        try {
            $zone = new \DateTimeZone((string) $this->plugin->app()->get('offset', 'UTC') ?: 'UTC');
            $now  = (new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');
            if ((new EndedPages(Factory::getContainer()->get(DatabaseInterface::class)))->sync($words, $now) > 0) {
                $this->plugin->speed()->purgeKeptPages();
                Sitemaps::changed();
            }
        } catch (\Throwable $e) {
        }
    }

    private function isList(): bool
    {
        $input = $this->plugin->app()->getInput();

        return Pagination::enabled($this->plugin->params) && $input->getCmd('option') === 'com_gridbox' && $input->getCmd('view') === 'blog'
            && \in_array(strtoupper((string) $input->getMethod()), ['GET', 'HEAD'], true);
    }
}
