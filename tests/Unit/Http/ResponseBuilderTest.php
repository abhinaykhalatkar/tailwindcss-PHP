<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Http\HttpDate;
use TailwindPhp\Http\Response;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\ServerRequest;

/**
 * The header matrix of design doc 10 (TASK-0016 acceptance 5; T-10).
 *
 * One test per row, plus the rows that are about what is **not** sent. Two of
 * those are invariants rather than preferences:
 *
 * - **I6** — the plain route is never `immutable`. Its content changes without
 *   its URL changing, so an immutable entry would pin a stylesheet in every
 *   browser that ever loaded it, for a year, with no way to correct it.
 * - **I7** — debug sends no `ETag` and no `Last-Modified`, so it can never
 *   answer 304 for a stylesheet it recompiles on every request.
 *
 * @covers-requirement FR-HTTP-2
 * @covers-requirement FR-HTTP-6
 * @covers-requirement FR-HTTP-7
 */
final class ResponseBuilderTest extends TestCase
{
    private const FINGERPRINT = 'a1b2c3d4e5f6';
    private const ETAG = '"a1b2c3d4e5f6"';
    private const MTIME = 1700000000;
    private const PATH = '/cache/app.a1b2c3d4e5f6.css';
    private const GZ_PATH = '/cache/app.a1b2c3d4e5f6.css.gz';

    /**
     * @test
     */
    public function thePlainRouteRevalidates(): void
    {
        $response = self::asset(self::builder(), self::request(), false);

        self::assertSame(200, $response->status());
        self::assertSame('text/css; charset=utf-8', $response->headerValue('Content-Type'));
        self::assertSame('nosniff', $response->headerValue('X-Content-Type-Options'));
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertSame('Accept-Encoding', $response->headerValue('Vary'));
        self::assertSame(self::ETAG, $response->headerValue('ETag'));
        self::assertSame(HttpDate::format(self::MTIME), $response->headerValue('Last-Modified'));
        self::assertSame('400', $response->headerValue('Content-Length'));
        self::assertSame(self::PATH, $response->bodyPath());
        self::assertNull($response->body());
    }

    /**
     * I6: only a fingerprinted URL may promise it will never change.
     *
     * @test
     */
    public function theFingerprintedRouteIsImmutable(): void
    {
        $response = self::asset(self::builder(), self::request(), true);

        self::assertSame('public, max-age=31536000, immutable', $response->headerValue('Cache-Control'));
        self::assertSame(self::ETAG, $response->headerValue('ETag'));
    }

    /**
     * @test
     */
    public function theImmutableMaxAgeIsConfigurable(): void
    {
        $builder = new ResponseBuilder([], 60, false);
        $response = self::asset($builder, self::request(), true);

        self::assertSame('public, max-age=60, immutable', $response->headerValue('Cache-Control'));
    }

    /**
     * I7, decision HBC-A14.
     *
     * @test
     */
    public function debugSendsNoValidatorsAndStoresNothing(): void
    {
        $builder = new ResponseBuilder([], 31536000, true);
        $response = self::asset($builder, self::request(), true);

        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($response->headerValue('ETag'));
        self::assertNull($response->headerValue('Last-Modified'));
    }

    /**
     * The other half of I7: a debug response is never compressed, however
     * enthusiastically the client asks.
     *
     * @test
     */
    public function debugNeverCompresses(): void
    {
        $builder = new ResponseBuilder([], 31536000, true);
        $response = $builder->asset(
            self::request(['HTTP_ACCEPT_ENCODING' => 'gzip']),
            self::PATH,
            400,
            self::GZ_PATH,
            120,
            self::FINGERPRINT,
            self::MTIME,
            true
        );

        self::assertNull($response->headerValue('Content-Encoding'));
        self::assertSame(self::PATH, $response->bodyPath());
        self::assertSame('400', $response->headerValue('Content-Length'));
    }

    /**
     * And the third: a conditional request in debug is answered with the CSS,
     * not with a 304 for a stylesheet that has just been rebuilt.
     *
     * @test
     */
    public function debugNeverAnswers304(): void
    {
        $builder = new ResponseBuilder([], 31536000, true);
        $response = $builder->asset(
            self::request(['HTTP_IF_NONE_MATCH' => self::ETAG]),
            self::PATH,
            400,
            null,
            null,
            self::FINGERPRINT,
            self::MTIME,
            false
        );

        self::assertSame(200, $response->status());
    }

