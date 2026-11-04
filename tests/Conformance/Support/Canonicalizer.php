<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\SelectorList;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The canonical form two stylesheets are compared in (design doc 16 §Comparison,
 * step 3; decision ENG-A9).
 *
 * Byte equality is the wrong bar: the CLI wraps long values over lines, the
 * engine will not; one writes `(width >= 48rem)`, another `(width>=48rem)`.
 * This class removes **only** differences that cannot change what the CSS means,
 * and — the point of the adversarial acceptance of review R-0001 — it must never
 * be able to erase a real difference. The rules, with what each one deliberately
 * leaves alone:
 *
 * - **Nesting is flattened first** (`Css\Nesting::flatten`), because the pinned
 *   CLI emits native nesting (`.x { &:hover { @media … } }`). Comments are
 *   dropped; they carry no meaning.
 * - **At-rule names** are lower-cased. **Parameters** of `@media`, `@supports`
 *   and `@container`: whitespace collapsed, keywords lower-cased, `>= <= > < =`
 *   and `:` and `,` spaced one way. Custom-property names and everything inside
 *   a `(--Foo: …)` test are left exactly as written, and so is a container's
 *   name. Parameters of every other at-rule (`@keyframes` names, `@layer` names,
 *   `@property` names) keep their case; only whitespace is collapsed.
 * - **Selectors**: whitespace collapsed, combinators spaced one way, and the
 *   top-level *list* sorted. Nothing is ever reordered inside a compound
 *   selector, case is never changed, strings and `[attr]` brackets are copied
 *   verbatim, a hex-escape's terminating space (`.\32 xl`) is part of the escape,
 *   and the arguments of `:nth-*()` and `:lang()` only lose whitespace runs.
 * - **Declarations**: the property is lower-cased unless it is a custom property
 *   (`--Foo` stays); `!important` is kept. The value is trimmed and its
 *   whitespace collapsed, commas get one space after, `/` and `*` one on each
 *   side, numbers are written one way (`0.50` and `.5`, `1.0` and `1`), a `0`
 *   length loses its unit (`0px`, at the top level of a value only, so a
 *   `calc()` is never made invalid), and hex colours are lower-cased. Strings,
 *   `url(…)` contents and unquoted identifiers are never touched.
 * - **Custom-property values** get whitespace-run collapsing and nothing else:
 *   they are opaque token streams, so `a,b` and `a, b`, `0.5` and `.5` are
 *   *different* there. Runs are collapsed because the pinned CLI itself wraps
 *   long values (`--font-sans`) onto several lines.
 *
 * No regular expressions: byte scanners only, so this class stays portable into
 * `src/` when the comparison becomes a console command (design doc 21). No
 * dependency on PHPUnit.
 */
final class Canonicalizer
{
    /**
     * Units for which a bare `0` means the same length.
     *
     * @var array<string, true>
     */
    private const ZERO_LENGTH_UNITS = [
        'px' => true, 'rem' => true, 'em' => true, 'ex' => true, 'ch' => true, 'cap' => true, 'ic' => true,
        'lh' => true, 'rlh' => true, 'vh' => true, 'vw' => true, 'vi' => true, 'vb' => true, 'vmin' => true,
        'vmax' => true, 'svh' => true, 'svw' => true, 'lvh' => true, 'lvw' => true, 'dvh' => true, 'dvw' => true,
        'cm' => true, 'mm' => true, 'q' => true, 'in' => true, 'pt' => true, 'pc' => true, 'cqw' => true,
        'cqh' => true, 'cqi' => true, 'cqb' => true, 'cqmin' => true, 'cqmax' => true,
    ];

    private const SPACE = " \t\r\n\f";

    private const HEX = '0123456789abcdefABCDEF';

    private const IDENT_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const DIGITS = '0123456789';

    private function __construct()
    {
    }

    /**
     * Flatten, then normalise.
     *
     * @param DiagnosticBag|null $diagnostics Receives the flattener's diagnostics.
     */
    public static function canonical(StyleSheet $sheet, ?DiagnosticBag $diagnostics = null): StyleSheet
    {
        $flat = Nesting::flatten($sheet, $diagnostics);

        return new StyleSheet(self::nodes($flat->nodes));
    }

