<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Support\Regex;

/**
 * The header matrix of design doc 10 §Response headers, in one place
 * (FR-HTTP-2/6/7, threat T-10, decisions HBC-A4/A5/A14).
 *
 * Every response this library emits is built here, and that is on purpose:
 * caching correctness is a property of the *whole* matrix rather than of any
 * one row. `immutable` on a plain route poisons a URL that will change
 * (invariant I6); an `ETag` in debug hands the browser a 304 for a stylesheet
 * that is recompiled on every request (invariant I7, decision HBC-A14). Those
 * are two lines of code in two different branches, and they are only reliably
 * right if the branches sit next to each other.
 *
 * **Header safety (T-10).** Nothing reaches a response header without passing
 * {@see self::assertHeader()}: names are `^[A-Za-z0-9-]+$`, values carry no
 * control byte at all — not just no CR/LF. The two values that could ever be
 * influenced from outside are the fingerprint, which becomes an `ETag`, and the
 * redirect target; the first is re-validated with {@see Fingerprint::isValid()}
 * before use (decision CCP-A28, which makes this layer responsible for it) and
 * the second is built from configuration and that same fingerprint.
 *
 * **Configured extras are merged first**, so a generated header always wins.
 * `extra_headers` exists for `Access-Control-Allow-Origin` on a CDN; it is not
 * a way to replace the `Cache-Control` or `Content-Type` this library's
 * correctness rests on.
 */
final class ResponseBuilder
{
    /**
     * The only content type this library produces (FR-HTTP-6).
     */
    private const CONTENT_TYPE = 'text/css; charset=utf-8';

    /**
     * Sent on every response (HBC-A4): the body is CSS, and a browser must not
     * be allowed to decide otherwise.
     */
    private const NOSNIFF = 'nosniff';

    /**
     * Always sent with a body, gzip twin or not — a cache that stored the
     * plain form must not later hand it to a client that got the gzip form.
     */
    private const VARY = 'Accept-Encoding';

    /**
     * The methods a stylesheet route answers (doc 10 §HEAD).
     */
    private const ALLOW = 'GET, HEAD';
    private const ALLOW_OPTIONS = 'GET, HEAD, OPTIONS';

    /**
     * The suffix every route carries.
     */
    private const SUFFIX = '.css';

    /**
     * @var array<string, string>
     */
    private $extraHeaders;

    /**
     * @var int
     */
    private $immutableMaxAge;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @param array<string, string> $extraHeaders From `http.extra_headers`.
     * @param int $immutableMaxAge From `http.immutable_max_age`, seconds.
     * @param bool $debug The configured debug flag — never anything a request
     *                    said (threat T-09).
     *
     * @throws ConfigurationException When a configured extra header would be
     *                                unsafe to send.
     */
    public function __construct(array $extraHeaders, int $immutableMaxAge, bool $debug)
    {
        $problems = [];
        $checked = [];

        foreach ($extraHeaders as $name => $value) {
            $name = (string) $name;

            if (!\is_string($value)) {
                $problems[] = \sprintf('http.extra_headers: the value of "%s" must be a string.', $name);

                continue;
            }

            if (!self::isValidName($name)) {
                $problems[] = \sprintf(
                    'http.extra_headers: "%s" is not a valid header name (letters, digits and hyphens only).',
                    $name
                );

                continue;
            }

            if (!self::isValidValue($value)) {
                // The value is deliberately not echoed: it is the thing that
                // contains a control byte, and a problem list ends up in a log.
                $problems[] = \sprintf(
                    'http.extra_headers: the value of "%s" contains a control character.',
                    $name
                );

                continue;
            }

            $checked[$name] = $value;
        }

        if ($problems !== []) {
            throw new ConfigurationException($problems);
        }

        $this->extraHeaders = $checked;
        $this->immutableMaxAge = $immutableMaxAge;
        $this->debug = $debug;
    }

