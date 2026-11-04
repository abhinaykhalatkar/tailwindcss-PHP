<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\CompileOutput;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The PHP engine's two budgets (design doc 13 §Budgets, `EngineBench`):
 *
 * - `@import "tailwindcss"` — the preflight and the whole theme — with
 *   **2 000 candidates** in at most **300 ms** and **32 MB** peak;
 * - the same entry with **500 candidates**, a typical small site, in at most
 *   **80 ms**.
 *
 * **The candidates** are the generated corpora of the four largest everyday
 * families — `layout`, `spacing-sizing`, `typography`, `backgrounds-borders`
 * (`tests/Conformance/corpus/utilities/`) — taken round-robin: line 1 of each
 * file in that order, then line 2 of each, and so on, a repeated candidate
 * kept once, until the count is reached. So the sets are deterministic, spread
 * over all four families rather than the head of one, and carry the corpora's
 * variant forms (`md:`, `hover:`, `md:hover:`, `dark:`, `group-hover:`,
 * `[&>*]:`), arbitrary values and `!` as they occur; the 500 are the first 500
 * of the 2 000. Every one of them compiles (asserted below, unconditionally).
 *
 * **The measurement**: one untimed warm-up, then best of five samples, each
 * the mean of {@see self::BATCH} compiles by a fresh `Engine\Compiler` (its
 * registries built again, as a request builds them), the cycle collector
 * paused. Time is CPU time from `getrusage()` (wall time is reported beside
 * it); memory is the peak above the usage before the sample,
 * measured where `memory_reset_peak_usage()` exists (PHP 8.2+) and reported as
 * unavailable below it.
 *
 * **Asserted only when `TAILWINDPHP_BENCH=1`**, like every row in this suite;
 * otherwise the row is skipped with its measurement. The time budgets are
 * multiplied by `BENCH_TOLERANCE` (doc 20: 1.25 on pull requests, 1.0 nightly;
 * 1.0 when unset). Doc 13 assumes PHP 8.3 with opcache; the message says
 * whether opcache was loaded. On the development host (PHP 8.2 ZTS, no
 * opcache, 3–5× slower than the CI reference) the rows pass with room: see
 * doc 13 §M2 measured.
 */
final class EngineBenchTest extends TestCase
{
    private const FAMILIES = ['layout', 'spacing-sizing', 'typography', 'backgrounds-borders'];

    private const ENTRY = "@import \"tailwindcss\";\n";

    private const TIMED_RUNS = 5;

    /**
     * Compiles per sample: Windows advances `getrusage()` in 15.6 ms ticks, so
     * a single 20 ms compile can read as 0 or 31 ms of CPU. A sample is the
     * mean of four, which bounds the tick error at about 4 ms.
     */
    private const BATCH = 4;

    private const LARGE = 2000;
    private const LARGE_BUDGET_MS = 300.0;
    private const LARGE_BUDGET_MB = 32.0;

    private const SMALL = 500;
    private const SMALL_BUDGET_MS = 80.0;

    /**
     * Not a timing: the inputs the budgets are measured on are what this
     * class says they are.
     *
     * @test
     */
    public function theCandidateSetsAreDeterministicAndEveryOneCompiles(): void
    {
        $large = self::candidates(self::LARGE);
        $small = self::candidates(self::SMALL);

        self::assertSame($large, self::candidates(self::LARGE), 'the same set every time');
        self::assertCount(self::LARGE, \array_unique($large));
        self::assertSame(\array_slice($large, 0, self::SMALL), $small);

        $variants = \array_filter($large, static function (string $candidate): bool {
            return \strpos($candidate, ':') !== false;
        });

        self::assertGreaterThan(200, \count($variants), 'variant forms are in the set');

        $output = self::compile($large);

        self::assertSame(self::LARGE, $output->stats['utilities']);
        self::assertSame([], $output->diagnostics->all());
    }

    /**
     * @test
     */
    public function twoThousandCandidatesWithThePreflightAndTheThemeMeetTheBudget(): void
    {
        $measured = self::measure(self::candidates(self::LARGE));
        $report = self::report('2 000 candidates', $measured, self::LARGE_BUDGET_MS, self::LARGE_BUDGET_MB);

        self::gate($report);

        self::assertLessThanOrEqual(self::LARGE_BUDGET_MS * self::tolerance(), $measured['cpuMs'], $report);

        if ($measured['peakMb'] !== null) {
            self::assertLessThanOrEqual(self::LARGE_BUDGET_MB, $measured['peakMb'], $report);
        }
    }

    /**
     * @test
     */
    public function fiveHundredCandidatesMeetTheSmallSiteBudget(): void
    {
        $measured = self::measure(self::candidates(self::SMALL));
        $report = self::report('500 candidates', $measured, self::SMALL_BUDGET_MS, null);

        self::gate($report);

        self::assertLessThanOrEqual(self::SMALL_BUDGET_MS * self::tolerance(), $measured['cpuMs'], $report);
    }