    // ----------------------------------------------------------------- nodes

    /**
     * @param array<array-key, Node> $nodes
     *
     * @return list<Node>
     */
    private static function nodes(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out[] = self::declaration($node);
            } elseif ($node instanceof Rule) {
                $out[] = new Rule(self::selector($node->selector), self::nodes($node->nodes));
            } elseif ($node instanceof AtRule) {
                $name = \strtolower($node->name);

                $out[] = new AtRule(
                    $name,
                    self::params($name, $node->params),
                    $node->nodes === null ? null : self::nodes($node->nodes)
                );
            } elseif (!$node instanceof Comment) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function declaration(Declaration $declaration): Declaration
    {
        $custom = \strncmp($declaration->property, '--', 2) === 0;
        $property = $custom ? $declaration->property : \strtolower($declaration->property);
        $value = $custom ? self::customValue($declaration->value) : self::value($declaration->value);

        return new Declaration($property, $value, $declaration->important);
    }

    // ------------------------------------------------------------ at-rules

    private static function params(string $name, string $params): string
    {
        switch ($name) {
            case 'media':
            case 'supports':
            case 'container':
                return self::query($name, $params);
            case 'layer':
                return self::list($params);
            default:
                return self::collapse($params);
        }
    }

    /**
     * Whitespace runs outside strings become one space; the ends are trimmed.
     */
    private static function collapse(string $text): string
    {
        $out = '';
        $pending = false;

        foreach (self::tokens($text, false) as $token) {
            if ($token[0] === 'ws') {
                $pending = $out !== '';

                continue;
            }

            if ($pending) {
                $out .= ' ';
                $pending = false;
            }

            $out .= $token[1];
        }

        return $out;
    }

    /**
     * A comma list such as `theme, base, components`: one space after each comma.
     */
    private static function list(string $text): string
    {
        $out = '';
        $pending = false;

        foreach (self::tokens($text, false) as $token) {
            if ($token[0] === 'ws') {
                $pending = $out !== '';

                continue;
            }

            if ($token[1] === ',') {
                $out .= ', ';
                $pending = false;

                continue;
            }

            if ($pending && \substr($out, -1) !== ' ') {
                $out .= ' ';
            }

            $pending = false;
            $out .= $token[1];
        }

        return \rtrim($out);
    }

    /**
     * The parameters of `@media`, `@supports` and `@container`.
     */
    private static function query(string $name, string $params): string
    {
        $tokens = self::tokens($params, true);
        $out = '';
        $pending = false;
        $depth = 0;
        $verbatim = 0;
        $previous = null;

        foreach ($tokens as $token) {
            [$type, $text] = [$token[0], $token[1]];

            if ($type === 'ws') {
                $pending = $out !== '';

                continue;
            }

            $space = $pending;
            $pending = false;
            $emit = $text;

            if ($type === 'ident') {
                if (\strncmp($text, '--', 2) === 0 && $verbatim === 0 && $depth > 0) {
                    $verbatim = $depth;
                }

                $keep = $verbatim > 0
                    || \strncmp($text, '--', 2) === 0
                    || self::isContainerName($name, $text, $depth, $out);
                $emit = $keep ? $text : \strtolower($text);
            } elseif ($type === 'num' && $verbatim === 0) {
                $emit = self::numberText($token, 0, false);
            }

            if ($type === 'delim') {
                if ($text === ')') {
                    $space = false;
                    --$depth;

                    if ($verbatim > 0 && $depth < $verbatim) {
                        $verbatim = 0;
                    }
                } elseif ($text === ':' || $text === ',') {
                    $space = false;
                } elseif (self::isComparison($text)) {
                    $space = $out !== '';
                } elseif ($text === '(' && $previous !== null && $previous[0] === 'ident' && !$space) {
                    $space = false;
                }
            }

            if ($space && $out !== '' && \substr($out, -1) !== '(' && \substr($out, -1) !== ' ') {
                $out .= ' ';
            }

            $out .= $emit;

            if ($type === 'delim') {
                if ($text === '(') {
                    ++$depth;
                } elseif ($text === ':' || $text === ',' || self::isComparison($text)) {
                    $out .= ' ';
                }
            }

            $previous = $token;
        }

        return \rtrim($out);
    }