    /**
     * A built stylesheet: the 200 rows of the matrix, or the 304 the request
     * asked for.
     *
     * Which body is sent is decided here rather than by the caller, because
     * "never gzip in debug" (I7) belongs with the rest of the debug rules. The
     * caller supplies facts — what exists, what the client accepts — and this
     * method supplies the policy.
     *
     * @param string $path Absolute path of the plain artefact.
     * @param int $length Its size in bytes.
     * @param string|null $gzPath Its pre-compressed twin, when one exists.
     * @param int|null $gzLength That twin's size in bytes.
     * @param string $fingerprint The artefact's fingerprint; becomes the ETag.
     * @param int $lastModified The artefact's mtime.
     * @param bool $immutable True only for a request that arrived on a
     *                        fingerprinted route (invariant I6).
     *
     * @throws \InvalidArgumentException When the fingerprint is not a
     *                                   fingerprint (CCP-A28).
     */
    public function asset(
        ServerRequest $request,
        string $path,
        int $length,
        ?string $gzPath,
        ?int $gzLength,
        string $fingerprint,
        int $lastModified,
        bool $immutable
    ): Response {
        if (!Fingerprint::isValid($fingerprint)) {
            // Not echoed: an invalid fingerprint is exactly the value that
            // might carry a newline, and this message may be logged.
            throw new \InvalidArgumentException('The fingerprint is not valid and cannot become an ETag.');
        }

        $etag = Fingerprint::toETag($fingerprint);
        $cacheControl = $this->cacheControl($immutable);
        $gzip = !$this->debug
            && $gzPath !== null
            && $gzLength !== null
            && AcceptEncoding::parse($request->acceptEncoding())->accepts('gzip');

        if (!$this->debug && $this->isNotModified($request, $etag, $lastModified)) {
            // Doc 10 §Conditional: a 304 repeats the validators of the 200 it
            // stands in for, and nothing else.
            $headers = $this->headers([
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
                'Vary' => self::VARY,
            ]);

            return Response::none(304, $headers);
        }

        $generated = [
            'Content-Type' => self::CONTENT_TYPE,
            'X-Content-Type-Options' => self::NOSNIFF,
            'Cache-Control' => $cacheControl,
            'Vary' => self::VARY,
        ];

        if (!$this->debug) {
            // HBC-A14: debug sends neither validator, so a browser cannot be
            // handed a 304 for a stylesheet that is rebuilt every request.
            $generated['ETag'] = $etag;
            $generated['Last-Modified'] = HttpDate::format($lastModified);
        }

        if ($gzip) {
            $generated['Content-Encoding'] = 'gzip';
        }

        $generated['Content-Length'] = (string) ($gzip ? $gzLength : $length);

        $headers = $this->headers($generated);

        if ($request->isHead()) {
            // Doc 10 §HEAD: the headers a GET would have produced, including
            // the Content-Length of the negotiated body.
            return Response::none(200, $headers);
        }

        return Response::file(200, $headers, $gzip ? (string) $gzPath : $path);
    }

    /**
     * A body we already hold: the debug overlay (200) and the production
     * failure comment (500) of doc 15.
     *
     * Never cached and never validated — a failure must not be remembered by
     * anything, and the next request has to be given the chance to succeed.
     */
    public function inline(ServerRequest $request, int $status, string $body): Response
    {
        $headers = $this->headers([
            'Content-Type' => self::CONTENT_TYPE,
            'X-Content-Type-Options' => self::NOSNIFF,
            'Cache-Control' => 'no-store',
            'Vary' => self::VARY,
            'Content-Length' => (string) \strlen($body),
        ]);

        if ($request->isHead()) {
            return Response::none($status, $headers);
        }

        return Response::inline($status, $headers, $body);
    }

    /**
     * The 302 for a fingerprint we no longer hold (FR-HTTP-4, ADR-018).
     *
     * `Location` is a **relative reference** (HBC-A4) so a library mounted in a
     * sub-directory redirects within it rather than to the domain root.
     *
     * The target is **assembled here** rather than accepted ready-made, so the
     * fingerprint passes the same {@see Fingerprint::isValid()} gate as the one
     * that becomes an `ETag` (CCP-A28). Taking a finished string would leave a
     * caller free to build `styles.../../etc/passwd.css`, which carries no
     * control byte and so would sail past the header validator below.
     *
     * @param string $basename The route's own basename without `.css`, from
     *                         configuration.
     * @param string $fingerprint The current fingerprint, from the manifest.
     *
     * @throws \InvalidArgumentException When the fingerprint is not a
     *                                   fingerprint.
     */
    public function redirect(string $basename, string $fingerprint): Response
    {
        if (!Fingerprint::isValid($fingerprint)) {
            throw new \InvalidArgumentException('The fingerprint is not valid and cannot become a Location.');
        }

        return Response::none(302, $this->headers([
            'Content-Type' => self::CONTENT_TYPE,
            'X-Content-Type-Options' => self::NOSNIFF,
            'Location' => $basename . '.' . $fingerprint . self::SUFFIX,
            'Cache-Control' => 'no-cache',
            'Content-Length' => '0',
        ]));
    }

