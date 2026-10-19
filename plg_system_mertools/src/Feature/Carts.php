<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Old shop carts: Gridbox never removes a cart; the carts in use are noted (onAfterInitialise) and those nobody
 * can open any more are removed by hand, daily or above a limit (hourly in onAfterRespond).
 */

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Extension\MerTools;
use Merserwis\Plugin\System\MerTools\Tool\CartCleaner;

final class Carts extends Feature
{
    public function cartsOn(): bool
    {
        return (bool) (int) $this->plugin->params->get('cart_enabled', 1);
    }

    public function cartCleaner(): CartCleaner
    {
        return new CartCleaner(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /** A request with Gridbox's cart cookie: note that the cart is in use (it must not be removed). */
    public function trackCart(): void
    {
        if (!$this->cartsOn()) {
            return;
        }
        $id = $this->plugin->app()->getInput()->cookie->getInt('gridbox_store_cart', 0);
        if ($id <= 0) {
            return;
        }
        try {
            $this->cartCleaner()->track($id, time());
        } catch (\Throwable $e) {
            // no Gridbox shop or no tables yet: nothing to note
        }
    }

    /**
     * After the page has been sent: at most once an hour (a file in the cache folder keeps the time,
     * so other requests cost one stat), the watching bookkeeping and the automatic clean-up.
     */
    public function hourly(): void
    {
        if (!$this->cartsOn()) {
            return;
        }
        $gate = MerTools::cacheBase() . '/mertools-cart-tick';
        $now  = time();
        if (is_file($gate) && (int) @filemtime($gate) > $now - 3600) {
            return;
        }
        if (!@touch($gate)) {
            // the cache folder cannot be written: no hourly work at all rather than on every request
            return;
        }

        // the visitor has the page already; the work goes on without them waiting where PHP allows it
        $finished = $this->plugin->finishResponse();
        try {
            $cleaner = $this->cartCleaner();
            if (!$cleaner->supported()) {
                return;
            }
            $cleaner->tick($now, (string) $this->plugin->params->get('cart_mode', 'manual'), (int) $this->plugin->params->get('cart_limit', 50000),
                (int) $this->plugin->params->get('cart_days', 30), (bool) (int) $this->plugin->params->get('cart_empty', 1), $finished ? 8.0 : 2.0);
        } catch (\Throwable $e) {
            // tried again in an hour
        }
    }

    /**
     * The buttons of the cart clean-up in the plugin settings (figures, check, clean now). The days and
     * the empty-cart choice come from the form as it is on screen, also unsaved.
     */
    public function handleAction(string $action): void
    {
        $input   = $this->plugin->app()->getInput();
        $cleaner = $this->cartCleaner();
        if (!$cleaner->supported()) {
            $this->plugin->sendJson(['success' => false, 'message' => Text::_('PLG_SYSTEM_MERTOOLS_CART_UNSUPPORTED')]);
        }
        $days  = $input->post->getInt('days', (int) $this->plugin->params->get('cart_days', 30));
        $empty = (bool) $input->post->getInt('empty', (int) $this->plugin->params->get('cart_empty', 1));
        $now   = time();
        try {
            if ($action === 'cart_stats') {
                $this->plugin->sendJson(['success' => true, 'stats' => $cleaner->stats($now, $days)]);
            }
            @set_time_limit(90);
            $result = $cleaner->run($now, $days, $empty, true, $action === 'cart_check', 25.0, 'manual', $action === 'cart_clean');
            $this->plugin->sendJson(['success' => true, 'result' => $result, 'stats' => $cleaner->stats($now, $days)]);
        } catch (\Throwable $e) {
            $this->plugin->sendJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
