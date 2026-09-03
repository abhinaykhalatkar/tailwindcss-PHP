<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use TailwindPhp\Support\Regex;

/**
 * The short content hash that addresses a built stylesheet (ADR-004).
 *
 * It appears in the URL, in the artefact filename and in the ETag, which is why
 * it is derived from the content hash rather than from a counter or a timestamp:
 * identical CSS produces an identical fingerprint, so a redeploy that changes
 * nothing does not bust a single cache.
 *
 * {@see self::isValid()} exists because the fingerprint arrives from the URL on
 * the request path — it is attacker-controlled input, and a filename is built
 * from it (threat T-02).
 */
final class Fingerprint
{
    /**
     * Shortest and longest permitted fingerprint, in hex characters.
     */
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 16;

    private function __construct()
    {
    }

    /**
     * @param string $bareHex Lowercase hex with no `sha256:` prefix (CSF-A3).
     * @param int $length 8–16; clamped rather than rejected, since the value
     *                    is already range-validated by the config schema.
     */
    public static function fromContentHash(string $bareHex, int $length): string
    {
        if ($length < self::MIN_LENGTH) {
            $length = self::MIN_LENGTH;
        }

        if ($length > self::MAX_LENGTH) {
            $length = self::MAX_LENGTH;
        }

        return \substr($bareHex, 0, $length);
    }

    /**
     * Whether a string is a syntactically valid fingerprint.
     *
     * Anchored and character-restricted: this is what stops a crafted route from
     * reaching the filesystem as part of a path (threat T-02).
     *
     * The `D` modifier is load-bearing. Without it PCRE lets `$` match
     * immediately before a final newline, so `"0123abcd\n"` would validate —
     * and this value goes on to become a filename *and* the body of an ETag
     * header, where a newline is response splitting rather than a typo.
     */
    public static function isValid(string $fingerprint): bool
    {
        return Regex::match('#^[a-f0-9]{8,16}$#D', $fingerprint);
    }

    /**
     * The ETag form — the fingerprint in double quotes (ADR-004).
     */
    public static function toETag(string $fingerprint): string
    {
        return '"' . $fingerprint . '"';
    }
}
