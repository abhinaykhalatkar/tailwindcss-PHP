<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * Removes anything shaped like a filesystem location from text that is about to
 * be logged, shown in the debug overlay, or written into a cache artefact
 * (design doc 15 §Logging, threat T-06).
 *
 * A class rather than a trait method because three unrelated places need it:
 * the exception hierarchy (via {@see ExceptionContext}), the diagnostics that
 * end up in the manifest's `warnings[]`, and anything else that later has to
 * emit user-supplied text. `Engine → Exception` is an allowed dependency edge
 * (design doc 17).
 *
 * The implementation is deliberately hand-written rather than pattern-based:
 * `Support\Regex` is the only permitted PCRE call site (threat T-12), and this
 * sits below it in the dependency order.
 *
 * **How it decides.** The text is split into tokens on whitespace and the
 * punctuation that normally brackets a path in a sentence; a token that starts
 * at a root, at a drive, or with a wrapper scheme becomes a marker. Splitting is
 * lossless for everything else — non-path tokens are re-emitted byte for byte
 * along with their separators — so an over-eager boundary costs nothing.
 * `http://` and `https://` URLs are deliberately kept: a documentation link in a
 * message is not a filesystem leak, and blanking it makes errors harder to act
 * on. `Support\Path::isWrapper()` still refuses them as *paths*.
 */
final class LogSafety
{
    /**
     * Bytes that terminate a token.
     *
     * Beyond whitespace and quoting punctuation this includes the three bytes
     * of each curly quote (`“ ” ‘ ’`), because a path pasted from a word
     * processor is otherwise glued to them. Splitting mid-sequence is safe: the
     * pieces are reassembled unchanged unless one of them looks like a path.
     */
    private const BOUNDARIES = " \t\n\r\0\x0B\"'()[]<>,;=`{}|\xE2\x80\x9C\x9D\x98\x99";

    /**
     * Leading decoration stripped before a token is judged, so that `*​/etc/x`
     * or `-/etc/x` is still recognised.
     */
    private const LEADING_PUNCTUATION = '*#@!?&+-~$';

    private function __construct()
    {
    }

    /**
     * The visible replacement for a redacted location.
     */
    public static function marker(): string
    {
        return '<path>';
    }

    public static function scrubText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $out = '';
        $token = '';
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];

            if (\strpos(self::BOUNDARIES, $char) !== false) {
                $out .= self::scrubToken($token) . $char;
                $token = '';

                continue;
            }

            $token .= $char;
        }

        return $out . self::scrubToken($token);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function scrubArray(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            $scrubbed[$key] = self::scrubValue($value);
        }

        return $scrubbed;
    }

    /**
     * True when a token names a filesystem location.
     */
    public static function looksLikePath(string $token): bool
    {
        $candidate = \ltrim($token, self::LEADING_PUNCTUATION);

        if ($candidate === '') {
            return false;
        }

        // A web URL is not a filesystem leak; keep it readable.
        if (\strncasecmp($candidate, 'http://', 7) === 0 || \strncasecmp($candidate, 'https://', 8) === 0) {
            return false;
        }

        if (self::startsAtARoot($candidate)) {
            return true;
        }

        // "path:/etc/passwd" and "in:C:\Windows\x" — a location glued behind a
        // label. Every colon is tried, because the label may itself contain one.
        $offset = 0;

        while (($position = \strpos($candidate, ':', $offset)) !== false) {
            $remainder = \substr($candidate, $position + 1);

            if ($remainder !== '' && self::startsAtARoot($remainder)) {
                return true;
            }

            $offset = $position + 1;
        }

        return false;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function scrubValue($value)
    {
        if (\is_string($value)) {
            return self::scrubText($value);
        }

        if (\is_array($value)) {
            return self::scrubArray($value);
        }

        return $value;
    }

    private static function scrubToken(string $token): string
    {
        return self::looksLikePath($token) ? self::marker() : $token;
    }

    /**
     * POSIX root, backslash (UNC or Windows root-relative), drive plus
     * separator, or any non-web wrapper scheme.
     */
    private static function startsAtARoot(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if ($value[0] === '/' || $value[0] === '\\') {
            return true;
        }

        if (
            \strlen($value) >= 3
            && $value[1] === ':'
            && self::isAsciiAlpha($value[0])
            && ($value[2] === '/' || $value[2] === '\\')
        ) {
            return true;
        }

        if (\strncasecmp($value, 'data:', 5) === 0) {
            return true;
        }

        return self::hasWrapperScheme($value);
    }

    /**
     * True for "scheme://…" with a syntactically valid, non-web scheme.
     */
    private static function hasWrapperScheme(string $value): bool
    {
        $position = \strpos($value, '://');

        if ($position === false || $position === 0) {
            return false;
        }

        for ($i = 0; $i < $position; ++$i) {
            $char = $value[$i];

            if (self::isAsciiAlpha($char)) {
                continue;
            }

            $ordinal = \ord($char);

            $isSchemeByte = ($ordinal >= 48 && $ordinal <= 57)
                || $char === '+'
                || $char === '.'
                || $char === '-';

            if (!$isSchemeByte) {
                return false;
            }
        }

        return self::isAsciiAlpha($value[0]);
    }

    /**
     * ASCII-only letter test — `ctype_alpha()` needs `ext-ctype`, which is not a
     * declared dependency (ADR-008), and is locale-dependent.
     */
    private static function isAsciiAlpha(string $byte): bool
    {
        $ordinal = \ord($byte);

        return ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);
    }
}
