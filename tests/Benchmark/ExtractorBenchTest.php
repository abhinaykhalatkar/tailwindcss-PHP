<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\CandidateExtractor;

/**
 * Extractor throughput (CSF-A25, design doc 13 as corrected in review round 3).
 *
 * These bounds used to live in `CandidateExtractorTest` and `T05LimitsTest`,
 * where they did not belong. A wall-clock assertion inside `composer test`
 * measures whatever else the machine is doing: one cold sample with no warm-up
 * failed four runs in five under opcache+JIT, and twice in two on the full
 * suite, at 163–302 ms/MB against a 150 ms bound. It was reporting load, not
 * throughput, and a test that fails for reasons unrelated to the code is worse
 * than no test — people learn to re-run it.
 *
 * So the measurement moved here, where it is run deliberately rather than on
 * every commit, and it is done properly: three untimed warm-up passes to let
 * the JIT settle, then best-of-five, which discards a scheduler hiccup without
 * hiding a real regression (a genuine 2x slowdown loses every sample, not one).
 *
 * The budgets are per CSF-A25: **150 ms/MB on real template content** and
 * **500 ms/MB on adversarial shapes**. Random bytes are deliberately not a
 * target — they contain almost nothing the extractor must consume, so a budget
 * calibrated on them measures a shape nobody ships.
 */
final class ExtractorBenchTest extends TestCase
{
    private const MEGABYTE = 1048576;

    private const WARMUP_RUNS = 3;
    private const TIMED_RUNS = 5;

    private const TEMPLATE_BUDGET_MS = 150.0;
    private const ADVERSARIAL_BUDGET_MS = 500.0;

    /**
     * Real template content: the shape the extractor actually meets.
     *
     * @test
     */
    public function realTemplateContentMeetsItsBudget(): void
    {
        $elapsedMs = self::bestOf(self::templateBuffer());

        self::assertLessThanOrEqual(self::TEMPLATE_BUDGET_MS, $elapsedMs, \sprintf(
            'Real-template throughput was %.1f ms/MB (budget %.1f).',
            $elapsedMs,
            self::TEMPLATE_BUDGET_MS
        ));
    }

    /**
     * The five shapes the round-2 review named, each one a way of making the
     * scanner reject repeatedly rather than consume.
     *
     * @dataProvider adversarialShapes
     *
     * @test
     */
    public function anAdversarialShapeMeetsItsBudget(string $unit): void
    {
        $elapsedMs = self::bestOf(self::repeatTo($unit, self::MEGABYTE));

        self::assertLessThanOrEqual(self::ADVERSARIAL_BUDGET_MS, $elapsedMs, \sprintf(
            'Adversarial throughput was %.1f ms/MB (budget %.1f).',
            $elapsedMs,
            self::ADVERSARIAL_BUDGET_MS
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adversarialShapes(): array
    {
        return [
            'open parens' => ['('],
            'open brackets' => ['['],
            'bracket letter' => ['[a'],
            'quoted bracket letter' => ['["a'],
            'hyphen digit' => ['-1'],
        ];
    }

    /**
     * Best-of-N milliseconds for one megabyte, after warm-up.
     *
     * Skips — with the measured number, never silently — when opcache is not
     * loaded, because the budgets are calibrated for it and an un-JIT-ed
     * interpreter is a different machine for this purpose.
     */
    private static function bestOf(string $buffer): float
    {
        $extractor = new CandidateExtractor();

        for ($i = 0; $i < self::WARMUP_RUNS; ++$i) {
            $extractor->extract($buffer);
        }

        $best = \INF;

        for ($i = 0; $i < self::TIMED_RUNS; ++$i) {
            $started = \microtime(true);
            $extractor->extract($buffer);
            $best = \min($best, (\microtime(true) - $started) * 1000);
        }

        if (\PHP_VERSION_ID < 80100 || !\extension_loaded('Zend OPcache')) {
            self::markTestSkipped(\sprintf(
                'Budgets assume opcache/JIT (CSF-A25); not loaded here. Measured anyway: %.1f ms/MB.',
                $best
            ));
        }

        // Asserted only when the benchmark is *asked for*. This suite is part
        // of the default `phpunit` run, so an unconditional bound reintroduces
        // one level up exactly the flakiness it was moved here to escape:
        // measured alone the template buffer takes ~90 ms/MB, but inside the
        // full suite the same code measures 163–302 because the machine is
        // busy — 4 failures on my first full-suite run with JIT.
        //
        // `phpunit.xml.dist` is outside this task's `file_scope`, so the gate
        // lives here. Excluding `Benchmark` from the default suite would be the
        // tidier fix and this check could then go.
        if (\getenv('TAILWINDPHP_BENCH') !== '1') {
            self::markTestSkipped(\sprintf(
                'Set TAILWINDPHP_BENCH=1 to assert the budget. Measured: %.1f ms/MB.',
                $best
            ));
        }

        return $best;
    }

    /**
     * A megabyte of markup with the class attributes, variants and one
     * arbitrary value that real templates carry.
     */
    private static function templateBuffer(): string
    {
        $unit = '<div class="flex items-center gap-2 p-4 hover:bg-slate-50 md:grid md:grid-cols-2">' . "\n"
            . '  <span class="text-sm font-medium text-slate-700">Label</span>' . "\n"
            . '  <a href="/x" class="inline-flex w-[calc(100%-2rem)] rounded-md">Link</a>' . "\n"
            . '</div>' . "\n";

        return self::repeatTo($unit, self::MEGABYTE);
    }

    private static function repeatTo(string $unit, int $bytes): string
    {
        return \substr(\str_repeat($unit, (int) \ceil($bytes / \strlen($unit))), 0, $bytes);
    }
}
