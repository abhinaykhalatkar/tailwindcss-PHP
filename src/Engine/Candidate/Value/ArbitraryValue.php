<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate\Value;

/**
 * A bracketed value: `w-[32rem]`, `bg-[length:var(--x)]`, and the value half
 * of an arbitrary property `[color:red]` (design doc 07 step 8).
 *
 * `raw` is the text between the brackets as written; `normalised` is what the
 * compiler emits (underscores decoded, math operators spaced, type hint
 * removed); `dataType` is the hint (`length` in `[length:var(--x)]`) or null.
 *
 * This class is also the T-15 boundary (design doc 12): {@see self::validate()}
 * is the only way candidate text becomes a CSS value, a variant selector or a
 * modifier, and it refuses anything that could end a declaration or a rule
 * early, open a comment, or carry a script URL. Byte scanning only — no regex
 * (T-12) — and every scan is one pass over at most {@see self::MAX_LENGTH}
 * bytes (T-05).
 */
final class ArbitraryValue implements ValueInterface
{
    public const MAX_LENGTH = 256;

    /**
     * Functions whose arguments get spaces around `+ - * /` (v4's
     * `addWhitespaceAroundMathOperators`).
     *
     * @var array<string, true>
     */
    private const MATH_FUNCTIONS = [
        'calc' => true, 'min' => true, 'max' => true, 'clamp' => true, 'mod' => true, 'rem' => true,
        'sin' => true, 'cos' => true, 'tan' => true, 'asin' => true, 'acos' => true, 'atan' => true,
        'atan2' => true, 'pow' => true, 'sqrt' => true, 'hypot' => true, 'log' => true, 'exp' => true,
        'round' => true,
    ];

    /**
     * URL schemes refused inside `url()`, `src()` and string literals
     * (defence in depth: browsers no longer run them from CSS, but the
     * generated stylesheet must not even carry one).
     *
     * @var list<string>
     */
    private const DANGEROUS_SCHEMES = ['javascript:', 'vbscript:', 'data:text/html'];

    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    /**
     * @var string
     */
    public $raw;

    /**
     * @var string
     */
    public $normalised;

    /**
     * @var string|null
     */
    public $dataType;

    public function __construct(string $raw, string $normalised, ?string $dataType = null)
    {
        $this->raw = $raw;
        $this->normalised = $normalised;
        $this->dataType = $dataType;
    }

    /**
     * Parse the text between a value's brackets: validate, normalise, split
     * off a type hint. Null when unsafe or empty (`p-[]`, `p-[_]`).
     */
    public static function parse(string $inner): ?self
    {
        $normalised = self::validate($inner);

        if ($normalised === null) {
            return null;
        }

        $dataType = null;
        $hint = self::typeHintLength($normalised);

        if ($hint > 0) {
            $dataType = (string) \substr($normalised, 0, $hint);
            $normalised = (string) \substr($normalised, $hint + 1);
        }

        if (\trim($normalised) === '') {
            return null;
        }

        return new self($inner, $normalised, $dataType);
    }

    /**
     * Design doc 07 §Arbitrary value validation: the normalised value, or
     * null when either $raw or its normalised form is not safe to emit (see
     * {@see self::isSafe()}). Checking both matters: decoding turns `_` into a
     * space, so `_url(…)` — harmless as written — becomes ` url(…)`.
     */
    public static function validate(string $raw): ?string
    {
        if (!self::isSafe($raw)) {
            return null;
        }

        $normalised = self::normalise($raw);

        // Normalisation only adds spaces (at most two per operator or comma),
        // so the second check allows three times the input length.
        return self::isSafeWithin($normalised, 3 * self::MAX_LENGTH) ? $normalised : null;
    }

