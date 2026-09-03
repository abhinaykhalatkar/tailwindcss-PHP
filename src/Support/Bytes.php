<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * Byte-level primitives shared by path handling, log scrubbing and output
 * truncation.
 *
 * These three answers were each written more than once while the M0 layers were
 * built in parallel, which is how they end up disagreeing later; one home means
 * one behaviour.
 *
 * Two deliberate absences:
 *
 * - **`ctype_*` is not used.** It needs `ext-ctype`, which is not a declared
 *   dependency (ADR-008), and it is locale-dependent: under some locales a high
 *   byte counts as alphabetic, which would let `"\xC9:/x"` be read as a Windows
 *   drive prefix and slip past path confinement.
 * - **`mb_*` is not used.** String handling is byte-oriented project-wide
 *   (design doc 18 §Design rules); {@see self::cutAtCharacterBoundary()} decodes
 *   UTF-8 lead bytes by hand rather than requiring an extension.
 */
final class Bytes
{
    /**
     * Longest UTF-8 sequence, in bytes.
     */
    private const MAX_SEQUENCE = 4;

    private function __construct()
    {
    }

    /**
     * Whether a single byte is an ASCII letter.
     */
    public static function isAsciiAlpha(string $byte): bool
    {
        $ordinal = \ord($byte);

        return ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);
    }

    /**
     * Length in bytes of the UTF-8 sequence a lead byte starts.
     *
     * @return int 0 when the byte cannot begin a sequence — a continuation byte
     *             or an invalid lead.
     */
    public static function utf8SequenceLength(int $leadByte): int
    {
        if (($leadByte & 0x80) === 0x00) {
            return 1;
        }

        if (($leadByte & 0xE0) === 0xC0) {
            return 2;
        }

        if (($leadByte & 0xF0) === 0xE0) {
            return 3;
        }

        if (($leadByte & 0xF8) === 0xF0) {
            return 4;
        }

        return 0;
    }

    /**
     * Cut $s to at most $length bytes without splitting a UTF-8 character.
     *
     * Walks back from the cut over continuation bytes to the lead byte, then
     * keeps that character only if all of it fits.
     *
     * When the walk runs past the longest possible sequence the input is not
     * valid UTF-8 at that point; the raw cut is returned rather than an empty
     * string, because discarding a whole 2 KB excerpt over a few stray bytes is
     * worse than a slightly invalid one.
     */
    public static function cutAtCharacterBoundary(string $s, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if (\strlen($s) <= $length) {
            return $s;
        }

        $cut = \substr($s, 0, $length);
        $index = \strlen($cut);
        $steps = 0;

        while ($index > 0 && $steps <= self::MAX_SEQUENCE) {
            $byte = \ord($cut[$index - 1]);

            // Continuation byte (10xxxxxx): still inside a character.
            if (($byte & 0xC0) === 0x80) {
                --$index;
                ++$steps;

                continue;
            }

            $sequenceLength = self::utf8SequenceLength($byte);

            if ($sequenceLength === 0) {
                return \substr($cut, 0, $index - 1);
            }

            return ($index - 1) + $sequenceLength <= \strlen($cut)
                ? \substr($cut, 0, ($index - 1) + $sequenceLength)
                : \substr($cut, 0, $index - 1);
        }

        return $cut;
    }
}