    /**
     * @dataProvider matchingConditionals
     *
     * @param array<string, string> $server
     *
     * @test
     */
    public function aMatchingValidatorIs304(array $server): void
    {
        $response = self::asset(self::builder(), self::request($server), false);

        self::assertSame(304, $response->status());
        self::assertSame(self::ETAG, $response->headerValue('ETag'));
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertSame('Accept-Encoding', $response->headerValue('Vary'));
        self::assertNull($response->bodyPath());
        self::assertNull($response->body());
        // A 304 describes no body, so it must not claim a length for one.
        self::assertNull($response->headerValue('Content-Length'));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function matchingConditionals(): array
    {
        return [
            'exact ETag' => [['HTTP_IF_NONE_MATCH' => self::ETAG]],
            'weak form of the same ETag' => [['HTTP_IF_NONE_MATCH' => 'W/' . self::ETAG]],
            'a wildcard' => [['HTTP_IF_NONE_MATCH' => '*']],
            'a list containing it' => [['HTTP_IF_NONE_MATCH' => '"other", ' . self::ETAG . ', "third"']],
            'a list with padding' => [['HTTP_IF_NONE_MATCH' => '  "other" ,  ' . self::ETAG . '  ']],
            'If-Modified-Since equal to the mtime' => [
                ['HTTP_IF_MODIFIED_SINCE' => HttpDate::format(self::MTIME)],
            ],
            'If-Modified-Since after the mtime' => [
                ['HTTP_IF_MODIFIED_SINCE' => HttpDate::format(self::MTIME + 3600)],
            ],
        ];
    }

    /**
     * @dataProvider nonMatchingConditionals
     *
     * @param array<string, string> $server
     *
     * @test
     */
    public function anythingElseIsAFullResponse(array $server): void
    {
        $response = self::asset(self::builder(), self::request($server), false);

        self::assertSame(200, $response->status());
        self::assertSame(self::PATH, $response->bodyPath());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function nonMatchingConditionals(): array
    {
        return [
            'a different ETag' => [['HTTP_IF_NONE_MATCH' => '"0123abcd"']],
            'an unquoted fingerprint' => [['HTTP_IF_NONE_MATCH' => 'a1b2c3d4e5f6']],
            'an empty If-None-Match is absent' => [['HTTP_IF_NONE_MATCH' => '']],
            'If-Modified-Since before the mtime' => [
                ['HTTP_IF_MODIFIED_SINCE' => HttpDate::format(self::MTIME - 1)],
            ],
            // HttpDate::parse refuses to guess, and an unparseable date must
            // fall through to a 200 rather than be read as "not modified".
            'an unparseable If-Modified-Since' => [['HTTP_IF_MODIFIED_SINCE' => 'yesterday']],
            'an obsolete date format' => [['HTTP_IF_MODIFIED_SINCE' => 'Sunday, 06-Nov-94 08:49:37 GMT']],
        ];
    }

    /**
     * RFC 9110: when the client sent `If-None-Match`, `If-Modified-Since` is
     * not consulted at all — not even as a tie-breaker.
     *
     * @test
     */
    public function ifNoneMatchSuppressesIfModifiedSince(): void
    {
        $response = self::builder()->asset(
            self::request([
                'HTTP_IF_NONE_MATCH' => '"0123abcd"',
                'HTTP_IF_MODIFIED_SINCE' => HttpDate::format(self::MTIME + 3600),
            ]),
            self::PATH,
            400,
            null,
            null,
            self::FINGERPRINT,
            self::MTIME,
            false
        );

        self::assertSame(200, $response->status());
    }

    /**
     * @test
     */
    public function theCompressedTwinIsSentWhenTheClientAcceptsIt(): void
    {
        $response = self::builder()->asset(
            self::request(['HTTP_ACCEPT_ENCODING' => 'gzip, deflate']),
            self::PATH,
            400,
            self::GZ_PATH,
            120,
            self::FINGERPRINT,
            self::MTIME,
            false
        );

        self::assertSame('gzip', $response->headerValue('Content-Encoding'));
        self::assertSame(self::GZ_PATH, $response->bodyPath());
        // The length of what is actually sent, not of the stylesheet.
        self::assertSame('120', $response->headerValue('Content-Length'));
        self::assertSame('Accept-Encoding', $response->headerValue('Vary'));
    }

    /**
     * @dataProvider uncompressedCases
     *
     * @test
     */
    public function otherwiseThePlainArtefactIsSent(?string $acceptEncoding, ?string $gzPath, ?int $gzLength): void
    {
        $response = self::builder()->asset(
            self::request($acceptEncoding === null ? [] : ['HTTP_ACCEPT_ENCODING' => $acceptEncoding]),
            self::PATH,
            400,
            $gzPath,
            $gzLength,
            self::FINGERPRINT,
            self::MTIME,
            false
        );

        self::assertNull($response->headerValue('Content-Encoding'));
        self::assertSame(self::PATH, $response->bodyPath());
        self::assertSame('400', $response->headerValue('Content-Length'));
        // Sent even when nothing was negotiated, so a shared cache keyed on it
        // stays correct if a later request does negotiate.
        self::assertSame('Accept-Encoding', $response->headerValue('Vary'));
    }

    /**
     * @return array<string, array{string|null, string|null, int|null}>
     */
    public static function uncompressedCases(): array
    {
        return [
            'no twin on disk' => ['gzip', null, null],
            'the client sent no Accept-Encoding' => [null, self::GZ_PATH, 120],
            'the client refused gzip' => ['gzip;q=0', self::GZ_PATH, 120],
            'the client asked for something else' => ['br', self::GZ_PATH, 120],
        ];
    }

    /**
     * Doc 10 §HEAD: identical headers, including the `Content-Length` of the
     * body that a GET would have received — negotiated form included.
     *
     * @test
     */
    public function headRepeatsTheHeadersAndSendsNoBody(): void
    {
        $server = ['REQUEST_METHOD' => 'HEAD', 'HTTP_ACCEPT_ENCODING' => 'gzip'];

        $head = self::asset(self::builder(), self::request($server), true, self::GZ_PATH, 120);
        unset($server['REQUEST_METHOD']);
        $get = self::asset(self::builder(), self::request($server), true, self::GZ_PATH, 120);

        self::assertSame($get->headers(), $head->headers());
        self::assertSame('120', $head->headerValue('Content-Length'));
        self::assertNull($head->bodyPath());
        self::assertNull($head->body());
    }

    /**
     * @test
     */
    public function anInlineBodyIsNeverStored(): void
    {
        $response = self::builder()->inline(self::request(), 200, '/* overlay */');

        self::assertSame(200, $response->status());
        self::assertSame('/* overlay */', $response->body());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertSame('13', $response->headerValue('Content-Length'));
        self::assertNull($response->headerValue('ETag'));
        self::assertNull($response->headerValue('Last-Modified'));
        self::assertNull($response->bodyPath());
    }

    /**
     * @test
     */
    public function aFailureBodyCarriesItsStatus(): void
    {
        $response = self::builder()->inline(self::request(), 500, '/* tailwindcss-php: build failed */');

        self::assertSame(500, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertSame('/* tailwindcss-php: build failed */', $response->body());
    }

    /**
     * @test
     */
    public function headSuppressesAnInlineBodyToo(): void
    {
        $response = self::builder()->inline(self::request(['REQUEST_METHOD' => 'HEAD']), 500, 'xxxx');

        self::assertNull($response->body());
        self::assertSame('4', $response->headerValue('Content-Length'));
    }

    /**
     * HBC-A4: a relative reference, so a library mounted under a
     * sub-directory redirects within it.
     *
     * @test
     */
    public function theRedirectIsRelativeAndUncached(): void
    {
        $response = self::builder()->redirect('styles', 'a1b2c3d4e5f6');

        self::assertSame(302, $response->status());
        self::assertSame('styles.a1b2c3d4e5f6.css', $response->headerValue('Location'));
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertSame('0', $response->headerValue('Content-Length'));
        self::assertNull($response->body());
        self::assertNull($response->bodyPath());
    }

    /**
     * @test
     */
    public function unsupportedMethodsAreRefusedWithAnAllowHeader(): void
    {
        $response = self::builder()->methodNotAllowed();

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headerValue('Allow'));
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertSame('0', $response->headerValue('Content-Length'));
        self::assertNull($response->body());
    }

    /**
     * @test
     */
    public function handledOptionsAdvertisesTheThreeMethods(): void
    {
        $response = self::builder()->options();

        self::assertSame(204, $response->status());
        self::assertSame('GET, HEAD, OPTIONS', $response->headerValue('Allow'));
        self::assertNull($response->body());
        self::assertNull($response->headerValue('Content-Length'));
    }

    /**
     * Doc 10 §Conditional is specific about what a 304 repeats: the validators
     * of the 200 it stands in for, and nothing else. It is the one response
     * that does **not** carry `Content-Type` — HBC-A4 enumerates 302, 405 and
     * 500 — because a 304 describes a body the client already holds, and
     * content headers on it are at best noise and at worst a description that
     * disagrees with what was cached.
     *
     * @test
     */
    public function aNotModifiedResponseCarriesOnlyTheCachingHeaders(): void
    {
        $response = self::produce('notModified');

        self::assertSame(['ETag', 'Cache-Control', 'Vary'], \array_keys($response->headers()));
    }

    /**
     * HBC-A4: every response that describes a body identifies itself as CSS and
     * forbids sniffing, including the ones whose body is empty.
     *
     * @dataProvider everyResponseWithABody
     *
     * @test
     */
    public function everyResponseIsTypedAndUnsniffable(string $factory): void
    {
        $response = self::produce($factory);

        self::assertSame('text/css; charset=utf-8', $response->headerValue('Content-Type'));
        self::assertSame('nosniff', $response->headerValue('X-Content-Type-Options'));
    }

    /**
     * Doc 10: these are the integrator's business, and a library that sets them
     * silently breaks pages it knows nothing about (T-06).
     *
     * @dataProvider everyResponse
     *
     * @test
     */
    public function noResponseAdvertisesTheServerOrAPolicy(string $factory): void
    {
        $headers = self::produce($factory)->headers();

        foreach (['Content-Security-Policy', 'X-Powered-By', 'Server'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $headers);
        }
    }

    /**
     * Data-set names are prefixed rather than bare status codes: PHP casts a
     * numeric string key to an int, which would make this an `array<int, …>`
     * and quietly break the `array<string, …>` contract every provider here
     * declares.
     *
     * @return array<string, array{string}>
     */
    public static function everyResponse(): array
    {
        return ['a 200' => ['asset']] + self::everyResponseWithABody() + ['a 304' => ['notModified']];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyResponseWithABody(): array
    {
        return [
            'a 200' => ['asset'],
            'a 302' => ['redirect'],
            'a 405' => ['methodNotAllowed'],
            'a 500' => ['inline'],
        ];
    }

    /**
     * @test
     */
    public function configuredExtraHeadersAreMerged(): void
    {
        $builder = new ResponseBuilder(['Access-Control-Allow-Origin' => '*'], 31536000, false);
        $response = self::asset($builder, self::request(), false);

        self::assertSame('*', $response->headerValue('Access-Control-Allow-Origin'));
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
    }

    /**
     * `extra_headers` adds; it does not replace. Letting configuration
     * overwrite `Cache-Control` or `Content-Type` would put the invariants this
     * class exists to hold behind a config key.
     *
     * @test
     */
    public function configuredExtrasCannotOverrideAGeneratedHeader(): void
    {
        $builder = new ResponseBuilder([
            'Cache-Control' => 'public, max-age=99999999, immutable',
            'Content-Type' => 'text/html',
        ], 31536000, false);

        $response = self::asset($builder, self::request(), false);

        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertSame('text/css; charset=utf-8', $response->headerValue('Content-Type'));
    }

    /**
     * T-10. The rejection happens when the builder is constructed, so a
     * dangerous header cannot be discovered on the request path.
     *
     * @dataProvider unsafeExtraHeaders
     *
     * @param array<string, mixed> $extra
     *
     * @test
     */
    public function unsafeExtraHeadersAreRefused(array $extra): void
    {
        $this->expectException(ConfigurationException::class);

        new ResponseBuilder($extra, 31536000, false);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unsafeExtraHeaders(): array
    {
        return [
            'CR LF in the value' => [['X-Test' => "a\r\nX-Injected: 1"]],
            'a bare LF in the value' => [['X-Test' => "a\nb"]],
            'a bare CR in the value' => [['X-Test' => "a\rb"]],
            'a NUL in the value' => [['X-Test' => "a\0b"]],
            'a DEL in the value' => [['X-Test' => "a\x7Fb"]],
            'a newline in the name' => [["X-Test\r\nX-Injected" => '1']],
            'a colon in the name' => [['X-Test: x' => '1']],
            'a space in the name' => [['X Test' => '1']],
            'an empty name' => [['' => '1']],
            'a non-string value' => [['X-Test' => 1]],
        ];
    }

    /**
     * Every problem in one exception rather than one per round trip.
     *
     * @test
     */
    public function everyUnsafeExtraHeaderIsReportedAtOnce(): void
    {
        try {
            new ResponseBuilder(['X Test' => '1', 'X-Other' => "a\r\nb"], 31536000, false);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('X Test', $e->getMessage());
            self::assertStringContainsString('X-Other', $e->getMessage());
        }
    }

    /**
     * The message must not repeat the offending value: it is the thing with the
     * control byte in it, and the message ends up in a log.
     *
     * @test
     */
    public function theRejectionDoesNotEchoTheOffendingValue(): void
    {
        try {
            new ResponseBuilder(['X-Test' => "a\r\nX-Injected: 1"], 31536000, false);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('X-Injected', $e->getMessage());
        }
    }

    /**
     * Decision CCP-A28 puts the fingerprint check here: `Fingerprint::toETag()`
     * does not validate, so a fingerprint carrying a newline would otherwise
     * become an `ETag` header and split the response.
     *
     * @dataProvider invalidFingerprints
     *
     * @test
     */
    public function anInvalidFingerprintNeverBecomesAnETag(string $fingerprint): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::builder()->asset(self::request(), self::PATH, 400, null, null, $fingerprint, self::MTIME, false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFingerprints(): array
    {
        return [
            'a trailing newline' => ["a1b2c3d4e5f6\n"],
            'an embedded CR LF' => ["a1b2\r\nc3d4e5f6"],
            'a quote' => ['a1b2c3d4e"f6'],
            'too short' => ['0123abc'],
            'too long' => ['0123456789abcdef0'],
            'uppercase' => ['A1B2C3D4E5F6'],
            'empty' => [''],
        ];
    }

    private static function builder(): ResponseBuilder
    {
        return new ResponseBuilder([], 31536000, false);
    }

    /**
     * The artefact call with the constant arguments filled in, so each test
     * shows only the two or three facts it is actually about.
     */
    private static function asset(
        ResponseBuilder $builder,
        ServerRequest $request,
        bool $immutable,
        ?string $gzPath = null,
        ?int $gzLength = null
    ): Response {
        return $builder->asset(
            $request,
            self::PATH,
            400,
            $gzPath,
            $gzLength,
            self::FINGERPRINT,
            self::MTIME,
            $immutable
        );
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(array $server = []): ServerRequest
    {
        return ServerRequest::fromArray($server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/styles.css']);
    }

    /**
     * One response of each kind, for the properties that hold across all of
     * them.
     */
    private static function produce(string $factory): Response
    {
        $builder = self::builder();

        switch ($factory) {
            case 'notModified':
                return $builder->asset(
                    self::request(['HTTP_IF_NONE_MATCH' => self::ETAG]),
                    self::PATH,
                    400,
                    null,
                    null,
                    self::FINGERPRINT,
                    self::MTIME,
                    false
                );
            case 'redirect':
                return $builder->redirect('styles', 'a1b2c3d4e5f6');
            case 'methodNotAllowed':
                return $builder->methodNotAllowed();
            case 'inline':
                return $builder->inline(self::request(), 500, '/* tailwindcss-php: build failed */');
            default:
                return self::asset($builder, self::request(), false);
        }
    }
}
