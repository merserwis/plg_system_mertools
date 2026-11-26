<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * What the site's robots.txt says to each AI bot now (the whole file, MerTools' block and the rest), with whose
 * the bot is and what it fetches for; whether the file can be written.
 */

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Merserwis\Plugin\System\MerTools\Feature\AiBotsFeature;
use Merserwis\Plugin\System\MerTools\Tool\AiBots;

final class MtaibotsField extends FormField
{
    protected $type = 'Mtaibots';

    protected function getInput()
    {
        $h     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $t     = static fn (string $k): string => htmlspecialchars(Text::_('PLG_SYSTEM_MERTOOLS_AIBOTS_' . $k), ENT_QUOTES, 'UTF-8');
        $state = AiBotsFeature::state();
        $badge = ['allowed' => 'success', 'partly' => 'warning', 'blocked' => 'danger'];
        $rows  = '';
        foreach (AiBots::BOTS as $bot => [$owner, $purpose]) {
            $s     = $state['status'][$bot];
            $rows .= '<tr><td><code>' . $h($bot) . '</code></td><td>' . $h($owner) . '</td><td>' . $t('P_' . $purpose) . '</td>'
                . '<td><span class="badge bg-' . $badge[$s['status']] . '">' . $t('S_' . strtoupper($s['status'])) . '</span>'
                . ($s['own'] ? '' : ' <small class="text-muted">' . $t('VIA_ALL') . '</small>') . '</td></tr>';
        }
        $note = !$state['exists'] ? $t('NO_FILE') : (!$state['writable'] ? '<strong>' . $t('NOT_WRITABLE') . '</strong>' : ($state['mine'] ? $t('HAS_BLOCK') : $t('NO_BLOCK')));

        return '<div class="mt-aibots"><p>' . $note . '</p>'
            . '<table class="table table-sm" style="max-width:760px"><thead><tr><th>' . $t('COL_BOT') . '</th><th>' . $t('COL_OWNER') . '</th><th>' . $t('COL_PURPOSE')
            . '</th><th>' . $t('COL_STATUS') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="small text-muted">' . $t('CDN') . '</p></div>';
    }
}