    /**
     * Whether $raw may be emitted inside a declaration value or selector,
     * reading it the way a CSS tokenizer does (CSS Syntax §4):
     *
     * - at most {@see self::MAX_LENGTH} bytes;
     * - no byte below 0x20 and no DEL anywhere, and no escape that decodes to
     *   one (`\a `, `\d `, `\1 `…);
     * - no `{` or `}` anywhere, not even inside a string (they can never be
     *   needed in a value and they are what would end a rule);
     * - no `;` and no comment opener outside strings;
     * - `(…)` and `[…]` balanced and properly nested;
     * - every string terminated; a backslash always escapes something;
     * - an unquoted `url(` is read as §4.3.6 reads it: a quote, a `(` or
     *   whitespace before anything but the closing `)` would make it a
     *   bad-url ending somewhere else, so it is refused;
     * - no function whose name contains an escape (`\75rl(` is `url(`) and no
     *   `expression(`;
     * - no `javascript:`, `vbscript:` or `data:text/html` URL in `url()`,
     *   `src()` or any string literal — compared after CSS escapes are
     *   decoded and whitespace, control characters and underscores dropped,
     *   case-insensitively (as a WHATWG URL parser strips them).
     */
    public static function isSafe(string $raw): bool
    {
        return self::isSafeWithin($raw, self::MAX_LENGTH);
    }

    private static function isSafeWithin(string $raw, int $maxLength): bool
    {
        $length = \strlen($raw);

        if ($length > $maxLength) {
            return false;
        }

        $closers = [];
        // True while scanning an identifier that contains an escape.
        $escapedName = false;
        $i = 0;

        while ($i < $length) {
            $c = $raw[$i];

            if (self::isForbiddenByte($c)) {
                return false;
            }

            if ($c === '\\') {
                $end = self::escapeEnd($raw, $i, $length);

                if ($end === null) {
                    return false;
                }

                $escapedName = true;
                $i = $end;

                continue;
            }

            if ($c === '"' || $c === "'") {
                $end = self::stringEnd($raw, $i, $length);

                if ($end === null || self::isDangerousUrl((string) \substr($raw, $i + 1, $end - $i - 1))) {
                    return false;
                }

                $escapedName = false;
                $i = $end + 1;

                continue;
            }

            if ($c === ';' || ($c === '/' && $i + 1 < $length && $raw[$i + 1] === '*')) {
                return false;
            }

            if ($c === '(') {
                // CSS decodes escapes in a function name (§4.3.4), so an
                // escaped name may spell `url`, `src` or `expression`.
                if ($escapedName) {
                    return false;
                }

                $name = \strtolower(self::nameBefore($raw, $i));

                if ($name === 'expression') {
                    return false;
                }

                $closers[] = ')';

                // Only an identifier makes `url(` a url token (§4.3.4): after
                // `#` the name is a hash token and after `@` an at-keyword,
                // and either is followed by an ordinary `(` block whose
                // contents are tokenized normally — scanned as such below.
                // (A digit before it is part of the name, so `1url` never
                // matches; neither does `-url`.)
                $nameStart = $i - \strlen($name);
                $before = $nameStart > 0 ? $raw[$nameStart - 1] : '';
                $urlToken = $name === 'url' && $before !== '#' && $before !== '@';

                if ($urlToken) {
                    $close = self::unquotedUrlEnd($raw, $i + 1, $length);

                    if ($close === null) {
                        return false;
                    }

                    if ($close >= 0) {
                        if (self::isDangerousUrl((string) \substr($raw, $i + 1, $close - $i - 1))) {
                            return false;
                        }

                        // Continue at the `)`, which closes the frame.
                        $i = $close;

                        continue;
                    }
                } elseif ($name === 'src') {
                    $close = self::unquotedSrcEnd($raw, $i + 1, $length);

                    if ($close !== null && self::isDangerousUrl((string) \substr($raw, $i + 1, $close - $i - 1))) {
                        return false;
                    }
                }
            } elseif ($c === '[') {
                $closers[] = ']';
            } elseif ($c === ')' || $c === ']') {
                if ($closers === [] || \array_pop($closers) !== $c) {
                    return false;
                }
            }

            $escapedName = $escapedName && self::isNameByte($c);
            ++$i;
        }

        return $closers === [];
    }

