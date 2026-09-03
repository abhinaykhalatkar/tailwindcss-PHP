<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\ClockInterface;
use TailwindPhp\Support\FrozenClock;

final class FrozenClockTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(FrozenClock::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function itIsAClock(): void
    {
        self::assertInstanceOf(ClockInterface::class, new FrozenClock(1000));
    }

    /** @test */
    public function itDoesNotMoveOnItsOwn(): void
    {
        $clock = new FrozenClock(1000);

        self::assertSame(1000, $clock->now());
        self::assertSame(1000, $clock->now());
        self::assertSame(1000.0, $clock->microtime());
        self::assertSame(1000.0, $clock->microtime());
    }

    /** @test */
    public function microtimeDefaultsToTheWholeSecond(): void
    {
        self::assertSame(1000.0, (new FrozenClock(1000))->microtime());
    }

    /** @test */
    public function microtimeCanBeSetIndependently(): void
    {
        $clock = new FrozenClock(1000, 1000.25);

        self::assertSame(1000, $clock->now());
        self::assertSame(1000.25, $clock->microtime());
    }

    /** @test */
    public function advanceMovesBothReadings(): void
    {
        $clock = new FrozenClock(1000, 1000.25);

        $clock->advance(5);

        self::assertSame(1005, $clock->now());
        self::assertSame(1005.25, $clock->microtime());
    }

    /** @test */
    public function advanceAccumulates(): void
    {
        $clock = new FrozenClock(1000);

        $clock->advance(3);
        $clock->advance(4);

        self::assertSame(1007, $clock->now());
    }

    /**
     * Staleness checks compare mtimes, so a test occasionally needs to step
     * backwards to simulate a file from the past.
     *
     * @test
     */
    public function advanceAcceptsANegativeStep(): void
    {
        $clock = new FrozenClock(1000);

        $clock->advance(-10);

        self::assertSame(990, $clock->now());
        self::assertSame(990.0, $clock->microtime());
    }

    /** @test */
    public function advanceByZeroChangesNothing(): void
    {
        $clock = new FrozenClock(1000, 1000.5);

        $clock->advance(0);

        self::assertSame(1000, $clock->now());
        self::assertSame(1000.5, $clock->microtime());
    }
}
