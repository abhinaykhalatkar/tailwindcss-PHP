<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

/**
 * The five request facts this library is allowed to know (design doc 12 T-09
 * and T-11; TASK-0016 acceptance 2).
 *
 * It is a deliberately tiny window onto `$_SERVER`, and the narrowness is the
 * feature. A stylesheet route needs a method, a path and three conditional or
 * negotiation headers; it does not need cookies, query parameters, the
 * authorization header or the client's address, so none of those can be read
 * through this type and there is no other reader in `src/` outside `Console/`.
 * That is what makes "debug mode cannot be turned on by a request" (T-09) a
 * property of the code rather than a promise.
 *
 * Every value is kept as an **opaque string**. Nothing here is parsed, split on
 * `&`, or fed to `parse_str`: the three header values are handed to the classes
 * that own their grammars ({@see AcceptEncoding}, {@see HttpDate}, the
 * conditional logic in {@see ResponseBuilder}) and the path is handed to
 * {@see RouteMatcher}. A value longer than 8 KB is treated as **absent**
 * (decision HBC-A16) rather than truncated, because half a header is not a
 * header, and a truncated `If-None-Match` that happens to end mid-token could
 * only ever produce a wrong answer.
 */
final class ServerRequest
{
    /**
     * Longest header value accepted, in bytes (HBC-A16).
     */
    private const MAX_HEADER_BYTES = 8192;

    /**
     * What an absent or unusable `REQUEST_METHOD` means.
     */
    private const DEFAULT_METHOD = 'GET';

    /**
     * @var string
     */
    private $method;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null
     */
    private $ifNoneMatch;

    /**
     * @var string|null
     */
    private $ifModifiedSince;

    /**
     * @var string|null
     */
    private $acceptEncoding;

    private function __construct(
        string $method,
        string $path,
        ?string $ifNoneMatch,
        ?string $ifModifiedSince,
        ?string $acceptEncoding
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->ifNoneMatch = $ifNoneMatch;
        $this->ifModifiedSince = $ifModifiedSince;
        $this->acceptEncoding = $acceptEncoding;
    }

    /**
     * The one `$_SERVER` read in `src/` outside `Console/` (static rule R3).
     */
    public static function fromGlobals(): self
    {
        return self::fromArray($_SERVER);
    }

    /**
     * @param array<string, mixed> $server Server variables; keys other than the
     *                                     five below are ignored entirely.
     */
    public static function fromArray(array $server): self
    {
        $method = self::value($server, 'REQUEST_METHOD');
        $path = self::value($server, 'REQUEST_URI');

        if ($path === null) {
            // `PATH_INFO` is what a front controller mounted at `index.php`
            // sees; `REQUEST_URI` is what everything else sees.
            $path = self::value($server, 'PATH_INFO');
        }

        return new self(
            $method === null ? self::DEFAULT_METHOD : self::upper($method),
            $path === null ? '' : $path,
            self::value($server, 'HTTP_IF_NONE_MATCH'),
            self::value($server, 'HTTP_IF_MODIFIED_SINCE'),
            self::value($server, 'HTTP_ACCEPT_ENCODING')
        );
    }

    /**
     * The method as raw bytes, ASCII-upper-cased and nothing else.
     *
     * **It must never be echoed, logged unscrubbed, or interpolated into a
     * header.** It is safe only because the one consumer compares it with
     * `in_array(..., true)` against the methods we answer, so a token carrying
     * a CR LF simply fails to match and becomes a 405.
     *
     * @return string Upper-case; `GET` when the request did not say.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * @return string The raw request target, query string and all — normalising
     *                it is {@see RouteMatcher}'s job, and doing it in two
     *                places is how the two stop agreeing.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function ifNoneMatch(): ?string
    {
        return $this->ifNoneMatch;
    }

    public function ifModifiedSince(): ?string
    {
        return $this->ifModifiedSince;
    }

    public function acceptEncoding(): ?string
    {
        return $this->acceptEncoding;
    }

    /**
     * Whether the body should be withheld — `HEAD` gets the headers a `GET`
     * would produce and nothing else (doc 10 §HEAD).
     */
    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return string|null Null when absent, not a string, empty, or over the
     *                     8 KB bound.
     */
    private static function value(array $server, string $key): ?string
    {
        if (!isset($server[$key]) || !\is_string($server[$key])) {
            return null;
        }

        $value = $server[$key];

        if ($value === '' || \strlen($value) > self::MAX_HEADER_BYTES) {
            return null;
        }

        return $value;
    }

    /**
     * ASCII-only upper-casing.
     *
     * `strtoupper()` consults the C locale on PHP 7.3, so a host that has called
     * `setlocale()` can change what a method name upper-cases to. A method is a
     * protocol token, not text.
     */
    private static function upper(string $value): string
    {
        return \strtr($value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }
}
