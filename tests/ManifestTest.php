<?php

/*
 * The settings and the pages of the administrator stay together: every setting of the plugin's manifest
 * is on exactly one page of com_mertools, a field shown on a condition (showon) depends only on fields
 * of its own page (else it would never show), every page and tab of Helper/Catalog.php exists, every
 * switch the control panel or the quick setup reads is a setting, and every text has both languages.
 */

define('_JEXEC', 1);
spl_autoload_register(function ($c) {
    if ($c === 'Joomla\\Registry\\Registry') {
        eval('namespace Joomla\\Registry; class Registry {}');
    }
});
require __DIR__ . '/../com_mertools/admin/src/Helper/Catalog.php';

use Merserwis\Component\MerTools\Administrator\Helper\Catalog as C;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$root = __DIR__ . '/..';
$xml  = simplexml_load_file($root . '/plg_system_mertools/mertools.xml');
$pageOf = [];
$fieldsets = [];
$dupes = [];
foreach ($xml->config->fields->fieldset as $fs) {
    $page = (string) $fs['page'];
    $fieldsets[(string) $fs['name']] = $page;
    foreach ($fs->field as $f) {
        $name = (string) $f['name'];
        if (isset($pageOf[$name])) {
            $dupes[] = $name;
        }
        $pageOf[$name] = $page;
    }
}
$check('every setting once', $dupes, []);
$homeless = array_keys(array_filter($pageOf, fn ($p, $n) => $p === '' && !in_array($n, ['intro_note', 'mt_admin'], true), ARRAY_FILTER_USE_BOTH));
$check('every setting on a page (only the intro of the plugin form is not)', $homeless, []);

$cross = [];
foreach ($xml->config->fields->fieldset as $fs) {
    foreach ($fs->xpath('.//field[@showon]') as $f) {
        preg_match_all('/(?:^|\[AND\]|\[OR\])([a-z0-9_]+)!?:/', (string) $f['showon'], $m);
        foreach ($m[1] as $ref) {
            // fields of a subform refer to their own row
            if (isset($pageOf[$ref]) && $pageOf[$ref] !== (string) $fs['page'] && isset($pageOf[(string) $f['name']])) {
                $cross[] = $f['name'] . ' -> ' . $ref;
            }
        }
    }
}
$check('showon only within a page', $cross, []);

$pagesInXml = array_values(array_unique(array_filter($fieldsets)));
sort($pagesInXml);
$pages = array_keys(C::PAGES);
sort($pages);
$check('the pages of the catalog are the pages of the manifest', $pages, $pagesInXml);
$config = array_keys(array_filter($fieldsets, fn ($p) => $p === 'config'));
$check('the configuration tiles are its tabs, in order', array_keys(C::CONFIG), $config);

$keys = [];
foreach (C::PAGES as $p) {
    $keys = array_merge($keys, $p['on']);
}
foreach (C::CONFIG as $t) {
    $keys = array_merge($keys, $t['on']);
}
foreach (C::SETUP as $items) {
    foreach ($items as $item) {
        $keys = array_merge($keys, $item['set']);
    }
}
$check('every switch read by the catalog is a setting', array_values(array_diff(array_unique($keys), array_keys($pageOf))), []);
$setupKeys = [];
foreach (C::SETUP as $items) {
    foreach ($items as $item) {
        $setupKeys = array_merge($setupKeys, $item['set']);
    }
}
$check('the quick setup sets only switches (0/1 radios)', array_values(array_filter(array_unique($setupKeys), function ($k) use ($xml) {
    $f = $xml->xpath('//field[@name="' . $k . '"]')[0] ?? null;

    return !$f || (string) $f['type'] !== 'radio';
})), []);

// languages
$ini = function (string $file): array {
    preg_match_all('/^([A-Z0-9_]+)=/m', (string) file_get_contents($file), $m);

    return array_flip($m[1]);
};
foreach (['en-GB', 'pl-PL'] as $tag) {
    $com = $ini("$root/com_mertools/admin/language/$tag/com_mertools.ini") + $ini("$root/com_mertools/admin/language/$tag/com_mertools.sys.ini");
    $plg = $ini("$root/plg_system_mertools/language/$tag/plg_system_mertools.ini");
    $need = [];
    foreach (array_keys(C::PAGES) as $p) {
        $need[] = 'COM_MERTOOLS_PAGE_' . strtoupper($p);
        $need[] = 'COM_MERTOOLS_PAGE_' . strtoupper($p) . '_DESC';
    }
    foreach (array_keys(C::CONFIG) as $t) {
        $need[] = 'COM_MERTOOLS_CFG_' . strtoupper($t);
        $need[] = 'COM_MERTOOLS_CFG_' . strtoupper($t) . '_DESC';
    }
    foreach (array_merge(C::GROUPS, ['config']) as $g) {
        $need[] = 'COM_MERTOOLS_GROUP_' . strtoupper($g);
        $need[] = 'COM_MERTOOLS_GROUP_' . strtoupper($g) . '_DESC';
    }
    foreach (C::SETUP as $section => $items) {
        $need[] = 'COM_MERTOOLS_GROUP_' . strtoupper($section);
        foreach (array_keys($items) as $s) {
            $need[] = 'COM_MERTOOLS_SETUP_' . strtoupper($s) . '_LABEL';
            $need[] = 'COM_MERTOOLS_SETUP_' . strtoupper($s) . '_DESC';
        }
    }
    foreach (glob("$root/com_mertools/{admin/src/*/*.php,admin/src/*/*/*.php,admin/tmpl/*/*.php,com_mertools.xml}", GLOB_BRACE) as $file) {
        // a key built in code ('COM_MERTOOLS_PAGE_' . …) is checked above, from the catalog
        preg_match_all('/COM_MERTOOLS[A-Z0-9_]*/', (string) file_get_contents($file), $m);
        $need = array_merge($need, array_filter($m[0], fn ($k) => !str_ends_with($k, '_')));
    }
    $check("$tag: every text of the component", array_values(array_diff(array_unique($need), array_keys($com))), []);
    $labels = [];
    foreach ($xml->config->fields->fieldset as $fs) {
        if ((string) $fs['label'] !== '') {
            $labels[] = (string) $fs['label'];
        }
    }
    $check("$tag: every tab label of the plugin", array_values(array_diff(array_unique($labels), array_keys($plg))), []);
}

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
