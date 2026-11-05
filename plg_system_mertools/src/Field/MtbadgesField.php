<?php

namespace Merserwis\Plugin\System\MerTools\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Merserwis\Plugin\System\MerTools\Tool\Badges;

/**
 * The badges in the plugin settings (Shop → Badges): a preview on a product picture of the site with
 * the badges of its shop, in the look of the form as it is now (its corners can be clicked), and a card
 * for every style (a click chooses it) — mertools-badges-admin.js follows the form.
 */
final class MtbadgesField extends FormField
{
    protected $type = 'Mtbadges';

    /** the badges shown at most */
    private const SHOWN = 3;

    protected function getInput()
    {
        $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        [$image, $badges] = $this->sample();
        $wrapper = Badges::html($badges);
        $photo   = $image !== '' ? ' style="background-image:url(\'' . $e(str_replace(["'", '\\'], ['%27', '%5C'], $image)) . '\')"' : '';

        $corners = '';
        foreach (Badges::CORNERS as $corner) {
            $corners .= '<button type="button" class="mt-bp-corner mt-bp-corner-' . $corner . '" data-mt-corner="' . $corner . '" aria-pressed="false" title="'
                . $e(Text::_('PLG_SYSTEM_MERTOOLS_BADGE_CORNER_' . strtoupper($corner))) . '"><span class="visually-hidden">'
                . $e(Text::_('PLG_SYSTEM_MERTOOLS_BADGE_CORNER_' . strtoupper($corner))) . '</span></button>';
        }
        $cards = '';
        foreach (Badges::STYLES as $style) {
            $name   = Text::_('PLG_SYSTEM_MERTOOLS_BADGE_STYLE_' . strtoupper($style));
            $cards .= '<button type="button" class="mt-bp-card" data-mt-style="' . $style . '" aria-pressed="false" aria-label="' . $e($name) . '">'
                . '<span class="mt-bp mt-bp-img" data-style="' . $style . '"' . $photo . '>' . $wrapper . '</span>'
                . '<span class="mt-bp-name" aria-hidden="true">' . $e($name) . '</span></button>';
        }

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('plg_system_mertools.badges-admin', 'plg_system_mertools/mertools-badges-admin.js', [], ['defer' => true]);
        $wa->addInlineStyle(Badges::previewCss() . self::CSS);

        return '<div class="mt-bp-panel" data-mt-badges-preview>'
            . '<div class="mt-bp-main"><div class="mt-bp mt-bp-img mt-bp-big"' . $photo . '>' . $wrapper . $corners . '</div>'
            . '<p class="small text-muted mt-2 mb-0">' . $e(Text::_($image !== '' ? 'PLG_SYSTEM_MERTOOLS_BADGE_UI_PREVIEW' : 'PLG_SYSTEM_MERTOOLS_BADGE_UI_PREVIEW_NO_PHOTO')) . '</p></div>'
            . '<div class="mt-bp-side"><h4 class="h6">' . $e(Text::_('PLG_SYSTEM_MERTOOLS_BADGE_UI_STYLES')) . '</h4><div class="mt-bp-cards">' . $cards . '</div></div>'
            . '</div>';
    }

