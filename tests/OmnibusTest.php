<?php

define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Omnibus.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/PriceRequest.php';
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Omnibus;
use Merserwis\Plugin\System\MerTools\Tool\PriceRequest;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-60s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};
$check('off by default', Omnibus::enabled(new Registry()), false);
$check('on when set', Omnibus::enabled(new Registry(['shop_omnibus' => '1'])), true);
$check('lines hidden unless shown by MerTools', Omnibus::css(), '.omnibus_info{display:none!important}.omnibus_info.mt-omnibus-on{display:block!important}');

// the tags as merserwis.pl prints them (with a nonce), and variants
$nonce = 'ZDZjMGRjNjQyMjVmN2JhMWFmMWMy';
$page  = "<head>\n<script src=\"/media/system/js/core.min.js?d453df\"></script>\n"
    . "<script src=\"/plugins/system/omnibus/assets/scprice.js\" nonce=\"$nonce\"></script>\n"
    . "<script src=\"/plugins/system/omnibus/assets/scr_omnibus.js\" nonce=\"$nonce\"></script>\n"
    . "<script>var x = '/plugins/system/omnibus/assets/scr_omnibus.js';</script>\n</head>";
$noOmnibus = Omnibus::dropScript($page, Omnibus::SCRIPT);
$check('Omnibus script tag gone', str_contains($noOmnibus, 'src="/plugins/system/omnibus/assets/scr_omnibus.js"'), false);
$check('the price script stays', str_contains($noOmnibus, 'assets/scprice.js'), true);
$check('other scripts and inline code stay', substr_count($noOmnibus, '<script'), 3);
$check('no empty line left', str_contains($noOmnibus, "></script>\n<script>var x"), true);
$both = Omnibus::dropScript($noOmnibus, PriceRequest::OLD_SCRIPT);
$check('old price script gone', str_contains($both, 'scprice.js'), false);
$check('core script still there', str_contains($both, 'core.min.js'), true);
$check('with a version and the full address', Omnibus::dropScript('<script defer src="https://www.merserwis.pl/plugins/system/omnibus/assets/scr_omnibus.js?v=2"></script>', Omnibus::SCRIPT), '');
$check('a file of another name stays', Omnibus::dropScript('<script src="/plugins/system/omnibus/assets/scr_omnibus.js.bak"></script>', Omnibus::SCRIPT), '<script src="/plugins/system/omnibus/assets/scr_omnibus.js.bak"></script>');
$check('page without it unchanged', Omnibus::dropScript('<p>x</p>', Omnibus::SCRIPT), '<p>x</p>');
echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
