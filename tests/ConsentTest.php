<?php

/*
 * Consent to cookies: the trackers of the rules wait as text/plain with their level (their <noscript>
 * pixel taken out), Google Tag Manager and the page's own scripts stay, scripts marked by hand or for
 * TermsFeed are left as they are; TermsFeed's window is taken out of merserwis.pl's real code; the
 * head script goes first in <head> with the nonce; the settings are read safely.
 */

define('_JEXEC', 1);
spl_autoload_register(function ($c) { if ($c === 'Joomla\\Registry\\Registry') { eval('namespace Joomla\\Registry; class Registry { public function __construct(private array $d = []) {} public function get($k, $def = null) { return $this->d[$k] ?? $def; } }'); } });
require __DIR__ . '/../plg_system_mertools/src/Tool/Speedup.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/Consent.php';

use Joomla\Registry\Registry;
use Merserwis\Plugin\System\MerTools\Tool\Consent;

$fail = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-66s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$p = new Registry();
$check('off by default', Consent::enabled($p), false);
$check('all three levels by default', Consent::levels($p), ['functionality', 'tracking', 'targeting']);
$check('levels as saved, unknown ones dropped', Consent::levels(new Registry(['consent_levels' => ['targeting', 'tracking', 'x']])), ['tracking', 'targeting']);
$check('rules: default list read', Consent::rules($p)[0], ['targeting', 'connect.facebook.net']);
$check('rules: bad lines skipped, level lower-cased', Consent::rules(new Registry(['consent_scripts' => "Tracking:  clarity.ms \nnothing here\nads: x\n"])), [['tracking', 'clarity.ms']]);
$check('policy: an address from the root kept', Consent::policyUrl(new Registry(['consent_policy' => '/blog/regulamin/polityka-prywatnosci'])), '/blog/regulamin/polityka-prywatnosci');
$check('policy: javascript: refused', Consent::policyUrl(new Registry(['consent_policy' => 'javascript:alert(1)'])), '');
$check('policy: quote refused', Consent::policyUrl(new Registry(['consent_policy' => '/a"onmouseover=x'])), '');
$cfg = Consent::jsConfig(new Registry(['consent_accent' => 'red;}', 'consent_position' => 'top', 'consent_days' => 9999]), ['title' => 'T', 'text' => 'X']);
$check('accent: only a hex colour', $cfg['accent'], '');
$check('position: unknown -> centre', $cfg['pos'], 'center');
$check('days: at most 13 months', $cfg['days'], 395);
$check('texts: defaults of the language when empty', [$cfg['t']['title'], $cfg['t']['text']], ['T', 'X']);
$check('texts: own title', Consent::jsConfig(new Registry(['consent_title' => ' Ciasteczka ']), ['title' => 'T', 'text' => 'X'])['t']['title'], 'Ciasteczka');

// merserwis.pl's head and body (GTM and the pixel delayed by MerTools afterwards, TermsFeed's window)
$fb   = "<script>\n!function(f,b,e,v,n,t,s)\n{if(f.fbq)return;n=f.fbq=function(){};t.src=v}(window, document,'script',\n'https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', '1606311083614425');\nfbq('track', 'PageView');\n</script>";
$gtm  = "<script>(function(w,d,s,l,i){j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;})(window,document,'script','dataLayer','GTM-TJP5CT6');</script>";
$ns   = '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1606311083614425&amp;ev=PageView&amp;noscript=1"/></noscript>';
$tf   = "<!-- Cookie Consent by TermsFeed -->\n<script type=\"text/javascript\" src=\"/assets/js/cookie-consent.js\"\n  charset=\"UTF-8\"></script>\n"
    . "<script type=\"text/javascript\" charset=\"UTF-8\">\n  document.addEventListener('DOMContentLoaded', function () {\n    cookieconsent.run({\"notice_banner_type\": \"simple\"});\n  });\n</script>\n\n"
    . "<noscript>Free cookie consent management tool by TermsFeed</a></noscript>\n<!-- End Cookie Consent by TermsFeed -->";
