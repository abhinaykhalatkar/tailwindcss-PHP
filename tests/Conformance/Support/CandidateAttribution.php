<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

use TailwindPhp\Engine\Css\SelectorList;

/**
 * Maps a generated rule back to the candidate that produced it
 * (design doc 16 §Comparison: "every utility rule's selector is
 * `.<escaped candidate>`").
 *
 * A selector is searched for class selectors, strings, `[attr]` brackets and
 * escapes respected, and the **longest class whose un-escaped name is one of the
 * candidates under test** is the answer, per complex selector of a list.
 * Searching the whole selector rather than taking its first class is deliberate:
 * an arbitrary variant such as
 * `[.parent_&]:p-4` is emitted as `.parent .\[\.parent_\&\]\:p-4`, whose first
 * class is not the candidate. Variant wrappers do not matter either: after
 * flattening, `md:hover:bg-red-500` is `.md\:hover\:bg-red-500:hover` inside an
 * `@media` rule, and attributes to the same candidate as every other rule that
 * candidate produced.
 *
 * A selector with no class that names a candidate returns `null`: the rule is
 * authored CSS or something the candidate list does not account for.
 *
 * No regular expressions, no PHPUnit: this is a byte scanner that can be
 * promoted into `src/` unchanged. `unescapeIdentifier()` is the one implementation:
 * `tools/GoldenGenerator::unescapeIdentifier()` delegates to it (TASK-0052).
 */
final class CandidateAttribution
{
    private const HEX = '0123456789abcdefABCDEF';

    private const SPACE = " \t\r\n\f";

    private const IDENT_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * U+FFFD, what CSS substitutes for an escape that names no character.
     */
    private const REPLACEMENT = "\xEF\xBF\xBD";

    /**
     * @var array<string, true>
     */
    private $candidates = [];

    /**
     * @param list<string> $candidates The candidates the comparison is about.
     */
    public function __construct(array $candidates)
    {
        foreach ($candidates as $candidate) {
            $this->candidates[(string) $candidate] = true;
        }
    }

    /**
     * The candidate a selector belongs to, or null: the first of
     * {@see attributeAll()}.
     */
    public function attribute(string $selector): ?string
    {
        $all = $this->attributeAll($selector);

        return $all[0] ?? null;
    }

    /**
     * Every candidate a selector belongs to, in order, each once.
     *
     * Each complex selector of a selector list contributes **the longest** listed
     * class in it (the first of equals): in `.flex .\[\.flex_\&\]\:p-4` both
     * `flex` and `[.flex_&]:p-4` may be listed, and the rule is the longer one's,
     * because a short name that is merely a class inside another candidate's
     * selector must not claim the rule. A list such as `.\!flex, .flex\!` that
     * covers two listed candidates belongs to both.
     *
     * @return list<string>
     */
    public function attributeAll(string $selector): array
    {
        $found = [];

        foreach (SelectorList::split($selector) as $part) {
            $best = null;

            foreach (self::classesOf($part) as $class) {
                if (isset($this->candidates[$class]) && ($best === null || \strlen($class) > \strlen($best))) {
                    $best = $class;
                }
            }

            if ($best !== null && !\in_array($best, $found, true)) {
                $found[] = $best;
            }
        }

        return $found;
    }

    /**
     * The class names in a selector, un-escaped, in order.
     *
     * @return list<string>
     */
    public static function classesOf(string $selector): array
    {
        $classes = [];
        $length = \strlen($selector);
        $i = 0;

        while ($i < $length) {
            $byte = $selector[$i];

            if ($byte === '\\') {
                $i = self::escapeEnd($selector, $i, $length);

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $i = self::stringEnd($selector, $i, $length);

                continue;
            }

            if ($byte === '[') {
                $i = self::bracketEnd($selector, $i, $length);

                continue;
            }

            if ($byte === '.' && $i + 1 < $length) {
                $end = self::identifierEnd($selector, $i + 1, $length);

                if ($end > $i + 1) {
                    $classes[] = self::unescapeIdentifier((string) \substr($selector, $i + 1, $end - $i - 1));
                    $i = $end;

                    continue;
                }
            }

            ++$i;
        }

        return $classes;
    }

    /**
     * Undo CSS identifier escaping (CSS Syntax 3 §4.3.7): `\:` is `:`, and a hex
     * escape such as `\32 ` is the character it names, with the single
     * whitespace that ends it consumed. An escape that names no character (NUL,
     * a surrogate, past U+10FFFF, a trailing backslash) becomes U+FFFD.
     */
    public static function unescapeIdentifier(string $escaped): string
    {
        $length = \strlen($escaped);
        $result = '';
        $i = 0;

        while ($i < $length) {
            if ($escaped[$i] !== '\\') {
                $result .= $escaped[$i];
                ++$i;

                continue;
            }

            ++$i;

            if ($i >= $length) {
                $result .= self::REPLACEMENT;

                break;
            }

            if (\strpos(self::HEX, $escaped[$i]) !== false) {
                $digits = '';

                while ($i < $length && \strlen($digits) < 6 && \strpos(self::HEX, $escaped[$i]) !== false) {
                    $digits .= $escaped[$i];
                    ++$i;
                }

                if ($i < $length && \strpos(self::SPACE, $escaped[$i]) !== false) {
                    if ($escaped[$i] === "\r" && $i + 1 < $length && $escaped[$i + 1] === "\n") {
                        ++$i;
                    }

                    ++$i;
                }

                $result .= self::codePoint((int) \hexdec($digits));

                continue;
            }

            // An escaped literal; a multibyte character is copied whole.
            $result .= $escaped[$i];
            ++$i;

            while ($i < $length && (\ord($escaped[$i]) & 0xC0) === 0x80 && (\ord($escaped[$i - 1]) & 0x80) !== 0) {
                $result .= $escaped[$i];
                ++$i;
            }
        }

        return $result;
    }

    private static function codePoint(int $code): string
    {
        // Zero, a negative, a surrogate or past U+10FFFF is not a code point (CSS Syntax §4.3.7).
        if ($code <= 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return self::REPLACEMENT;
        }

        if ($code < 0x80) {
            return \chr($code);
        }

        if ($code < 0x800) {
            return \chr(0xC0 | ($code >> 6)) . \chr(0x80 | ($code & 0x3F));
        }

        if ($code < 0x10000) {
            return \chr(0xE0 | ($code >> 12)) . \chr(0x80 | (($code >> 6) & 0x3F)) . \chr(0x80 | ($code & 0x3F));
        }

        return \chr(0xF0 | ($code >> 18))
            . \chr(0x80 | (($code >> 12) & 0x3F))
            . \chr(0x80 | (($code >> 6) & 0x3F))
            . \chr(0x80 | ($code & 0x3F));
    }

    /**
     * End of an identifier (escapes included) starting at `$i`.
     */
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
