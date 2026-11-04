<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Support\Bytes;

/**
 * CSS escaping (decision HBC-A2).
 *
 * {@see self::string()} shipped in M1, because the debug overlay puts an
 * *exception message* inside a CSS string literal and a message is the one
 * thing in the stylesheet an attacker may have influenced.
 * {@see self::identifier()} arrived with the engine in M2: every generated
 * class selector is `.` + the escaped candidate (design doc 07 §Application).
 *
 * Byte-oriented and regex-free on purpose. Every character that matters here is
 * ASCII, and the input may be arbitrary bytes — a `preg_*` call would risk
 * choking on invalid UTF-8, and this is not one of the sites `Support\Regex` is
 * allowed at anyway.
 */
final class Escape
{
    /**
     * Bytes {@see self::identifier()} never escapes once past the first two
     * positions: ASCII letters and digits, `-`, `_`, and every byte >= 0x80.
     */
    private const IDENTIFIER_SAFE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_'
        . "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F"
        . "\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF"
        . "\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF"
        . "\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF"
        . "\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        . "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF"
        . "\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    private function __construct()
    {
    }

    /**
     * Escape a string for use inside a double-quoted CSS string literal.
     *
     * The rules are CSS Syntax Level 3 §4.3.7 plus one substitution:
     *
     * - `\` and `"` are backslash-escaped, or they would end the literal.
     * - A newline is **illegal** inside a CSS string, so LF and CR become the
     *   `\A ` / `\D ` hex escapes. The **trailing space is part of the escape**:
     *   without it, a following hex digit would be swallowed into the escape
     *   sequence and `"\A" . "1"` would render as U+A1, not a newline then "1".
     * - NUL becomes U+FFFD, which is what a conforming CSS parser does with it
     *   anyway (§3.3 preprocessing). Emitting it raw would let a NUL reach a
     *   consumer that treats it as a terminator.
     *
     * Everything else, including all multi-byte UTF-8, passes through: it is
     * already legal inside a string literal and re-encoding it would only
     * mangle text in the overlay.
     */
    public static function string(string $value): string
    {
        $out = '';

        for ($i = 0, $length = \strlen($value); $i < $length; ++$i) {
            $byte = $value[$i];

            switch ($byte) {
                case '\\':
                    $out .= '\\\\';

                    break;
                case '"':
                    $out .= '\\"';

                    break;
                case "\n":
                    $out .= '\\A ';

                    break;
                case "\r":
                    $out .= '\\D ';

                    break;
                case "\0":
                    // U+FFFD REPLACEMENT CHARACTER, in UTF-8.
                    $out .= "\xEF\xBF\xBD";

                    break;
                default:
                    $out .= $byte;
            }
        }

        return $out;
    }

    /**
     * Escape $raw for use as a CSS identifier — a class name in a selector.
     *
     * This is the CSSOM `CSS.escape()` algorithm (CSSOM §2.1.1), which is
     * what Tailwind applies to candidates, so `hover:bg-[#fff]` becomes
     * `hover\:bg-\[\#fff\]` and `2xl:flex` becomes `\32 xl\:flex`:
     *
     * - NUL becomes U+FFFD;
     * - control bytes (0x01–0x1F, 0x7F), a leading digit, and a digit right
     *   after a leading `-` become a hex escape with its terminating space;
     * - a lone `-` becomes `\-`;
     * - letters, digits, `-`, `_` and every byte ≥ 0x80 pass through;
     * - every other byte — all remaining ASCII punctuation and the space —
     *   is backslash-escaped.
     *
     * Byte-oriented: multi-byte UTF-8 needs no escaping in an identifier and
     * is copied as is. The result always tokenizes back to one ident whose
     * value is $raw (barring NUL, which no identifier can hold).
     */
    public static function identifier(string $raw): string
    {
        $length = \strlen($raw);

        if ($length === 1 && $raw === '-') {
            return '\\-';
        }

        $fragments = [];
        $i = 0;

        while ($i < $length) {
            // Runs that never need escaping, after the two leading positions
            // whose digits are special.
            if ($i >= 2) {
                $run = (int) \strspn($raw, self::IDENTIFIER_SAFE, $i);

                if ($run > 0) {
                    $fragments[] = (string) \substr($raw, $i, $run);
                    $i += $run;

                    continue;
                }
            }

            $byte = $raw[$i];
            $ordinal = \ord($byte);
            $isDigit = $ordinal >= 48 && $ordinal <= 57;

            if ($ordinal === 0) {
                $fragments[] = "\xEF\xBF\xBD";
            } elseif ($ordinal < 0x20 || $ordinal === 0x7F) {
                $fragments[] = '\\' . \dechex($ordinal) . ' ';
            } elseif ($isDigit && ($i === 0 || ($i === 1 && $raw[0] === '-'))) {
                $fragments[] = '\\3' . $byte . ' ';
            } elseif ($ordinal >= 0x80 || $isDigit || $byte === '-' || $byte === '_' || Bytes::isAsciiAlpha($byte)) {
                $fragments[] = $byte;
            } else {
                $fragments[] = '\\' . $byte;
            }

            ++$i;
        }

        return \implode('', $fragments);
    }
}
