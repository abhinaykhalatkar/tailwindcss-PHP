<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Freshness;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Freshness\FileSnapshot;

/**
 * @covers-requirement FR-FRESH-1
 */
final class FileSnapshotTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(FileSnapshot::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function sortsFilesByRelativePathRegardlessOfInputOrder(): void
    {
        $snapshot = new FileSnapshot([
            'z.css' => ['k' => 'i', 'm' => 1, 's' => 1],
            'a.css' => ['k' => 'i', 'm' => 2, 's' => 2],
            'm.css' => ['k' => 'i', 'm' => 3, 's' => 3],
        ]);

        self::assertSame(['a.css', 'm.css', 'z.css'], \array_keys($snapshot->files()));
    }

    /** @test */
    public function hasReportsPresenceByRelativePath(): void
    {
        $snapshot = new FileSnapshot(['a.css' => ['k' => 'i', 'm' => 1, 's' => 1]]);

        self::assertTrue($snapshot->has('a.css'));
        self::assertFalse($snapshot->has('b.css'));
    }

    /** @test */
    public function getReturnsTheRecordOrNull(): void
    {
        $record = ['k' => 's', 'm' => 5, 's' => 10];
        $snapshot = new FileSnapshot(['templates/a.twig' => $record]);

        self::assertSame($record, $snapshot->get('templates/a.twig'));
        self::assertNull($snapshot->get('nope'));
    }

    /** @test */
    public function countReflectsTheNumberOfFiles(): void
    {
        $snapshot = new FileSnapshot([
            'a.css' => ['k' => 'i', 'm' => 1, 's' => 1],
            'b.css' => ['k' => 'i', 'm' => 1, 's' => 1],
        ]);

        self::assertSame(2, $snapshot->count());
    }

    /** @test */
    public function emptySnapshotHasZeroCount(): void
    {
        self::assertSame(0, (new FileSnapshot([]))->count());
    }

    /** @test */
    public function missingRootsDefaultsToEmpty(): void
    {
        self::assertSame([], (new FileSnapshot([]))->missingRoots());
    }

    /** @test */
    public function missingRootsIsExposedAndReindexed(): void
    {
        $snapshot = new FileSnapshot([], [2 => 'templates', 5 => 'assets/styles']);

        self::assertSame(['templates', 'assets/styles'], $snapshot->missingRoots());
    }
}
