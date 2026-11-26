<?php

/**
 * Standalone test of AiBots: MerTools' block of AI bots in robots.txt (the rest of the file byte for byte),
 * what the whole file says to each bot, llms.txt.  Run: php tests/AiBotsTest.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../plg_system_mertools/src/Tool/Sitemap.php';
require __DIR__ . '/../plg_system_mertools/src/Tool/AiBots.php';

use Merserwis\Plugin\System\MerTools\Tool\AiBots;

$fail  = 0;
$check = function (string $label, $got, $exp) use (&$fail) {
    $ok = $got === $exp;
    $fail += $ok ? 0 : 1;
    printf("%s %-64s %s\n", $ok ? 'OK ' : 'FAIL', $label, $ok ? '' : "\n   got=" . var_export($got, true) . "\n   exp=" . var_export($exp, true));
};

$joomla = "# If the Joomla site is installed within a folder\nUser-agent: *\nDisallow: /administrator/\nDisallow: /tmp/\n";
$check('settings: the known bots, in their order', AiBots::selected(['PerplexityBot', 'GPTBot', 'Evil<bot>', '']), ['GPTBot', 'PerplexityBot']);
$check('settings: a list as text', AiBots::selected('GPTBot,CCBot'), ['GPTBot', 'CCBot']);
$check('settings: nothing', AiBots::selected(null), []);

$on = AiBots::apply($joomla, ['GPTBot', 'CCBot']);
$check('the block at the start, an empty line, then the file as it was', $on, AiBots::BEGIN . "\n"
    . "# Written by the MerTools settings (SEO: AI bots); changes here are replaced.\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: CCBot\nDisallow: /\n" . AiBots::END . "\n\n" . $joomla);
$check('applied again: the same file', AiBots::apply($on, ['GPTBot', 'CCBot']), $on);
$check('fewer bots: the block replaced, not added', substr_count(AiBots::apply($on, ['CCBot']), AiBots::BEGIN) . '|' . str_contains(AiBots::apply($on, ['CCBot']), 'GPTBot'), '1|');
$check('no bots: the file as it was before', AiBots::apply($on, []), $joomla);
$check('no bots, no block: the file untouched', AiBots::apply($joomla, []), $joomla);
// the copy of merserwis.pl: Windows line endings, no line ending after the last line, empty lines in it
$site = "User-agent: *\r\nDisallow: /administrator/\r\n\r\nSitemap: https://example.com/sitemap.xml";
foreach (['no last line ending, CRLF' => $site, 'empty lines at the end' => $joomla . "\n\n", 'one line' => 'User-agent: *', 'a BOM' => "\xEF\xBB\xBF" . $joomla] as $what => $file) {
    $check('back byte for byte: ' . $what, AiBots::apply(AiBots::apply($file, ['GPTBot', 'ClaudeBot']), []), $file);
}
$on2 = AiBots::apply($site, ['ClaudeBot']);
$check('Windows line endings kept, also in the block', str_contains(str_replace("\r\n", '', $on2), "\n"), false);
$check('an empty file: only the block, and back to empty', [str_starts_with(AiBots::apply('', ['GPTBot']), AiBots::BEGIN), AiBots::apply(AiBots::apply('', ['GPTBot']), [])], [true, '']);
$check('a block moved by hand to the end: found and taken out', AiBots::apply($joomla . "\n" . AiBots::block(['GPTBot']), []), $joomla . "\n");

$st = AiBots::status($on);
$check('status: blocked by the block, own group', $st['GPTBot'], ['status' => 'blocked', 'own' => true]);
$check('status: another follows * (some paths refused)', $st['ClaudeBot'], ['status' => 'partly', 'own' => false]);
$check('status: allowed without rules', AiBots::status("User-agent: *\nDisallow:\n")['PerplexityBot'], ['status' => 'allowed', 'own' => false]);
$check('status: no robots.txt, all allowed', AiBots::status(null)['GPTBot']['status'], 'allowed');
// a group of the bot elsewhere in the file that allows it: Google joins the groups, Allow wins the tie — shown as it is
$check('status: an Allow of the bot elsewhere wins over the block, shown', AiBots::status("User-agent: GPTBot\nAllow: /\n\n" . AiBots::block(['GPTBot']))['GPTBot']['status'], 'allowed');
$check('status: an Allow of one path does not lift a Disallow of many', AiBots::status("User-agent: GPTBot\nDisallow: /private/*\nAllow: /private/x\n")['GPTBot']['status'], 'partly');
$check('status: all refused but one path opened: partly', AiBots::status("User-agent: GPTBot\nDisallow: /\nAllow: /public/\n")['GPTBot']['status'], 'partly');
$check('status: all refused, an Allow of the same "/" losing nothing: still the tie rule (allowed)', AiBots::status("User-agent: GPTBot\nDisallow: /\nAllow: /\n")['GPTBot']['status'], 'allowed');
$check('status: a rule of the owner\'s file read too', AiBots::status("User-agent: PerplexityBot\nDisallow: /\n")['PerplexityBot']['status'], 'blocked');

$l = AiBots::llms('Merserwis', "Mierniki\n i kamery", [['Oferta', 'https://example.com/oferta'], ['Kontakt [PL]', 'https://example.com/a b(1)'], ['Zły', 'javascript:alert(1)'], ['', 'https://example.com/x']],
    'https://example.com/sitemap.xml', 'Strony');
$check('llms.txt: name, description, pages, sitemap', $l, "# Merserwis\n\n> Mierniki i kamery\n\n## Strony\n\n- [Oferta](https://example.com/oferta)\n"
    . "- [Kontakt \\[PL\\]](https://example.com/a%20b%281%29)\n- [Sitemap](https://example.com/sitemap.xml)\n");
$check('llms.txt: no description, no pages', AiBots::llms('', '', [], ''), "# Website\n");

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
