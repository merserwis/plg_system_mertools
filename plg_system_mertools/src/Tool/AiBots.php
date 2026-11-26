<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The bots of AI services in robots.txt, and llms.txt. MerTools keeps a block of its own at the start of the
 * site's robots.txt, between two comment lines, with a group "User-agent: <bot> / Disallow: /" for each bot the
 * settings block; the rest of the file is left byte for byte. The status of each bot is read from the whole
 * file (as a bot reads it: its own group, else that of *), so a rule written elsewhere in the file shows too.
 * llms.txt (llmstxt.org): the site's name, its description and its main pages, as Markdown.
 * No Joomla in here: tests/AiBotsTest.php.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class AiBots
{
    public const BEGIN = '# BEGIN MerTools: AI bots';

    public const END = '# END MerTools: AI bots';

    /** the bots by the name in their User-agent, with whose they are and what for (the key of their language string) */
    public const BOTS = [
        'GPTBot'             => ['OpenAI', 'TRAIN'],
        'OAI-SearchBot'      => ['OpenAI', 'SEARCH'],
        'ChatGPT-User'       => ['OpenAI', 'USER'],
        'ClaudeBot'          => ['Anthropic', 'TRAIN'],
        'Claude-SearchBot'   => ['Anthropic', 'SEARCH'],
        'Claude-User'        => ['Anthropic', 'USER'],
        'PerplexityBot'      => ['Perplexity', 'SEARCH'],
        'Perplexity-User'    => ['Perplexity', 'USER'],
        'Google-Extended'    => ['Google', 'TRAIN'],
        'Applebot-Extended'  => ['Apple', 'TRAIN'],
        'meta-externalagent' => ['Meta', 'TRAIN'],
        'Amazonbot'          => ['Amazon', 'TRAIN'],
        'CCBot'              => ['Common Crawl', 'TRAIN'],
        'Bytespider'         => ['ByteDance', 'TRAIN'],
        'MistralAI-User'     => ['Mistral', 'USER'],
        'DuckAssistBot'      => ['DuckDuckGo', 'SEARCH'],
    ];

    /** @return string[] the known bots of a setting (a list of names), in the order of BOTS */
    public static function selected(mixed $value): array
    {
        $names = array_map('strval', \is_array($value) ? $value : (\is_string($value) && $value !== '' ? explode(',', $value) : []));

        return array_values(array_filter(array_keys(self::BOTS), static fn (string $bot): bool => \in_array($bot, $names, true)));
    }

    /** MerTools' block for these bots ('' for none), lines ended by $eol */
    public static function block(array $bots, string $eol = "\n"): string
    {
        if (!$bots) {
            return '';
        }
        $lines = [self::BEGIN, '# ' . 'Written by the MerTools settings (SEO: AI bots); changes here are replaced.'];
        foreach ($bots as $bot) {
            $lines[] = 'User-agent: ' . $bot;
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }
        $lines[\count($lines) - 1] = self::END;

        return implode($eol, $lines) . $eol;
    }

    /**
     * robots.txt with MerTools' block for $bots in place of the one it had: at the start of the file, an empty line
     * after it; with no bots without a block. The rest of the file as it was, byte for byte (also without its last line
     * ending): taking the block out gives the file back. A bot follows the group of its own name wherever it is.
     */
    public static function apply(string $robots, array $bots): string
    {
        $rest = self::without($robots);
        $add  = self::block($bots, str_contains($robots, "\r\n") ? "\r\n" : "\n");
        if ($add === '' || $rest === '') {
            return $add !== '' ? $add : $rest;
        }

        return $add . (str_contains($robots, "\r\n") ? "\r\n" : "\n") . $rest;
    }

    /** robots.txt without MerTools' block and the one empty line after it */
    public static function without(string $robots): string
    {
        $re = '#^' . preg_quote(self::BEGIN, '#') . '[ \t]*\r?$.*?^' . preg_quote(self::END, '#') . '[ \t]*(?:\r?\n(?:\r?\n)?|$)#ms';

        return preg_match($re, $robots, $m, PREG_OFFSET_CAPTURE) ? substr($robots, 0, $m[0][1]) . substr($robots, $m[0][1] + \strlen($m[0][0])) : $robots;
    }

    /**
     * What robots.txt says to each bot about the whole site: 'blocked' (the home page refused), 'partly' (some
     * paths refused), 'allowed'; with 'own' whether the bot has a group of its own (else it follows "*").
     *
     * @return array<string, array{status: string, own: bool}>
     */
    public static function status(?string $robots): array
    {
        $groups = Sitemap::parseRobots((string) $robots);
        $out    = [];
        foreach (array_keys(self::BOTS) as $bot) {
            $rules  = Sitemap::rulesFor($groups, $bot);
            $own    = false;
            foreach (array_keys($groups) as $name) {
                $own = $own || ($name !== '*' && str_starts_with(strtolower($bot), $name));
            }
            // some path refused: a Disallow not lifted everywhere it applies — only an Allow of the very same pattern does
            // that (same length, Allow wins the tie); "Allow: /private/x" leaves "/private/secret" refused
            $allows = array_column(array_filter($rules, static fn (array $r): bool => $r[0]), 1);
            $refuse = array_filter($rules, static fn (array $r): bool => !$r[0] && !\in_array($r[1], $allows, true));
            // the home page refused, but an Allow that wins for the path it names ("Disallow: /" + "Allow: /public/"): partly
            $opened = array_filter($rules, static fn (array $r): bool => $r[0] && Sitemap::allowed($rules, rtrim(str_replace('*', 'x', $r[1]), '$')));
            $status = !Sitemap::allowed($rules, '/') ? ($opened ? 'partly' : 'blocked') : ($refuse ? 'partly' : 'allowed');
            $out[$bot] = ['status' => $status, 'own' => $own];
        }

        return $out;
    }

    /**
     * llms.txt: "# name", "> description", then the pages as a list of links ([title](address)), the
     * sitemap last. Line breaks of a value become spaces, brackets of a title are escaped.
     *
     * @param array<int, array{0: string, 1: string}> $pages [title, absolute address]
     */
    public static function llms(string $name, string $description, array $pages, string $sitemap, string $pagesHeading = 'Pages'): string
    {
        $flat = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));
        $out  = '# ' . ($flat($name) !== '' ? $flat($name) : 'Website') . "\n";
        if ($flat($description) !== '') {
            $out .= "\n> " . $flat($description) . "\n";
        }
        $links = [];
        foreach ($pages as [$title, $url]) {
            $title = $flat((string) $title);
            $url   = $flat((string) $url);
            if ($title !== '' && preg_match('#^https?://#i', $url)) {
                $links[] = '- [' . addcslashes($title, '[]\\') . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url) . ')';
            }
        }
        if ($sitemap !== '') {
            $links[] = '- [Sitemap](' . $sitemap . ')';
        }
        if ($links) {
            $out .= "\n## " . $flat($pagesHeading) . "\n\n" . implode("\n", array_unique($links)) . "\n";
        }

        return $out;
    }
}
