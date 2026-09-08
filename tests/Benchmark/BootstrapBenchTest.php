<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Bootstrap budget (design doc 13 §Budgets): building the facade from a
 * configuration array — validation, defaults, hashing — in ≤ 1 ms median on
 * PHP 8.3 with opcache.
 *
 * The number is asserted only when TAILWINDPHP_BENCH=1 (the CI bench job);
 * elsewhere the row skips and prints what it measured, because a laptop
 * without opcache is not the reference machine.
 *
 * @covers-requirement NFR-PERF-1
 */
final class BootstrapBenchTest extends TestCase
{
    private const WARMUP_RUNS = 20;
    private const TIMED_RUNS = 200;
    private const BUDGET_MS = 1.0;

    /**
     * @test
     */
    public function fromArrayMeetsTheBootstrapBudget(): void
    {
        $project = TempProject::make();

        try {
            for ($i = 0; $i < self::WARMUP_RUNS; $i++) {
                $project->tailwind();
            }

            $samples = [];

            for ($i = 0; $i < self::TIMED_RUNS; $i++) {
                $start = \microtime(true);
                $project->tailwind();
                $samples[] = (\microtime(true) - $start) * 1000.0;
            }
        } finally {
            $project->remove();
        }

        $median = Median::of($samples);

        if (\getenv('TAILWINDPHP_BENCH') !== '1') {
            self::markTestSkipped(\sprintf(
                'Set TAILWINDPHP_BENCH=1 to assert the budget. Measured: %.3f ms median for Tailwind::fromArray().',
                $median
            ));
        }

        self::assertLessThanOrEqual(self::BUDGET_MS, $median, \sprintf(
            'Tailwind::fromArray() took %.3f ms median over %d runs (budget %.1f ms).',
            $median,
            self::TIMED_RUNS,
            self::BUDGET_MS
        ));
    }
}