    /**
     * 405 for anything that is not `GET`, `HEAD` or a handled `OPTIONS`
     * (HBC-A5): empty body, and never stored.
     */
    public function methodNotAllowed(): Response
    {
        return Response::none(405, $this->headers([
            'Content-Type' => self::CONTENT_TYPE,
            'X-Content-Type-Options' => self::NOSNIFF,
            'Allow' => self::ALLOW,
            'Cache-Control' => 'no-store',
            'Content-Length' => '0',
        ]));
    }

    /**
     * 204 for `OPTIONS`, and only when `http.handle_options` is on (HBC-A6);
     * otherwise the integrator's CORS layer owns the method and we return null
     * from the responder instead.
     */
    public function options(): Response
    {
        return Response::none(204, $this->headers([
            'Content-Type' => self::CONTENT_TYPE,
            'X-Content-Type-Options' => self::NOSNIFF,
            'Allow' => self::ALLOW_OPTIONS,
            'Cache-Control' => 'no-store',
        ]));
    }

    /**
     * Doc 10 §Conditional, in the order RFC 9110 requires: `If-None-Match`
     * decides on its own when present, and `If-Modified-Since` is consulted
     * only in its absence.
     *
     * An `If-Modified-Since` we cannot parse is not a match. {@see HttpDate}
     * refuses to guess for exactly this reason — the cost of `null` is one full
     * 200, the cost of a wrong guess is a stale stylesheet the browser will not
     * ask about again.
     */
    private function isNotModified(ServerRequest $request, string $etag, int $lastModified): bool
    {
        $ifNoneMatch = $request->ifNoneMatch();

        if ($ifNoneMatch !== null) {
            foreach (\explode(',', $ifNoneMatch) as $token) {
                $token = \trim($token);

                if ($token === '*') {
                    return true;
                }

                // A weak validator compares equal to a strong one for the
                // purposes of If-None-Match (RFC 9110 §8.8.3.2).
                if (\strncmp($token, 'W/', 2) === 0) {
                    $token = \substr($token, 2);
                }

                if ($token === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = $request->ifModifiedSince();

        if ($ifModifiedSince === null) {
            return false;
        }

        $since = HttpDate::parse($ifModifiedSince);

        return $since !== null && $lastModified <= $since;
    }

    private function cacheControl(bool $immutable): string
    {
        if ($this->debug) {
            return 'no-store';
        }

        if (!$immutable) {
            // Doc 10: "store but revalidate". The plain route's content changes
            // without its URL changing, so this is the only safe answer for it
            // (invariant I6).
            return 'no-cache';
        }

        return \sprintf('public, max-age=%d, immutable', $this->immutableMaxAge);
    }

    /**
     * Merge the configured extras under the generated headers and validate
     * everything on the way out.
     *
     * @param array<string, string> $generated
     *
     * @return array<string, string>
     */
    private function headers(array $generated): array
    {
        $headers = $this->extraHeaders;

        foreach ($generated as $name => $value) {
            self::assertHeader($name, $value);
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * The last gate a name and value pass before they are sent (T-10).
     *
     * Reaching it with a bad value is a bug rather than a configuration
     * mistake — the constructor has already rejected everything that came from
     * config — so it throws the type that says so.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertHeader(string $name, string $value): void
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid response header name "%s".', $name));
        }

        if (!self::isValidValue($value)) {
            throw new \InvalidArgumentException(
                \sprintf('The value of response header "%s" contains a control character.', $name)
            );
        }
    }

    private static function isValidName(string $name): bool
    {
        return Regex::match('#^[A-Za-z0-9-]+$#D', $name);
    }

    /**
     * No control byte at all, rather than no CR/LF.
     *
     * CR and LF are what splits a response, but a bare CR, a NUL or a DEL in a
     * header value is equally something we did not intend to send, and some
     * intermediaries normalise lone control bytes into a newline.
     */
    private static function isValidValue(string $value): bool
    {
        return !Regex::match('#[\x00-\x1F\x7F]#', $value);
    }
}
