<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Gridbox's own MVC factory, with one change: the models of the lists of apps and of pages are
 * MerTools' subclasses (AppsModel, PagesModel here), which put the pinned pages first and sort by a
 * column MerTools added. Put in place only for a request of such a list with something to order
 * (see GridboxLists::bootGridbox()); every other model, view and controller is Gridbox's.
 */

namespace Merserwis\Plugin\System\MerTools\Gridbox;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactory;

final class ListFactory extends MVCFactory
{
    /** @var array{pins: int[], expression: ?string, dir: string}|null the order of the list of this request */
    public static ?array $plan = null;

    private const MODELS = ['model\\appsmodel' => AppsModel::class, 'model\\pagesmodel' => PagesModel::class];

    /** Gridbox's factory as it was made (its namespace, form factory, database…), in this class. */
    public static function from(MVCFactory $factory): self
    {
        $copy = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        // the properties are private to MVCFactory (and its traits): copied in its scope
        \Closure::bind(static function (MVCFactory $from, MVCFactory $to): void {
            foreach (get_object_vars($from) as $name => $value) {
                $to->$name = $value;
            }
        }, null, MVCFactory::class)($factory, $copy);

        return $copy;
    }

    protected function getClassName(string $suffix, string $prefix)
    {
        $class = parent::getClassName($suffix, $prefix);
        $own   = self::MODELS[strtolower($suffix)] ?? null;
        if ($class === null || $own === null || strtolower($prefix) !== 'administrator' || self::$plan === null || !is_subclass_of($own, $class)) {
            return $class;
        }

        return $own;
    }
}
