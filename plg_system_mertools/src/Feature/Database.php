<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Database: the indexes of Gridbox's tables (checked daily and after Gridbox is installed or updated) and a "<"
 * in introductions kept as &lt; (after saves in Gridbox and daily).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\DbIndexes;
use Merserwis\Plugin\System\MerTools\Tool\IntroText;

final class Database extends Feature
{
    public function indexesOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('db_indexes', 1);
    }

    public function dbIndexes(): DbIndexes
    {
        return new DbIndexes(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /**
     * Once a day: the indexes still there? (Gridbox updated, the database restored from a backup…)
     * The check is a few SHOW INDEX; a missing index is created after the response has gone out.
     */
    public function dailyIndexCheck(): void
    {
        if (!$this->indexesOn()) {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-dbindex-tick';
        if (is_file($gate) && (int) @filemtime($gate) > time() - 86400) {
            return;
        }
        if (!@touch($gate)) {
            return;
        }
        try {
            $indexes = $this->dbIndexes();
            if (!\in_array('missing', array_column($indexes->status(), 'state'), true)) {
                return;
            }
            $this->plugin->finishResponse();
            @ignore_user_abort(true);
            @set_time_limit(300);
            $indexes->ensure();
        } catch (\Throwable $e) {
            // tried again the next day
        }
    }

    public function introLtOn(): bool
    {
        return (int) $this->plugin->params->get('intro_lt', 1) === 1;
    }

    /**
     * After anything sent to Gridbox by a logged-in user (the editor's save, the settings of a page,
     * a CSV import…): a "<" Gridbox's lists would lose with an old libxml is kept as &lt; (IntroText).
     * Looks only for the rows that have one, so it costs a few ms.
     */
    public function introGuard(): void
    {
        if (!$this->introLtOn()) {
            return;
        }
        $app   = $this->plugin->app();
        $input = $app->getInput();
        $user  = $app->getIdentity();
        if (strtoupper((string) $input->getMethod()) !== 'POST' || $input->getCmd('option') !== 'com_gridbox' || !$user || $user->guest) {
            return;
        }
        register_shutdown_function(static function (): void {
            try {
                IntroText::repair(Factory::getContainer()->get(DatabaseInterface::class));
            } catch (\Throwable $e) {
            }
        });
    }

    /** Once a day: introductions changed past Gridbox (another import, the database directly). */
    public function dailyIntroCheck(): void
    {
        if (!$this->introLtOn()) {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-intro-tick';
        if ((is_file($gate) && (int) @filemtime($gate) > time() - 86400) || !@touch($gate)) {
            return;
        }
        try {
            if (IntroText::repair(Factory::getContainer()->get(DatabaseInterface::class)) && ($this->plugin->speed()->pageCacheOn() || $this->plugin->speed()->dataCacheOn())) {
                $this->plugin->speed()->pageCache()->purge(false);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Gridbox (its component or package) installed or updated: the missing indexes are created again at once. */
    public function afterInstall(\Joomla\Event\EventInterface $event): void
    {
        if (!$this->indexesOn()) {
            return;
        }
        try {
            $eid = (int) (method_exists($event, 'getEid') ? $event->getEid() : $event->getArgument('eid'));
            if ($eid <= 0) {
                return;
            }
            $db      = Factory::getContainer()->get(DatabaseInterface::class);
            $element = (string) $db->setQuery($db->getQuery(true)->select($db->quoteName('element'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = ' . $eid))->loadResult();
            if (DbIndexes::isGridbox($element)) {
                $this->dbIndexes()->ensure();
            }
        } catch (\Throwable $e) {
            // the daily check creates them
        }
    }

    /** The index panel: the state of the indexes, or the missing ones created. */
    public function handleAction(string $action): void
    {
        try {
            $indexes = $this->dbIndexes();
            if ($action === 'dbidx_fix') {
                @set_time_limit(120);
                $this->plugin->sendJson(['success' => true] + $indexes->ensure());
            }
            $this->plugin->sendJson(['success' => true, 'status' => $indexes->status()]);
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
