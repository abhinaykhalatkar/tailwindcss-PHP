<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\ServerRequest;

/**
 * Threat T-10 — nothing this library sends may split a response.
 *
 * A CR/LF inside a header value ends the header block early and lets whatever
 * follows be read as further headers or as the body: one injected
 * `Set-Cookie`, one injected `Content-Type: text/html` and a stylesheet route
 * has become a stored-XSS vector.
 *
 * There are only two places a value could come from:
 *
 * - **`http.extra_headers`**, which is configuration and is therefore checked
 *   once, when the builder is constructed, rather than per request. A site
 *   whose config carries a bad header fails immediately and visibly, instead of
 *   on the first request that happens to reach the branch.
 * - **the fingerprint**, which becomes the `ETag` and the redirect target. It
 *   comes from a manifest we wrote, but it is also the one token the URL can
 *   influence, so decision CCP-A28 makes this layer validate it before use.
 *
 * The check is deliberately stricter than the threat: **no control byte at
 * all**, not just no CR and no LF. A bare CR, a NUL or a DEL in a header value
 * is something we did not intend to send, and some intermediaries normalise
 * lone control bytes into a newline.
 *
 * @covers-requirement NFR-SEC-7
 *
 * @covers-requirement NFR-SEC-9
 */
final class T10HeaderInjectionTest extends TestCase
{
    private const FINGERPRINT = 'a1b2c3d4e5f6';

    /**
     * @dataProvider injectionPayloads
     *
     * @test
     */
    public function anExtraHeaderValueCarryingAControlByteIsRefused(string $payload): void
    {
        $this->expectException(ConfigurationException::class);

        new ResponseBuilder(['X-Custom' => 'safe' . $payload . 'tail'], 31536000, false);
    }

    /**
     * @dataProvider injectionPayloads
     *
     * @test
     */
    public function anExtraHeaderNameCarryingAControlByteIsRefused(string $payload): void
    {
        $this->expectException(ConfigurationException::class);

        new ResponseBuilder(['X-Custom' . $payload => 'value'], 31536000, false);
    }

    /**
     * The classic payloads plus the near misses that a `str_replace("\r\n")`
     * style filter would let through.
     *
     * @return array<string, array{string}>
     */
    public static function injectionPayloads(): array
    {
        return [
            'CR LF then a header' => ["\r\nX-Injected: 1"],
            'LF then a header' => ["\nX-Injected: 1"],
            'CR then a header' => ["\rX-Injected: 1"],
            'a bare CR' => ["\r"],
            'a bare LF' => ["\n"],
            'double CR LF then a body' => ["\r\n\r\n<script>alert(1)</script>"],
            'LF CR' => ["\n\r"],
            'a NUL' => ["\0"],
            'a NUL then CR LF' => ["\0\r\n"],
            'a vertical tab' => ["\x0b"],
            'a form feed' => ["\x0c"],
            'a horizontal tab' => ["\t"],
            'DEL' => ["\x7f"],
            'an escape byte' => ["\x1b"],
        ];
    }

