<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Touch budget (design doc 13 §Budgets): a template's mtime moved but its
 * candidates did not, so the request re-extracts that one file, finds the
 * same set and republishes the manifest — in ≤ 5 ms median on PHP 8.3 with
 * opcache.
 *
 * Asserted only when TAILWINDPHP_BENCH=1; skipped with the measurement
 * elsewhere.
 *
 * @covers-requirement FR-FRESH-3 NFR-PERF-1
 */
final class TouchBenchTest extends TestCase
{
    private const WARMUP_RUNS = 10;
    private const TIMED_RUNS = 100;
    private const BUDGET_MS = 5.0;

    /**
     * @test
     */
    public function anUnchangedCandidateSetIsTouchedWithinBudget(): void
    {
        $project = TempProject::make();

        try {
            $project->tailwind()->resolve('app');
            $offset = 10;

            for ($i = 0; $i < self::WARMUP_RUNS; $i++) {
                $project->touchFile('templates/index.twig', $offset++);
                $project->tailwind()->resolve('app');
            }

            $samples = [];

            for ($i = 0; $i < self::TIMED_RUNS; $i++) {
                $project->touchFile('templates/index.twig', $offset++);
                $start = \microtime(true);
                $result = $project->tailwind()->resolve('app');
                $samples[] = (\microtime(true) - $start) * 1000.0;

                if ($result->source() !== 'touched') {
                    self::fail('expected a touched result, got ' . $result->source());
                }
            }
        } finally {
            $project->remove();
        }

        $median = Median::of($samples);

        if (\getenv('TAILWINDPHP_BENCH') !== '1') {
            self::markTestSkipped(\sprintf(
                'Set TAILWINDPHP_BENCH=1 to assert the budget. Measured: %.3f ms median per touched request.',
                $median
            ));
        }

        self::assertLessThanOrEqual(self::BUDGET_MS, $median, \sprintf(
            'A touched request took %.3f ms median over %d runs (budget %.1f ms).',
            $median,
            self::TIMED_RUNS,
            self::BUDGET_MS
        ));
    }
}
