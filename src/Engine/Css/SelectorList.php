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
     * Identifier bytes: ASCII letters, digits, `-`, `_` and every byte >= 0x80.
     */
    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_'
        . "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F"
        . "\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF"
        . "\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF"
        . "\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF"
        . "\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        . "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF"
        . "\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

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
        $features = self::topLevelFeatures($selector);

        return $features[0] || $features[1];
    }

    /**
     * Whether $selector has a pseudo-element (`::x`, or a legacy `:before`,
     * `:after`, `:first-line`, `:first-letter`) outside parentheses and
     * brackets. `:is()` cannot hold one — it would match nothing — so the
     * flattener expands such parent lists per selector (ENG-A14).
     */
    public static function hasPseudoElement(string $selector): bool
    {
        return self::topLevelFeatures($selector)[1];
    }

    /**
     * Move a type selector written right after a compound-leading `&` in
     * front of it: `&div.x` → `div&.x`, so substituting the parent gives
     * `div.a.x` and not `.adiv.x` (a type selector must come first in its
     * compound). Strings, brackets and escapes are respected; an `&` in the
     * middle of a compound is left alone.
     */
    public static function hoistTypeSelectors(string $selector): string
    {
        $fragments = [];
        $from = 0;
        $length = \strlen($selector);

        foreach (self::ampersandOffsets($selector) as $offset) {
            $next = $offset + 1 < $length ? \ord($selector[$offset + 1]) : 0;
            // A letter, a non-ASCII byte, or `*` (the universal selector).
            $startsType = ($next >= 97 && $next <= 122) || ($next >= 65 && $next <= 90)
                || $next >= 128 || $next === 42;
            $leadsCompound = $offset === 0 || \strpos(" \t\n>+~(,", $selector[$offset - 1]) !== false;

            if (!$startsType || !$leadsCompound) {
                continue;
            }

            $nameLength = $next === 42 ? 1 : (int) \strspn($selector, self::NAME_BYTES, $offset + 1);
            $fragments[] = (string) \substr($selector, $from, $offset - $from);
            $fragments[] = (string) \substr($selector, $offset + 1, $nameLength) . '&';
            $from = $offset + 1 + $nameLength;
        }

        if ($fragments === []) {
            return $selector;
        }

        $fragments[] = (string) \substr($selector, $from);

        return \implode('', $fragments);
    }

    /**
     * One scan for the flattener: whether $selector has a combinator, and
     * whether it has a pseudo-element, outside parentheses and brackets.
     *
     * @return array{0: bool, 1: bool}
     */
    private static function topLevelFeatures(string $selector): array
    {
        $selector = self::trim($selector);
        $length = \strlen($selector);
        $combinator = false;
        $pseudoElement = false;
        $depth = 0;
        $i = 0;

        while ($i < $length) {
            $i += (int) \strcspn($selector, "\\\"'()[] \t\n>+~:", $i);

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
                if ($c !== ':') {
                    $combinator = true;
                } elseif ($i + 1 < $length && $selector[$i + 1] === ':') {
                    $pseudoElement = true;
                    ++$i;
                } else {
                    $nameLength = (int) \strspn($selector, self::NAME_BYTES, $i + 1);
                    $name = \strtolower((string) \substr($selector, $i + 1, $nameLength));
                    $pseudoElement = $pseudoElement || isset(self::LEGACY_PSEUDO_ELEMENTS[$name]);
                }
            }

            ++$i;
        }

        return [$combinator, $pseudoElement];
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