    /**
     * Header names are an allowlist, not a denylist: anything outside
     * `[A-Za-z0-9-]` is refused, so a colon or a space cannot start a second
     * header either.
     *
     * @dataProvider malformedNames
     *
     * @test
     */
    public function aMalformedHeaderNameIsRefused(string $name): void
    {
        $this->expectException(ConfigurationException::class);

        new ResponseBuilder([$name => 'value'], 31536000, false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedNames(): array
    {
        return [
            'a colon' => ['X-Custom: injected'],
            'a space' => ['X Custom'],
            'a leading space' => [' X-Custom'],
            'an underscore' => ['X_Custom'],
            'a dot' => ['X.Custom'],
            'a comma' => ['X,Custom'],
            'a quote' => ['X-"Custom"'],
            'empty' => [''],
            'a UTF-8 name' => ['X-Ünicode'],
            'a high byte' => ["X-\xff"],
        ];
    }

    /**
     * A safe extra header is still merged — without this, every row above would
     * pass on a builder that rejected everything.
     *
     * @test
     */
    public function aWellFormedExtraHeaderIsAccepted(): void
    {
        $builder = new ResponseBuilder([
            'Access-Control-Allow-Origin' => '*',
            'X-Custom-9' => 'a b c; q=0.5, "quoted"',
        ], 31536000, false);

        $response = $builder->methodNotAllowed();

        self::assertSame('*', $response->headerValue('Access-Control-Allow-Origin'));
        self::assertSame('a b c; q=0.5, "quoted"', $response->headerValue('X-Custom-9'));
    }

    /**
     * Decision CCP-A28: `Fingerprint::toETag()` does not validate, so this
     * layer must. A fingerprint carrying a newline would otherwise become the
     * body of an `ETag` header.
     *
     * @dataProvider injectionPayloads
     *
     * @test
     */
    public function aFingerprintCarryingAControlByteNeverBecomesAnETag(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ResponseBuilder([], 31536000, false))->asset(
            self::request(),
            '/cache/app.css',
            10,
            null,
            null,
            self::FINGERPRINT . $payload,
            1700000000,
            false
        );
    }

    /**
     * The `D` modifier in {@see Fingerprint::isValid()} is what stops the last
     * of these; without it PCRE lets `$` match before a final newline, and a
     * trailing `\n` in an `ETag` is a response split.
     *
     * @test
     */
    public function theValidatorItselfRejectsATrailingNewline(): void
    {
        self::assertTrue(Fingerprint::isValid(self::FINGERPRINT));
        self::assertFalse(Fingerprint::isValid(self::FINGERPRINT . "\n"));
        self::assertFalse(Fingerprint::isValid("\n" . self::FINGERPRINT));
    }

    /**
     * The same guard covers the redirect target, and it has to be the
     * *fingerprint* that is checked rather than the finished string. A caller
     * that assembled `styles.../../etc/passwd.css` would produce a `Location`
     * with no control byte in it at all, so the header validator alone would
     * pass it.
     *
     * @dataProvider injectionPayloads
     *
     * @test
     */
    public function aRedirectFingerprintCarryingAControlByteIsRefused(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ResponseBuilder([], 31536000, false))->redirect('styles', self::FINGERPRINT . $payload);
    }

    /**
     * @dataProvider pathShapedFingerprints
     *
     * @test
     */
    public function aRedirectFingerprintShapedLikeAPathIsRefused(string $fingerprint): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ResponseBuilder([], 31536000, false))->redirect('styles', $fingerprint);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathShapedFingerprints(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'an absolute path' => ['/etc/passwd'],
            'a windows path' => ['C:\\Windows\\win.ini'],
            'a separator' => ['a1b2/c3d4'],
            'a wrapper' => ['php://filter'],
            'a quote' => ['a1b2"c3d4'],
            'empty' => [''],
        ];
    }

    /**
     * The positive control: a real fingerprint still redirects, and the target
     * is the relative reference HBC-A4 requires.
     *
     * @test
     */
    public function aValidFingerprintProducesARelativeLocation(): void
    {
        $response = (new ResponseBuilder([], 31536000, false))->redirect('styles', self::FINGERPRINT);

        self::assertSame(302, $response->status());
        self::assertSame('styles.' . self::FINGERPRINT . '.css', $response->headerValue('Location'));
    }

    /**
     * Nothing a request sends is echoed into a response header, so there is no
     * request-side payload to test — but the conditional headers are the ones
     * that come closest, and this pins that they influence only the status.
     *
     * @test
     */
    public function requestHeadersAreNeverEchoedBack(): void
    {
        $response = (new ResponseBuilder([], 31536000, false))->asset(
            self::request([
                'HTTP_IF_NONE_MATCH' => "\"a1b2c3d4e5f6\"\r\nX-Injected: 1",
                'HTTP_ACCEPT_ENCODING' => "gzip\r\nX-Injected: 2",
            ]),
            '/cache/app.css',
            10,
            null,
            null,
            self::FINGERPRINT,
            1700000000,
            false
        );

        foreach ($response->headers() as $name => $value) {
            self::assertStringNotContainsString('X-Injected', $value);
            self::assertStringNotContainsString("\r", $value);
            self::assertStringNotContainsString("\n", $value);
        }

        self::assertArrayNotHasKey('X-Injected', $response->headers());
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(array $server = []): ServerRequest
    {
        return ServerRequest::fromArray($server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/styles.css']);
    }
}
