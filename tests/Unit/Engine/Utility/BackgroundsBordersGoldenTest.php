<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FamilyGoldenRunner;

/**
 * The BackgroundsBorders family's gate (TASK-0038 criterion 3): every candidate of the
 * generated corpus (`corpus/utilities/backgrounds-borders.txt`, variants included) and of
 * the hand-written edge files whose root belongs to the family is compiled with the real
 * utility compiler, wrapped in its variants by the real variant applier, and compared with
 * the rules the pinned CLI emitted for it.
 *
 * `edge-quirks` is included for its `bg` lines (typed values with an opacity, and the size hint).
 *
 * `edge-colors` is included because most of it is this family (`bg-*`, `border-*`, `ring-*`
 * colours). `edge-rejections` is not: of its five family lines, `bg-[url(javascript:alert(1))]`
 * is allow-listed for the CLI's output, but the runner reads the golden with `Css\Parser`,
 * which drops that declaration as a malformed unquoted `url(`. Both sides are then empty,
 * so the runner would report the shared entry as stale (the other four lines match). The
 * engine's refusal is asserted in `Handlers\BackgroundsBordersTest` instead.
 *
 * Which candidates count is decided by the hand-written inventory (the 98 %
 * denominator, ENG-A12), never by the registry.
 */
final class BackgroundsBordersGoldenTest extends TestCase
{
    private const EDGE_FILES = [
        'edge-values',
        'edge-fractions-negatives',
        'edge-arbitrary',
        'edge-modifiers',
        'edge-important',
        'edge-colors',
        'edge-quirks',
    ];

    private const THRESHOLD = 0.98;

    /** @test */
    public function backgroundsBordersCandidatesMatchTheGoldens(): void
    {
        $result = (new FamilyGoldenRunner())->run('backgrounds-borders', self::EDGE_FILES);

        // The generated corpus alone has 937 family candidates; the edge files add 189.
        self::assertGreaterThan(1100, $result->total(), 'the corpus has BackgroundsBorders candidates');
        self::assertGreaterThanOrEqual(self::THRESHOLD, $result->rate(), $result->report());
        // The report lists every mismatch; there is none today.
        self::assertSame([], $result->mismatches(), $result->report());
        // `edge-quirks` (TASK-0042): the five typed values with an opacity are nothing on both sides and gated here;
        // `bg-[size:10px]` is the one allow-listed line (the engine has no `size` type hint).
        self::assertSame(['bg-[size:10px]'], $result->skipped(), 'only the size-hint quirk is allow-listed');
    }
}