    /**
     * The first `$count` distinct candidates of the four families, round-robin.
     *
     * @return list<string>
     */
    private static function candidates(int $count): array
    {
        $paths = new CorpusPaths();
        $lists = [];

        foreach (self::FAMILIES as $family) {
            $contents = \file_get_contents($paths->sourcePath('utilities/' . $family));
            self::assertIsString($contents);
            $lists[] = CorpusPaths::parseCandidates($contents);
        }

        $seen = [];
        $out = [];
        $more = true;

        for ($line = 0; $more; ++$line) {
            $more = false;

            foreach ($lists as $list) {
                if (!isset($list[$line])) {
                    continue;
                }

                $more = true;
                $candidate = $list[$line];

                if (isset($seen[$candidate])) {
                    continue;
                }

                $seen[$candidate] = true;
                $out[] = $candidate;

                if (\count($out) === $count) {
                    return $out;
                }
            }
        }

        self::fail(\sprintf('The four corpora hold only %d distinct candidates.', \count($out)));
    }

    /**
     * @param list<string> $candidates
     */
    private static function compile(array $candidates): CompileOutput
    {
        return (new Compiler())->compile(new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), $candidates));
    }

    /**
     * Best of five batched samples after a warm-up, the GC paused.
     *
     * @param list<string> $candidates
     *
     * @return array{cpuMs: float, wallMs: float, peakMb: float|null, bytes: int}
     */
    private static function measure(array $candidates): array
    {
        $collecting = \gc_enabled();
        $cpu = \INF;
        $wall = \INF;
        $peak = null;
        $bytes = 0;

        \gc_disable();

        try {
            self::compile($candidates);

            for ($run = 0; $run < self::TIMED_RUNS; ++$run) {
                $before = \memory_get_usage();
                $resettable = \function_exists('memory_reset_peak_usage');

                if ($resettable) {
                    \memory_reset_peak_usage();
                }

                $startedCpu = self::cpu();
                $startedWall = \hrtime(true);

                for ($compile = 0; $compile < self::BATCH; ++$compile) {
                    $bytes = \strlen(self::compile($candidates)->css);
                }

                $wall = \min($wall, (\hrtime(true) - $startedWall) / 1e6 / self::BATCH);
                $cpu = \min($cpu, (self::cpu() - $startedCpu) * 1000 / self::BATCH);

                if ($resettable) {
                    $used = (\memory_get_peak_usage() - $before) / 1048576;
                    $peak = $peak === null ? $used : \max($peak, $used);
                }
            }
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        return ['cpuMs' => (float) $cpu, 'wallMs' => (float) $wall, 'peakMb' => $peak, 'bytes' => $bytes];
    }

    /**
     * @param array{cpuMs: float, wallMs: float, peakMb: float|null, bytes: int} $measured
     */
    private static function report(string $what, array $measured, float $budgetMs, ?float $budgetMb): string
    {
        return \sprintf(
            '%s: %.1f ms CPU (%.1f ms wall), %s peak, %d bytes of CSS; '
                . 'budget %.0f ms%s (tolerance %.2f). PHP %s, opcache %s.',
            $what,
            $measured['cpuMs'],
            $measured['wallMs'],
            $measured['peakMb'] === null ? 'unmeasured (PHP < 8.2)' : \sprintf('%.1f MB', $measured['peakMb']),
            $measured['bytes'],
            $budgetMs,
            $budgetMb === null ? '' : \sprintf(', %.0f MB', $budgetMb),
            self::tolerance(),
            \PHP_VERSION,
            \extension_loaded('Zend OPcache') && (bool) \ini_get('opcache.enable_cli') ? 'on' : 'off'
        );
    }

    /**
     * Skips with the measurement unless the benchmark was asked for.
     */
    private static function gate(string $report): void
    {
        if (\getenv('TAILWINDPHP_BENCH') !== '1') {
            self::markTestSkipped('Set TAILWINDPHP_BENCH=1 to assert the budget. Measured: ' . $report);
        }
    }

    /**
     * `BENCH_TOLERANCE`, 1.0 when unset; a value outside 1.0 … 4.0 is refused
     * rather than silently widening a budget.
     */
    private static function tolerance(): float
    {
        $value = \getenv('BENCH_TOLERANCE');

        if ($value === false || $value === '') {
            return 1.0;
        }

        if (!\is_numeric($value) || (float) $value < 1.0 || (float) $value > 4.0) {
            self::fail('BENCH_TOLERANCE must be a number from 1.0 to 4.0.');
        }

        return (float) $value;
    }

    private static function cpu(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \hrtime(true) / 1e9;
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }
}