$json = '<script type="application/json" class="joomla-script-options new">{"fbq(":1}</script>';
$hand = '<script type="text/plain" cookie-consent="tracking" src="https://static.hotjar.com/c/hotjar-1.js"></script>';
$own  = '<script src="/media/plg_system_mertools/js/mertools-consent.min.js" defer></script>';
$html = "<html><head><meta charset=\"utf-8\">$json$gtm</head><body><p>x</p>$fb$ns$hand$own$tf</body></html>";

$held = Consent::hold($html, Consent::rules($p));
$check('pixel held as targeting', substr_count($held, '<script type="text/plain" data-mertools-consent="targeting">'), 1);
$check('pixel code kept whole', str_contains($held, "fbq('track', 'PageView');\n</script>"), true);
$check('pixel <noscript> taken out', str_contains($held, 'facebook.com/tr'), false);
$check('GTM runs (Consent Mode)', str_contains($held, $gtm), true);
$check('JSON options untouched', str_contains($held, $json), true);
$check('script marked for TermsFeed: its address hidden from Gridbox\'s joining', str_contains($held, '<script type="text/plain" cookie-consent="tracking" data-mertools-src="https://static.hotjar.com/c/hotjar-1.js"></script>'), true);
$check('a marked script that is not text/plain untouched', Consent::hold('<script cookie-consent="strictly-necessary" src="/a.js"></script>', []), '<script cookie-consent="strictly-necessary" src="/a.js"></script>');
$check('held twice: the same', Consent::hold($held, Consent::rules($p)), $held);
$check('a held local script: no src left for Gridbox', Consent::hold('<script src="/media/x/connect.facebook.net.js" defer></script>', Consent::rules($p)),
    '<script type="text/plain" data-mertools-consent="targeting" data-mertools-src="/media/x/connect.facebook.net.js" defer></script>');
$check('own script untouched', str_contains($held, $own), true);
$check('no rules: only the hand-marked address hidden', Consent::hold($html, []), str_replace($hand, '<script type="text/plain" cookie-consent="tracking" data-mertools-src="https://static.hotjar.com/c/hotjar-1.js"></script>', $html));
$typed = Consent::hold("<script type='text/javascript' src=\"https://connect.facebook.net/x.js\"></script>", Consent::rules($p));
$check('a typed script loses its type', $typed, '<script type="text/plain" data-mertools-consent="targeting" data-mertools-src="https://connect.facebook.net/x.js"></script>');

$clean = Consent::dropTermsFeed($held);
$check('TermsFeed loader gone', str_contains($clean, 'cookie-consent.js'), false);
$check('TermsFeed run() gone', str_contains($clean, 'cookieconsent.run'), false);
$check('TermsFeed noscript and comments gone', str_contains($clean, 'TermsFeed'), false);
$check('the rest of the page stays', str_contains($clean, '<p>x</p>') && str_contains($clean, $gtm) && str_contains($clean, $own), true);
$check('a script marked cookie-consent= stays', str_contains($clean, 'cookie-consent="tracking" data-mertools-src='), true);

$head = Consent::injectHead($clean, Consent::headScript($p), 'N0nce');
$check('head script first in <head>, with the nonce', str_starts_with(substr($head, strlen('<html><head>')), '<script nonce="N0nce" class="exclude-deffer" data-mertools-consent-head>'), true);
$check('head script before GTM', strpos($head, 'consent","default"') < strpos($head, 'googletagmanager'), true);
$check('no <head>: page unchanged', Consent::injectHead('<p>x</p>', 'x', ''), '<p>x</p>');
$check('head script held by nothing', Consent::hold($head, Consent::rules($p)) === $head, true);
$check('without Clarity no clarity stub', str_contains(Consent::headScript(new Registry(['consent_clarity' => 0])), 'clarity'), false);
$check('without TermsFeed its cookie not read', str_contains(Consent::headScript(new Registry(['consent_termsfeed' => 0])), 'cookie_consent'), false);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
