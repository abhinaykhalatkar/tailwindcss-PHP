<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FamilyGoldenRunner;

/**
 * The Layout family's gate (TASK-0032 criterion 9, rebuilt on the shared runner in
 * TASK-0049): every Layout candidate of the generated corpus
 * (`corpus/utilities/layout.txt`, variants included) and of the hand-written edge files
 * is compiled with the real utility compiler, wrapped in its variants by the real
 * variant applier, and compared with the rules the pinned CLI emitted for it.
 *
 * Which candidates count is decided by the hand-written inventory (the 98 %
 * denominator, ENG-A12), never by the registry.
 */
final class LayoutGoldenTest extends TestCase
{
    private const EDGE_FILES = [
        'edge-values',
        'edge-fractions-negatives',
        'edge-arbitrary',
        'edge-modifiers',
        'edge-important',
    ];

    private const THRESHOLD = 0.98;

    /** @test */
    public function layoutCandidatesMatchTheGoldens(): void
    {
        $result = (new FamilyGoldenRunner())->run('layout', self::EDGE_FILES);

        // The generated corpus alone is 755 lines; the edge files add the Layout lines they hold.
        self::assertGreaterThan(750, $result->total(), 'the corpus has Layout candidates');
        self::assertGreaterThanOrEqual(self::THRESHOLD, $result->rate(), $result->report());
        // The report lists every mismatch; there is none today.
        self::assertSame([], $result->mismatches(), $result->report());
        self::assertSame([], $result->skipped(), 'nothing in the Layout corpus is allow-listed');
    }
}