    private static function isComparison(string $text): bool
    {
        return $text === '>=' || $text === '<=' || $text === '>' || $text === '<' || $text === '=';
    }

    /**
     * Whether an identifier outside parentheses in a `@container` prelude is the
     * container's *name*, which is case-sensitive: any word that is not one of
     * the query keywords.
     */
    private static function isContainerName(string $at, string $text, int $depth, string $out): bool
    {
        if ($at !== 'container' || $depth !== 0) {
            return false;
        }

        $lower = \strtolower($text);

        return $lower !== 'and' && $lower !== 'or' && $lower !== 'not';
    }

    // ------------------------------------------------------------ selectors

    private static function selector(string $selector): string
    {
        $parts = [];

        foreach (SelectorList::split($selector) as $part) {
            $parts[] = self::complexSelector($part);
        }

        \usort($parts, 'strcmp');

        return \implode(', ', $parts);
    }

    /**
     * One complex selector with its whitespace and combinators written one way.
     */
    private static function complexSelector(string $selector): string
    {
        $length = \strlen($selector);
        $out = '';
        $pending = false;
        /** @var list<bool> $preserve */
        $preserve = [];
        $escapeSpace = false;
        $i = 0;

        while ($i < $length) {
            $byte = $selector[$i];
            $keepRuns = $preserve !== [] && $preserve[\count($preserve) - 1];

            if (\strpos(self::SPACE, $byte) !== false) {
                $i += (int) \strspn($selector, self::SPACE, $i);
                $pending = $out !== '';

                continue;
            }

            // A hex escape swallows the one space that ends it (`.\32 xl`); that
            // space is not a combinator, so a real descendant space after it
            // still has to be written.
            $force = $escapeSpace;
            $escapeSpace = false;

            if ($byte === '\\') {
                $end = self::escapeEnd($selector, $i, $length);

                self::space($out, $pending, $force);
                $out .= (string) \substr($selector, $i, $end - $i);
                $escapeSpace = \strpos(self::SPACE, $selector[$end - 1]) !== false;
                $i = $end;

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $end = self::stringEnd($selector, $i, $length);

                self::space($out, $pending, $force);
                $out .= (string) \substr($selector, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($byte === '[') {
                $end = self::bracketEnd($selector, $i, $length);

                self::space($out, $pending, $force);
                $out .= (string) \substr($selector, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($byte === '(') {
                $preserve[] = self::preservesArguments($out);
                $pending = false;
                $out .= '(';
                ++$i;

                continue;
            }

            if ($byte === ')') {
                \array_pop($preserve);
                $pending = false;
                $out .= ')';
                ++$i;

                continue;
            }

            if ($byte === ',' && !$keepRuns) {
                $out = \rtrim($out) . ', ';
                $pending = false;
                ++$i;

                continue;
            }

            if (($byte === '>' || $byte === '+' || $byte === '~') && !$keepRuns) {
                $before = \substr($out, -1);

                if ($out !== '' && $before !== '(' && $before !== ' ' && $before !== ',') {
                    $out .= ' ';
                }

                $out .= $byte . ' ';
                $pending = false;
                ++$i;

                continue;
            }

            self::space($out, $pending, $force);
            $out .= $byte;
            ++$i;
        }

        return \rtrim($out);
    }

    /**
     * One space before a token that follows whitespace, except where a space is
     * already there or would sit against an opening parenthesis or a comma.
     * `$force` is set when the space already there belongs to a hex escape.
     */
    private static function space(string &$out, bool &$pending, bool $force): void
    {
        if ($pending && $out !== '') {
            $last = \substr($out, -1);

            if ($last !== '(' && $last !== ',' && ($last !== ' ' || $force)) {
                $out .= ' ';
            }
        }

        $pending = false;
    }

    /**
     * Whether the `(` about to be written opens the argument list of a pseudo
     * whose arguments are not selectors: `:nth-child(2n+1)`, `:lang(en)`.
     */
    private static function preservesArguments(string $out): bool
    {
        $end = \strlen($out);
        $start = $end;

        while ($start > 0 && \strpos(self::IDENT_BYTES, $out[$start - 1]) !== false) {
            --$start;
        }

        if ($start === 0 || $start === $end || $out[$start - 1] !== ':') {
            return false;
        }

        $name = \strtolower((string) \substr($out, $start, $end - $start));

        return \strncmp($name, 'nth-', 4) === 0 || $name === 'lang' || $name === 'dir';
    }

    // ---------------------------------------------------------------- values

    /**
     * A custom property's value: whitespace runs collapsed outside strings and
     * `url()`, and nothing else.
     */
    private static function customValue(string $value): string
    {
        $out = '';
        $pending = false;

        foreach (self::tokens($value, false) as $token) {
            if ($token[0] === 'ws') {
                $pending = $out !== '';

                continue;
            }

            if ($pending) {
                $out .= ' ';
                $pending = false;
            }

            $out .= $token[1];
        }

        return $out;
    }

    /**
     * An ordinary declaration value in canonical form.
     */
    private static function value(string $value): string
    {
        $out = '';
        $pending = false;
        $depth = 0;
        $afterComma = false;
        $afterOperator = false;

        foreach (self::tokens($value, false) as $token) {
            [$type, $text] = [$token[0], $token[1]];

            if ($type === 'ws') {
                $pending = $out !== '';

                continue;
            }

            $emit = $text;

            if ($type === 'num') {
                $emit = self::numberText($token, $depth, true);
            } elseif ($type === 'hash') {
                $emit = self::hash($text);
            }

            $operator = $type === 'delim' && ($text === '/' || $text === '*');
            $closing = $type === 'delim' && $text === ')';
            $comma = $type === 'delim' && $text === ',';
            $last = \substr($out, -1);

            $space = $pending || $afterComma || $afterOperator || $operator;

            if ($closing || $comma || $last === '(' || $out === '') {
                $space = false;
            }

            if ($space) {
                $out .= ' ';
            }

            $out .= $emit;
            $pending = false;
            $afterComma = $comma;
            $afterOperator = $operator;

            if ($type === 'delim' && $text === '(') {
                ++$depth;
            } elseif ($closing && $depth > 0) {
                --$depth;
            }
        }

        return $out;
    }

    /**
     * @param array{0: string, 1: string, 2: array<string, string>} $token
     */
    private static function numberText(array $token, int $depth, bool $collapseZero): string
    {
        [$sign, $int, $frac, $exp, $unit] = [
            $token[2]['sign'], $token[2]['int'], $token[2]['frac'], $token[2]['exp'], $token[2]['unit'],
        ];

        if ($frac !== '') {
            $frac = \rtrim($frac, '0');
        }

        if ($frac === '') {
            $int = $int === '' ? '0' : $int;
            $number = $sign . $int . $exp;
        } else {
            if (\strspn($int, '0') === \strlen($int)) {
                $int = '';
            }

            $number = $sign . $int . '.' . $frac . $exp;
        }

        $zero = \strspn($int . $frac, '0') === \strlen($int . $frac) && $exp === '' && $frac === '';

        // Units are case-insensitive, so `40REM` and `40rem` are the same length.
        $unit = \strtolower($unit);

        if ($collapseZero && $depth === 0 && $zero && $unit !== '' && isset(self::ZERO_LENGTH_UNITS[$unit])) {
            return $number;
        }

        return $number . $unit;
    }

    private static function hash(string $text): string
    {
        $digits = (string) \substr($text, 1);
        $length = \strlen($digits);

        if (
            $length > 0
            && \strspn($digits, self::HEX) === $length
            && ($length === 3 || $length === 4 || $length === 6 || $length === 8)
        ) {
            return '#' . \strtolower($digits);
        }

        return $text;
    }

    // ------------------------------------------------------------- tokenizer

    /**
     * Split a value or prelude into tokens, keeping strings, `url(…)` contents,
     * escapes and identifiers whole so nothing downstream can reach into them.
     *
     * @param bool $operators Also produce `>=` and `<=` as single tokens.
     *
     * @return list<array{0: string, 1: string, 2: array<string, string>}>
     */
    private static function tokens(string $text, bool $operators): array
    {
        $tokens = [];
        $length = \strlen($text);
        $i = 0;

        while ($i < $length) {
            $byte = $text[$i];

            if (\strpos(self::SPACE, $byte) !== false) {
                $i += (int) \strspn($text, self::SPACE, $i);
                $tokens[] = ['ws', ' ', []];

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $end = self::stringEnd($text, $i, $length);
                $tokens[] = ['str', (string) \substr($text, $i, $end - $i), []];
                $i = $end;

                continue;
            }

            if ($byte === '/' && $i + 1 < $length && $text[$i + 1] === '*') {
                $close = \strpos($text, '*/', $i + 2);
                $end = $close === false ? $length : $close + 2;
                $tokens[] = ['str', (string) \substr($text, $i, $end - $i), []];
                $i = $end;

                continue;
            }

            if (self::isIdentifierStart($text, $i, $length)) {
                $end = self::identifierEnd($text, $i, $length);
                $word = (string) \substr($text, $i, $end - $i);

                if (\strtolower($word) === 'url' && $end < $length && $text[$end] === '(') {
                    $inner = $end + 1;
                    $skip = $inner + (int) \strspn($text, self::SPACE, $inner);
                    $quoted = $skip < $length && ($text[$skip] === '"' || $text[$skip] === "'");

                    if (!$quoted) {
                        $close = \strpos($text, ')', $inner);
                        $stop = $close === false ? $length : $close + 1;
                        $tokens[] = ['str', (string) \substr($text, $i, $stop - $i), []];
                        $i = $stop;

                        continue;
                    }
                }

                $tokens[] = ['ident', $word, []];
                $i = $end;

                continue;
            }

            if (self::isNumberStart($text, $i, $length)) {
                [$token, $end] = self::numberToken($text, $i, $length);
                $tokens[] = $token;
                $i = $end;

                continue;
            }

            if ($byte === '#') {
                $end = $i + 1 + (int) \strspn($text, self::IDENT_BYTES, $i + 1);
                $tokens[] = ['hash', (string) \substr($text, $i, $end - $i), []];
                $i = $end;

                continue;
            }

            if ($operators && ($byte === '>' || $byte === '<') && $i + 1 < $length && $text[$i + 1] === '=') {
                $tokens[] = ['delim', $byte . '=', []];
                $i += 2;

                continue;
            }

            $tokens[] = ['delim', $byte, []];
            ++$i;
        }

        return $tokens;
    }

    private static function isIdentifierStart(string $text, int $i, int $length): bool
    {
        $byte = $text[$i];

        if (\strpos(self::LETTERS, $byte) !== false || $byte === '_' || \ord($byte) >= 0x80) {
            return true;
        }

        if ($byte === '\\') {
            return $i + 1 < $length;
        }

        if ($byte === '-' && $i + 1 < $length) {
            $next = $text[$i + 1];

            return \strpos(self::LETTERS, $next) !== false || $next === '_' || $next === '-'
                || \ord($next) >= 0x80 || $next === '\\';
        }

        return false;
    }

    private static function identifierEnd(string $text, int $i, int $length): int
    {
        while ($i < $length) {
            $byte = $text[$i];

            if ($byte === '\\') {
                $i = self::escapeEnd($text, $i, $length);

                continue;
            }

            if (\strpos(self::IDENT_BYTES, $byte) !== false || \ord($byte) >= 0x80) {
                ++$i;

                continue;
            }

            break;
        }

        return $i;
    }

    private static function isNumberStart(string $text, int $i, int $length): bool
    {
        $byte = $text[$i];

        if (\strpos(self::DIGITS, $byte) !== false) {
            return true;
        }

        if ($byte === '.') {
            return $i + 1 < $length && \strpos(self::DIGITS, $text[$i + 1]) !== false;
        }

        if (($byte === '+' || $byte === '-') && $i + 1 < $length) {
            $next = $text[$i + 1];

            return \strpos(self::DIGITS, $next) !== false
                || ($next === '.' && $i + 2 < $length && \strpos(self::DIGITS, $text[$i + 2]) !== false);
        }

        return false;
    }

    /**
     * @return array{0: array{0: string, 1: string, 2: array<string, string>}, 1: int}
     */
    private static function numberToken(string $text, int $start, int $length): array
    {
        $i = $start;
        $sign = '';

        if ($text[$i] === '+' || $text[$i] === '-') {
            $sign = $text[$i];
            ++$i;
        }

        $digits = (int) \strspn($text, self::DIGITS, $i);
        $int = (string) \substr($text, $i, $digits);
        $i += $digits;
        $frac = '';

        if ($i + 1 < $length && $text[$i] === '.' && \strpos(self::DIGITS, $text[$i + 1]) !== false) {
            $fraction = (int) \strspn($text, self::DIGITS, $i + 1);
            $frac = (string) \substr($text, $i + 1, $fraction);
            $i += 1 + $fraction;
        }

        $exp = '';

        if ($i < $length && ($text[$i] === 'e' || $text[$i] === 'E')) {
            $j = $i + 1;

            if ($j < $length && ($text[$j] === '+' || $text[$j] === '-')) {
                ++$j;
            }

            if ($j < $length && \strpos(self::DIGITS, $text[$j]) !== false) {
                $j += (int) \strspn($text, self::DIGITS, $j);
                $exp = (string) \substr($text, $i, $j - $i);
                $i = $j;
            }
        }

        $unit = '';

        if ($i < $length && $text[$i] === '%') {
            $unit = '%';
            ++$i;
        } elseif ($i < $length && \strpos(self::LETTERS, $text[$i]) !== false) {
            $letters = (int) \strspn($text, self::LETTERS, $i);
            $unit = (string) \substr($text, $i, $letters);
            $i += $letters;
        }

        return [
            [
                'num',
                (string) \substr($text, $start, $i - $start),
                ['sign' => $sign, 'int' => $int, 'frac' => $frac, 'exp' => $exp, 'unit' => $unit],
            ],
            $i,
        ];
    }

    /**
     * End of an escape starting at `$i` (a backslash): the escaped byte, or up
     * to six hex digits and the one whitespace that terminates them.
     */
    private static function escapeEnd(string $text, int $i, int $length): int
    {
        if ($i + 1 >= $length) {
            return $length;
        }

        if (\strpos(self::HEX, $text[$i + 1]) === false) {
            return $i + 2;
        }

        $j = $i + 1;
        $digits = 0;

        while ($j < $length && $digits < 6 && \strpos(self::HEX, $text[$j]) !== false) {
            ++$j;
            ++$digits;
        }

        if ($j < $length && \strpos(self::SPACE, $text[$j]) !== false) {
            if ($text[$j] === "\r" && $j + 1 < $length && $text[$j + 1] === "\n") {
                ++$j;
            }

            ++$j;
        }

        return $j;
    }

    private static function stringEnd(string $text, int $i, int $length): int
    {
        $quote = $text[$i];

        for ($j = $i + 1; $j < $length; ++$j) {
            if ($text[$j] === '\\') {
                ++$j;

                continue;
            }

            if ($text[$j] === $quote) {
                return $j + 1;
            }
        }

        return $length;
    }

    /**
     * End of an attribute selector starting at `[`, strings and escapes inside
     * it respected.
     */
    private static function bracketEnd(string $text, int $i, int $length): int
    {
        for ($j = $i + 1; $j < $length; ++$j) {
            $byte = $text[$j];

            if ($byte === '\\') {
                ++$j;

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $j = self::stringEnd($text, $j, $length) - 1;

                continue;
            }

            if ($byte === ']') {
                return $j + 1;
            }
        }

        return $length;
    }
}
