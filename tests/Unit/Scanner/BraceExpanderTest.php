<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Scanner\BraceExpander;

final class BraceExpanderTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider expansionCases
     *
     * @param list<string> $expected
     */
    public function expandsAsExpected(string $pattern, array $expected): void
    {
        $result = BraceExpander::expand($pattern);
        \sort($result, \SORT_STRING);

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function expansionCases(): array
    {
        return [
            'no braces at all' => ['flex', ['flex']],
            'empty string' => ['', ['']],
            'simple two-way alternative' => ['bg-{red,blue}-500', ['bg-blue-500', 'bg-red-500']],
            'three-way alternative' => ['{a,b,c}', ['a', 'b', 'c']],
            // design doc 09's own worked example.
            'doc09 worked example' => [
                '{hover:,}bg-red-{500,600}',
                ['bg-red-500', 'bg-red-600', 'hover:bg-red-500', 'hover:bg-red-600'],
            ],
            'ascending numeric range' => ['{1..3}', ['1', '2', '3']],
            'descending numeric range collapses to ascending output' => ['{3..1}', ['1', '2', '3']],
            'single-element range' => ['{5..5}', ['5']],
            'negative numeric range' => ['{-1..1}', ['-1', '0', '1']],
            'nested braces' => ['a-{b,{c,d}}', ['a-b', 'a-c', 'a-d']],
            'deeply nested braces' => ['{a,{b,{c,d}}}', ['a', 'b', 'c', 'd']],
            'a group with a single alternative still expands' => ['{solo}', ['solo']],
            'unterminated group is left literal' => ['a-{unterminated', ['a-{unterminated']],
            'unopened close brace is left literal' => ['a-unopened}', ['a-unopened}']],
            'adjacent groups multiply' => ['{a,b}{c,d}', ['ac', 'ad', 'bc', 'bd']],
            'text before and after a group is preserved' => ['pre-{a,b}-post', ['pre-a-post', 'pre-b-post']],
            'range combined with an alternative' => ['{1..2}-{x,y}', ['1-x', '1-y', '2-x', '2-y']],
        ];
    }

    /** @test */
    public function throwsWhenExpansionWouldExceedTheCap(): void
    {
        $this->expectException(ScanLimitException::class);

        // 2^12 = 4096 combinations, well past the 1 000 cap.
        BraceExpander::expand(\str_repeat('{a,b}', 12));
    }

    /** @test */
    public function exceptionCarriesTheLimitNameAndValue(): void
    {
        try {
            BraceExpander::expand(\str_repeat('{a,b}', 12));
            self::fail('Expected a ScanLimitException.');
        } catch (ScanLimitException $e) {
            self::assertSame('inline_expansions', $e->limitName());
            self::assertSame(1000, $e->limitValue());
        }
    }

    /** @test */
    public function exactlyOneThousandResultsDoesNotThrow(): void
    {
        // 1000 == 10^3, exactly at the cap boundary (not beyond it).
        $result = BraceExpander::expand('{0..9}{0..9}{0..9}');

        self::assertCount(1000, $result);
    }

    /** @test */
    public function oneThousandAndOneResultsThrows(): void
    {
        $this->expectException(ScanLimitException::class);

        BraceExpander::expand('{0..9}{0..9}{0..10}');
    }

    /**
     * Review round 1, item 5: a huge range must be rejected without ever
     * building it — the old implementation allocated the whole 3-million
     * element array before checking the cap.
     *
     * @test
     */
    public function aHugeRangeThrowsWithoutAllocatingIt(): void
    {
        $before = \memory_get_peak_usage();

        try {
            BraceExpander::expand('{1..3000000}');
            self::fail('Expected a ScanLimitException.');
        } catch (ScanLimitException $e) {
            self::assertSame('inline_expansions', $e->limitName());
        }

        $delta = \memory_get_peak_usage() - $before;

        // A 3-million-entry array of short strings would cost tens of MB;
        // this bounds it far below that without pinning an exact byte count.
        self::assertLessThan(1024 * 1024, $delta, 'Peak memory must not grow by anywhere near a full range.');
    }

    /**
     * Review round 1, item 5: the old implementation recursed once per
     * nesting level with no bound, which is a fatal error (stack exhaustion)
     * at 20 000 levels rather than a caught exception.
     *
     * @test
     */
    public function extremeNestingThrowsInsteadOfExhaustingTheStack(): void
    {
        $pattern = \str_repeat('{', 20000) . 'a' . \str_repeat('}', 20000);

        $this->expectException(ScanLimitException::class);

        BraceExpander::expand($pattern);
    }

    /** @test */
    public function extremeNestingExceptionNamesTheNestingLimit(): void
    {
        $pattern = \str_repeat('{', 20000) . 'a' . \str_repeat('}', 20000);

        try {
            BraceExpander::expand($pattern);
            self::fail('Expected a ScanLimitException.');
        } catch (ScanLimitException $e) {
            self::assertStringContainsString('nesting', $e->limitName());
            self::assertSame(16, $e->limitValue());
        }
    }

    /**
     * A long chain of single-alternative sequential groups is not "nested"
     * in the brace-pair sense, but recurses through the same call stack one
     * frame per group — the depth cap must close this shape too.
     *
     * @test
     */
    public function aLongChainOfSingleAlternativeGroupsAlsoThrows(): void
    {
        $pattern = \str_repeat('{a}', 20000);

        $this->expectException(ScanLimitException::class);

        BraceExpander::expand($pattern);
    }

    /** @test */
    public function sixteenLevelsOfNestingIsStillAllowed(): void
    {
        $pattern = \str_repeat('{', 16) . 'a' . \str_repeat('}', 16);

        self::assertSame(['a'], BraceExpander::expand($pattern));
    }
    /**
     * An integer-overflowing range is a limit error, not a TypeError.
     *
     * `{0..9223372036854775807}` overflows the int subtraction; PHP promotes
     * the result to float, which then fails the counting method's `int` return
     * type. `@source inline(...)` is author input, so a hostile range has to
     * produce the documented ScanLimitException the caller already handles.
     *
     * @dataProvider overflowingRanges
     *
     * @test
     */
    public function anOverflowingRangeThrowsTheLimitException(string $pattern): void
    {
        $this->expectException(ScanLimitException::class);

        BraceExpander::expand($pattern);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overflowingRanges(): array
    {
        return [
            'max int upper bound' => ['{0..9223372036854775807}'],
            'min int lower bound' => ['{-9223372036854775808..0}'],
            'both extremes' => ['{-9223372036854775808..9223372036854775807}'],

            // Round 3: these five were *fatal* memory exhaustion, not a limit
            // error. Within ~1 000 of 2^63 the float span collapses to 1.0
            // through loss of precision, so the range looked tiny; the
            // generation loop then counted in `int` until `++$n` overflowed to
            // float, at which point `<=` could never become false.
            'max int singleton' => ['{9223372036854775807..9223372036854775807}'],
            'adjacent to max int' => ['{9223372036854775806..9223372036854775807}'],
            'above max int (cast clamps)' => ['{9223372036854775808..9223372036854775809}'],
            'far above max int' => ['{99999999999999999999..99999999999999999999}'],
            'descending across min int' => ['{-9223372036854775807..-9223372036854775808}'],
        ];
    }

    /**
     * A range near — but not at — the integer edge still expands exactly.
     *
     * This row caught a regression of my own: gating on the *float* span alone
     * reported a span of 1 for a true span of 8, silently truncating the range
     * to a single value. The float test is a coarse filter; the count itself
     * has to be an exact integer subtraction.
     *
     * @test
     */
    public function arangeNearTheIntegerEdgeStillExpandsExactly(): void
    {
        self::assertCount(8, BraceExpander::expand('{9223372036854775000..9223372036854775007}'));
    }

    /**
     * Ordinary ranges are untouched by the endpoint guard.
     *
     * @test
     */
    public function ordinaryRangesAreUnaffected(): void
    {
        self::assertSame(['1', '2', '3'], BraceExpander::expand('{1..3}'));
        self::assertSame(['3', '2', '1'], BraceExpander::expand('{3..1}'));
        self::assertSame(['-2', '-1', '0', '1', '2'], BraceExpander::expand('{-2..2}'));
        self::assertCount(1000, BraceExpander::expand('{1..1000}'));
    }
}
