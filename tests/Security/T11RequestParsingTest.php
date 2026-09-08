<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Http\AcceptEncoding;
use TailwindPhp\Http\HttpDate;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\RouteMatcher;
use TailwindPhp\Http\ServerRequest;

/**
 * Threat T-11 — hostile request bytes must be boring.
 *
 * Everything this library reads from a request is attacker-controlled, and
 * every one of the four parsers it owns — the route, `Accept-Encoding`,
 * `If-Modified-Since`, `If-None-Match` — is a place where a parser bug becomes
 * a security bug. The design keeps them cheap and unambitious:
 *
 * - values are **opaque** in {@see ServerRequest}: nothing is split on `&`,
 *   nothing is fed to `parse_str`, no key becomes a variable;
 * - each value is **bounded** before it is parsed (8 KB per header, 2 KB per
 *   route), so no input buys unbounded work;
 * - every parser **fails closed**: an `Accept-Encoding` it cannot read means no
 *   compression, an `If-Modified-Since` it cannot read means a full 200, a
 *   route it cannot read means "not ours".
 *
 * The assertion running through all of it is that nothing throws. A parser that
 * throws on hostile input turns a stylesheet request into a 500, which is a
 * denial of service with extra steps.
 *
 * @covers-requirement NFR-SEC-5
 */
