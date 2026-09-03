<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Str;

final class StrTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Str::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider prefixCases
     */
    public function removePrefixStripsOnlyALeadingMatch(string $subject, string $prefix, string $expected): void
    {
        self::assertSame($expected, Str::removePrefix($subject, $prefix));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function prefixCases(): array
    {
        return [
            'present' => ['assets/main.css', 'assets/', 'main.css'],
            'absent' => ['assets/main.css', 'public/', 'assets/main.css'],
            'empty prefix' => ['abc', '', 'abc'],
            'whole string' => ['abc', 'abc', ''],
            'prefix longer than subject' => ['ab', 'abc', 'ab'],
            'only the first occurrence' => ['aaa', 'a', 'aa'],
            'match must be at the start' => ['xabc', 'abc', 'xabc'],
            'byte-wise not case-insensitive' => ['ABC', 'abc', 'ABC'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider suffixCases
     */
    public function removeSuffixStripsOnlyATrailingMatch(string $subject, string $suffix, string $expected): void
    {
        self::assertSame($expected, Str::removeSuffix($subject, $suffix));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function suffixCases(): array
    {
        return [
            'present' => ['main.css', '.css', 'main'],
            'absent' => ['main.css', '.js', 'main.css'],
            'empty suffix' => ['abc', '', 'abc'],
            'whole string' => ['abc', 'abc', ''],
            'suffix longer than subject' => ['ab', 'abc', 'ab'],
            'only the last occurrence' => ['aaa', 'a', 'aa'],
            'match must be at the end' => ['abcx', 'abc', 'abcx'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider afterLastCases
     */
    public function afterLastReturnsTheTailOrTheWholeString(
        string $subject,
        string $needle,
        string $expected
    ): void {
        self::assertSame($expected, Str::afterLast($subject, $needle));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function afterLastCases(): array
    {
        return [
            'last separator wins' => ['a/b/c.css', '/', 'c.css'],
            'single occurrence' => ['a/b', '/', 'b'],
            'absent returns the whole string' => ['abc', '/', 'abc'],
            'needle at the end gives empty' => ['a/', '/', ''],
            'multi-byte needle' => ['x::y::z', '::', 'z'],
            'empty needle returns the whole string' => ['abc', '', 'abc'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider beforeFirstCases
     */
    public function beforeFirstReturnsTheHeadOrTheWholeString(
        string $subject,
        string $needle,
        string $expected
    ): void {
        self::assertSame($expected, Str::beforeFirst($subject, $needle));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function beforeFirstCases(): array
    {
        return [
            'first separator wins' => ['a/b/c', '/', 'a'],
            'absent returns the whole string' => ['abc', '/', 'abc'],
            'needle at the start gives empty' => ['/a', '/', ''],
            'multi-byte needle' => ['x::y::z', '::', 'x'],
            'empty needle returns the whole string' => ['abc', '', 'abc'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider truncateCases
     */
    public function truncateBytesNeverExceedsTheBudget(
        string $subject,
        int $maxBytes,
        string $ellipsis,
        string $expected
    ): void {
        $result = Str::truncateBytes($subject, $maxBytes, $ellipsis);

        self::assertSame($expected, $result);
        self::assertLessThanOrEqual($maxBytes, \strlen($result));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function truncateCases(): array
    {
        return [
            'short enough is untouched' => ['abc', 10, '...', 'abc'],
            'exactly at the budget is untouched' => ['abcde', 5, '...', 'abcde'],
            'ascii is cut and suffixed' => ['abcdefghij', 8, '...', 'abcde...'],
            'empty ellipsis' => ['abcdefghij', 4, '', 'abcd'],
            'budget smaller than the ellipsis' => ['abcdefghij', 2, '...', '..'],
            'zero budget' => ['abcdefghij', 0, '...', ''],
            'empty subject' => ['', 5, '...', ''],
        ];
    }

    /**
     * A cut must land on a character boundary: a truncated log line that ends
     * mid-sequence is invalid UTF-8 and can break a log viewer.
     *
     * @test
     */
    public function truncateBytesDoesNotSplitAMultiByteSequence(): void
    {
        // 'é' is two bytes (C3 A9). A budget of 2 lands between them, so the
        // whole character is dropped rather than half-emitted.
        $subject = 'aébcdef';

        self::assertSame('a', Str::truncateBytes($subject, 2, ''));

        // A budget of 3 fits the sequence exactly, so it is kept.
        self::assertSame('aé', Str::truncateBytes($subject, 3, ''));
        self::assertSame(3, \strlen(Str::truncateBytes($subject, 3, '')));
    }

    /** @test */
    public function truncateBytesDropsAnIncompleteLeadingSequence(): void
    {
        // A four-byte emoji that cannot fit is dropped whole rather than clipped.
        $subject = 'ab' . "\xF0\x9F\x98\x80" . 'cd';

        $result = Str::truncateBytes($subject, 4, '');

        self::assertSame('ab', $result);
    }

    /** @test */
    public function truncateBytesKeepsACompleteSequenceThatFitsExactly(): void
    {
        $subject = 'ab' . "\xF0\x9F\x98\x80" . 'cd';

        $result = Str::truncateBytes($subject, 6, '');

        self::assertSame('ab' . "\xF0\x9F\x98\x80", $result);
    }

    /**
     * The doc 15 use case: `message_short` is capped at 200 bytes.
     *
     * @test
     */
    public function truncateBytesSatisfiesTheMessageShortBudget(): void
    {
        $result = Str::truncateBytes(\str_repeat('x', 500), 200);

        self::assertSame(200, \strlen($result));
        self::assertSame('...', \substr($result, -3));
    }
}
