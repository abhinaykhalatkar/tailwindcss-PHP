<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Pipeline;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Design doc 13 #7: the request path must stay small.
 *
 * The number is measured in a **fresh PHP process**, because inside PHPUnit the
 * answer would depend on which test ran first — every earlier test has already
 * autoloaded most of the library, so an in-process delta would read as a
 * comfortable zero no matter how heavy the path became.
 *
 * What this pins is the pipeline's own cost: classes autoloaded by
 * `AssetPipeline::resolve()` on a bundle that turns out to be FRESH. The whole
 * warm request (building `Config` from the raw array, wiring the collaborators,
 * then resolving) costs more than doc 13's 25 — see the report on TASK-0015;
 * that overage lives in `Config\*`, not here, and is the orchestrator's call.
 *
 * @covers-requirement NFR-PERF-1
 */
final class WarmPathBudgetTest extends TestCase
{
    /**
     * Doc 13 #7. The measured figure is asserted rather than merely printed:
     * a budget nobody fails is a budget nobody keeps.
     */
    private const RESOLVE_BUDGET = 25;

    /**
     * The whole warm request (bootstrap + wiring + resolve), doc 13 #7 / CCP-A33.
     */
    private const TOTAL_BUDGET = 45;

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
    public function aFreshBundleResolvesWithinTheClassBudget(): void
    {
        $root = TempTree::make('tailwindphp-budget');
        $this->root = $root;

        $prepare = $this->probe('prepare', $root);
        self::assertStringContainsString('source=compiled', $prepare, 'the probe should have built the cache');

        $measure = $this->probe('measure', $root);

        $counts = self::parse($measure);

        self::assertSame('cached', $counts['source'], 'the second run must be a warm, fresh hit');
        self::assertLessThanOrEqual(
            self::RESOLVE_BUDGET,
            (int) $counts['resolve'],
            \sprintf(
                'AssetPipeline::resolve() autoloaded %d classes on the FRESH path; doc 13 #7 budgets %d. '
                . 'Measured: %s',
                (int) $counts['resolve'],
                self::RESOLVE_BUDGET,
                $measure
            )
        );
        self::assertLessThanOrEqual(
            self::TOTAL_BUDGET,
            (int) $counts['total'],
            \sprintf(
                'the whole warm request autoloaded %d classes; CCP-A33 budgets %d. Measured: %s',
                (int) $counts['total'],
                self::TOTAL_BUDGET,
                $measure
            )
        );
    }

    /**
     * Runs the probe out of process and returns its single output line.
     */
    private function probe(string $mode, string $root): string
    {
        // Out of process through `exec`: skipped where the host disables it.
        RequiresSpawning::checkExec();

        $command = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(__DIR__ . '/warm-path-probe.php')
            . ' ' . \escapeshellarg($mode)
            . ' ' . \escapeshellarg($root);

        $output = [];
        $status = 0;
        \exec($command . ' 2>&1', $output, $status);

        $text = \trim(\implode("\n", $output));

        self::assertSame(0, $status, 'the probe failed: ' . $text);

        return $text;
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $line): array
    {
        $values = [];

        foreach (\explode(' ', \trim($line)) as $pair) {
            $parts = \explode('=', $pair, 2);

            if (\count($parts) === 2) {
                $values[$parts[0]] = $parts[1];
            }
        }

        self::assertArrayHasKey('resolve', $values, 'unexpected probe output: ' . $line);
        self::assertArrayHasKey('source', $values, 'unexpected probe output: ' . $line);

        return $values;
    }
}
