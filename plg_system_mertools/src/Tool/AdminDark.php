<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * The Gridbox panel in the dark colours of Joomla's administrator template. Atum marks a dark
 * administrator with data-color-scheme="dark" on <html> (its style set to Dark, the user's own
 * choice, or the system's when it follows it); Gridbox's panel keeps its light stylesheet, so its
 * white boxes stay white while the texts that take their colour from Joomla turn light — and
 * disappear. From Gridbox's own stylesheet a dark twin is made: every rule that sets a colour is
 * repeated under that mark with the colour turned — light backgrounds and borders dark, dark texts
 * light — and the colours that carry meaning (Gridbox's blue, red, green buttons, the dark sidebars)
 * left as they are. The twin holds every colour, background and border of Gridbox's rules, so its
 * rules weigh against each other as Gridbox's own do. Made again when Gridbox's stylesheet changes, so a Gridbox update is followed.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class AdminDark
{
    /** the mark Atum puts on <html> in dark mode */
    public const SCOPE = 'html[data-color-scheme="dark"]';

    /** the properties whose colours are turned, and how: as a background, a border or a text */
    private const KIND = [
        'background' => 'bg', 'background-color' => 'bg', 'background-image' => 'bg',
        'color' => 'text', 'fill' => 'text', 'stroke' => 'text', 'caret-color' => 'text', '-webkit-text-fill-color' => 'text',
        'border' => 'line', 'border-color' => 'line', 'border-top' => 'line', 'border-right' => 'line', 'border-bottom' => 'line', 'border-left' => 'line',
        'border-top-color' => 'line', 'border-right-color' => 'line', 'border-bottom-color' => 'line', 'border-left-color' => 'line',
        'outline' => 'line', 'outline-color' => 'line', 'box-shadow' => 'line', 'column-rule' => 'line', 'column-rule-color' => 'line',
    ];

    private const NAMED = ['white' => [255, 255, 255], 'black' => [0, 0, 0], 'whitesmoke' => [245, 245, 245], 'gainsboro' => [220, 220, 220],
        'lightgray' => [211, 211, 211], 'lightgrey' => [211, 211, 211], 'silver' => [192, 192, 192], 'gray' => [128, 128, 128], 'grey' => [128, 128, 128],
        'darkgray' => [169, 169, 169], 'darkgrey' => [169, 169, 169], 'dimgray' => [105, 105, 105], 'dimgrey' => [105, 105, 105], 'snow' => [255, 250, 250],
        'ghostwhite' => [248, 248, 255], 'aliceblue' => [240, 248, 255], 'ivory' => [255, 255, 240], 'beige' => [245, 245, 220]];

    /** The dark twin of a stylesheet: only the rules that set a colour, each under the dark mark. */
    public static function css(string $css, string $scope = self::SCOPE): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        return self::block($css, $scope);
    }

    /** The rules of a block of CSS (a stylesheet or the inside of @media / @supports), turned. */
    private static function block(string $css, string $scope): string
    {
        $out = '';
        $len = \strlen($css);
        $i   = 0;
        while ($i < $len) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));
            // a statement before the rule (@import, @charset): skipped
            while (($semi = strpos($prelude, ';')) !== false && ($prelude[0] ?? '') === '@') {
                $prelude = trim(substr($prelude, $semi + 1));
            }
            $close = self::matching($css, $open);
            $inner = substr($css, $open + 1, $close - $open - 1);
            $i     = $close + 1;
            if ($prelude === '') {
                continue;
            }
            if ($prelude[0] === '@') {
                if (preg_match('/^@(media|supports|layer|container)\b/i', $prelude)) {
                    $nested = self::block($inner, $scope);
                    if ($nested !== '') {
                        $out .= $prelude . '{' . $nested . '}';
                    }
                }
                // @keyframes, @font-face, @page: no colours of the page to turn
                continue;
            }
            $declarations = self::declarations($inner);
            if ($declarations !== '') {
                $out .= self::scope($prelude, $scope) . '{' . $declarations . '}';
            }
        }

        return $out;
    }

    /** The position of the brace that closes the one at $open (or the end). */
    private static function matching(string $css, int $open): int
    {
        $depth = 0;
        $len   = \strlen($css);
        $quote = '';
        for ($j = $open; $j < $len; $j++) {
            $c = $css[$j];
            if ($quote !== '') {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $j;
            }
        }

        return $len;
    }

    /**
     * The colour declarations of a rule, turned. The ones that do not change are repeated too (also
     * "border: none", "background: transparent"): every twin is one step more specific than its
     * rule, so a twin left out would lose to the twin of a less specific rule it beat before (the
     * white number of the current page under the grey of the other page numbers).
     */
    private static function declarations(string $body): string
    {
        $out = [];
        foreach (self::split($body, ';') as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $kind     = self::KIND[$property] ?? null;
            if ($kind === null) {
                continue;
            }
            $value     = trim(substr($declaration, $colon + 1));
            $important = '';
            if (preg_match('/\s*!\s*important\s*$/i', $value, $m)) {
                $important = '!important';
                $value     = substr($value, 0, -\strlen($m[0]));
            }
            // a colour from a variable of Joomla's template: Joomla's to set (its dark values are not known here)
            if (stripos($value, 'var(') !== false) {
                continue;
            }
            $out[] = $property . ':' . self::value($value, $kind) . $important;
        }

        return implode(';', $out);
    }

    /** The colours in a value turned (url(…) and var(…) left alone). */
    public static function value(string $value, string $kind): string
    {
        return preg_replace_callback('/url\([^)]*\)|var\([^)]*\)|#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\([^)]*\)|\b(?:' . implode('|', array_keys(self::NAMED)) . ')\b/i',
            static function ($m) use ($kind) {
                $token = $m[0];
                if (preg_match('/^(url|var)\(/i', $token)) {
                    return $token;
                }
                $rgba = self::parse($token);
                if ($rgba === null) {
                    return $token;
                }
                $turned = self::turn($rgba, $kind);

                return $turned === null ? $token : self::format($turned);
            }, $value) ?? $value;
    }

    /**
     * A colour turned for a dark page, or null when it stays: a light background or border becomes dark
     * (its hue kept, a little of it), a dark text light; strong colours and dark backgrounds stay.
     *
     * @param array{0: int, 1: int, 2: int, 3: float} $rgba
     *
     * @return array{0: int, 1: int, 2: int, 3: float}|null
     */
    public static function turn(array $rgba, string $kind): ?array
    {
        [$h, $s, $l] = self::hsl($rgba[0], $rgba[1], $rgba[2]);
        if ($kind === 'text') {
            // a dark text: light; a coloured text (a link, a red warning) stays as it is
            if ($l > 0.55 || ($s >= 0.35 && $l >= 0.3)) {
                return null;
            }
            $l = 0.93 - $l * 0.6;
            $s = min($s, 0.3);
        } else {
            if ($l < 0.6) {
                return null;
            }
            $l = $kind === 'bg' ? 0.15 + (1 - $l) * 0.45 : 0.24 + (1 - $l) * 0.45;
            $s = min($s, 0.3);
        }
        if ($s < 0.04) {
            // greys: the blue-grey of Joomla's dark administrator
            $h = 215;
            $s = $kind === 'text' ? 0.12 : 0.16;
        }
        [$r, $g, $b] = self::rgb($h, $s, $l);

        return [$r, $g, $b, $rgba[3]];
    }

    /** @return array{0: int, 1: int, 2: int, 3: float}|null */
    public static function parse(string $token): ?array
    {
        $t = strtolower(trim($token));
        if (isset(self::NAMED[$t])) {
            return [...self::NAMED[$t], 1.0];
        }
        if ($t[0] === '#') {
            $hex = substr($t, 1);
            if (\strlen($hex) === 3 || \strlen($hex) === 4) {
                $hex = preg_replace('/(.)/', '$1$1', $hex) ?? $hex;
            }
            if (\strlen($hex) !== 6 && \strlen($hex) !== 8) {
                return null;
            }

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), \strlen($hex) === 8 ? round(hexdec(substr($hex, 6, 2)) / 255, 3) : 1.0];
        }
        if (!preg_match('/^(rgba?|hsla?)\((.*)\)$/', $t, $m)) {
            return null;
        }
        $parts = preg_split('/\s*[,\/]\s*|\s+/', trim($m[2])) ?: [];
        if (\count($parts) < 3 || str_contains($m[2], 'var(') || str_contains($m[2], 'from ')) {
            return null;
        }
        $alpha = isset($parts[3]) ? (str_ends_with($parts[3], '%') ? (float) $parts[3] / 100 : (float) $parts[3]) : 1.0;
        if ($m[1][0] === 'r') {
            $c = array_map(static fn ($p) => str_ends_with($p, '%') ? (int) round((float) $p * 2.55) : (int) round((float) $p), \array_slice($parts, 0, 3));

            return [max(0, min(255, $c[0])), max(0, min(255, $c[1])), max(0, min(255, $c[2])), $alpha];
        }
        [$r, $g, $b] = self::rgb((float) $parts[0], (float) $parts[1] / 100, (float) $parts[2] / 100);

        return [$r, $g, $b, $alpha];
    }

    private static function format(array $c): string
    {
        return $c[3] >= 1 ? sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]) : sprintf('rgba(%d,%d,%d,%s)', $c[0], $c[1], $c[2], rtrim(rtrim(number_format($c[3], 3, '.', ''), '0'), '.'));
    }

    /** @return array{0: float, 1: float, 2: float} hue 0–360, saturation and lightness 0–1 */
    private static function hsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l   = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r      => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g      => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default  => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    /** Text split on a character outside brackets and quotes. */
    private static function split(string $text, string $on): array
    {
        $out   = [];
        $depth = 0;
        $quote = '';
        $start = 0;
        $len   = \strlen($text);
        for ($j = 0; $j < $len; $j++) {
            $c = $text[$j];
            if ($quote !== '') {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $quote) {
                    $quote = '';
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            } elseif ($c === $on && $depth === 0) {
                $out[]  = substr($text, $start, $j - $start);
                $start = $j + 1;
            }
        }
        $out[] = substr($text, $start);

        return $out;
    }

    /** Each selector of a rule under the dark mark (one that names html or :root gets it there). */
    public static function scope(string $selectors, string $scope = self::SCOPE): string
    {
        $mark = substr($scope, 4);
        $out  = [];
        foreach (self::split($selectors, ',') as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            if (preg_match('/^html(?![\w-])/i', $selector)) {
                $out[] = 'html' . $mark . substr($selector, 4);
            } elseif (preg_match('/^:root(?![\w-])/i', $selector)) {
                $out[] = ':root' . $mark . substr($selector, 5);
            } else {
                $out[] = $scope . ' ' . $selector;
            }
        }

        return implode(',', $out);
    }
}
