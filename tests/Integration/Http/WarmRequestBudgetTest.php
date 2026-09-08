<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempTree;

/**
 * What a warm request costs in autoloaded classes (design doc 13 #7, decisions
 * CCP-A33 and HBC-A19; TASK-0018 acceptance 2).
 *
 * Measured in a **fresh process**, for the reason the sibling probe in
 * `tests/Integration/Pipeline/` gives: inside PHPUnit the delta would measure
 * which test ran first, not the request path.
 *
 * The number asserted here is the one the code actually produces, not the one
 * the plan asks for, and the gap is deliberate rather than hidden — see
 * {@see self::TOTAL_CEILING}. A budget nobody fails is a budget nobody keeps;
 * a budget the suite fails is not a budget either.
 *
 * @covers-requirement NFR-PERF-1 FR-PIPE-2
 */
final class WarmRequestBudgetTest extends TestCase
{
    /**
     * The whole warm request: `Tailwind::fromArray()` plus `respond()` on a
     * cached, fresh bundle.
     *
     * **Measured at 46**, down from 49, after TASK-0020 made
     * `DriverResolver`'s environment and verifier lazy: with `driver: php` the
     * resolver returns before it touches either, so the three
     * `Compiler\Cli\*` classes and the `resources/checksums.json` read that
     * used to be paid on every warm request are gone.
     *
     * The guard is 47 — the measurement plus one class of headroom — as ruled
     * in the TASK-0019 round-4 review. What remains above CCP-A33's original 45
     * is the configuration bootstrap, which that decision explicitly deferred to
     * M3 (a compiled/cached `Config`).
     */
    private const TOTAL_CEILING = 47;

    /**
     * HBC-A19, binding and met: the compressed 200 path may hold 8 `Http\`
     * classes.
     */
    private const HTTP_CEILING = 8;

    /**
     * `Compiler\Cli\*` classes tolerated on a `driver: php` warm request.
     *
     * **None**, now that the resolver builds its environment and verifier on
     * first use. This was three entries while `DriverResolver` took them as
     * ordinary constructor arguments; the list is kept — empty — because an
     * empty allowance is the assertion, and a future refactor that reintroduces
     * one has to add it here deliberately rather than by accident.
     *
     * @var list<string>
     */
    private const CLI_CONSTRUCTOR_DEPENDENCIES = [];

    /**
     * @var string|null
     */
    private $root;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * @test
     */
    public function aWarmRequestStaysInsideTheClassBudget(): void
    {
        $counts = $this->measure();

        self::assertSame('1', $counts['served'], 'the probe should have served the stylesheet');
        self::assertNotSame('0', $counts['bytes'], 'the probe should have emitted a body');

        self::assertLessThanOrEqual(
            self::TOTAL_CEILING,
            (int) $counts['total'],
            \sprintf(
                'a warm request autoloaded %d TailwindPhp classes; the guard is %d. Measured: %s',
                (int) $counts['total'],
                self::TOTAL_CEILING,
                $counts['line']
            )
        );
    }

    /**
     * HBC-A19.
     *
     * @test
     */
    public function theHttpLayerStaysWithinItsOwnBudget(): void
    {
        $counts = $this->measure();

        self::assertLessThanOrEqual(self::HTTP_CEILING, (int) $counts['http'], $counts['line']);
    }

    /**
     * The compile transaction, the SCSS stage and the console have no business
     * on a request that is answered from the cache. This is the assertion that
     * would catch a refactor making the facade eager.
     *
     * @test
     */
    public function nothingThatBuildsOrCompilesIsLoaded(): void
    {
        $counts = $this->measure();
        $loaded = $counts['forbidden'] === 'none' ? [] : \explode(',', $counts['forbidden']);
        $unexpected = \array_diff($loaded, self::CLI_CONSTRUCTOR_DEPENDENCIES);

        self::assertSame(
            [],
            \array_values($unexpected),
            'a warm request loaded something that only a build should need: ' . $counts['line']
        );
    }

    /**
     * @return array<string, string>
     */
    private function measure(): array
    {
        $root = TempTree::make('tailwindphp-warm-request');
        $this->root = $root;

        $prepare = $this->probe('prepare', $root);
        self::assertStringContainsString('served=1', $prepare, 'the probe should have built the cache');

        $line = $this->probe('measure', $root);
        $values = ['line' => $line];

        foreach (\explode(' ', \trim(\strtok($line, "\n") ?: '')) as $pair) {
            $parts = \explode('=', $pair, 2);

            if (\count($parts) === 2) {
                $values[$parts[0]] = $parts[1];
            }
        }

        foreach (['served', 'bytes', 'total', 'http', 'forbidden'] as $key) {
            self::assertArrayHasKey($key, $values, 'unexpected probe output: ' . $line);
        }

        return $values;
    }

    /**
     * Runs the probe out of process and returns its output.
     */
    private function probe(string $mode, string $root): string
    {
        $command = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(__DIR__ . '/warm-request-probe.php')
            . ' ' . \escapeshellarg($mode)
            . ' ' . \escapeshellarg($root);

        $output = [];
        $status = 0;
        \exec($command . ' 2>&1', $output, $status);

        $text = \trim(\implode("\n", $output));

        self::assertSame(0, $status, 'the probe failed: ' . $text);

        return $text;
    }
}
