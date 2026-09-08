<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Scanner\CandidateSet;
use TailwindPhp\Support\Hash;

/**
 * @covers-requirement FR-SCAN-5
 */
final class CandidateSetTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CandidateSet::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function unionsAllPerFileCandidatesSortedAndDeduplicated(): void
    {
        $set = new CandidateSet(
            [
                'a.twig' => ['flex', 'p-4'],
                'b.twig' => ['p-4', 'grid'],
            ],
            [],
            [],
            [],
            1000
        );

        self::assertSame(['flex', 'grid', 'p-4'], $set->all());
        self::assertSame(3, $set->count());
    }

    /** @test */
    public function includesSafelistEntries(): void
    {
        $set = new CandidateSet([], ['underline'], [], [], 1000);

        self::assertSame(['underline'], $set->all());
    }

    /** @test */
    public function includesInlineSources(): void
    {
        $set = new CandidateSet([], [], [], ['no-underline'], 1000);

        self::assertSame(['no-underline'], $set->all());
    }

    /** @test */
    public function blocklistRemovesEntriesFromEveryOtherSource(): void
    {
        $set = new CandidateSet(
            ['a.twig' => ['flex', 'hidden']],
            ['hidden'],
            ['hidden'],
            ['hidden'],
            1000
        );

        self::assertSame(['flex'], $set->all());
    }

    /** @test */
    public function emptyInputsProduceAnEmptySet(): void
    {
        $set = new CandidateSet([], [], [], [], 1000);

        self::assertSame([], $set->all());
        self::assertSame(0, $set->count());
        self::assertFalse($set->wasTruncated());
    }

    /**
     * CSF-A10: keep the first N in sorted order, drop the rest.
     *
     * @test
     */
    public function truncatesToTheCapInSortedOrder(): void
    {
        $set = new CandidateSet(
            ['a.twig' => ['z-candidate', 'a-candidate', 'm-candidate']],
            [],
            [],
            [],
            2
        );

        self::assertSame(['a-candidate', 'm-candidate'], $set->all());
        self::assertSame(2, $set->count());
        self::assertTrue($set->wasTruncated());
    }

    /** @test */
    public function exactlyAtTheCapIsNotTruncated(): void
    {
        $set = new CandidateSet(['a.twig' => ['a', 'b']], [], [], [], 2);

        self::assertFalse($set->wasTruncated());
        self::assertSame(['a', 'b'], $set->all());
    }

    /**
     * Review round 1 item 8: `array_slice()` with a negative length drops
     * from the *end* instead of keeping nothing, which would silently keep
     * candidates a negative cap was supposed to reject outright.
     *
     * @test
     */
    public function aNegativeCapIsClampedToZero(): void
    {
        $set = new CandidateSet(['a.twig' => ['a', 'b', 'c']], [], [], [], -5);

        self::assertSame([], $set->all());
        self::assertSame(0, $set->count());
        self::assertTrue($set->wasTruncated());
    }

    /** @test */
    public function hashEqualsSha256LinesOfTheSortedUnion(): void
    {
        $set = new CandidateSet(['a.twig' => ['flex', 'p-4']], [], [], [], 1000);

        self::assertSame(Hash::sha256Lines(['flex', 'p-4']), $set->hash());
    }

    /** @test */
    public function hashOfAnEmptySetIsTheEmptyDigest(): void
    {
        $set = new CandidateSet([], [], [], [], 1000);

        self::assertSame(Hash::sha256Lines([]), $set->hash());
    }

    /** @test */
    public function hashIsStableRegardlessOfInputOrder(): void
    {
        $first = new CandidateSet(['a.twig' => ['flex', 'p-4', 'grid']], [], [], [], 1000);
        $second = new CandidateSet(['b.twig' => ['grid', 'flex', 'p-4']], [], [], [], 1000);

        self::assertSame($first->hash(), $second->hash());
    }
}