    /**
     * A picture of a product of the site that has badges (else of any product) and the badges to show
     * on it: that product's, else the first ones of the shop, else two examples.
     *
     * @return array{0:string, 1:list<array{title:string, color:string, out:bool}>}
     */
    private function sample(): array
    {
        $image = '';
        $rows  = [];
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $q   = static fn (string $join, string $where): string => 'SELECT p.id, p.intro_image FROM ' . $db->quoteName('#__gridbox_pages', 'p') . ' JOIN '
                . $db->quoteName('#__gridbox_app', 'a') . ' ON a.id = p.app_id' . $join . " WHERE a.type = 'products' AND p.published = 1 AND p.intro_image <> ''" . $where
                . ' ORDER BY p.id';
            $row = $db->setQuery($q(' JOIN ' . $db->quoteName('#__gridbox_store_badges_map', 'bm') . ' ON bm.product_id = p.id', ''), 0, 1)->loadAssoc()
                ?: $db->setQuery($q('', ''), 0, 1)->loadAssoc();
            if ($row) {
                $src   = (string) $row['intro_image'];
                $image = preg_match('#^(https?:)?//#i', $src) ? $src : Uri::root() . ltrim($src, '/');
                $rows  = (array) $db->setQuery('SELECT b.title, b.color, b.type FROM ' . $db->quoteName('#__gridbox_store_badges_map', 'bm') . ' JOIN '
                    . $db->quoteName('#__gridbox_store_badges', 'b') . ' ON b.id = bm.badge_id WHERE bm.product_id = ' . (int) $row['id'] . ' ORDER BY bm.order_list, bm.id')->loadAssocList();
            }
            if (\count($rows) < 2) {
                $rows = (array) $db->setQuery('SELECT title, color, type FROM ' . $db->quoteName('#__gridbox_store_badges') . ' ORDER BY id', 0, self::SHOWN + 1)->loadAssocList();
            }
        } catch (\Throwable $ex) {
            $rows = [];
        }
        // the sale badge as a product of 20 % off shows it
        $badges = \array_slice(Badges::forProduct($rows, ['price' => '100', 'sale_price' => '80', 'stock' => '', 'min' => ''], false, ''), 0, self::SHOWN);
        if (!$badges) {
            $badges = [['title' => Text::_('PLG_SYSTEM_MERTOOLS_BADGE_UI_SAMPLE_SALE'), 'color' => '#f64231', 'out' => false],
                ['title' => Text::_('PLG_SYSTEM_MERTOOLS_BADGE_UI_SAMPLE_NEW'), 'color' => '#ffc700', 'out' => false]];
        }

        return [$image, $badges];
    }

    /** the settings on the left, the preview on the right staying in sight while they are changed; one column on a narrow screen */
    private const CSS = '.mt-page-badges .card-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(340px,46%);column-gap:28px;align-items:start}'
        . '.mt-page-badges .card-body>*{grid-column:1}'
        . '.mt-page-badges .card-body>.mt-bp-group{grid-column:2;grid-row:1/span 40;position:sticky;top:80px;margin:0}'
        . '.mt-page-badges .mt-bp-group .controls{margin:0;max-width:none;width:100%}'
        . '@media (max-width:1100px){.mt-page-badges .card-body{display:block}.mt-page-badges .card-body>.mt-bp-group{position:static;margin-bottom:1.5rem}}'
        . '.mt-bp-img{display:block;position:relative;overflow:hidden;border-radius:6px;aspect-ratio:4/3;'
        . 'background:#fff linear-gradient(135deg,#eef2f7,#c9d3e0) center/contain no-repeat;box-shadow:inset 0 0 0 1px rgba(128,128,128,.25)}'
        . '.mt-bp-img[style]{background-color:#fff;background-size:contain;background-position:center;background-repeat:no-repeat}'
        . '.mt-bp-big{width:100%;max-width:440px}'
        . '.mt-bp-corner{position:absolute;z-index:3;width:18px;height:18px;padding:0;border-radius:50%;border:2px dashed rgba(29,78,216,.55);background:rgba(255,255,255,.75);cursor:pointer}'
        . '.mt-bp-corner[aria-pressed="true"]{border-style:solid;border-color:#1d4ed8;background:#1d4ed8}'
        . '.mt-bp-corner:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}'
        . '.mt-bp-corner-tl{top:3px;left:3px}.mt-bp-corner-tr{top:3px;right:3px}.mt-bp-corner-bl{bottom:3px;left:3px}.mt-bp-corner-br{bottom:3px;right:3px}'
        . '.mt-bp-side{margin-top:14px}.mt-bp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px}'
        . '.mt-bp-card{display:flex;flex-direction:column;gap:4px;padding:4px;border:2px solid transparent;border-radius:8px;background:none;text-align:left;cursor:pointer;color:inherit}'
        . '.mt-bp-card:hover{border-color:rgba(128,128,128,.35)}.mt-bp-card[aria-pressed="true"]{border-color:#1d4ed8}'
        . '.mt-bp-card:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}'
        . '.mt-bp-name{font-size:.8rem;font-weight:600}'
        . '.mt-bp-card .mt-bp .ba-blog-post-badge-wrapper{--mt-fs:.55rem}';
}
