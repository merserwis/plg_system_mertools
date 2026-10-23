<?php

/**
 * MerTools Sitemap: the addresses of SitemapSource compared with Gridbox's sitemap on the same data
 * (spec-sitemap.md, section 11: test 6, criteria 2, 3, 4 and 7). Read-only, except with --dates: then
 * the dates are worked out as on a build and their state is written to #__mertools_sitemap of that
 * database (use it on a copy).
 *
 * Run with the PHP of a Joomla site that has Gridbox (e.g. the gbx-web container):
 *   php sitemap_check.php <joomla root> [config dir] [robots.txt] [sitemap.xml to compare] [--dates]
 * The config dir holds a configuration.php to use instead of the site's own (another database);
 * robots.txt and sitemap.xml default to the files of the site.
 */

const _JEXEC = 1;
ob_start(); // Joomla's session does not start after output

$withDates = in_array('--dates', $argv, true);
[$root, $config, $robotsFile, $sitemapFile] = array_pad(array_values(array_diff(array_slice($argv, 1), ['--dates'])), 4, '');
if ($root === '' || !is_file($root . '/includes/defines.php')) {
    fwrite(STDERR, "usage: php sitemap_check.php <joomla root> [config dir] [robots.txt] [sitemap.xml]\n");
    exit(2);
}
define('JPATH_BASE', $root);
if ($config !== '') {
    define('JPATH_CONFIGURATION', $config);
}
$_SERVER += ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET'];
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Sitemap.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Feed.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/History.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/SitemapSource.php';

use Balbooa\Component\Gridbox\Site\Helper\GridboxHelper;
use Balbooa\Component\Gridbox\Site\Helper\SitemapHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Sitemap;
use Merserwis\Plugin\System\MerTools\Tool\SitemapSource;

$container = Factory::getContainer();
$container->alias('session.web', 'session.web.site')
    ->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
Factory::$application = $app;
$app->loadLanguage();
JLoader::registerNamespace('Balbooa\\Component\\Gridbox\\Site', JPATH_SITE . '/components/com_gridbox/src');
JLoader::registerNamespace('Balbooa\\Component\\Gridbox\\Administrator', JPATH_ADMINISTRATOR . '/components/com_gridbox/src');
$app->bootComponent('com_gridbox');

$out = [];
$say = function (string $line) use (&$out) {
    $out[] = $line;
};
$db = $container->get(DatabaseInterface::class);
$say('database: ' . $db->setQuery('SELECT DATABASE() AS d')->loadObject()->d);

// Gridbox's own sitemap of these data, built in memory (SitemapHelper::create without File::write)
$t       = microtime(true);
$gridbox = new SitemapHelper();
GridboxHelper::getGridboxMenuItems();
$default = '&Itemid=' . GridboxHelper::getDefaultMenuItem();
$lang    = new stdClass();
$xml     = '';
$menu    = $db->setQuery($db->getQuery(true)
    ->select('m.id, m.link')
    ->from('#__menu_types AS mt')
    ->leftJoin('`#__menu` AS m ON mt.menutype = m.menutype')
    ->where('mt.client_id = 0')
    ->where('published = 1')
    ->where('access = 1')
    ->where('m.type = ' . $db->quote('component')))->loadObjectList();
foreach ($menu as $item) {
    parse_str(str_replace('index.php?', '', $item->link), $q);
    $data = isset($q['option'], $q['id']) ? $gridbox->getMenuModify($q) : (object) ['sitemap_include' => 1, 'lastmod' => '', 'changefreq' => '', 'priority' => ''];
    $xml .= $gridbox->getUrl(Route::_('index.php?Itemid=' . $item->id), $data);
}
$xml .= $gridbox->getPages(0, 'single', $default, $lang);
$apps = $db->setQuery($db->getQuery(true)
    ->select('id, type')
    ->from('#__gridbox_app')
    ->where('type <> ' . $db->quote('system_apps'))
    ->where('published = 1')
    ->where('access = 1'))->loadObjectList();
foreach ($apps as $a) {
    if ($a->type !== 'single') {
        if (!GridboxHelper::getGridboxMenuItemidByApp($a->id)) {
            $row  = $db->setQuery('SELECT sitemap_include FROM #__gridbox_app WHERE id = ' . (int) $a->id)->loadObject();
            $xml .= $gridbox->getUrl(Route::_('index.php?option=com_gridbox&view=blog&app=' . $a->id . '&id=0' . $default), $row);
        }
        $xml .= $gridbox->getCategories($a->id, '', $default, $lang);
        $xml .= $gridbox->getTags($a->id, '', $default, $lang);
        $xml .= $gridbox->getAuthors($a->id, '', $default, $lang);
    }
    $xml .= $gridbox->getPages($a->id, $a->type, $default, $lang);
}
preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);
$reference = array_unique($m[1]);
$say(sprintf('Gridbox in memory: %d addresses (%.1f s)', count($reference), microtime(true) - $t));

// SitemapSource
$t      = microtime(true);
$zone   = new DateTimeZone((string) $app->get('offset', 'UTC') ?: 'UTC');
$now    = (new DateTime('now', $zone))->format('Y-m-d H:i:s');
$source = new SitemapSource($db);
$site   = $source->website();
$items  = $source->collect($now, (string) $app->get('robots', ''));
foreach ($items as &$item) {
    $item['loc'] = Sitemap::loc($item['route'], $site['domain'], $site['slash']);
}
unset($item);
$say(sprintf('SitemapSource: %d items (%.1f s, %.0f MB peak)', count($items), microtime(true) - $t, memory_get_peak_usage(true) / 1048576));

