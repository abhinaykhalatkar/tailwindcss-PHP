<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate\Value;

use TailwindPhp\Engine\Candidate\Parser;

/**
 * Data-type inference for arbitrary values — a byte-level port of Tailwind
 * v4's `inferDataType` (design doc 07 §Value resolution rules, rule 5).
 *
 * A utility that accepts several kinds of value (`bg-[…]` may be a colour, an
 * image, a position or a size) asks {@see self::infer()} with the types it
 * accepts, in its own order; the first type whose check passes wins. A value
 * starting with `var(` is never inferred — its type is unknowable — so the
 * utility falls back to its default or needs a type hint. Regex-free (T-12).
 *
 * | type          | passes when (v4 example)                                                   |
 * |---------------|----------------------------------------------------------------------------|
 * | color         | starts with `#`; a colour function `rgb[a]( hsl[a]( hwb( color( lab(`      |
 * |               | `lch( oklab( oklch( light-dark( color-mix(` in any case; or a named or     |
 * |               | system colour (`#0088cc`, `rgb(0 0 0)`, `red`, `currentcolor`)              |
 * | length        | a number with a length unit (`px rem vh cqw Q` …), or a math function      |
 * |               | (`32rem`, `calc(…)`)                                                       |
 * | percentage    | a number followed by `%`, or a math function (`50%`)                       |
 * | ratio         | `<number> / <number>`, spaces optional, or a math function (`16/9`)        |
 * | number        | a plain number with optional sign, decimals, exponent; or a math function  |
 * |               | (`1.5`, `-2e3`)                                                            |
 * | integer       | a non-negative integer written canonically (`12`; not `01`, `1e3`, `-1`)   |
 * | url           | `url(` … `)` (`url(/a.png)`)                                               |
 * | position      | space-separated parts, each `center top right bottom left`, a length or a  |
 * |               | percentage; `var(…)` parts ignored; at least one part (`center_top`)       |
 * | bg-size       | comma-separated sizes, each `cover`, `contain`, or one or two of `auto`,   |
 * |               | a length or a percentage (`auto_100%`)                                     |
 * | line-width    | `thin`, `medium` or `thick`                                                |
 * | image         | comma-separated parts, each `url(…)`, a gradient, `element(` `image(`      |
 * |               | `cross-fade(` `image-set(`; `var(…)` parts ignored; at least one part      |
 * | family-name   | comma-separated parts, none starting with a digit; `var(…)` parts ignored  |
 * |               | (`Inter,_sans-serif`)                                                      |
 * | generic-name  | `serif sans-serif monospace cursive fantasy system-ui ui-serif`            |
 * |               | `ui-sans-serif ui-monospace ui-rounded math emoji fangsong`                |
 * | absolute-size | `xx-small` … `xxx-large`                                                   |
 * | relative-size | `larger`, `smaller`                                                        |
 * | angle         | a number with `deg rad grad turn` (`45deg`)                                |
 * | vector        | three numbers separated by spaces (`1_0_0`)                                |
 *
 * Splitting on `,` and on spaces is depth-aware ({@see Parser::segment()}),
 * exactly as v4 does it, and like v4 the parts are not trimmed.
 */
final class Classifier
{
    /**
     * Every type, in the order {@see self::classify()} tries them.
     * `family-name` is last because it accepts almost any word and would
     * otherwise shadow `generic-name` and the size keywords. Utilities use
     * {@see self::infer()} with their own type list, as v4 does; `classify()`
     * is a convenience for diagnostics and tests.
     *
     * @var list<string>
     */
    public const TYPES = [
        'color', 'length', 'percentage', 'ratio', 'number', 'integer', 'url', 'position', 'bg-size',
        'line-width', 'image', 'generic-name', 'absolute-size', 'relative-size', 'angle', 'vector',
        'family-name',
    ];

    private const DIGITS = '0123456789';

