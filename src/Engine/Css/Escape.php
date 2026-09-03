<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

/**
 * CSS escaping (decision HBC-A2).
 *
 * Only {@see self::string()} ships in M1; `ident()` arrives with the engine in
 * M2. It exists this early because the debug overlay puts an *exception
 * message* inside a CSS string literal, and a message is the one thing in the
 * stylesheet that an attacker may have influenced.
 *
 * Byte-oriented and regex-free on purpose. Every character that matters here is
 * ASCII, and the input may be arbitrary bytes — a `preg_*` call would risk
 * choking on invalid UTF-8, and this is not one of the sites `Support\Regex` is
 * allowed at anyway.
 */
final class Escape
{
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
}
