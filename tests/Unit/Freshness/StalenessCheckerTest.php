<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Freshness;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Freshness\FileSnapshot;
use TailwindPhp\Freshness\StalenessChecker;
use TailwindPhp\Freshness\StalenessReport;

final class StalenessCheckerTest extends TestCase
{
    /** @test */
    public function emptyAgainstEmptyIsFresh(): void
    {
        $report = StalenessChecker::compare(new FileSnapshot([]), []);

        self::assertTrue($report->isFresh());
    }

    /** @test */
    public function identicalSnapshotsAreFresh(): void
    {
        $then = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]];
        $now = new FileSnapshot(['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]]);

        $report = StalenessChecker::compare($now, $then);

        self::assertTrue($report->isFresh());
    }

    /** @test */
    public function anAddedSourceFileIsTemplatesChanged(): void
    {
        $now = new FileSnapshot(['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]]);

        $report = StalenessChecker::compare($now, []);

        self::assertSame(StalenessReport::TEMPLATES_CHANGED, $report->kind());
        self::assertSame(['a.twig'], $report->changedOrAdded());
        self::assertSame([], $report->removed());
    }

    /** @test */
    public function aRemovedSourceFileIsTemplatesChanged(): void
    {
        $then = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]];

        $report = StalenessChecker::compare(new FileSnapshot([]), $then);

        self::assertSame(StalenessReport::TEMPLATES_CHANGED, $report->kind());
        self::assertSame([], $report->changedOrAdded());
        self::assertSame(['a.twig'], $report->removed());
    }

    /** @test */
    public function aChangedMtimeIsTemplatesChanged(): void
    {
        $then = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]];
        $now = new FileSnapshot(['a.twig' => ['k' => 's', 'm' => 200, 's' => 8]]);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(StalenessReport::TEMPLATES_CHANGED, $report->kind());
        self::assertSame(['a.twig'], $report->changedOrAdded());
    }

    /** @test */
    public function aChangedSizeWithTheSameMtimeIsTemplatesChanged(): void
    {
        // Same-second edits are the exact reason size is compared too
        // (design doc 05 §Layer 1).
        $then = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]];
        $now = new FileSnapshot(['a.twig' => ['k' => 's', 'm' => 100, 's' => 9]]);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(StalenessReport::TEMPLATES_CHANGED, $report->kind());
    }

    /** @test */
    public function anAddedInputFileWinsAsInputsChanged(): void
    {
        $then = ['templates/a.twig' => ['k' => 's', 'm' => 1, 's' => 1]];
        $now = new FileSnapshot([
            'templates/a.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'assets/styles/main.css' => ['k' => 'i', 'm' => 5, 's' => 5],
        ]);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(StalenessReport::INPUTS_CHANGED, $report->kind());
        self::assertSame([], $report->changedOrAdded());
        self::assertSame([], $report->removed());
    }

    /** @test */
    public function aChangedInputFileWinsOverAChangedSourceFile(): void
    {
        $then = [
            'templates/a.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'assets/styles/main.css' => ['k' => 'i', 'm' => 5, 's' => 5],
        ];
        $now = new FileSnapshot([
            'templates/a.twig' => ['k' => 's', 'm' => 2, 's' => 1],
            'assets/styles/main.css' => ['k' => 'i', 'm' => 6, 's' => 5],
        ]);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(StalenessReport::INPUTS_CHANGED, $report->kind());
    }

    /** @test */
    public function aRemovedInputFileWinsAsInputsChanged(): void
    {
        $then = ['assets/styles/main.css' => ['k' => 'i', 'm' => 5, 's' => 5]];

        $report = StalenessChecker::compare(new FileSnapshot([]), $then);

        self::assertSame(StalenessReport::INPUTS_CHANGED, $report->kind());
    }

    /** @test */
    public function removedFilesUnderAMissingRootAreExcludedFromRemoved(): void
    {
        $then = [
            'templates/a.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'templates/b.twig' => ['k' => 's', 'm' => 2, 's' => 2],
        ];
        $now = new FileSnapshot([], ['templates']);

        $report = StalenessChecker::compare($now, $then);

        // Both files vanished only because their root is currently missing —
        // FR-FRESH-7 says that is not "removed", it is "unavailable", and the
        // pipeline decides fail-soft from FileSnapshot::missingRoots() itself.
        self::assertTrue($report->isFresh());
        self::assertSame([], $report->removed());
    }

    /** @test */
    public function removedFilesOutsideTheMissingRootAreStillReported(): void
    {
        $then = [
            'templates/a.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'other/b.twig' => ['k' => 's', 'm' => 2, 's' => 2],
        ];
        $now = new FileSnapshot([], ['templates']);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(StalenessReport::TEMPLATES_CHANGED, $report->kind());
        self::assertSame(['other/b.twig'], $report->removed());
    }

    /** @test */
    public function aMissingRootDoesNotMaskAFileWithASimilarNamePrefix(): void
    {
        // "templates-extra/x.twig" is not under root "templates" even though
        // it shares a string prefix (the classic byte-prefix trap).
        $then = ['templates-extra/x.twig' => ['k' => 's', 'm' => 1, 's' => 1]];
        $now = new FileSnapshot([], ['templates']);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(['templates-extra/x.twig'], $report->removed());
    }

    /** @test */
    public function multipleChangedAndAddedSourceFilesAreSortedInTheReport(): void
    {
        $then = ['z.twig' => ['k' => 's', 'm' => 1, 's' => 1]];
        $now = new FileSnapshot([
            'z.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'a.twig' => ['k' => 's', 'm' => 1, 's' => 1],
            'm.twig' => ['k' => 's', 'm' => 1, 's' => 1],
        ]);

        $report = StalenessChecker::compare($now, $then);

        self::assertSame(['a.twig', 'm.twig'], $report->changedOrAdded());
    }
}
