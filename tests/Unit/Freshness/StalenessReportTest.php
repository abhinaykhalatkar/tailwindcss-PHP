<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Freshness;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Freshness\StalenessReport;

final class StalenessReportTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(StalenessReport::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function freshReportIsFreshAndCarriesNoLists(): void
    {
        $report = new StalenessReport(StalenessReport::FRESH);

        self::assertTrue($report->isFresh());
        self::assertSame(StalenessReport::FRESH, $report->kind());
        self::assertSame([], $report->changedOrAdded());
        self::assertSame([], $report->removed());
    }

    /** @test */
    public function inputsChangedIsNotFresh(): void
    {
        $report = new StalenessReport(StalenessReport::INPUTS_CHANGED);

        self::assertFalse($report->isFresh());
        self::assertSame(StalenessReport::INPUTS_CHANGED, $report->kind());
    }

    /** @test */
    public function templatesChangedCarriesTheChangedAndRemovedLists(): void
    {
        $report = new StalenessReport(
            StalenessReport::TEMPLATES_CHANGED,
            ['templates/a.twig'],
            ['templates/b.twig']
        );

        self::assertFalse($report->isFresh());
        self::assertSame(['templates/a.twig'], $report->changedOrAdded());
        self::assertSame(['templates/b.twig'], $report->removed());
    }

    /** @test */
    public function rejectsAnUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StalenessReport('bogus');
    }

    /** @test */
    public function listsAreReindexed(): void
    {
        $report = new StalenessReport(StalenessReport::TEMPLATES_CHANGED, [3 => 'a'], [7 => 'b']);

        self::assertSame(['a'], $report->changedOrAdded());
        self::assertSame(['b'], $report->removed());
    }
}