    /**
     * Decode an arbitrary value the way v4's `decodeArbitraryValue` does:
     *
     * - `_` becomes a space, except an escaped `\_` (which becomes `_`), any
     *   `_` inside `url(…)` / `src(…)`, and the first argument of `var(…)` /
     *   `theme(…)` (a variable name); frames are named by
     *   {@see self::nameBefore()}, which stops at `#` and `@`, so `#url(a_b)`
     *   and `@url(a_b)` keep their underscore too — safe (the value was
     *   validated as an ordinary block), though v4 may decode it;
     * - inside math functions (`calc()`, `min()`, `clamp()`, …) operators
     *   get the spaces CSS requires: `calc(100%-2rem)` → `calc(100% - 2rem)`.
     *
     * Expects input that passed {@see self::isSafe()}; quotes are kept.
     */
    public static function normalise(string $raw): string
    {
        $fragments = [];
        /** @var list<array{0: string, 1: bool}> $frames function name, still in its first argument */
        $frames = [];
        $length = \strlen($raw);
        $i = 0;

        while ($i < $length) {
            $c = $raw[$i];

            if ($c === '\\' || $c === '_') {
                $i = self::decodeUnderscoreOrEscape($raw, $i, $length, $frames, $fragments);

                continue;
            }

            if ($c === '"' || $c === "'") {
                $end = self::stringEnd($raw, $i, $length) ?? $length - 1;
                $fragments[] = $c;
                $j = $i + 1;

                while ($j < $end) {
                    if ($raw[$j] === '\\' || $raw[$j] === '_') {
                        $j = self::decodeUnderscoreOrEscape($raw, $j, $end, $frames, $fragments);

                        continue;
                    }

                    $fragments[] = $raw[$j];
                    ++$j;
                }

                if ($end < $length) {
                    $fragments[] = $raw[$end];
                }

                $i = $end + 1;

                continue;
            }

            if ($c === '(') {
                $frames[] = [\strtolower(self::nameBefore($raw, $i)), true];
            } elseif ($c === ')') {
                \array_pop($frames);
            } elseif ($c === ',' && $frames !== []) {
                // The enclosing function has moved past its first argument.
                $top = \array_pop($frames);
                $frames[] = [$top[0], false];
            }

            $fragments[] = $c;
            ++$i;
        }

        return self::spaceMathOperators(\implode('', $fragments));
    }

    /**
     * Length of a leading type hint — `[a-z-]+` followed by `:` — or 0.
     */
    public static function typeHintLength(string $value): int
    {
        $hint = (int) \strspn($value, 'abcdefghijklmnopqrstuvwxyz-');

        return $hint > 0 && $hint < \strlen($value) && $value[$hint] === ':' ? $hint : 0;
    }

    /**
     * @param list<array{0: string, 1: bool}> $frames
     * @param list<string> $fragments
     */
    private static function decodeUnderscoreOrEscape(
        string $raw,
        int $i,
        int $end,
        array $frames,
        array &$fragments
    ): int {
        if ($raw[$i] === '\\') {
            if ($i + 1 < $end && $raw[$i + 1] === '_') {
                $fragments[] = '_';
            } else {
                $fragments[] = (string) \substr($raw, $i, 2);
            }

            return $i + 2;
        }

        $fragments[] = self::keepsUnderscores($frames) ? '_' : ' ';

        return $i + 1;
    }

    /**
     * @param list<array{0: string, 1: bool}> $frames
     */
    private static function keepsUnderscores(array $frames): bool
    {
        foreach ($frames as $frame) {
            if ($frame[0] === 'url' || $frame[0] === 'src') {
                return true;
            }
        }

        if ($frames === []) {
            return false;
        }

        $top = $frames[\count($frames) - 1];

        return ($top[0] === 'var' || $top[0] === 'theme') && $top[1];
    }

