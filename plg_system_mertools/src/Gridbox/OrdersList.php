<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The part of MerTools' list models that orders the list (see ListFactory). Should the changed query
 * fail (a database that cannot run it), the list is read again as Gridbox orders it.
 */

namespace Merserwis\Plugin\System\MerTools\Gridbox;

\defined('_JEXEC') or die;

use Merserwis\Plugin\System\MerTools\Tool\ListSort;

trait OrdersList
{
    private bool $mtOrdered = false;

    private bool $mtOff = false;

    protected function getListQuery()
    {
        $query = parent::getListQuery();
        $plan  = ListFactory::$plan;
        // Gridbox's own order of the pages (dragged by hand) is left as it is
        if ($this->mtOff || $plan === null || !\is_object($query) || $this->getState('list.ordering') === 'order_list') {
            return $query;
        }
        $this->mtOrdered = ListSort::apply($query, $plan['pins'], $plan['expression'], $plan['dir']) || $this->mtOrdered;

        return $query;
    }

    public function getItems()
    {
        $items = parent::getItems();
        if ($items === false && $this->mtOrdered && !$this->mtOff) {
            $this->mtOff            = true;
            $this->query            = [];
            $this->lastQueryStoreId = null;
            $this->cache            = [];
            $items = parent::getItems();
        }

        return $items;
    }
}
