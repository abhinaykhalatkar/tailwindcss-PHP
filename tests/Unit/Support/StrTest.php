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

    /**
     * T-06 (TASK-0053): text a diagnostic echoes cannot break a log line or
     * `json_encode` — each control byte, invalid UTF-8 byte, C1 control, bidi
     * control or line separator becomes one `?`.
     *
     * @test
     *
     * @dataProvider unprintable
     */
    public function printableReplacesEachUnsafeByteOrSequence(string $subject, string $expected): void
    {
        $result = Str::printable($subject);

        self::assertSame($expected, $result);
        self::assertNotFalse(\json_encode($result), 'the result is valid UTF-8');
        self::assertLessThanOrEqual(\strlen($subject), \strlen($result), 'never longer');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unprintable(): array
    {
        return [
            'NUL' => ["a\x00b", 'a?b'],
            'SOH' => ["a\x01b", 'a?b'],
            'tab' => ["a\tb", 'a?b'],
            'newline' => ["a\nb", 'a?b'],
            'carriage return' => ["a\r\nb", 'a??b'],
            'ESC sequence' => ["\x1b[31mred\x1b[0m", '?[31mred?[0m'],
            'DEL' => ["a\x7fb", 'a?b'],
            'C1 control (U+0085)' => ["a\xc2\x85b", 'a?b'],
            'C1 CSI (U+009B)' => ["a\xc2\x9bb", 'a?b'],
            'lone continuation byte' => ["a\x80b", 'a?b'],
            'lone lead byte' => ["a\xc3", 'a?'],
            'truncated three-byte sequence' => ["a\xe2\x80b", 'a??b'],
            'overlong slash' => ["a\xc0\xafb", 'a??b'],
            'overlong three-byte' => ["\xe0\x80\xaf", '???'],
            'UTF-16 surrogate' => ["\xed\xa0\x80", '???'],
            'past U+10FFFF' => ["\xf4\x90\x80\x80", '????'],
            'byte FF' => ["\xff", '?'],
            'LRE U+202A' => ["a\xe2\x80\xaab", 'a?b'],
            'RLO U+202E' => ["a\xe2\x80\xaeb", 'a?b'],
            'LRI U+2066' => ["a\xe2\x81\xa6b", 'a?b'],
            'PDI U+2069' => ["a\xe2\x81\xa9b", 'a?b'],
            'LRM U+200E' => ["a\xe2\x80\x8eb", 'a?b'],
            'RLM U+200F' => ["a\xe2\x80\x8fb", 'a?b'],
            'ALM U+061C' => ["a\xd8\x9cb", 'a?b'],
            'line separator U+2028' => ["a\xe2\x80\xa8b", 'a?b'],
            'paragraph separator U+2029' => ["a\xe2\x80\xa9b", 'a?b'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider printableAlready
     */
    public function printableKeepsPrintableText(string $subject): void
    {
        self::assertSame($subject, Str::printable($subject));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function printableAlready(): array
    {
        return [
            'empty' => [''],
            'ASCII with punctuation' => ['@custom-variant x (&:hover) { } ~`!@#$%^&*()_+-=[]\\|;:\'",.<>/?'],
            'two-byte (é)' => ["caf\xc3\xa9"],
            'ellipsis U+2026' => ["abc\xe2\x80\xa6"],
            'em dash U+2014' => ["a\xe2\x80\x94b"],
            'U+202F (narrow no-break space, next to the bidi block)' => ["a\xe2\x80\xafb"],
            'U+2065 (unassigned, before the isolates)' => ["a\xe2\x81\xa5b"],
            'CJK' => ["\xe4\xb8\xad\xe6\x96\x87"],
            'emoji (four bytes)' => ["\xf0\x9f\x98\x80"],
            'U+10FFFF' => ["\xf4\x8f\xbf\xbf"],
            'U+00A0 (first after C1)' => ["\xc2\xa0"],
        ];
    }
}