$compare = function (string $label, array $mine, array $theirs) use ($say) {
    $only1 = array_values(array_diff($mine, $theirs));
    $only2 = array_values(array_diff($theirs, $mine));
    $say(sprintf('%s: %d vs %d, %s', $label, count($mine), count($theirs), $only1 || $only2 ? 'DIFFERENT' : 'identical'));
    foreach (array_slice($only1, 0, 10) as $u) {
        $say('   only MerTools: ' . $u);
    }
    foreach (array_slice($only2, 0, 10) as $u) {
        $say('   only Gridbox:  ' . $u);
    }
};

// test 6: the same scope as Gridbox (all types, its flags, no other filter)
$parity = Sitemap::rules(new Registry(['sitemap_type_tag' => 1, 'sitemap_type_author' => 1, 'sitemap_noindex' => 0, 'sitemap_robots' => 0]), null);
$seen   = [];
$kept   = [];
foreach ($items as $item) {
    if (Sitemap::reason($item, $parity, $seen) === null) {
        $kept[] = $item['loc'];
    }
}
$compare('test 6, Gridbox scope vs Gridbox in memory', $kept, $reference);
if ($sitemapFile !== '' || is_file(JPATH_ROOT . '/sitemap.xml')) {
    $file = $sitemapFile !== '' ? $sitemapFile : JPATH_ROOT . '/sitemap.xml';
    preg_match_all('#<loc>(.*?)</loc>#', (string) file_get_contents($file), $m);
    $compare('test 6, Gridbox scope vs ' . basename($file), $kept, array_unique($m[1]));
}

// the defaults: reasons, maps (criteria 2, 3, 7)
$robotsFile = $robotsFile !== '' ? $robotsFile : JPATH_ROOT . '/robots.txt';
$robotsTxt  = is_file($robotsFile) ? (string) file_get_contents($robotsFile) : null;
$rules      = Sitemap::rules(new Registry(), $robotsTxt);
$seen       = [];
$reasons    = [];
$groups     = [];
$why        = [];
$mapItems   = [];
foreach ($items as $item) {
    $reason = Sitemap::reason($item, $rules, $seen);
    if ($reason === null) {
        $groups[$item['group']][] = [$item['loc'], null];
        $mapItems[]               = $item;
    } else {
        $reasons[$reason][$item['type']] = ($reasons[$reason][$item['type']] ?? 0) + 1;
        $why[$item['loc']] = $why[$item['loc']] ?? $reason;
    }
}
$total = array_sum(array_map('count', $groups));
$say(sprintf('defaults: %d addresses in the map%s', $total, $robotsTxt === null ? ' (no robots.txt)' : ''));
foreach (Sitemap::REASONS as $reason) {
    if (isset($reasons[$reason])) {
        $types = implode(', ', array_map(fn ($t, $n) => "$t $n", array_keys($reasons[$reason]), $reasons[$reason]));
        $say(sprintf('   left out, %-10s %5d  (%s)', $reason, array_sum($reasons[$reason]), $types));
    }
}
foreach (Sitemap::layout($groups, true, 10000)['files'] as $name => $entries) {
    $say(sprintf('   %-28s %5d', $name, count($entries)));
}
$blocked = $robotsTxt === null ? 0 : count(array_filter($mapItems, fn ($i) => Sitemap::blocked($rules['robots'], Sitemap::path($i['loc']))));
$noindex = count(array_filter($mapItems, fn ($i) => Sitemap::isNoindex($i['robots'])));
$say("criterion 3: in the map and blocked by robots.txt: $blocked, noindex: $noindex");
$missing = array_diff($reference, array_column($mapItems, 'loc'));
$unexplained = array_filter($missing, fn ($u) => !isset($why[$u]));
$say(sprintf('criterion 7: %d of Gridbox\'s addresses not in the map, %d of them without a reason', count($missing), count($unexplained)));
foreach (array_slice($unexplained, 0, 10) as $u) {
    $say('   no reason: ' . $u);
}

// criterion 4: the dates (--dates: written to the state table of this database)
if ($withDates) {
    $t     = microtime(true);
    $dated = $source->dates($mapItems, time(), false, 'omit', $zone);
    $known = array_values(array_filter(array_column($dated, 'lastmod'), fn ($d) => $d !== null));
    $say(sprintf('criterion 4: %d of %d addresses dated (%.1f s), newest %s, oldest %s, mass dates warning: %s', count($known), count($dated),
        microtime(true) - $t, $known ? Sitemap::time(max($known)) : '-', $known ? Sitemap::time(min($known)) : '-', Sitemap::massDates($known, time()) ? 'yes' : 'no'));
    $byDay = array_count_values(array_map(fn ($d) => gmdate('Y-m-d', $d), $known));
    krsort($byDay);
    $say('   by day: ' . implode(', ', array_map(fn ($d, $n) => "$d $n", array_keys($byDay), array_slice($byDay, 0, 8))));
    foreach (['menu', 'page', 'category', 'app'] as $type) {
        $of = array_filter($dated, fn ($i) => $i['type'] === $type);
        $say(sprintf('   %-9s %5d, %5d dated', $type, count($of), count(array_filter($of, fn ($i) => $i['lastmod'] !== null))));
    }
    // addresses given after the four arguments: their dates
    foreach ($dated as $i) {
        if (in_array($i['loc'], array_slice($argv, 1), true)) {
            $say('   ' . $i['loc'] . ' -> ' . ($i['lastmod'] === null ? 'no lastmod' : Sitemap::time($i['lastmod'])));
        }
    }
}

ob_end_clean();
echo implode("\n", $out), "\n";
exit(count($unexplained) ? 1 : 0);
