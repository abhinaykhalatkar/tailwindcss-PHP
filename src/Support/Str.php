<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The handful of byte-safe string helpers PHP does not already give us
 * (design doc 17 §Support).
 *
 * Everything here works on **bytes**. `ext-mbstring` is not a dependency
 * (ADR-008) and CSS, class names and paths are all handled byte-wise
 * (design doc 18 §Design rules), so `strlen`/`substr` are the right tools and
 * `mb_*` is never used. The one place character boundaries matter is
 * {@see self::truncateBytes()}, which delegates to {@see Bytes} rather than
 * reaching for an extension.
 *
 * `str_contains`, `str_starts_with` and `str_ends_with` are deliberately absent:
 * they are native on the PHP 8.2 floor.
 */
final class Str
{
    /**
     * U+0020–U+007E: the bytes {@see self::printable()} passes without a look.
     */
    private const PRINTABLE_ASCII = ' !"#$%&\'()*+,-./0123456789:;<=>?@'
        . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /**
     * Code points {@see self::printable()} replaces although they are
     * well-formed: the marks, the separators and the bidi controls.
     */
    private const UNSAFE_SEQUENCES = [
        "\u{061C}" => true,
        "\u{200E}" => true,
        "\u{200F}" => true,
        "\u{2028}" => true,
        "\u{2029}" => true,
        "\u{202A}" => true,
        "\u{202B}" => true,
        "\u{202C}" => true,
        "\u{202D}" => true,
        "\u{202E}" => true,
        "\u{2066}" => true,
        "\u{2067}" => true,
        "\u{2068}" => true,
        "\u{2069}" => true,
    ];

    private function __construct()
    {
    }

    /**
     * Remove $prefix from the front of $s, once, if it is there.
     */
    public static function removePrefix(string $s, string $prefix): string
    {
        if ($prefix === '' || !\str_starts_with($s, $prefix)) {
            return $s;
        }

        return \substr($s, \strlen($prefix));
    }

    /**
     * Remove $suffix from the end of $s, once, if it is there.
     */
    public static function removeSuffix(string $s, string $suffix): string
    {
        if ($suffix === '' || !\str_ends_with($s, $suffix)) {
            return $s;
        }

        return \substr($s, 0, \strlen($s) - \strlen($suffix));
    }

    /**
     * Everything after the last occurrence of $needle, or the whole string when
     * $needle does not occur.
     */
    public static function afterLast(string $s, string $needle): string
    {
        if ($needle === '') {
            return $s;
        }

        $position = \strrpos($s, $needle);

        if ($position === false) {
            return $s;
        }

        return \substr($s, $position + \strlen($needle));
    }

    /**
     * Everything before the first occurrence of $needle, or the whole string
     * when $needle does not occur.
     */
    public static function beforeFirst(string $s, string $needle): string
    {
        if ($needle === '') {
            return $s;
        }

        $position = \strpos($s, $needle);

        if ($position === false) {
            return $s;
        }

        return \substr($s, 0, $position);
    }

    /**
     * $s with every byte or sequence that can break a log line, a terminal or
     * `json_encode` replaced by one `?` (threat T-06):
     *
     * - C0 controls (U+0000–U+001F, tab and newline included) and DEL;
     * - C1 controls (U+0080–U+009F), which some terminals obey as escapes;
     * - every byte that is not part of well-formed UTF-8 (Unicode table 3-7:
     *   no overlong form, no surrogate, nothing past U+10FFFF) — one `?` per
     *   offending byte;
     * - the bidirectional controls U+202A–U+202E and U+2066–U+2069, the marks
     *   U+200E, U+200F and U+061C, and the separators U+2028 and U+2029, which
     *   reorder or break the text a reader sees.
     *
     * Everything else passes, multi-byte UTF-8 included, so the result is
     * never longer than $s and is always valid UTF-8. A byte loop rather than
     * a pattern: no `preg_*` outside `Support\Regex` (R1).
     */
    public static function printable(string $s): string
    {
        $length = \strlen($s);

        if (\strspn($s, self::PRINTABLE_ASCII) === $length) {
            return $s;
        }

        $out = '';

        for ($i = 0; $i < $length;) {
            $byte = \ord($s[$i]);

            if ($byte < 0x80) {
                $out .= $byte < 0x20 || $byte === 0x7F ? '?' : $s[$i];
                ++$i;

                continue;
            }

            $size = self::sequenceLength($s, $i, $length);

            if ($size === 0) {
                $out .= '?';
                ++$i;

                continue;
            }

            $sequence = (string) \substr($s, $i, $size);
            $out .= self::isUnsafeSequence($sequence) ? '?' : $sequence;
            $i += $size;
        }

        return $out;
    }

    /**
     * Shorten $s so the result is at most $maxBytes bytes **including**
     * $ellipsis, without ever cutting through a UTF-8 sequence.
     *
     * The budget is a byte budget because that is what the consumers care
     * about: doc 15's `message_short` is capped at 200 bytes before it goes into
     * a manifest, and a log line truncated mid-character is invalid UTF-8 that
     * can break whatever reads it later.
     *
     * When $maxBytes cannot even hold the ellipsis, a byte-safe prefix of the
     * ellipsis is returned, so the contract "never longer than $maxBytes, never
     * invalid UTF-8" holds for every input.
     */
    public static function truncateBytes(string $s, int $maxBytes, string $ellipsis = '...'): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (\strlen($s) <= $maxBytes) {
            return $s;
        }

        $keep = $maxBytes - \strlen($ellipsis);

        if ($keep <= 0) {
            return Bytes::cutAtCharacterBoundary($ellipsis, $maxBytes);
        }

        return Bytes::cutAtCharacterBoundary($s, $keep) . $ellipsis;
    }

    /**
     * The length of the well-formed UTF-8 sequence that starts at $i (2–4),
     * or 0 when the bytes there are not one (Unicode table 3-7).
     */
    private static function sequenceLength(string $s, int $i, int $length): int
    {
        $lead = \ord($s[$i]);

        if ($lead >= 0xC2 && $lead <= 0xDF) {
            $size = 2;
            [$low, $high] = [0x80, 0xBF];
        } elseif ($lead >= 0xE0 && $lead <= 0xEF) {
            $size = 3;
            [$low, $high] = $lead === 0xE0 ? [0xA0, 0xBF] : ($lead === 0xED ? [0x80, 0x9F] : [0x80, 0xBF]);
        } elseif ($lead >= 0xF0 && $lead <= 0xF4) {
            $size = 4;
            [$low, $high] = $lead === 0xF0 ? [0x90, 0xBF] : ($lead === 0xF4 ? [0x80, 0x8F] : [0x80, 0xBF]);
        } else {
            return 0;
        }

        if ($i + $size > $length) {
            return 0;
        }

        // The second byte has the lead's own range; the rest are any continuation.
        for ($k = 1; $k < $size; ++$k) {
            $byte = \ord($s[$i + $k]);

            if ($byte < $low || $byte > $high) {
                return 0;
            }

            [$low, $high] = [0x80, 0xBF];
        }

        return $size;
    }

    /**
     * A well-formed sequence that is still unsafe to echo: a C1 control or one
     * of {@see self::UNSAFE_SEQUENCES}.
     */
    private static function isUnsafeSequence(string $sequence): bool
    {
        if (\strlen($sequence) === 2 && $sequence[0] === "\xC2" && \ord($sequence[1]) <= 0x9F) {
            return true;
        }

        return isset(self::UNSAFE_SEQUENCES[$sequence]);
    }
}
