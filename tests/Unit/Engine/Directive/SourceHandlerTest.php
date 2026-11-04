<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;

/**
 * `@source` (criterion 5): paths are reported, never opened (T-01); inline
 * candidates are expanded and handed back as strings for the candidate parser
 * (T-15).
 */
final class SourceHandlerTest extends TestCase
{
    /** @test */
    public function pathsAndNegatedPathsAreReported(): void
    {
        $run = new ProcessorHarness('@source "../templates"; @source not "./vendor";');

        self::assertSame([
            ['kind' => 'path', 'value' => '../templates'],
            ['kind' => 'not', 'value' => './vendor'],
        ], $run->sheet->sources);
        self::assertSame([], $run->codes());
        self::assertSame('', $run->authored());
    }

    /** @test */
    public function inlineCandidatesAreExpanded(): void
    {
        $run = new ProcessorHarness('@source inline("underline {hover:,focus:,}font-bold p-{1..3}");');

        self::assertSame(
            ['underline', 'hover:font-bold', 'focus:font-bold', 'font-bold', 'p-1', 'p-2', 'p-3'],
            $run->sheet->inlineCandidates
        );
    }

    /** @test */
    public function negatedInlineCandidatesAreExcluded(): void
    {
        $run = new ProcessorHarness('@source not inline("p-{2,3}");');

        self::assertSame(['p-2', 'p-3'], $run->sheet->excludedCandidates);
        self::assertSame([], $run->sheet->inlineCandidates);
    }

    /**
     * @test
     *
     * @dataProvider ignored
     */
    public function anythingElseIsIgnoredWithAWarning(string $css): void
    {
        $run = new ProcessorHarness($css);

        self::assertSame([Diagnostic::W_SOURCE_IGNORED], $run->codes());
        self::assertSame([], $run->sheet->inlineCandidates);
        self::assertSame([], $run->sheet->sources);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ignored(): array
    {
        return [
            'unbalanced brace' => ['@source inline("p-{1..3");'],
            'stray closing brace' => ['@source inline("p-1}");'],
            'expansion too large' => ['@source inline("a{1..50}{1..50}");'],
            'not a string' => ['@source templates;'],
            'empty path' => ['@source "";'],
            'unknown function' => ['@source glob("*.html");'],
        ];
    }
}
