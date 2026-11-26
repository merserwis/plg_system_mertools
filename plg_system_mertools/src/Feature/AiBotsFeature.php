<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The bots of AI services (tab Sitemap → AI bots): the bots ticked in the settings are refused in the site's
 * robots.txt, in a block of MerTools' own (Tool\AiBots), written when the settings are saved — only when it
 * changes, the file as it was first copied to Joomla's tmp folder; a file that cannot be written is said, not
 * forced. llms.txt answered by Joomla (as sitemap.xml): the text of the settings, else the site's name, its
 * description and the first level of its main menu. A file llms.txt of the site's own is served by the server
 * before Joomla and wins.
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\AiBots;
use Merserwis\Plugin\System\MerTools\Tool\Sitemap;

final class AiBotsFeature extends Feature
{
    public const ROBOTS = 'robots.txt';

    /** After the settings are saved: robots.txt with MerTools' block of the ticked bots, when it changes. */
    public function afterSave(Registry $params): void
    {
        $file = JPATH_ROOT . '/' . self::ROBOTS;
        $old  = is_file($file) ? (string) file_get_contents($file) : '';
        $bots = AiBots::selected($params->get('aibots_block', []));
        $new  = AiBots::apply($old, $bots);
        if ($new === $old) {
            return;
        }
        $app = $this->plugin->app();
        $this->plugin->loadLanguage();
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(JPATH_ROOT))) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_MERTOOLS_AIBOTS_NOT_WRITABLE'), 'warning');

            return;
        }
        // the file as it was, once per change (Joomla's tmp folder is not served)
        $tmp = rtrim((string) $app->get('tmp_path', JPATH_ROOT . '/tmp'), '/');
        if (is_file($file) && (!is_dir($tmp) || !@copy($file, $tmp . '/mertools-robots-' . date('Ymd-His') . '.txt'))) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_MERTOOLS_AIBOTS_NO_COPY'), 'warning');

            return;
        }
        $part = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($part, $new) === false || !rename($part, $file)) {
            @unlink($part);
            $app->enqueueMessage(Text::_('PLG_SYSTEM_MERTOOLS_AIBOTS_NOT_WRITABLE'), 'warning');

            return;
        }
        $app->enqueueMessage($bots ? Text::sprintf('PLG_SYSTEM_MERTOOLS_AIBOTS_WRITTEN', implode(', ', $bots)) : Text::_('PLG_SYSTEM_MERTOOLS_AIBOTS_REMOVED'));
    }

    /** /llms.txt (GET or HEAD), when switched on: answered here, before the router */
    public function serveLlms(): void
    {
        $params = $this->plugin->params;
        $app    = $this->plugin->app();
        $method = strtoupper((string) $app->getInput()->getMethod());
        $path   = substr((string) Uri::getInstance()->getPath(), \strlen(rtrim(Uri::root(true), '/')));
        if (!(int) $params->get('llms_enabled', 0) || $path !== '/llms.txt' || !\in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }
        $text = trim(str_replace("\r\n", "\n", (string) $params->get('llms_text', '')));
        if ($text === '') {
            $this->plugin->loadLanguage();
            $text = AiBots::llms((string) $app->get('sitename', ''), (string) $app->get('MetaDesc', ''), $this->menuPages(),
                Sitemap::enabled($params) ? rtrim(Uri::root(), '/') . '/sitemap.xml' : '', Text::_('PLG_SYSTEM_MERTOOLS_LLMS_PAGES'));
        } else {
            $text .= "\n";
        }
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'public, max-age=3600', true);
        $app->setHeader('Content-Length', (string) \strlen($text), true);
        $app->allowCache(true);
        $app->setBody($method === 'HEAD' ? '' : $text);
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
        echo $app->toString(false);
        $app->close();
    }

    /** @return array<int, array{0: string, 1: string}> the first level of the menu of the home page: [title, address] */
    private function menuPages(): array
    {
        try {
            $menu = $this->plugin->app()->getMenu();
            $home = $menu ? $menu->getDefault() : null;
            if (!$home) {
                return [];
            }
            $pages = [[$home->title, Uri::root()]];
            foreach ($menu->getItems(['menutype', 'level'], [$home->menutype, 1]) as $item) {
                if ((int) $item->id === (int) $home->id || (int) $item->access !== 1 || \in_array($item->type, ['separator', 'heading', 'alias'], true)) {
                    continue;
                }
                $url = $item->type === 'url' ? (string) $item->link : Route::link('site', 'index.php?Itemid=' . (int) $item->id, false, Route::TLS_IGNORE, true);
                if (!preg_match('#^https?://#i', $url)) {
                    $url = rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
                }
                $pages[] = [(string) $item->title, $url];
            }

            return \array_slice($pages, 0, 50);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** For the settings: robots.txt (null: none), whether it can be written, the status of each bot */
    public static function state(): array
    {
        $file = JPATH_ROOT . '/' . self::ROBOTS;
        $text = is_file($file) ? (string) file_get_contents($file) : null;

        return ['exists' => $text !== null, 'writable' => $text !== null ? is_writable($file) : is_writable(JPATH_ROOT),
            'mine' => $text !== null && str_contains($text, AiBots::BEGIN), 'status' => AiBots::status($text)];
    }
}