    /**
     * Port of v4's `addWhitespaceAroundMathOperators`.
     *
     * The `rtrim()` per operator makes this quadratic in the worst case, which
     * is accepted: the input is bounded by three times {@see self::MAX_LENGTH}.
     */
    private static function spaceMathOperators(string $input): string
    {
        if (\strpos($input, '(') === false || !self::hasMathFunction($input)) {
            return $input;
        }

        $result = '';
        /** @var list<bool> $formattable */
        $formattable = [];
        $length = \strlen($input);

        for ($i = 0; $i < $length; ++$i) {
            $c = $input[$i];
            $inMath = $formattable !== [] && $formattable[\count($formattable) - 1];

            if ($c === '(') {
                $result .= '(';
                $start = $i;

                for ($j = $i - 1; $j >= 0; --$j) {
                    $o = \ord($input[$j]);

                    if (($o >= 48 && $o <= 57) || ($o >= 97 && $o <= 122)) {
                        $start = $j;
                    } else {
                        break;
                    }
                }

                $function = (string) \substr($input, $start, $i - $start);
                $formattable[] = isset(self::MATH_FUNCTIONS[$function]) || ($inMath && $function === '');

                continue;
            }

            if ($c === ')') {
                $result .= ')';
                \array_pop($formattable);

                continue;
            }

            if (!$inMath) {
                $result .= $c;

                continue;
            }

            if ($c === ',') {
                $result .= ', ';

                continue;
            }

            if ($c === ' ') {
                if (\substr($result, -1) !== ' ') {
                    $result .= ' ';
                }

                continue;
            }

            if ($c !== '+' && $c !== '-' && $c !== '*' && $c !== '/') {
                $result .= $c;

                continue;
            }

            $trimmed = \rtrim($result);
            $previous = (string) \substr($trimmed, -1);
            $beforePrevious = (string) \substr($trimmed, -2, 1);
            $next = $i + 1 < $length ? $input[$i + 1] : '';

            $operator = self::isDigit($previous)
                || self::isDigit($next)
                || $previous === ')'
                || ($next !== '' && \strpos('(+-*/', $next) !== false);

            if (($previous === 'e' || $previous === 'E') && self::isDigit($beforePrevious)) {
                // Scientific notation: `1e-3`.
                $result .= $c;
            } elseif ($previous === '' || \strpos('+-*/(,', $previous) !== false) {
                // A sign, not an operator.
                $result .= $c;
            } elseif ($i > 0 && $input[$i - 1] === ' ') {
                $result .= $c . ' ';
            } elseif ($operator) {
                $result .= ' ' . $c . ' ';
            } else {
                // `var(--a)-x` or `1rem-x`: could be a name; leave it alone.
                $result .= $c;
            }
        }

        return $result;
    }

