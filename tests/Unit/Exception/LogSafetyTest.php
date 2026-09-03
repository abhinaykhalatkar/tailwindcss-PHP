<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Exception\LogSafety;

/**
 * The scrubber that keeps filesystem locations out of logs, the debug overlay
 * and cache artefacts (design doc 15 §Logging, threat T-06).
 */
final class LogSafetyTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(LogSafety::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider redactedCases
     */
    public function aLocationIsReplacedByTheMarker(string $input): void
    {
        $scrubbed = LogSafety::scrubText($input);

        self::assertStringContainsString(LogSafety::marker(), $scrubbed);
        self::assertStringNotContainsString('/etc/passwd', $scrubbed);
        self::assertStringNotContainsString('Windows', $scrubbed);
    }

    /**
     * Every one of these survived the first version of the scrubber because the
     * path was glued to punctuation that was not a token boundary.
     *
     * @return array<string, array{0: string}>
     */
    public static function redactedCases(): array
    {
        return [
            'bare' => ['cannot open /etc/passwd'],
            'in backticks' => ['cannot open `/etc/passwd` now'],
            'in braces' => ['cannot open {/etc/passwd} now'],
            'pipe separated' => ['a|/etc/passwd|b'],
            'curly double quotes' => ["cannot open \xE2\x80\x9C/etc/passwd\xE2\x80\x9D now"],
            'curly single quotes' => ["cannot open \xE2\x80\x98/etc/passwd\xE2\x80\x99 now"],
            'behind a label' => ['path:/etc/passwd'],
            'windows behind a label' => ['in:C:\\Windows\\system32'],
            'leading asterisk' => ['*/etc/passwd'],
            'leading dash' => ['-/etc/passwd'],
            'leading hash' => ['#/etc/passwd'],
            'leading space' => [' /etc/passwd'],
            'windows drive' => ['open C:\\Windows\\system32 failed'],
            'unc share' => ['open \\\\server\\share\\secret failed'],
            'file wrapper' => ['open file:///etc/passwd failed'],
            'phar wrapper' => ['open phar://evil.phar/etc/passwd failed'],
            'data wrapper' => ['data:text/plain;base64,L2V0Yy9wYXNzd2Q='],
        ];
    }

    /**
     * A documentation link is not a filesystem leak, and blanking it makes an
     * error harder to act on.
     *
     * @test
     *
     * @dataProvider preservedCases
     */
    public function ordinaryTextIsLeftAlone(string $input): void
    {
        self::assertSame($input, LogSafety::scrubText($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function preservedCases(): array
    {
        return [
            'plain sentence' => ['the utility could not be generated'],
            'relative path' => ['assets/styles/main.css'],
            'https url' => ['see https://tailwindcss.com/docs for details'],
            'http url' => ['see http://example.com/x for details'],
            'https url in backticks' => ['see `https://tailwindcss.com/docs` now'],
            'class candidate' => ['bg-red-500'],
            'arbitrary value' => ['w-[calc(100%-2rem)]'],
            'empty' => [''],
            'colon without a path' => ['ratio 16:9'],
            'time' => ['took 12:34 to build'],
            'non-ascii text' => ["caf\xC3\xA9 latte"],
        ];
    }

    /** @test */
    public function scrubArrayRecursesIntoNestedArrays(): void
    {
        $scrubbed = LogSafety::scrubArray([
            'a' => '/etc/passwd',
            'b' => ['c' => 'C:\\Windows\\x', 'd' => ['e' => 'file:///etc/passwd']],
            'n' => 42,
            'f' => 1.5,
            'z' => null,
            't' => true,
        ]);

        self::assertSame(LogSafety::marker(), $scrubbed['a']);
        self::assertSame(LogSafety::marker(), $scrubbed['b']['c']);
        self::assertSame(LogSafety::marker(), $scrubbed['b']['d']['e']);

        // Non-strings pass through untouched.
        self::assertSame(42, $scrubbed['n']);
        self::assertSame(1.5, $scrubbed['f']);
        self::assertNull($scrubbed['z']);
        self::assertTrue($scrubbed['t']);
    }

    /**
     * Splitting on a boundary is lossless: only tokens that look like a path
     * change, everything else is reassembled byte for byte.
     *
     * @test
     */
    public function separatorsAndSurroundingTextArePreserved(): void
    {
        self::assertSame(
            'cannot open `' . LogSafety::marker() . '` now',
            LogSafety::scrubText('cannot open `/etc/passwd` now')
        );
    }

    /** @test */
    public function aPathContainingASpaceKeepsItsTailButLosesTheRoot(): void
    {
        // Documented limitation: whitespace has to be a token boundary or
        // ordinary prose would be swallowed. The absolute prefix always goes.
        $scrubbed = LogSafety::scrubText('open /etc/my files/x now');

        self::assertStringNotContainsString('/etc/my', $scrubbed);
        self::assertStringContainsString(LogSafety::marker(), $scrubbed);
    }
}
