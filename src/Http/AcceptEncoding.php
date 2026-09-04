<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

/**
 * One parsed `Accept-Encoding` header, RFC 7231 §5.3.4 (design doc 07;
 * TASK-0016 §3).
 *
 * The governing bias: **failing to compress is free, compressing wrongly is
 * not.** A client that cannot decode gzip and is sent gzip receives an
 * unreadable stylesheet; a client that could have had gzip and gets plain CSS
 * pays a few kilobytes. Every ambiguity therefore resolves to "no" —
 * a malformed q-value, a header we cannot make sense of, a token we do not
 * recognise.
 *
 * Two details are easy to get backwards and are worth stating:
 *
 * - **`q=0` is a refusal, not a low preference**, and it outranks a wildcard
 *   that would otherwise allow the encoding. `gzip;q=0, *` means "anything but
 *   gzip", which is the opposite of what a `strpos($header, 'gzip')` check
 *   concludes.
 * - **`*` accepts gzip** (decision HBC-A17), unless the wildcard itself carries
 *   `q=0`.
 */
final class AcceptEncoding
{
    /**
     * Coding => whether it is acceptable, lowercased.
     *
     * @var array<string, bool>
     */
    private $codings;

    /**
     * @param array<string, bool> $codings
     */
    private function __construct(array $codings)
    {
        $this->codings = $codings;
    }

    /**
     * Parse a header value; null or unusable input yields a negotiation that
     * accepts nothing.
     */
    public static function parse(?string $header): self
    {
        if ($header === null || $header === '') {
            return new self([]);
        }

        // A control byte means the value did not come from a well-behaved
        // client. Rather than pick through it, refuse the whole header — the
        // cost is one uncompressed response.
        if (\strcspn($header, "\0\r\n") !== \strlen($header)) {
            return new self([]);
        }

        $codings = [];

        foreach (\explode(',', $header) as $part) {
            $parameters = \explode(';', $part);
            $coding = \strtolower(\trim(\array_shift($parameters)));

            if ($coding === '') {
                continue;
            }

            $codings[$coding] = self::isAcceptable($parameters);
        }

        return new self($codings);
    }

    /**
     * Whether $coding may be sent.
     *
     * An explicit entry always wins over the wildcard, in either direction: it
     * is what lets `gzip;q=0, *` refuse gzip while still allowing everything
     * else.
     */
    public function accepts(string $coding): bool
    {
        $coding = \strtolower($coding);

        if (\array_key_exists($coding, $this->codings)) {
            return $this->codings[$coding];
        }

        return $this->codings['*'] ?? false;
    }

    /**
     * Read the q-value from a coding's parameters.
     *
     * Absent means acceptable (q defaults to 1). Present means it must be a
     * well-formed number in [0, 1]; anything else is treated as a refusal
     * rather than as "probably fine", because a header we cannot parse is not
     * a header we should act on.
     *
     * @param list<string> $parameters
     */
    private static function isAcceptable(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            $pair = \explode('=', $parameter, 2);

            if (\strtolower(\trim($pair[0])) !== 'q') {
                continue;
            }

            if (!isset($pair[1])) {
                return false;
            }

            $value = \trim($pair[1]);

            // `is_numeric` rather than a cast: `(float) 'abc'` is 0.0, which
            // would read a malformed q-value as an explicit refusal by
            // accident rather than by decision. The outcome is the same here,
            // but only one of the two is a reason.
            if (!\is_numeric($value)) {
                return false;
            }

            $q = (float) $value;

            if ($q <= 0.0 || $q > 1.0) {
                return false;
            }
        }

        return true;
    }
}
