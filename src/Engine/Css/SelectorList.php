<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

/**
 * The little selector handling the engine needs (design doc 07 §Phase 1).
 *
 * Selectors stay strings; nothing here builds a selector tree. Every helper is
 * one forward scan that steps over the three things a naive `strpos` would
 * misread: quoted strings (`[x="a,b"]`), escapes (`.a\,b`, `.w-1\/2`,
 * `.\32 xl`) and — where it matters — attribute brackets. Regex-free (threat
 * T-12) and linear in the selector length.
 */
final class SelectorList
{
    private const WHITESPACE = " \t\n";

    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    /**
     * Legacy single-colon pseudo-elements (Selectors Level 4 §14).
     *
     * @var array<string, true>
     */
    private const LEGACY_PSEUDO_ELEMENTS = [
        'before' => true,
        'after' => true,
        'first-line' => true,
        'first-letter' => true,
    ];

    private function __construct()
    {
    }

    /**
     * Split on top-level commas; each part is trimmed and empty parts are
     * dropped.
     *
     * @return list<string>
     */
    public static function split(string $selector): array
    {
        $parts = [];
        $length = \strlen($selector);
        $depth = 0;
        $start = 0;
        $i = 0;

        while ($i < $length) {
            $i += (int) \strcspn($selector, "\\\"'()[],", $i);

            if ($i >= $length) {
                break;
            }

            $c = $selector[$i];

            if ($c === '\\' || $c === '"' || $c === "'") {
                $i = self::skip($selector, $i, $length);

                continue;
            }

            if ($c === '(' || $c === '[') {
                ++$depth;
            } elseif ($c === ')' || $c === ']') {
                $depth = $depth > 0 ? $depth - 1 : 0;
            } elseif ($depth === 0) {
                $parts[] = (string) \substr($selector, $start, $i - $start);
                $start = $i + 1;
            }

            ++$i;
        }

        $parts[] = (string) \substr($selector, $start);
        $kept = [];

        foreach ($parts as $part) {
            $part = self::trim($part);

            if ($part !== '') {
                $kept[] = $part;
            }
        }

        return $kept;
    }

    /**
     * Whether $selector has a nesting `&` outside strings, attribute brackets
     * and escapes.
     */
    public static function containsAmpersand(string $selector): bool
    {
        return self::countAmpersands($selector) > 0;
    }

    /**
     * Number of nesting `&` outside strings, attribute brackets and escapes.
     */
    public static function countAmpersands(string $selector): int
    {
        $count = 0;

        foreach (self::ampersandOffsets($selector) as $ignored) {
            ++$count;
        }

        return $count;
    }

    /**
     * Replace every nesting `&` with $replacement. The replacement is
     * inserted verbatim and never re-scanned.
     */
    public static function replaceAmpersand(string $selector, string $replacement): string
    {
        $fragments = [];
        $from = 0;

        foreach (self::ampersandOffsets($selector) as $offset) {
            $fragments[] = (string) \substr($selector, $from, $offset - $from);
            $fragments[] = $replacement;
            $from = $offset + 1;
        }

        if ($fragments === []) {
            return $selector;
        }

        $fragments[] = (string) \substr($selector, $from);

        return \implode('', $fragments);
    }

    /**
     * A complex selector — one with a combinator or a pseudo-element outside
     * parentheses and brackets. The flattener wraps a list of complex parents
     * in `:is()` instead of expanding it (design doc 07 §Nesting flattener).
     */
    public static function isComplex(string $selector): bool
    {
        $selector = self::trim($selector);
        $length = \strlen($selector);
        $depth = 0;
        $i = 0;

        while ($i < $length) {
            $i += (int) \strcspn($selector, "\\\"'()[] \t\n>+~:", $i);

            if ($i >= $length) {
                return false;
            }

            $c = $selector[$i];

            if ($c === '\\' || $c === '"' || $c === "'") {
                $i = self::skip($selector, $i, $length);

                continue;
            }

            if ($c === '(' || $c === '[') {
                ++$depth;
            } elseif ($c === ')' || $c === ']') {
                $depth = $depth > 0 ? $depth - 1 : 0;
            } elseif ($depth === 0) {
                if ($c !== ':') {
                    return true;
                }

                if ($i + 1 < $length && $selector[$i + 1] === ':') {
                    return true;
                }

                $nameLength = (int) \strspn(
                    $selector,
                    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_',
                    $i + 1
                );
                $name = \strtolower((string) \substr($selector, $i + 1, $nameLength));

                if (isset(self::LEGACY_PSEUDO_ELEMENTS[$name])) {
                    return true;
                }
            }

            ++$i;
        }

        return false;
    }

