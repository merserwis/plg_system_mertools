<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Checks;
use Merserwis\Plugin\System\MerTools\Tool\ImageOptimizer;

/**
 * Recommended settings of Gridbox, Joomla, the server and Cloudflare for MerTools: what each one is
 * now, what it should be, why, and where to change it.
 */
final class MtchecksField extends FormField
{
    protected $type = 'Mtchecks';

    private const ICONS = [Checks::OK => ['icon-check', 'text-success'], Checks::BAD => ['icon-times', 'text-danger'],
        Checks::WARN => ['icon-warning', 'text-warning'], Checks::INFO => ['icon-info-circle', 'text-info']];

    protected function getInput()
    {
        $app = Factory::getApplication();
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $gb  = null;
        try {
            $gb = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__gridbox_website')), 0, 1)->loadAssoc() ?: null;
        } catch (\Throwable $e) {
            $gb = null;
        }
        $plugin = PluginHelper::getPlugin('system', 'mertools');
        $params = new Registry(\is_object($plugin) ? $plugin->params : '');
        $opc    = ['enabled' => \function_exists('opcache_get_status') && (bool) (int) ini_get('opcache.enable'), 'status' => null,
            'ini' => ['memory_consumption' => (int) ini_get('opcache.memory_consumption'), 'max_accelerated_files' => (int) ini_get('opcache.max_accelerated_files')]];
        if ($opc['enabled']) {
            $st            = @opcache_get_status(false);
            $opc['status'] = \is_array($st) ? $st : null;
        }
        $checks = Checks::evaluate($gb, [
            'platform_prefix'   => (bool) (int) $app->get('cache_platformprefix', 0),
            'caching'           => (int) $app->get('caching', 0),
            'page_cache_plugin' => PluginHelper::isEnabled('system', 'cache'),
        ], $params->toArray(), ['php' => PHP_VERSION, 'webp' => ImageOptimizer::supported(), 'opcache' => $opc]);

        $esc   = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $t     = fn (string $k) => Text::_('PLG_SYSTEM_MERTOOLS_CHK_' . $k);
        $value = function (array $v) use ($t): string {
            $key = array_shift($v);

            return $key === 'TEXT' ? (string) ($v[0] ?? '') : ($v ? vsprintf($t('V_' . $key), $v) : $t('V_' . $key));
        };
        $rows  = '';
        $group = '';
        foreach ($checks as $c) {
            if ($c['group'] !== $group) {
                $group = $c['group'];
                $rows .= '<tr class="table-light"><th colspan="4" scope="colgroup">' . $esc($t('G_' . strtoupper($group))) . '</th></tr>';
            }
            [$icon, $color] = self::ICONS[$c['state']];
            $rows .= '<tr data-mt-check="' . $esc($c['id']) . '">'
                . '<td class="text-center"><span class="' . $icon . ' ' . $color . '" aria-hidden="true"></span><span class="visually-hidden">' . $esc($t('S_' . strtoupper($c['state']))) . '</span></td>'
                . '<td><strong>' . $esc($t($c['id'])) . '</strong><br><small class="text-muted">' . $t($c['id'] . '_WHERE') . '</small></td>'
                . '<td class="mt-check-now">' . $esc($value($c['value'])) . '</td>'
                . '<td><strong>' . $esc($t($c['id'] . '_WANT')) . '</strong><br><small>' . $t($c['id'] . '_WHY') . '</small></td></tr>';
        }

        $texts = [];
        foreach (['V_ON', 'V_OFF', 'V_UNKNOWN', 'S_OK', 'S_BAD', 'S_INFO'] as $k) {
            $texts[$k] = $t($k);
        }
        $wa = $app->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.checks', 'plg_system_mertools/mertools-checks.js', [], ['defer' => true]);
        $data = $esc(json_encode(['root' => Uri::root(), 'texts' => $texts]));

        return '<div class="mt-checks" data-mt-checks="' . $data . '"><table class="table table-sm align-middle mb-2"><thead><tr>'
            . '<th scope="col" class="text-center" style="width:3rem">' . $esc($t('H_STATE')) . '</th><th scope="col">' . $esc($t('H_SETTING')) . '</th>'
            . '<th scope="col">' . $esc($t('H_NOW')) . '</th><th scope="col">' . $esc($t('H_WANT')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<button type="button" class="btn btn-secondary btn-sm" data-mt-checks-reload><span class="icon-refresh" aria-hidden="true"></span> '
            . $esc($t('RELOAD')) . '</button></div>';
    }
}
