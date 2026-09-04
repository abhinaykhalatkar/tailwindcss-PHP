<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Http\AcceptEncoding;

/**
 * `Accept-Encoding` negotiation, RFC 7231 §5.3.4 (TASK-0016 acceptance 3).
 *
 * The bias throughout is that **failing to compress is free and compressing
 * wrongly is not**. A client that cannot decode gzip and receives it gets an
 * unreadable stylesheet; a client that could have had gzip and gets plain CSS
 * pays some bytes. So every ambiguous or malformed input resolves to `false`,
 * and the tests below are mostly about pinning that direction rather than the
 * happy path.
 */
final class AcceptEncodingTest extends TestCase
{
    /**
     * @dataProvider acceptingHeaders
     *
     * @test
     */
    public function gzipIsAccepted(?string $header): void
    {
        self::assertTrue(AcceptEncoding::parse($header)->accepts('gzip'));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function acceptingHeaders(): array
    {
        return [
            'bare gzip' => ['gzip'],
            'gzip among others' => ['gzip, deflate, br'],
            'with an explicit q of 1' => ['gzip;q=1'],
            'with a fractional q' => ['gzip;q=0.5'],
            'with q=1.0' => ['gzip;q=1.0'],
            'uppercase token' => ['GZIP'],
            'mixed case token' => ['GzIp'],
            'padded with spaces' => ['  gzip  '],
            'space before the q parameter' => ['gzip ; q=0.8'],
            // HBC-A17: the wildcard accepts gzip.
            'wildcard alone' => ['*'],
            'wildcard among others' => ['deflate, *'],
            'wildcard with a q' => ['*;q=0.1'],
        ];
    }

    /**
     * @dataProvider rejectingHeaders
     *
     * @test
     */
    public function gzipIsNotAccepted(?string $header): void
    {
        self::assertFalse(AcceptEncoding::parse($header)->accepts('gzip'));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function rejectingHeaders(): array
    {
        return [
            // The header being absent is not the same as it being empty, but
            // both mean "do not compress" here.
            'absent' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
            'other encodings only' => ['deflate, br'],

            // q=0 is an explicit refusal, and outranks a wildcard that would
            // otherwise allow it — the case a naive `strpos($header, 'gzip')`
            // gets exactly backwards.
            'explicit q of zero' => ['gzip;q=0'],
            'q of zero with decimals' => ['gzip;q=0.0'],
            'q of zero among others' => ['deflate, gzip;q=0'],
            'refused gzip with an allowing wildcard' => ['gzip;q=0, *'],

            // A wildcard refusal likewise.
            'wildcard refused' => ['*;q=0'],

            // Malformed input resolves to "no", never to a guess.
            'malformed q' => ['gzip;q=abc'],
            'q above 1' => ['gzip;q=2'],
            'negative q' => ['gzip;q=-1'],
            'empty q' => ['gzip;q='],
            'token that merely contains gzip' => ['notgzip'],
            'gzip as a prefix of another token' => ['gzipx'],
            'nul byte' => ["gzip\0"],
            'newline injected' => ["gzip\nfoo"],
        ];
    }

    /**
     * A refusal of one encoding says nothing about another.
     *
     * @test
     */
    public function refusingGzipDoesNotRefuseEverything(): void
    {
        $negotiated = AcceptEncoding::parse('gzip;q=0, deflate');

        self::assertFalse($negotiated->accepts('gzip'));
        self::assertTrue($negotiated->accepts('deflate'));
    }

    /**
     * The header is attacker-controlled, so parsing must stay cheap however
     * silly it gets.
     *
     * @test
     */
    public function alongHostileHeaderIsCheapToParse(): void
    {
        $header = \str_repeat('deflate;q=0.5, ', 5000) . 'gzip';
        $started = \microtime(true);

        self::assertTrue(AcceptEncoding::parse($header)->accepts('gzip'));
        self::assertLessThan(1000.0, (\microtime(true) - $started) * 1000);
    }
}
