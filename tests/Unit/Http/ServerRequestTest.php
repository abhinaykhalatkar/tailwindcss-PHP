<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Http\ServerRequest;

/**
 * The narrow window onto `$_SERVER` (TASK-0016 acceptance 2; threats T-09,
 * T-11).
 *
 * Most of these tests assert an **absence**, which is the point of the class:
 * the security property is not "it parses headers correctly" but "there is no
 * way to ask it for anything else". A request cannot turn on debug mode
 * (T-09) because there is no reader for the key that would.
 *
 * @covers-requirement NFR-SEC-5
 */
final class ServerRequestTest extends TestCase
{
    /**
     * @test
     */
    public function theFiveAllowedKeysAreRead(): void
    {
        $request = ServerRequest::fromArray([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/assets/styles.css?v=1',
            'HTTP_IF_NONE_MATCH' => '"a1b2c3d4e5f6"',
            'HTTP_IF_MODIFIED_SINCE' => 'Tue, 14 Nov 2023 22:13:20 GMT',
            'HTTP_ACCEPT_ENCODING' => 'gzip, deflate',
        ]);

        self::assertSame('GET', $request->method());
        self::assertSame('/assets/styles.css?v=1', $request->path());
        self::assertSame('"a1b2c3d4e5f6"', $request->ifNoneMatch());
        self::assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $request->ifModifiedSince());
        self::assertSame('gzip, deflate', $request->acceptEncoding());
    }

    /**
     * The path is handed on **as it arrived**, query string and all: normalising
     * it is {@see \TailwindPhp\Http\RouteMatcher}'s job, and a second
     * normaliser here is how the two stop agreeing about what a route is.
     *
     * @test
     */
    public function thePathIsNotNormalised(): void
    {
        $request = ServerRequest::fromArray(['REQUEST_URI' => '//assets/styles.css?a=b#c']);

        self::assertSame('//assets/styles.css?a=b#c', $request->path());
    }

    /**
     * @test
     */
    public function pathInfoIsTheFallback(): void
    {
        $request = ServerRequest::fromArray(['PATH_INFO' => '/assets/styles.css']);

        self::assertSame('/assets/styles.css', $request->path());
    }

    /**
     * @test
     */
    public function requestUriWinsOverPathInfo(): void
    {
        $request = ServerRequest::fromArray([
            'REQUEST_URI' => '/assets/styles.css',
            'PATH_INFO' => '/something/else.css',
        ]);

        self::assertSame('/assets/styles.css', $request->path());
    }

    /**
     * @test
     */
    public function anAbsentPathIsEmpty(): void
    {
        self::assertSame('', ServerRequest::fromArray([])->path());
    }

    /**
     * @dataProvider methods
     *
     * @param array<string, mixed> $server
     *
     * @test
     */
    public function theMethodIsUpperCasedWithAGetDefault(array $server, string $expected): void
    {
        self::assertSame($expected, ServerRequest::fromArray($server)->method());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function methods(): array
    {
        return [
            'absent' => [[], 'GET'],
            'empty' => [['REQUEST_METHOD' => ''], 'GET'],
            'not a string' => [['REQUEST_METHOD' => 1], 'GET'],
            'already upper case' => [['REQUEST_METHOD' => 'HEAD'], 'HEAD'],
            'lower case' => [['REQUEST_METHOD' => 'head'], 'HEAD'],
            'mixed case' => [['REQUEST_METHOD' => 'PoSt'], 'POST'],
            // Not a method we answer, but it must arrive intact so the caller
            // can refuse it with a 405 rather than mistake it for something.
            'unknown' => [['REQUEST_METHOD' => 'delete'], 'DELETE'],
        ];
    }

    /**
     * @test
     */
    public function headIsRecognised(): void
    {
        self::assertTrue(ServerRequest::fromArray(['REQUEST_METHOD' => 'head'])->isHead());
        self::assertFalse(ServerRequest::fromArray(['REQUEST_METHOD' => 'GET'])->isHead());
    }

    /**
     * Decision HBC-A16. Truncating would be worse than dropping: half an
     * `If-None-Match` can still end on a syntactically complete token, and that
     * token would then be compared against our ETag.
     *
     * @dataProvider oversizedKeys
     *
     * @test
     */
    public function aHeaderOverEightKilobytesIsTreatedAsAbsent(string $key): void
    {
        $server = [$key => \str_repeat('a', 8193)];
        $request = ServerRequest::fromArray($server);

        switch ($key) {
            case 'REQUEST_METHOD':
                self::assertSame('GET', $request->method());

                break;
            case 'REQUEST_URI':
                self::assertSame('', $request->path());

                break;
            case 'HTTP_IF_NONE_MATCH':
                self::assertNull($request->ifNoneMatch());

                break;
            case 'HTTP_IF_MODIFIED_SINCE':
                self::assertNull($request->ifModifiedSince());

                break;
            default:
                self::assertNull($request->acceptEncoding());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oversizedKeys(): array
    {
        return [
            'method' => ['REQUEST_METHOD'],
            'uri' => ['REQUEST_URI'],
            'if-none-match' => ['HTTP_IF_NONE_MATCH'],
            'if-modified-since' => ['HTTP_IF_MODIFIED_SINCE'],
            'accept-encoding' => ['HTTP_ACCEPT_ENCODING'],
        ];
    }

    /**
     * The bound is 8192 inclusive, not 8192 exclusive.
     *
     * @test
     */
    public function exactlyEightKilobytesIsStillAHeader(): void
    {
        $value = \str_repeat('a', 8192);

        self::assertSame($value, ServerRequest::fromArray(['HTTP_ACCEPT_ENCODING' => $value])->acceptEncoding());
    }

    /**
     * An oversized value on one key does not disturb the others.
     *
     * @test
     */
    public function oneOversizedHeaderDoesNotDropTheRest(): void
    {
        $request = ServerRequest::fromArray([
            'HTTP_IF_NONE_MATCH' => \str_repeat('a', 8193),
            'HTTP_ACCEPT_ENCODING' => 'gzip',
        ]);

        self::assertNull($request->ifNoneMatch());
        self::assertSame('gzip', $request->acceptEncoding());
    }

    /**
     * T-09: the keys that would matter are the ones that are not read. There is
     * no accessor for any of these, so this test pins that adding one would be
     * a deliberate act.
     *
     * @test
     */
    public function everythingElseIsIgnored(): void
    {
        $request = ServerRequest::fromArray([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/assets/styles.css',
            'QUERY_STRING' => 'debug=1',
            'HTTP_X_DEBUG' => '1',
            'HTTP_COOKIE' => 'debug=1',
            'HTTP_AUTHORIZATION' => 'Bearer x',
            'HTTP_HOST' => 'evil.test',
            'HTTP_X_FORWARDED_HOST' => 'evil.test',
            'REMOTE_ADDR' => '203.0.113.1',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'DOCUMENT_ROOT' => '/var/www',
        ]);

        $reflection = new \ReflectionClass(ServerRequest::class);
        $accessors = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && $method->getNumberOfParameters() === 0) {
                $accessors[] = $method->getName();
            }
        }

        \sort($accessors);

        self::assertSame(
            ['acceptEncoding', 'ifModifiedSince', 'ifNoneMatch', 'isHead', 'method', 'path'],
            $accessors,
            'ServerRequest grew an accessor; check it cannot be used to widen what a request may influence (T-09).'
        );

        // And the values that are read are unaffected by the noise above.
        self::assertSame('/assets/styles.css', $request->path());
        self::assertNull($request->acceptEncoding());
    }

    /**
     * Values are opaque: nothing here parses, splits or decodes, so a hostile
     * one arrives intact at the class that owns its grammar (T-11).
     *
     * @dataProvider hostileValues
     *
     * @test
     */
    public function hostileValuesArriveUnchanged(string $value): void
    {
        $request = ServerRequest::fromArray([
            'HTTP_IF_NONE_MATCH' => $value,
            'HTTP_ACCEPT_ENCODING' => $value,
            'REQUEST_URI' => $value,
        ]);

        self::assertSame($value, $request->ifNoneMatch());
        self::assertSame($value, $request->acceptEncoding());
        self::assertSame($value, $request->path());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileValues(): array
    {
        return [
            'a NUL byte' => ["a\0b"],
            'a CR LF' => ["a\r\nb"],
            'an ampersand' => ['a=1&b=2'],
            'array syntax' => ['a[]=1&a[]=2'],
            'percent escapes' => ['%2e%2e%2f'],
            'a semicolon' => ['a;b=c'],
        ];
    }

    /**
     * `fromGlobals()` reads the same five keys; the superglobal is restored so
     * the rest of the suite is unaffected.
     *
     * @test
     */
    public function fromGlobalsReadsTheSameKeys(): void
    {
        $original = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'head';
            $_SERVER['REQUEST_URI'] = '/assets/styles.css';
            $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';

            $request = ServerRequest::fromGlobals();

            self::assertSame('HEAD', $request->method());
            self::assertSame('/assets/styles.css', $request->path());
            self::assertSame('gzip', $request->acceptEncoding());
        } finally {
            $_SERVER = $original;
        }
    }
}
