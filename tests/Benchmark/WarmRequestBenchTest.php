<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Warm-request budget (design doc 13 §Budgets): a fresh, cached request —
 * a new facade, the manifest, the staleness walk, the response — in ≤ 2 ms
 * median on PHP 8.3 with opcache.
 *
 * Asserted only when TAILWINDPHP_BENCH=1; skipped with the measurement
 * elsewhere.
 *
 * @covers-requirement NFR-PERF-1
 */
final class WarmRequestBenchTest extends TestCase
{
    private const WARMUP_RUNS = 20;
    private const TIMED_RUNS = 200;
    private const BUDGET_MS = 2.0;

    /**
     * @test
     */
    public function aFreshCachedRequestMeetsTheWarmBudget(): void
    {
        $project = TempProject::make();

        try {
            $project->tailwind()->resolve('app');

            for ($i = 0; $i < self::WARMUP_RUNS; $i++) {
                $project->request('assets/styles.css');
            }

            $samples = [];

            for ($i = 0; $i < self::TIMED_RUNS; $i++) {
                $start = \microtime(true);
                $response = $project->request('assets/styles.css');
                $samples[] = (\microtime(true) - $start) * 1000.0;

                if ($response === null || $response->status() !== 200) {
                    self::fail('the warm request did not serve a 200');
                }
            }
        } finally {
            $project->remove();
        }

        $median = Median::of($samples);

        if (\getenv('TAILWINDPHP_BENCH') !== '1') {
            self::markTestSkipped(\sprintf(
                'Set TAILWINDPHP_BENCH=1 to assert the budget. Measured: %.3f ms median per warm request.',
                $median
            ));
        }

        self::assertLessThanOrEqual(self::BUDGET_MS, $median, \sprintf(
            'A warm cached request took %.3f ms median over %d runs (budget %.1f ms).',
            $median,
            self::TIMED_RUNS,
            self::BUDGET_MS
        ));
    }
}