    /**
     * $selector without optional whitespace, for the compact serializer:
     * commas and combinators lose their surrounding spaces, other whitespace
     * runs become one space; strings and escapes are copied untouched.
     */
    public static function minify(string $selector): string
    {
        $parts = [];

        foreach (self::split($selector) as $part) {
            $parts[] = self::minifyOne($part);
        }

        return \implode(',', $parts);
    }

    private static function minifyOne(string $selector): string
    {
        $fragments = [];
        $length = \strlen($selector);
        $depth = 0;
        $i = 0;
        $last = '';

        while ($i < $length) {
            $run = (int) \strcspn($selector, "\\\"'()[] \t\n", $i);

            if ($run > 0) {
                $fragments[] = (string) \substr($selector, $i, $run);
                $last = $selector[$i + $run - 1];
                $i += $run;

                continue;
            }

            $c = $selector[$i];

            if ($c === '\\' || $c === '"' || $c === "'") {
                $end = self::skip($selector, $i, $length);
                $fragments[] = (string) \substr($selector, $i, $end - $i);
                $last = $selector[$end - 1];
                $i = $end;

                continue;
            }

            if ($c === ' ' || $c === "\t" || $c === "\n") {
                $i += (int) \strspn($selector, self::WHITESPACE, $i);
                $next = $i < $length ? $selector[$i] : '';
                $tight = $depth === 0 ? '>+~' : ',()';

                // Never an empty needle: strpos() warns on one before PHP 8.
                $loose = $last !== '' && $next !== ''
                    && \strpos($tight, $last) === false
                    && \strpos($tight, $next) === false;

                if ($loose) {
                    $fragments[] = ' ';
                    $last = ' ';
                }

                continue;
            }

            if ($c === '(' || $c === '[') {
                ++$depth;
            } elseif ($depth > 0) {
                --$depth;
            }

            $fragments[] = $c;
            $last = $c;
            ++$i;
        }

        return \implode('', $fragments);
    }

    /**
     * Offsets of the nesting `&` characters.
     *
     * @return \Generator<int, int>
     */
    private static function ampersandOffsets(string $selector): \Generator
    {
        $length = \strlen($selector);
        $brackets = 0;
        $i = 0;

        while ($i < $length) {
            $i += (int) \strcspn($selector, "\\\"'[]&", $i);

            if ($i >= $length) {
                return;
            }

            $c = $selector[$i];

            if ($c === '\\' || $c === '"' || $c === "'") {
                $i = self::skip($selector, $i, $length);

                continue;
            }

            if ($c === '[') {
                ++$brackets;
            } elseif ($c === ']') {
                $brackets = $brackets > 0 ? $brackets - 1 : 0;
            } elseif ($brackets === 0) {
                yield $i;
            }

            ++$i;
        }
    }

    /**
     * Offset just past the escape or quoted string starting at $i.
     *
     * A hex escape takes up to six hex digits plus one whitespace terminator
     * (CSS Syntax §4.3.7); any other escape is the backslash and one byte.
     * An unterminated string runs to the end.
     */
    private static function skip(string $selector, int $i, int $length): int
    {
        if ($selector[$i] === '\\') {
            if ($i + 1 >= $length) {
                return $length;
            }

            $digits = (int) \strspn($selector, self::HEX_DIGITS, $i + 1, 6);

            if ($digits === 0) {
                return $i + 2;
            }

            $end = $i + 1 + $digits;

            if ($end < $length && \strpos(self::WHITESPACE, $selector[$end]) !== false) {
                ++$end;
            }

            return $end;
        }

        $quote = $selector[$i];
        $j = $i + 1;

        while ($j < $length) {
            $j += (int) \strcspn($selector, $quote . '\\', $j);

            if ($j >= $length) {
                return $length;
            }

            if ($selector[$j] === $quote) {
                return $j + 1;
            }

            $j += 2;
        }

        return $length;
    }

    /**
     * Trim surrounding whitespace, keeping a trailing whitespace byte that
     * belongs to an escape: an escaped space (`.a\ `) or the terminator of a
     * hex escape (`.\31 `), which a later concatenation may depend on.
     */
    private static function trim(string $selector): string
    {
        $selector = \ltrim($selector, self::WHITESPACE);
        $trimmed = \rtrim($selector, self::WHITESPACE);
        $kept = \strlen($trimmed);

        if ($kept === \strlen($selector)) {
            return $trimmed;
        }

        // Walk the escapes of the trimmed text: does the last one end exactly
        // at the trim point and need the next whitespace byte?
        $i = 0;

        while ($i < $kept) {
            $i += (int) \strcspn($trimmed, '\\', $i);

            if ($i >= $kept) {
                break;
            }

            $end = self::skip($selector, $i, \strlen($selector));

            if ($end > $kept) {
                return (string) \substr($selector, 0, $end);
            }

            $i = $end;
        }

        return $trimmed;
    }
}