    /**
     * @var array<string, true>
     */
    private const LENGTH_UNITS = [
        'cm' => true, 'mm' => true, 'Q' => true, 'in' => true, 'pc' => true, 'pt' => true, 'px' => true,
        'em' => true, 'ex' => true, 'ch' => true, 'rem' => true, 'lh' => true, 'rlh' => true,
        'vw' => true, 'vh' => true, 'vmin' => true, 'vmax' => true, 'vb' => true, 'vi' => true,
        'svw' => true, 'svh' => true, 'lvw' => true, 'lvh' => true, 'dvw' => true, 'dvh' => true,
        'cqw' => true, 'cqh' => true, 'cqi' => true, 'cqb' => true, 'cqmin' => true, 'cqmax' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ANGLE_UNITS = ['deg' => true, 'rad' => true, 'grad' => true, 'turn' => true];

    /**
     * @var list<string>
     */
    private const MATH_FUNCTIONS = [
        'calc(', 'min(', 'max(', 'clamp(', 'mod(', 'rem(', 'sin(', 'cos(', 'tan(', 'asin(', 'acos(',
        'atan(', 'atan2(', 'pow(', 'sqrt(', 'hypot(', 'log(', 'exp(', 'round(',
    ];

    /**
     * @var list<string>
     */
    private const COLOR_FUNCTIONS = [
        'rgb(', 'rgba(', 'hsl(', 'hsla(', 'hwb(', 'color(', 'lab(', 'lch(', 'oklab(', 'oklch(',
        'light-dark(', 'color-mix(',
    ];

    /**
     * @var list<string>
     */
    private const IMAGE_FUNCTIONS = [
        'element(', 'image(', 'cross-fade(', 'image-set(',
        'linear-gradient(', 'radial-gradient(', 'conic-gradient(',
        'repeating-linear-gradient(', 'repeating-radial-gradient(', 'repeating-conic-gradient(',
    ];

    /**
     * @var array<string, true>
     */
    private const GENERIC_NAMES = [
        'serif' => true, 'sans-serif' => true, 'monospace' => true, 'cursive' => true, 'fantasy' => true,
        'system-ui' => true, 'ui-serif' => true, 'ui-sans-serif' => true, 'ui-monospace' => true,
        'ui-rounded' => true, 'math' => true, 'emoji' => true, 'fangsong' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ABSOLUTE_SIZES = [
        'xx-small' => true, 'x-small' => true, 'small' => true, 'medium' => true, 'large' => true,
        'x-large' => true, 'xx-large' => true, 'xxx-large' => true,
    ];

    /**
     * CSS named colours, `transparent`, `currentcolor` and the CSS system
     * colours, lower case.
     *
     * @var array<string, true>
     */
    private const NAMED_COLORS = [
        'aliceblue' => true, 'antiquewhite' => true, 'aqua' => true, 'aquamarine' => true, 'azure' => true,
        'beige' => true, 'bisque' => true, 'black' => true, 'blanchedalmond' => true, 'blue' => true,
        'blueviolet' => true, 'brown' => true, 'burlywood' => true, 'cadetblue' => true, 'chartreuse' => true,
        'chocolate' => true, 'coral' => true, 'cornflowerblue' => true, 'cornsilk' => true, 'crimson' => true,
        'cyan' => true, 'darkblue' => true, 'darkcyan' => true, 'darkgoldenrod' => true, 'darkgray' => true,
        'darkgreen' => true, 'darkgrey' => true, 'darkkhaki' => true, 'darkmagenta' => true,
        'darkolivegreen' => true, 'darkorange' => true, 'darkorchid' => true, 'darkred' => true,
        'darksalmon' => true, 'darkseagreen' => true, 'darkslateblue' => true, 'darkslategray' => true,
        'darkslategrey' => true, 'darkturquoise' => true, 'darkviolet' => true, 'deeppink' => true,
        'deepskyblue' => true, 'dimgray' => true, 'dimgrey' => true, 'dodgerblue' => true, 'firebrick' => true,
        'floralwhite' => true, 'forestgreen' => true, 'fuchsia' => true, 'gainsboro' => true,
        'ghostwhite' => true, 'gold' => true, 'goldenrod' => true, 'gray' => true, 'green' => true,
        'greenyellow' => true, 'grey' => true, 'honeydew' => true, 'hotpink' => true, 'indianred' => true,
        'indigo' => true, 'ivory' => true, 'khaki' => true, 'lavender' => true, 'lavenderblush' => true,
        'lawngreen' => true, 'lemonchiffon' => true, 'lightblue' => true, 'lightcoral' => true,
        'lightcyan' => true, 'lightgoldenrodyellow' => true, 'lightgray' => true, 'lightgreen' => true,
        'lightgrey' => true, 'lightpink' => true, 'lightsalmon' => true, 'lightseagreen' => true,
        'lightskyblue' => true, 'lightslategray' => true, 'lightslategrey' => true, 'lightsteelblue' => true,
        'lightyellow' => true, 'lime' => true, 'limegreen' => true, 'linen' => true, 'magenta' => true,
        'maroon' => true, 'mediumaquamarine' => true, 'mediumblue' => true, 'mediumorchid' => true,
        'mediumpurple' => true, 'mediumseagreen' => true, 'mediumslateblue' => true,
        'mediumspringgreen' => true, 'mediumturquoise' => true, 'mediumvioletred' => true,
        'midnightblue' => true, 'mintcream' => true, 'mistyrose' => true, 'moccasin' => true,
        'navajowhite' => true, 'navy' => true, 'oldlace' => true, 'olive' => true, 'olivedrab' => true,
        'orange' => true, 'orangered' => true, 'orchid' => true, 'palegoldenrod' => true, 'palegreen' => true,
        'paleturquoise' => true, 'palevioletred' => true, 'papayawhip' => true, 'peachpuff' => true,
        'peru' => true, 'pink' => true, 'plum' => true, 'powderblue' => true, 'purple' => true,
        'rebeccapurple' => true, 'red' => true, 'rosybrown' => true, 'royalblue' => true,
        'saddlebrown' => true, 'salmon' => true, 'sandybrown' => true, 'seagreen' => true, 'seashell' => true,
        'sienna' => true, 'silver' => true, 'skyblue' => true, 'slateblue' => true, 'slategray' => true,
        'slategrey' => true, 'snow' => true, 'springgreen' => true, 'steelblue' => true, 'tan' => true,
        'teal' => true, 'thistle' => true, 'tomato' => true, 'turquoise' => true, 'violet' => true,
        'wheat' => true, 'white' => true, 'whitesmoke' => true, 'yellow' => true, 'yellowgreen' => true,
        'transparent' => true, 'currentcolor' => true,
        'canvas' => true, 'canvastext' => true, 'linktext' => true, 'visitedtext' => true, 'activetext' => true,
        'buttonface' => true, 'buttontext' => true, 'buttonborder' => true, 'field' => true, 'fieldtext' => true,
        'highlight' => true, 'highlighttext' => true, 'selecteditem' => true, 'selecteditemtext' => true,
        'mark' => true, 'marktext' => true, 'graytext' => true, 'accentcolor' => true,
        'accentcolortext' => true,
    ];

    private function __construct()
    {
    }

    /**
     * The first of {@see self::TYPES} that $value passes, or `any` when none
     * does (always `any` for a value starting with `var(`).
     */
    public static function classify(string $value): string
    {
        return self::infer($value, self::TYPES) ?? 'any';
    }

    /**
     * The first of $types that $value passes, or null — v4's `inferDataType`.
     *
     * @param list<string> $types
     */
    public static function infer(string $value, array $types): ?string
    {
        if (\strncmp($value, 'var(', 4) === 0) {
            return null;
        }

        foreach ($types as $type) {
            if (self::is($type, $value)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether $value passes the check for $type (false for an unknown type).
     */
    public static function is(string $type, string $value): bool
    {
        switch ($type) {
            case 'color':
                return self::isColor($value);
            case 'length':
                return self::isLength($value);
            case 'percentage':
                return self::isPercentage($value);
            case 'ratio':
                return self::isRatio($value);
            case 'number':
                return self::numberEnd($value, 0) === \strlen($value) || self::hasMathFunction($value);
            case 'integer':
                return self::isInteger($value);
            case 'url':
                return self::isUrl($value);
            case 'position':
                return self::isPosition($value);
            case 'bg-size':
                return self::isBackgroundSize($value);
            case 'line-width':
                return $value === 'thin' || $value === 'medium' || $value === 'thick';
            case 'image':
                return self::isImage($value);
            case 'family-name':
                return self::isFamilyName($value);
            case 'generic-name':
                return isset(self::GENERIC_NAMES[$value]);
            case 'absolute-size':
                return isset(self::ABSOLUTE_SIZES[$value]);
            case 'relative-size':
                return $value === 'larger' || $value === 'smaller';
            case 'angle':
                return self::hasUnit($value, self::ANGLE_UNITS);
            case 'vector':
                return self::isVector($value);
        }

        return false;
    }

    private static function isColor(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if ($value[0] === '#') {
            return true;
        }

        $lower = \strtolower($value);

        foreach (self::COLOR_FUNCTIONS as $function) {
            if (\strncmp($lower, $function, \strlen($function)) === 0) {
                return true;
            }
        }

        return isset(self::NAMED_COLORS[$lower]);
    }

    private static function isLength(string $value): bool
    {
        return self::hasUnit($value, self::LENGTH_UNITS) || self::hasMathFunction($value);
    }

    private static function isPercentage(string $value): bool
    {
        $end = self::numberEnd($value, 0);

        return ($end > 0 && $end === \strlen($value) - 1 && $value[$end] === '%') || self::hasMathFunction($value);
    }

    private static function isRatio(string $value): bool
    {
        if (self::hasMathFunction($value)) {
            return true;
        }

        $end = self::numberEnd($value, 0);

        if ($end <= 0) {
            return false;
        }

        $end += (int) \strspn($value, " \t\n", $end);

        if ($end >= \strlen($value) || $value[$end] !== '/') {
            return false;
        }

        $end += 1 + (int) \strspn($value, " \t\n", $end + 1);

        return self::numberEnd($value, $end) === \strlen($value) && $end < \strlen($value);
    }

    private static function isInteger(string $value): bool
    {
        $length = \strlen($value);

        return $length > 0
            && \strspn($value, self::DIGITS) === $length
            && ($length === 1 || $value[0] !== '0');
    }

    private static function isUrl(string $value): bool
    {
        return \strncmp($value, 'url(', 4) === 0 && \substr($value, -1) === ')';
    }

    private static function isPosition(string $value): bool
    {
        $count = 0;

        foreach (Parser::segment($value, ' ') as $part) {
            if ($part === 'center' || $part === 'top' || $part === 'right' || $part === 'bottom' || $part === 'left') {
                ++$count;

                continue;
            }

            if (\strncmp($part, 'var(', 4) === 0) {
                continue;
            }

            if (self::isLength($part) || self::isPercentage($part)) {
                ++$count;

                continue;
            }

            return false;
        }

        return $count > 0;
    }

    private static function isBackgroundSize(string $value): bool
    {
        $count = 0;

        foreach (Parser::segment($value, ',') as $size) {
            if ($size === 'cover' || $size === 'contain') {
                ++$count;

                continue;
            }

            $values = Parser::segment($size, ' ');

            if (\count($values) !== 1 && \count($values) !== 2) {
                return false;
            }

            $all = true;

            foreach ($values as $one) {
                if ($one !== 'auto' && !self::isLength($one) && !self::isPercentage($one)) {
                    $all = false;

                    break;
                }
            }

            if ($all) {
                ++$count;
            }
        }

        return $count > 0;
    }

    private static function isImage(string $value): bool
    {
        $count = 0;

        foreach (Parser::segment($value, ',') as $part) {
            if (\strncmp($part, 'var(', 4) === 0) {
                continue;
            }

            if (self::isUrl($part)) {
                ++$count;

                continue;
            }

            $matched = false;

            foreach (self::IMAGE_FUNCTIONS as $function) {
                if (\strncmp($part, $function, \strlen($function)) === 0) {
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                return false;
            }

            ++$count;
        }

        return $count > 0;
    }

    private static function isFamilyName(string $value): bool
    {
        $count = 0;

        foreach (Parser::segment($value, ',') as $part) {
            if ($part !== '' && \strpos(self::DIGITS, $part[0]) !== false) {
                return false;
            }

            if (\strncmp($part, 'var(', 4) === 0) {
                continue;
            }

            ++$count;
        }

        return $count > 0;
    }

    private static function isVector(string $value): bool
    {
        $length = \strlen($value);
        $at = 0;

        for ($n = 0; $n < 3; ++$n) {
            if ($n > 0) {
                $spaces = (int) \strspn($value, ' ', $at);

                if ($spaces === 0) {
                    return false;
                }

                $at += $spaces;
            }

            $end = self::numberEnd($value, $at);

            if ($end <= $at) {
                return false;
            }

            $at = $end;
        }

        return $at === $length;
    }

    /**
     * A number immediately followed by one of $units, and nothing else.
     *
     * @param array<string, true> $units
     */
    private static function hasUnit(string $value, array $units): bool
    {
        $end = self::numberEnd($value, 0);

        return $end > 0 && isset($units[(string) \substr($value, $end)]);
    }

    private static function hasMathFunction(string $value): bool
    {
        if (\strpos($value, '(') === false) {
            return false;
        }

        foreach (self::MATH_FUNCTIONS as $function) {
            if (\strpos($value, $function) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Offset just past the number `[+-]?\d*\.?\d+([eE][+-]?\d+)?` starting at
     * $i, or -1 when there is none.
     */
    private static function numberEnd(string $value, int $i): int
    {
        $length = \strlen($value);
        $j = $i;

        if ($j < $length && ($value[$j] === '+' || $value[$j] === '-')) {
            ++$j;
        }

        $integer = (int) \strspn($value, self::DIGITS, $j);
        $j += $integer;

        if ($j < $length && $value[$j] === '.') {
            $fraction = (int) \strspn($value, self::DIGITS, $j + 1);

            if ($fraction > 0) {
                $j += 1 + $fraction;
            } elseif ($integer === 0) {
                return -1;
            }
        } elseif ($integer === 0) {
            return -1;
        }

        if ($j < $length && ($value[$j] === 'e' || $value[$j] === 'E')) {
            $k = $j + 1;

            if ($k < $length && ($value[$k] === '+' || $value[$k] === '-')) {
                ++$k;
            }

            $exponent = (int) \strspn($value, self::DIGITS, $k);

            if ($exponent > 0) {
                $j = $k + $exponent;
            }
        }

        return $j;
    }
}
