<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * Quick setup: the switches in sections, each with a sentence on what it does and the recommended
 * value; the values shown come back with the form (mt_initial), so only the switches changed are saved.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Merserwis\Component\MerTools\Administrator\View\Setup\HtmlView $this */
?>
<form action="<?php echo Route::_('index.php?option=com_mertools&view=setup'); ?>" method="post" name="adminForm" id="adminForm"
    class="form-validate mt-setup" data-cancel-task="setup.cancel" aria-label="<?php echo $this->escape(Text::_('COM_MERTOOLS_MENU_SETUP')); ?>">

    <p class="mt-page-desc"><?php echo Text::_('COM_MERTOOLS_SETUP_INTRO'); ?></p>
    <div class="alert alert-warning"><?php echo Text::_('COM_MERTOOLS_CHECK_TEXT'); ?></div>
    <p>
        <button type="button" class="btn btn-primary" data-mt-recommended>
            <span class="icon-wand" aria-hidden="true"></span> <?php echo Text::_('COM_MERTOOLS_SETUP_RECOMMENDED_ALL'); ?></button>
    </p>

    <?php foreach ($this->sections as $section => $switches) : ?>
        <section class="card mb-3" aria-labelledby="mt-setup-<?php echo $this->escape($section); ?>">
            <h2 class="card-header h4 m-0" id="mt-setup-<?php echo $this->escape($section); ?>"><?php echo Text::_('COM_MERTOOLS_GROUP_' . strtoupper($section)); ?></h2>
            <div class="card-body">
                <?php foreach ($switches as $switch => $item) :
                    $id    = 'jform_' . $switch;
                    $key   = 'COM_MERTOOLS_SETUP_' . strtoupper($switch);
                    ?>
                    <div class="mt-setup-row">
                        <div class="mt-setup-text">
                            <span class="mt-setup-label" id="<?php echo $id; ?>-lbl"><?php echo Text::_($key . '_LABEL'); ?></span>
                            <span class="badge <?php echo $item['recommended'] ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo Text::_($item['recommended'] ? 'COM_MERTOOLS_SETUP_RECOMMENDED_ON' : 'COM_MERTOOLS_SETUP_RECOMMENDED_OFF'); ?></span>
                            <p class="mt-setup-desc" id="<?php echo $id; ?>-desc"><?php echo Text::_($key . '_DESC'); ?></p>
                        </div>
                        <fieldset id="<?php echo $id; ?>" class="mt-setup-switch" aria-labelledby="<?php echo $id; ?>-lbl" aria-describedby="<?php echo $id; ?>-desc"
                            data-recommended="<?php echo (int) $item['recommended']; ?>">
                            <div class="switcher">
                                <?php foreach ([0 => 'JNO', 1 => 'JYES'] as $value => $text) : ?>
                                    <input type="radio" id="<?php echo $id . $value; ?>" name="jform[<?php echo $this->escape($switch); ?>]" value="<?php echo $value; ?>"
                                        <?php echo $item['value'] === $value ? 'checked class="active"' : ''; ?>>
                                    <label for="<?php echo $id . $value; ?>"><?php echo Text::_($text); ?></label>
                                <?php endforeach; ?>
                                <span class="toggle-outside"><span class="toggle-inside"></span></span>
                            </div>
                        </fieldset>
                        <input type="hidden" name="mt_initial[<?php echo $this->escape($switch); ?>]" value="<?php echo (int) $item['value']; ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
