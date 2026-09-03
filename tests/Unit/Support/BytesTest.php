<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Bytes;

/**
 * The byte-level helpers shared by path handling, log scrubbing and stderr
 * truncation. Everything here was written three times over before it was
 * collected into one place, so the cases below are the union of what each
 * caller relied on.
 */
final class BytesTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Bytes::class);

        self::assertTrue($reflection->isFinal());
    }

    // -------------------------------------------------------- isAsciiAlpha

    /**
     * @test
     *
     * @dataProvider asciiLetterCases
     */
    public function isAsciiAlphaAnswersForSingleBytes(string $byte, bool $expected): void
    {
        self::assertSame($expected, Bytes::isAsciiAlpha($byte));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function asciiLetterCases(): array
    {
        return [
            'lowercase a' => ['a', true],
            'lowercase z' => ['z', true],
            'uppercase A' => ['A', true],
            'uppercase Z' => ['Z', true],
            'digit' => ['5', false],
            'underscore' => ['_', false],
            'colon' => [':', false],
            'slash' => ['/', false],
            'backslash' => ['\\', false],
            'space' => [' ', false],
            'NUL' => ["\0", false],
            // The reason ctype_alpha() is avoided: under some locales a high
            // byte counts as alphabetic, which would let "\xC9:/x" read as a
            // Windows drive prefix.
            'high byte C9' => ["\xC9", false],
            'high byte FF' => ["\xFF", false],
            'byte just below A' => ['@', false],
            'byte just above Z' => ['[', false],
            'byte just below a' => ['`', false],
            'byte just above z' => ['{', false],
        ];
    }

    // -------------------------------------------------- utf8SequenceLength

    /**
     * @test
     *
     * @dataProvider leadByteCases
     */
    public function utf8SequenceLengthClassifiesLeadBytes(int $byte, int $expected): void
    {
        self::assertSame($expected, Bytes::utf8SequenceLength($byte));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function leadByteCases(): array
    {
        return [
            'ASCII NUL' => [0x00, 1],
            'ASCII a' => [0x61, 1],
            'ASCII DEL' => [0x7F, 1],
            'two-byte lead C3' => [0xC3, 2],
            'two-byte lead C2' => [0xC2, 2],
            'three-byte lead E2' => [0xE2, 3],
            'four-byte lead F0' => [0xF0, 4],
            'continuation 80' => [0x80, 0],
            'continuation BF' => [0xBF, 0],
            'invalid F8' => [0xF8, 0],
            'invalid FF' => [0xFF, 0],
        ];
    }

    // --------------------------------------------- cutAtCharacterBoundary

    /** @test */
    public function aCutShorterThanTheStringIsReturnedWhole(): void
    {
        self::assertSame('abc', Bytes::cutAtCharacterBoundary('abc', 10));
    }

    /** @test */
    public function aZeroOrNegativeLengthYieldsAnEmptyString(): void
    {
        self::assertSame('', Bytes::cutAtCharacterBoundary('abc', 0));
        self::assertSame('', Bytes::cutAtCharacterBoundary('abc', -1));
    }

    /** @test */
    public function anAsciiCutIsExact(): void
    {
        self::assertSame('abcd', Bytes::cutAtCharacterBoundary('abcdefgh', 4));
    }

    /**
     * 'é' is two bytes (C3 A9): a budget landing between them drops the whole
     * character rather than emitting half of it.
     *
     * @test
     */
    public function aTwoByteCharacterStraddlingTheCutIsDropped(): void
    {
        self::assertSame('a', Bytes::cutAtCharacterBoundary('aébcdef', 2));
    }

    /** @test */
    public function aTwoByteCharacterThatFitsExactlyIsKept(): void
    {
        $result = Bytes::cutAtCharacterBoundary('aébcdef', 3);

        self::assertSame('aé', $result);
        self::assertSame(3, \strlen($result));
    }

    /** @test */
    public function aFourByteCharacterStraddlingTheCutIsDropped(): void
    {
        $subject = 'ab' . "\xF0\x9F\x98\x80" . 'cd';

        self::assertSame('ab', Bytes::cutAtCharacterBoundary($subject, 4));
    }

    /** @test */
    public function aFourByteCharacterThatFitsExactlyIsKept(): void
    {
        $subject = 'ab' . "\xF0\x9F\x98\x80" . 'cd';

        self::assertSame('ab' . "\xF0\x9F\x98\x80", Bytes::cutAtCharacterBoundary($subject, 6));
    }

    /**
     * A run of continuation bytes longer than any real sequence means the input
     * is not valid UTF-8 at the cut. Returning '' there would throw away the
     * whole excerpt over a few stray bytes, so the raw cut is kept — the
     * behaviour `CliProcessException` depends on for a 2 KB stderr sample.
     *
     * @test
     */
    public function anInvalidUtf8RunReturnsTheRawCutRatherThanNothing(): void
    {
        // Five continuation bytes at the cut: more than any real sequence, so
        // the back-off gives up and keeps what it has.
        $subject = \str_repeat('x', 10) . \str_repeat("\x80", 6) . \str_repeat('y', 10);

        $result = Bytes::cutAtCharacterBoundary($subject, 15);

        self::assertNotSame('', $result);
        self::assertSame(15, \strlen($result));
        self::assertStringStartsWith('xxxxxxxxxx', $result);
    }

    /**
     * Fewer stray bytes than that and the walk still reaches a valid lead byte,
     * so it backs off to the last whole character instead.
     *
     * @test
     */
    public function aShortInvalidRunBacksOffToTheLastValidCharacter(): void
    {
        $subject = \str_repeat('x', 10) . \str_repeat("\x80", 6) . \str_repeat('y', 10);

        self::assertSame(\str_repeat('x', 10), Bytes::cutAtCharacterBoundary($subject, 14));
    }

    /** @test */
    public function anEmptySubjectStaysEmpty(): void
    {
        self::assertSame('', Bytes::cutAtCharacterBoundary('', 5));
    }

    /** @test */
    public function theResultIsNeverLongerThanTheBudget(): void
    {
        $subject = 'aé' . "\xF0\x9F\x98\x80" . 'bcdef';

        for ($length = 0; $length <= \strlen($subject) + 2; ++$length) {
            self::assertLessThanOrEqual(
                $length,
                \strlen(Bytes::cutAtCharacterBoundary($subject, $length)),
                \sprintf('Cut at %d exceeded its budget.', $length)
            );
        }
    }
}
