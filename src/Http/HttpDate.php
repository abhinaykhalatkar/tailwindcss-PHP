<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

/**
 * HTTP date handling, RFC 7231 §7.1.1.1 (design doc 07; TASK-0016 §3).
 *
 * The asymmetry between the two methods is the design, not an oversight:
 *
 * - **{@see self::format()} emits only IMF-fixdate in GMT**, because that is
 *   the one form RFC 7231 allows a sender to generate. `Last-Modified` is
 *   compared byte-for-byte by intermediaries, so a locale-dependent day name or
 *   a non-GMT offset turns a cache hit into a miss.
 * - **{@see self::parse()} accepts only that same form**, and returns null for
 *   everything else rather than guessing. Its input is a request header, and
 *   the consequence of reading one wrongly is a 304 for content that *did*
 *   change — a stale stylesheet the browser will not re-request. Null costs one
 *   full 200; a wrong guess costs correctness, so the trade is not close.
 *
 * The two obsolete formats (RFC 850, asctime) are deliberately not accepted.
 * A client new enough to send `If-Modified-Since` against this library is new
 * enough to send the preferred form, and hand-parsing two-digit years invites
 * exactly the ambiguity RFC 7231 §7.1.1.1 warns about.
 */
final class HttpDate
{
    /**
     * IMF-fixdate: `Sun, 06 Nov 1994 08:49:37 GMT`.
     *
     * `D` and `M` are the day and month abbreviations, which PHP renders in
     * English regardless of locale — the property {@see self::format()} needs.
     */
    private const IMF_FIXDATE = 'D, d M Y H:i:s \G\M\T';

    private function __construct()
    {
    }

    /**
     * One instant as an IMF-fixdate string in GMT.
     */
    public static function format(int $timestamp): string
    {
        // `gmdate()` rather than `date()`: the output must not depend on the
        // ambient timezone, and a server's default is not ours to assume.
        return \gmdate(self::IMF_FIXDATE, $timestamp);
    }

    /**
     * One IMF-fixdate string as a Unix timestamp, or null when it is anything
     * else at all.
     *
     * Parsed by re-formatting rather than by regex: the value is accepted only
     * if formatting the instant it denotes reproduces the input byte for byte.
     * That single comparison rejects a wrong day name, an impossible day, an
     * unpadded number, a non-GMT zone and any trailing rubbish, without a
     * pattern that has to enumerate them — and it cannot drift from
     * {@see self::format()}, because it *is* {@see self::format()}.
     */
    public static function parse(string $value): ?int
    {
        // Bounded before anything else: this is a request header, and a hostile
        // one should not reach the date machinery at all. A valid IMF-fixdate is
        // exactly 29 bytes.
        if (\strlen($value) !== 29) {
            return null;
        }

        // Suppressed and immediately checked: an unparseable string raises a
        // warning and returns false, which is the answer being asked for
        // (design doc 18).
        $timestamp = @\strtotime($value);

        if (!\is_int($timestamp)) {
            return null;
        }

        return self::format($timestamp) === $value ? $timestamp : null;
    }
}