    private static function hasMathFunction(string $input): bool
    {
        foreach (self::MATH_FUNCTIONS as $function => $ignored) {
            if (\strpos($input, $function . '(') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Offset of the closing quote of the string opening at $i, or null when
     * the string is unterminated or holds a forbidden byte or escape.
     */
    private static function stringEnd(string $raw, int $i, int $length): ?int
    {
        $quote = $raw[$i];
        $j = $i + 1;

        while ($j < $length) {
            $c = $raw[$j];

            if (self::isForbiddenByte($c)) {
                return null;
            }

            if ($c === '\\') {
                $end = self::escapeEnd($raw, $j, $length);

                if ($end === null) {
                    return null;
                }

                $j = $end;

                continue;
            }

            if ($c === $quote) {
                return $j;
            }

            ++$j;
        }

        return null;
    }

    /**
     * Offset just past the escape starting at the backslash at $i (CSS Syntax
     * §4.3.7: up to six hex digits plus one whitespace, or any other byte), or
     * null when nothing follows, a forbidden byte follows, or the escape
     * decodes to a control character (C0 or DEL). Only a space ends a hex
     * escape here: a tab would do in CSS, but it is a byte below 0x20 and
     * those are refused everywhere.
     */
    private static function escapeEnd(string $raw, int $i, int $length): ?int
    {
        if ($i + 1 >= $length || self::isForbiddenByte($raw[$i + 1])) {
            return null;
        }

        $digits = (int) \strspn($raw, self::HEX_DIGITS, $i + 1, 6);

        if ($digits === 0) {
            return $i + 2;
        }

        $codePoint = (int) \hexdec((string) \substr($raw, $i + 1, $digits));

        if ($codePoint < 0x20 || $codePoint === 0x7F) {
            return null;
        }

        $end = $i + 1 + $digits;

        if ($end < $length && $raw[$end] === ' ') {
            ++$end;
        }

        return $end;
    }

    /**
     * Read an unquoted `url(` the way CSS Syntax §4.3.6 does, from the first
     * byte after the `(`:
     *
     * - -1 when the argument is a quoted string — then `url(` is an ordinary
     *   function and its string is checked like any other;
     * - the offset of the closing `)` for a well-formed unquoted url;
     * - null for anything the tokenizer would turn into a bad-url (a quote or
     *   `(` inside, whitespace not followed by `)`, a bad escape, no `)`),
     *   and for any byte {@see self::isSafe()} refuses everywhere (below
     *   0x20 — tab included —, DEL, `{`, `}`): the caller jumps to the `)`,
     *   so the body is checked here or not at all.
     */
    private static function unquotedUrlEnd(string $raw, int $j, int $length): ?int
    {
        $j += (int) \strspn($raw, ' ', $j);

        if ($j < $length && ($raw[$j] === '"' || $raw[$j] === "'")) {
            return -1;
        }

        while ($j < $length) {
            $c = $raw[$j];

            if (self::isForbiddenByte($c)) {
                return null;
            }

            if ($c === ')') {
                return $j;
            }

            if ($c === ' ') {
                $j += (int) \strspn($raw, ' ', $j);

                return $j < $length && $raw[$j] === ')' ? $j : null;
            }

            if ($c === '"' || $c === "'" || $c === '(') {
                return null;
            }

            if ($c === '\\') {
                $end = self::escapeEnd($raw, $j, $length);

                if ($end === null) {
                    return null;
                }

                $j = $end;

                continue;
            }

            ++$j;
        }

        return null;
    }

    /**
     * `src()` is an ordinary function (not a url token): offset of the first
     * `)` of an unquoted argument, or null when the argument is quoted
     * (strings are checked on their own) or unterminated. Only used for the
     * scheme check.
     */
    private static function unquotedSrcEnd(string $raw, int $j, int $length): ?int
    {
        $j += (int) \strspn($raw, " \t", $j);

        if ($j < $length && ($raw[$j] === '"' || $raw[$j] === "'")) {
            return null;
        }

        for (; $j < $length; ++$j) {
            if ($raw[$j] === '\\') {
                ++$j;
            } elseif ($raw[$j] === ')') {
                return $j;
            }
        }

        return null;
    }

    /**
     * Whether $url, once CSS escapes are decoded and whitespace, control
     * characters (≤ 0x20) and underscores are dropped, starts with a refused
     * scheme — the WHATWG URL parser strips C0 controls and spaces and removes
     * tab, LF and CR, so they cannot be allowed to split a scheme either.
     */
    private static function isDangerousUrl(string $url): bool
    {
        $plain = '';
        $length = \strlen($url);

        for ($i = 0; $i < $length && \strlen($plain) < 32; ++$i) {
            $c = $url[$i];

            if ($c === '\\' && $i + 1 < $length) {
                $digits = (int) \strspn($url, self::HEX_DIGITS, $i + 1, 6);

                if ($digits > 0) {
                    $codePoint = (int) \hexdec((string) \substr($url, $i + 1, $digits));
                    $c = $codePoint < 0x80 ? \chr($codePoint) : '?';
                    $i += $digits;
                } else {
                    $c = $url[$i + 1];
                    ++$i;
                }
            }

            if (\ord($c) > 0x20 && $c !== '_') {
                $plain .= $c;
            }
        }

        $plain = \strtolower($plain);

        foreach (self::DANGEROUS_SCHEMES as $scheme) {
            if (\strncmp($plain, $scheme, \strlen($scheme)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identifier (letters, digits, `-`, `_`) directly before offset $i.
     */
    private static function nameBefore(string $raw, int $i): string
    {
        $start = $i;

        while ($start > 0 && self::isNameByte($raw[$start - 1])) {
            --$start;
        }

        return (string) \substr($raw, $start, $i - $start);
    }

    /**
     * An identifier byte: ASCII letter or digit, `-`, `_`, or any byte ≥ 0x80.
     */
    private static function isNameByte(string $c): bool
    {
        $o = \ord($c);

        return ($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || ($o >= 48 && $o <= 57)
            || $o === 45 || $o === 95 || $o >= 128;
    }

    private static function isForbiddenByte(string $c): bool
    {
        $o = \ord($c);

        return $o < 0x20 || $o === 0x7F || $c === '{' || $c === '}';
    }

    private static function isDigit(string $c): bool
    {
        if ($c === '') {
            return false;
        }

        $o = \ord($c);

        return $o >= 48 && $o <= 57;
    }
}