final class T11RequestParsingTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';

    /**
     * Decision HBC-A16: over 8 KB is treated as **absent**, not truncated. Half
     * an `If-None-Match` can still end on a syntactically complete token, and
     * that token would then be compared against our ETag.
     *
     * @dataProvider headerKeys
     *
     * @test
     */
    public function aNineKilobyteHeaderIsDropped(string $key): void
    {
        $request = ServerRequest::fromArray([$key => \str_repeat('x', 9216)]);

        self::assertNull($this->accessor($request, $key), $key . ' survived the 8 KB bound');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function headerKeys(): array
    {
        return [
            'if-none-match' => ['HTTP_IF_NONE_MATCH'],
            'if-modified-since' => ['HTTP_IF_MODIFIED_SINCE'],
            'accept-encoding' => ['HTTP_ACCEPT_ENCODING'],
        ];
    }

    /**
     * A 9 KB request target is refused by the matcher before it is decoded, so
     * a megabyte of percent escapes costs one `strlen`.
     *
     * @test
     */
    public function anOverLongRouteIsRefusedWithoutBeingDecoded(): void
    {
        $matcher = new RouteMatcher(['app' => self::ROUTE]);

        self::assertNull($matcher->match(\str_repeat('%2e', 3072)));
        self::assertNull($matcher->match('assets/styles.' . \str_repeat('a', 9216) . '.css'));
        self::assertNull($matcher->match(\str_repeat('/', 9216)));
    }

    /**
     * Nothing here may throw, whatever arrives. Each parser is given every
     * payload, because "it only crashes on the header nobody thought to test"
     * is how this class of bug survives.
     *
     * @dataProvider hostileValues
     *
     * @test
     */
    public function noParserThrowsOnHostileInput(string $value): void
    {
        $matcher = new RouteMatcher(['app' => self::ROUTE]);

        self::assertNull($matcher->match($value . '/nothing'));
        // Fails closed: an unreadable negotiation means no compression.
        self::assertFalse(AcceptEncoding::parse($value)->accepts('gzip'));
        // Fails closed: an unreadable date means a full 200.
        self::assertNull(HttpDate::parse($value));

        $request = ServerRequest::fromArray([
            'REQUEST_METHOD' => $value,
            'REQUEST_URI' => $value,
            'HTTP_IF_NONE_MATCH' => $value,
            'HTTP_IF_MODIFIED_SINCE' => $value,
            'HTTP_ACCEPT_ENCODING' => $value,
        ]);

        // The request survives, and a response can still be built from it: the
        // conditional logic must treat an unreadable validator as "no match"
        // rather than as a reason to fail.
        $response = (new ResponseBuilder([], 31536000, false))->asset(
            $request,
            '/cache/app.css',
            10,
            null,
            null,
            'a1b2c3d4e5f6',
            1700000000,
            false
        );

        self::assertSame(200, $response->status());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileValues(): array
    {
        return [
            'a NUL byte' => ["\0"],
            'a NUL in the middle' => ["gzip\0deflate"],
            'CR LF' => ["gzip\r\nX-Injected: 1"],
            'a lone CR' => ["gzip\rdeflate"],
            'high bytes' => ["\xff\xfe\xfd"],
            'an invalid UTF-8 sequence' => ["\xc3\x28"],
            'percent escapes' => ['%2e%2e%2f%2e%2e%2f'],
            'double-encoded escapes' => ['%252e%252e%252f'],
            'a truncated escape' => ['%2'],
            'a lone percent' => ['%'],
            'query and fragment syntax' => ['?a=1&b[]=2#frag'],
            'array syntax' => ['a[]=1&a[]=2&a[]=3'],
            'a semicolon list' => ['a;b;c;d'],
            'null bytes and separators' => ["a\0;b,c=d"],
            'a format string' => ['%s%s%n%x'],
            'a regex bomb shape' => [\str_repeat('a', 200) . '!'],
            'only separators' => [',,,;;;==='],
            'whitespace only' => ["   \t  "],
        ];
    }

    /**
     * A header that is merely **long** is not hostile, and must not be treated
     * as such: a few hundred repeated tokens is well-formed, so the answer is
     * still "yes, gzip". The property under test is that the work stays linear
     * and bounded, not that length is suspicious.
     *
     * @dataProvider longButValidHeaders
     *
     * @test
     */
    public function aLongButWellFormedHeaderIsStillParsedCorrectly(string $header): void
    {
        self::assertTrue(AcceptEncoding::parse($header)->accepts('gzip'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function longButValidHeaders(): array
    {
        return [
            'a repeated token' => [\str_repeat('gzip,', 400) . 'gzip'],
            'a long q list' => [\str_repeat('gzip;q=0.5,', 300) . 'gzip'],
            'many other codings first' => [\str_repeat('br, deflate, ', 300) . 'gzip'],
        ];
    }

    /**
     * Malformed q-values resolve to "do not compress" rather than to a guess:
     * sending gzip to a client that cannot decode it produces an unreadable
     * stylesheet, and sending plain CSS to one that could have had gzip costs
     * a few kilobytes.
     *
     * @dataProvider malformedQValues
     *
     * @test
     */
    public function aMalformedQValueRefusesCompression(string $header): void
    {
        self::assertFalse(AcceptEncoding::parse($header)->accepts('gzip'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedQValues(): array
    {
        return [
            'not a number' => ['gzip;q=abc'],
            'empty' => ['gzip;q='],
            'no equals' => ['gzip;q'],
            'out of range' => ['gzip;q=2'],
            'negative' => ['gzip;q=-1'],
            'explicitly refused' => ['gzip;q=0'],
            'refused with decimals' => ['gzip;q=0.000'],
            // A refusal outranks a wildcard that would otherwise allow it.
            'refused despite a wildcard' => ['gzip;q=0, *'],
            'hex' => ['gzip;q=0x1'],
        ];
    }

    /**
     * A boundary worth recording rather than asserting blindly.
     *
     * `q=1e-9` is not a qvalue: RFC 9110's grammar allows only `0(.ddd)` and
     * `1(.ddd)`, and a strict reading would ignore the whole entry. The
     * committed {@see AcceptEncoding} reads it as a well-formed number inside
     * [0, 1] and therefore as a very low but non-zero preference — so gzip is
     * sent.
     *
     * That is the safe direction of the two. The dangerous mistake would be
     * reading a refusal as an acceptance; this reads an acceptance as an
     * acceptance, and the client did list gzip and did not say `q=0`. Pinned
     * here so the behaviour is a decision rather than a coincidence.
     *
     * @test
     */
    public function anExponentialQValueReadsAsANonZeroPreference(): void
    {
        self::assertTrue(AcceptEncoding::parse('gzip;q=1e-9')->accepts('gzip'));
        // The refusal spelling still refuses, however it is written.
        self::assertFalse(AcceptEncoding::parse('gzip;q=0e0')->accepts('gzip'));
    }

    /**
     * A request target is handed on exactly as it arrived and normalised in one
     * place only. `#` is not a fragment to us — a real `REQUEST_URI` never
     * carries one — so a path containing it is simply not a route.
     *
     * @dataProvider awkwardTargets
     *
     * @test
     */
    public function awkwardRequestTargetsResolveOneWayOnly(string $target, bool $matches): void
    {
        $request = ServerRequest::fromArray(['REQUEST_URI' => $target]);

        self::assertSame($target, $request->path(), 'ServerRequest must not normalise');

        $match = (new RouteMatcher(['app' => self::ROUTE]))->match($target);

        self::assertSame($matches, $match !== null, 'wrong verdict for: ' . \rawurlencode($target));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function awkwardTargets(): array
    {
        return [
            'plain' => ['/assets/styles.css', true],
            'a query string' => ['/assets/styles.css?v=1', true],
            'an empty query string' => ['/assets/styles.css?', true],
            'repeated question marks' => ['/assets/styles.css??a=b?c=d', true],
            'a query string with a hash' => ['/assets/styles.css?v=1#frag', true],
            // Only one leading slash is stripped, so this is a different URL.
            'a doubled slash' => ['//assets/styles.css', false],
            'a tripled slash' => ['///assets/styles.css', false],
            'a fragment without a query' => ['/assets/styles.css#frag', false],
            'a trailing slash' => ['/assets/styles.css/', false],
            'a semicolon parameter' => ['/assets/styles.css;jsessionid=1', false],
            'a scheme' => ['http://host/assets/styles.css', false],
            'an authority form' => ['//host/assets/styles.css', false],
        ];
    }

    /**
     * The method is kept opaque, which is safe precisely because it is never
     * echoed and never parsed — it is compared, strictly, against the two
     * methods we answer. So a token with a smuggled CR LF in it cannot pass for
     * `GET`; it falls through to a 405 like any other unknown method.
     *
     * @dataProvider smuggledMethods
     *
     * @test
     */
    public function aSmuggledMethodTokenNeverPassesForAReadMethod(string $method): void
    {
        $request = ServerRequest::fromArray(['REQUEST_METHOD' => $method]);

        self::assertNotContains(
            $request->method(),
            ['GET', 'HEAD', 'OPTIONS'],
            'a crafted token was accepted as a method we answer'
        );
        self::assertFalse($request->isHead());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function smuggledMethods(): array
    {
        return [
            'CR LF after GET' => ["GET\r\nX-Injected: 1"],
            'a NUL after GET' => ["GET\0"],
            'a NUL after HEAD' => ["HEAD\0"],
            'leading whitespace' => [' GET'],
            'trailing whitespace' => ['GET '],
            'a lone NUL' => ["\0"],
            'a traversal string' => ['../../etc/passwd'],
            'a header line' => ['GET / HTTP/1.1'],
        ];
    }

    /**
     * The two things the method reader *does* do, and neither of them is
     * interpretation: fold ASCII case, and default to `GET` when the request
     * did not say (including when the value was too long to be a method).
     *
     * @test
     */
    public function theMethodIsFoldedAndDefaulted(): void
    {
        self::assertSame('GET', ServerRequest::fromArray(['REQUEST_METHOD' => 'gEt'])->method());
        self::assertSame('HEAD', ServerRequest::fromArray(['REQUEST_METHOD' => 'head'])->method());
        self::assertSame('GET', ServerRequest::fromArray([])->method());
        self::assertSame('GET', ServerRequest::fromArray(['REQUEST_METHOD' => ''])->method());
        self::assertSame(
            'GET',
            ServerRequest::fromArray(['REQUEST_METHOD' => \str_repeat('G', 9216)])->method(),
            'an over-long method is absent, and absent means GET'
        );
    }

    /**
     * An `If-None-Match` list of a few hundred entries is parsed in one pass
     * and answers correctly; a list that does not contain our ETag is a 200,
     * not a crash.
     *
     * @test
     */
    public function aLongValidatorListIsHandledInOnePass(): void
    {
        $tokens = [];

        for ($i = 0; $i < 400; $i++) {
            $tokens[] = \sprintf('"%08x"', $i);
        }

        $builder = new ResponseBuilder([], 31536000, false);

        $miss = $builder->asset(
            ServerRequest::fromArray(['HTTP_IF_NONE_MATCH' => \implode(', ', $tokens)]),
            '/cache/app.css',
            10,
            null,
            null,
            'a1b2c3d4e5f6',
            1700000000,
            false
        );

        $tokens[] = '"a1b2c3d4e5f6"';

        $hit = $builder->asset(
            ServerRequest::fromArray(['HTTP_IF_NONE_MATCH' => \implode(', ', $tokens)]),
            '/cache/app.css',
            10,
            null,
            null,
            'a1b2c3d4e5f6',
            1700000000,
            false
        );

        self::assertSame(200, $miss->status());
        self::assertSame(304, $hit->status());
    }

    /**
     * @return string|null
     */
    private function accessor(ServerRequest $request, string $key): ?string
    {
        switch ($key) {
            case 'HTTP_IF_NONE_MATCH':
                return $request->ifNoneMatch();
            case 'HTTP_IF_MODIFIED_SINCE':
                return $request->ifModifiedSince();
            default:
                return $request->acceptEncoding();
        }
    }
}
