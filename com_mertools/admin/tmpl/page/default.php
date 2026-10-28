<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * A page of settings: the fieldsets of the page as tabs (vertical for the configuration), or the one
 * fieldset alone.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Merserwis\Component\MerTools\Administrator\View\Page\HtmlView $this */

$page   = $this->page;
$names  = array_keys($this->fieldsets);
$tabsId = 'mt-tabs-' . $page;
// the first tab after the overview: where a tool is switched on
$settings = '';
foreach ($names as $name) {
    if (!str_ends_with($name, '_panel')) {
        $settings = $name;
        break;
    }
}
?>
<form action="<?php echo Route::_('index.php?option=com_mertools&view=page&page=' . $page); ?>" method="post" name="adminForm" id="adminForm"
    class="form-validate mt-page mt-page-<?php echo $this->escape($page); ?>" data-cancel-task="page.cancel" aria-label="<?php echo $this->escape(Text::_('COM_MERTOOLS_PAGE_' . strtoupper($page))); ?>">

    <?php if (!$this->plugin || !(int) $this->plugin->enabled) : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_MERTOOLS_PLUGIN_OFF'); ?>
            <a href="<?php echo Route::_('index.php?option=com_mertools'); ?>"><?php echo Text::_('COM_MERTOOLS_MENU_CPANEL'); ?></a>
        </div>
    <?php endif; ?>

    <p class="mt-page-desc"><?php echo Text::_('COM_MERTOOLS_PAGE_' . strtoupper($page) . '_DESC'); ?></p>

    <?php if ($this->state === 'off' && $settings !== '' && ($names[0] ?? '') !== $settings) : ?>
        <div class="alert alert-info">
            <?php echo Text::_('COM_MERTOOLS_TOOL_OFF'); ?>
            <a href="<?php echo Route::_('index.php?option=com_mertools&view=page&page=' . $page . '&tab=' . $settings); ?>"><?php echo Text::_('COM_MERTOOLS_TOOL_OFF_LINK'); ?></a>
        </div>
    <?php endif; ?>

    <?php if (!$this->form) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_MERTOOLS_ERROR_PAGE'); ?></div>
    <?php elseif (\count($names) === 1) : ?>
        <div class="card"><div class="card-body"><?php echo $this->form->renderFieldset($names[0]); ?></div></div>
    <?php else : ?>
        <?php
        $options = ['active' => $this->active ?: $names[0], 'breakpoint' => 768, 'orientation' => $page === 'config' ? 'vertical' : 'horizontal'];
        if ($this->active === '') {
            // the tab last open on this page comes back, unless the address names one
            $options['recall'] = true;
        }
        echo HTMLHelper::_('uitab.startTabSet', $tabsId, $options);
        foreach ($this->fieldsets as $name => $label) {
            echo HTMLHelper::_('uitab.addTab', $tabsId, $name, Text::_($label));
            echo $this->form->renderFieldset($name);
            echo HTMLHelper::_('uitab.endTab');
        }
        echo HTMLHelper::_('uitab.endTabSet');
        ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="page" value="<?php echo $this->escape($page); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
