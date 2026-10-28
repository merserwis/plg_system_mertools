<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * The control panel: tiles of the tools in sections, the configuration below them, and on the side
 * the state of MerTools, the buttons used most and the reminder to check the site after a change.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Merserwis\Component\MerTools\Administrator\View\Cpanel\HtmlView $this */
?>
<div class="mt-cpanel">
    <?php if (!$this->plugin) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_MERTOOLS_NO_PLUGIN'); ?></div>
    <?php elseif (!(int) $this->plugin->enabled) : ?>
        <form action="<?php echo Route::_('index.php?option=com_mertools'); ?>" method="post" class="alert alert-warning d-flex flex-wrap align-items-center gap-3">
            <span class="flex-grow-1"><?php echo Text::_('COM_MERTOOLS_PLUGIN_OFF'); ?></span>
            <?php if ($this->canEdit) : ?>
                <button type="submit" class="btn btn-warning"><span class="icon-publish" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_ENABLE'); ?></button>
            <?php endif; ?>
            <input type="hidden" name="task" value="page.enable">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
    <?php if (!$this->gridbox) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_MERTOOLS_NO_GRIDBOX'); ?></div>
    <?php endif; ?>

    <?php if ($this->isNew) : ?>
        <div class="mt-start card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span class="mt-start-icon icon-wand" aria-hidden="true"></span>
                <div class="flex-grow-1">
                    <h2 class="h4 mb-1"><?php echo Text::_('COM_MERTOOLS_START_TITLE'); ?></h2>
                    <p class="mb-0"><?php echo Text::_('COM_MERTOOLS_START_TEXT'); ?></p>
                </div>
                <a class="btn btn-primary btn-lg" href="<?php echo Route::_('index.php?option=com_mertools&view=setup'); ?>"><?php echo Text::_('COM_MERTOOLS_START_BUTTON'); ?></a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-xl-8">
            <?php foreach ($this->tiles as $group => $tiles) : ?>
                <section class="card mb-3 mt-section mt-group-<?php echo $this->escape($group); ?>" aria-labelledby="mt-group-<?php echo $this->escape($group); ?>">
                    <div class="card-header">
                        <h2 class="h4 m-0" id="mt-group-<?php echo $this->escape($group); ?>"><?php echo Text::_('COM_MERTOOLS_GROUP_' . strtoupper($group)); ?></h2>
                        <p class="mt-section-desc"><?php echo Text::_('COM_MERTOOLS_GROUP_' . strtoupper($group) . '_DESC'); ?></p>
                    </div>
                    <div class="card-body">
                        <ul class="mt-tiles">
                            <?php foreach ($tiles as $tile) :
                                $key   = ($tile['config'] ? 'COM_MERTOOLS_CFG_' : 'COM_MERTOOLS_PAGE_') . strtoupper($tile['name']);
                                $state = $tile['state'] === 'on' ? Text::_('COM_MERTOOLS_STATE_ON') : ($tile['state'] === 'off' ? Text::_('COM_MERTOOLS_STATE_OFF') : '');
                                ?>
                                <li>
                                    <a class="mt-tile mt-tile-<?php echo $this->escape($tile['state'] ?: 'none'); ?>" href="<?php echo Route::_($tile['link']); ?>"
                                        title="<?php echo $this->escape(Text::_($key . '_DESC')); ?>">
                                        <span class="mt-tile-icon"><span class="icon-<?php echo $this->escape($tile['icon']); ?>" aria-hidden="true"></span></span>
                                        <span class="mt-tile-name"><?php echo Text::_($key); ?></span>
                                        <?php if ($state !== '') : ?>
                                            <span class="mt-tile-state"><span class="mt-dot" aria-hidden="true"></span><?php echo $state; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="col-xl-4">
            <section class="card mb-3">
                <h2 class="card-header h4 m-0"><?php echo Text::_('COM_MERTOOLS_STATUS'); ?></h2>
                <div class="card-body">
                    <p class="mt-status-count"><?php echo Text::sprintf('COM_MERTOOLS_STATUS_ON', $this->on, $this->total); ?></p>
                    <dl class="mt-status row mb-0">
                        <dt class="col-6"><?php echo Text::_('COM_MERTOOLS_STATUS_VERSION'); ?></dt>
                        <dd class="col-6"><?php echo $this->escape($this->version); ?></dd>
                        <dt class="col-6"><?php echo Text::_('COM_MERTOOLS_STATUS_PLUGIN'); ?></dt>
                        <dd class="col-6"><?php echo Text::_($this->plugin && (int) $this->plugin->enabled ? 'COM_MERTOOLS_STATUS_PLUGIN_ON' : 'COM_MERTOOLS_STATUS_PLUGIN_OFF'); ?></dd>
                        <dt class="col-6"><?php echo Text::_('COM_MERTOOLS_STATUS_GRIDBOX'); ?></dt>
                        <dd class="col-6"><?php echo Text::_($this->gridbox ? 'JYES' : 'JNO'); ?></dd>
                        <dt class="col-6"><?php echo Text::_('COM_MERTOOLS_STATUS_STORE'); ?></dt>
                        <dd class="col-6"><?php echo Text::_($this->store ? 'JYES' : 'JNO'); ?></dd>
                    </dl>
                </div>
            </section>

            <section class="card mb-3">
                <h2 class="card-header h4 m-0"><?php echo Text::_('COM_MERTOOLS_ACTIONS'); ?></h2>
                <div class="card-body d-grid gap-2">
                    <a class="btn btn-outline-primary text-start" href="<?php echo Route::_('index.php?option=com_mertools&view=setup'); ?>">
                        <span class="icon-wand" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_MENU_SETUP'); ?></a>
                    <a class="btn btn-outline-primary text-start" href="<?php echo Route::_('index.php?option=com_mertools&view=page&page=config'); ?>">
                        <span class="icon-cog" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_MENU_CONFIG'); ?></a>
                    <?php if ($this->canClear) : ?>
                        <button type="button" class="btn btn-outline-secondary text-start" data-mertools-clear-cache>
                            <span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_CLEAR_CACHE'); ?></button>
                    <?php endif; ?>
                    <?php if ($this->gridbox) : ?>
                        <a class="btn btn-outline-secondary text-start" href="<?php echo Route::_('index.php?option=com_gridbox'); ?>">
                            <span class="icon-pencil-alt" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_OPEN_GRIDBOX'); ?></a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card mb-3 mt-check">
                <h2 class="card-header h4 m-0"><span class="icon-exclamation-triangle" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_CHECK_TITLE'); ?></h2>
                <div class="card-body"><?php echo Text::_('COM_MERTOOLS_CHECK_TEXT'); ?></div>
            </section>

            <p class="small text-muted"><?php echo Text::_('COM_MERTOOLS_SUPPORT'); ?></p>
        </div>
    </div>
</div>
