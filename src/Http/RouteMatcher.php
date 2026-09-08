<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Support\Regex;

/**
 * Decides whether a request path is one of ours (design doc 10 §Routes,
 * FR-HTTP-1, threat T-02).
 *
 * This is the front door, and it is deliberately the cheapest thing in the
 * library: two anchored patterns per bundle, built once, and **no I/O at all**.
 * A request for `/favicon.ico` on a site that mounts this library must cost a
 * `strlen` and a `preg_match`, not a stat.
 *
 * The order of operations is load-bearing and is the reason this is a class
 * rather than a `preg_match` at the call site:
 *
 * 1. **Bound the length** before anything else, so a megabyte of percent
 *    escapes cannot buy a megabyte of decoding.
 * 2. **Strip the query string.** Doc 10 ignores it for matching, which is what
 *    keeps `?v=` cache-busting working on the plain route.
 * 3. **Strip one leading slash** — only one. `//assets/styles.css` is a
 *    different URL and must not be normalised into ours.
 * 4. **Percent-decode exactly once**, then match. Decoding after the match
 *    would let `assets/styles.%2e%2e.css` through as a route and hand the
 *    encoded bytes to the layer that builds filenames; decoding twice would
 *    make `%252e` mean `.`, which is the classic double-decode escape.
 * 5. **Refuse control bytes**, which is where `%00` and `%0a` land once decoded
 *    — a NUL truncates a filename and a newline splits a response header.
 *
 * Matching is byte-mode, anchored at both ends and case-sensitive. Case matters
 * for more than tidiness: on a case-insensitive filesystem two spellings of one
 * route would both serve, so two URLs would address one artefact and each would
 * carry the other's immutable cache entry.
 */
final class RouteMatcher
{
    /**
     * Longest request path considered, in bytes, before the query string is
     * stripped.
     *
     * Our routes are a few dozen bytes; 2048 is the conventional practical URL
     * limit, so this is pure headroom whose only job is to bound the decode of
     * attacker-supplied input (threat T-11).
     */
    private const MAX_PATH_BYTES = 2048;

    /**
     * The suffix every route carries; the fingerprint is inserted before it.
     */
    private const SUFFIX = '.css';

    /**
     * Bundle name => [plain pattern, fingerprinted pattern, public route].
     *
     * @var array<string, array{string, string, string}>
     */
    private $patterns = [];

    /**
     * @param array<string, string> $routes Bundle name => `public_route`, in
     *                                      configuration order.
     *
     * @throws \InvalidArgumentException When a route does not end in `.css`.
     */
    public function __construct(array $routes)
    {
        foreach ($routes as $bundle => $route) {
            $base = self::baseOf((string) $bundle, $route);
            $quoted = Regex::quote($base, '#');

            $this->patterns[(string) $bundle] = [
                '#^' . $quoted . '\.css$#D',
                '#^' . $quoted . '\.([a-f0-9]{8,16})\.css$#D',
                $route,
            ];
        }
    }

    /**
     * @param string $pathOrUri The request path, with or without a leading
     *                          slash and with or without a query string.
     *
     * @return RouteMatch|null Null means "not ours" — the caller must do
     *                         nothing at all, not even a stat.
     */
    public function match(string $pathOrUri): ?RouteMatch
    {
        if ($pathOrUri === '' || \strlen($pathOrUri) > self::MAX_PATH_BYTES) {
            return null;
        }

        $path = $pathOrUri;
        $query = \strpos($path, '?');

        if ($query !== false) {
            $path = \substr($path, 0, $query);
        }

        if (isset($path[0]) && $path[0] === '/') {
            $path = \substr($path, 1);
        }

        if ($path === '') {
            return null;
        }

        // `rawurldecode` rather than `urldecode`: in a path a `+` is a literal
        // plus, not a space.
        $path = \rawurldecode($path);

        // Everything a decoded route may contain is printable ASCII. Refusing
        // the rest here means the patterns below never have to reason about a
        // NUL or a newline, and neither does anything downstream.
        if (\strcspn($path, "\0\r\n\t") !== \strlen($path)) {
            return null;
        }

        foreach ($this->patterns as $bundle => $pattern) {
            if (Regex::match($pattern[0], $path)) {
                return new RouteMatch($bundle, $pattern[2], null);
            }

            $captured = null;

            if (Regex::match($pattern[1], $path, $captured) && isset($captured[1])) {
                // Re-validated even though the pattern already constrains it:
                // this is the value that becomes a filename and the body of an
                // ETag header, and the guarantee should not rest on someone
                // remembering the `D` modifier above (see Fingerprint).
                if (Fingerprint::isValid($captured[1])) {
                    return new RouteMatch($bundle, $pattern[2], $captured[1]);
                }
            }
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException When $route does not end in `.css`.
     */
    private static function baseOf(string $bundle, string $route): string
    {
        $length = \strlen($route);
        $suffix = \strlen(self::SUFFIX);

        if ($length <= $suffix || \substr($route, -$suffix) !== self::SUFFIX) {
            // A route we cannot express as the two patterns is a configuration
            // error. The bundle name is ours, not the request's, so naming it
            // here is safe and is what makes the mistake findable.
            throw new \InvalidArgumentException(
                \sprintf('The public route of bundle "%s" must end in ".css".', $bundle)
            );
        }

        return \substr($route, 0, $length - $suffix);
    }
}
