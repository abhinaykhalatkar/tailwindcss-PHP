<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\ConformanceReport;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The per-family table of the conformance gate (TASK-0047 criterion 6): counts by
 * outcome, the strict pass rate (a known difference is not a pass) and the
 * plain-text rendering printed once at the end of a run.
 */
final class ConformanceReportTest extends TestCase
{
    /** @test */
    public function anEmptyReportHasNoRowsAndAFullRate(): void
    {
        $report = new ConformanceReport();

        self::assertSame([], $report->rows());
        self::assertSame(1.0, $report->rate());
        self::assertSame(0, $report->total());
    }

    /** @test */
    public function candidatesAreCountedByOutcome(): void
    {
        $report = new ConformanceReport();

        foreach ([ComparisonResult::PASS, ComparisonResult::PASS, ComparisonResult::PASS] as $status) {
            $report->record('layout', $status);
        }

        $report->record('layout', ComparisonResult::FAIL_VALUE, true);
        $report->record('layout', ComparisonResult::FAIL_MISSING);

        $row = $report->rows()['layout'];

        self::assertSame(5, $row['total']);
        self::assertSame(3, $row['passed']);
        self::assertSame(1, $row['known']);
        self::assertSame(1, $row['failed']);
        self::assertSame([ComparisonResult::FAIL_MISSING => 1, ComparisonResult::FAIL_VALUE => 1], $row['statuses']);
        self::assertEqualsWithDelta(0.6, $report->rate(), 1e-9);
    }

    /** @test */
    public function aKnownDifferenceIsNotAPassEvenWhenTheComparatorSaysSo(): void
    {
        $report = new ConformanceReport();

        // Both sides empty (the golden's rule did not parse): confirmed difference, still listed.
        $report->record('layout', ComparisonResult::PASS, true);
        $report->record('layout', ComparisonResult::PASS);

        $row = $report->rows()['layout'];

        self::assertSame(1, $row['passed']);
        self::assertSame(1, $row['known']);
        self::assertSame(0, $row['failed']);
        self::assertSame([], $row['statuses'], 'no failure class to break down');
        self::assertEqualsWithDelta(0.5, $report->rate(), 1e-9);
    }

    /** @test */
    public function theRateCanBeTakenOverSomeRowsOnly(): void
    {
        $report = new ConformanceReport();
        $report->record('layout', ComparisonResult::PASS);
        $report->record('layout', ComparisonResult::PASS);
        $report->record('variants', ComparisonResult::FAIL_ORDER);
        $report->record('directives', ComparisonResult::FAIL_EXTRA);

        self::assertEqualsWithDelta(2 / 3, $report->rate(['layout', 'variants']), 1e-9);
        self::assertSame(3, $report->total(['layout', 'variants']));
        self::assertSame(1.0, $report->rate(['layout']));
        self::assertSame(1.0, $report->rate(['no-such-row']), 'a row with nothing in it fails nothing');
    }

    /** @test */
    public function rowsKeepTheOrderTheyWereFirstSeenIn(): void
    {
        $report = new ConformanceReport();
        $report->record('typography', ComparisonResult::PASS);
        $report->record('layout', ComparisonResult::PASS);
        $report->record('typography', ComparisonResult::PASS);

        self::assertSame(['typography', 'layout'], \array_keys($report->rows()));
    }

    /** @test */
    public function aStatusOutsideTheComparatorsVocabularyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConformanceReport())->record('layout', 'FAIL_SOMETHING_ELSE');
    }

    /** @test */
    public function theTableListsEveryRowAndTheTotals(): void
    {
        $report = new ConformanceReport();

        for ($i = 0; $i < 197; ++$i) {
            $report->record('layout', ComparisonResult::PASS);
        }

        $report->record('layout', ComparisonResult::FAIL_VALUE, true);
        $report->record('layout', ComparisonResult::FAIL_ORDER, true);
        $report->record('layout', ComparisonResult::FAIL_VALUE);
        $report->record('variants', ComparisonResult::PASS);

        $text = $report->render('Conformance (CLI 4.1.18)');
        $lines = \explode("\n", \rtrim($text, "\n"));

        self::assertSame('Conformance (CLI 4.1.18)', $lines[0]);
        self::assertStringContainsString('family', $lines[1]);
        self::assertStringContainsString('rate', $lines[1]);

        $layout = self::line($lines, 'layout');
        self::assertMatchesRegularExpression('/layout\s+200\s+197\s+2\s+1\s+98\.5 %/', $layout);
        self::assertStringContainsString('FAIL_ORDER 1', $layout);
        self::assertStringContainsString('FAIL_VALUE 2', $layout);

        self::assertMatchesRegularExpression('/variants\s+1\s+1\s+0\s+0\s+100\.0 %/', self::line($lines, 'variants'));
        self::assertMatchesRegularExpression('/total\s+201\s+198\s+2\s+1\s+98\.5 %/', self::line($lines, 'total'));
    }

    /** @test */
    public function theTableOfAnEmptyReportStillHasItsHeaderAndTotal(): void
    {
        $text = (new ConformanceReport())->render('Title');

        self::assertStringContainsString('Title', $text);
        self::assertMatchesRegularExpression('/total\s+0\s+0\s+0\s+0\s+100\.0 %/', $text);
    }

    /**
     * @test
     *
     * @dataProvider candidates
     */
    public function aCandidateBelongsToTheFamilyOfItsLongestRoot(string $candidate, string $row): void
    {
        self::assertSame($row, ConformanceReport::rowOf(new Inventory(), $candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function candidates(): array
    {
        return [
            'a static utility' => ['flex', 'layout'],
            'a functional one' => ['p-4', 'spacing-sizing'],
            'variants are ignored' => ['md:hover:p-4', 'spacing-sizing'],
            'a variant with a colon inside brackets' => ['[&:hover]:p-4', 'spacing-sizing'],
            'important, either side' => ['!p-4', 'spacing-sizing'],
            'a negative' => ['-m-4', 'spacing-sizing'],
            'the longest root wins' => ['border-spacing-x-4', 'tables-transitions-transforms'],
            'an arbitrary property' => ['[mask-type:luminance]', 'arbitrary-property'],
            'an arbitrary property under a variant' => ['hover:[color:red]', 'arbitrary-property'],
            'no root at all' => ['zzz', '(no root)'],
            'a rejected one' => ['p--4', 'spacing-sizing'],
            'only markers' => ['!', '(no root)'],
        ];
    }

    /**
     * @param list<string> $lines
     */
    private static function line(array $lines, string $row): string
    {
        foreach ($lines as $line) {
            if (\strncmp($line, $row, \strlen($row)) === 0) {
                return $line;
            }
        }

        self::fail('No line for ' . $row . " in:\n" . \implode("\n", $lines));
    }
}
