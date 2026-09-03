<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The handful of byte-safe string helpers the polyfill does not already give us
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
 * they come from `symfony/polyfill-php80`, which is a required runtime
 * dependency (design doc 14 §Forbidden constructs).
 */
final class Str
{
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
}
