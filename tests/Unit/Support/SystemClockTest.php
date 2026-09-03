<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\ClockInterface;
use TailwindPhp\Support\SystemClock;

final class SystemClockTest extends TestCase
{
    /**
     * 2020-01-01T00:00:00Z. Any real clock is well past this, and asserting
     * against a fixed point keeps the test deterministic rather than comparing
     * the clock against itself.
     */
    private const A_PAST_TIMESTAMP = 1577836800;

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(SystemClock::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function itIsAClock(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock());
    }

    /** @test */
    public function nowIsAPlausibleUnixTimestamp(): void
    {
        self::assertGreaterThan(self::A_PAST_TIMESTAMP, (new SystemClock())->now());
    }

    /** @test */
    public function microtimeIsAPlausibleFloat(): void
    {
        self::assertGreaterThan((float) self::A_PAST_TIMESTAMP, (new SystemClock())->microtime());
    }

    /** @test */
    public function bothReadingsAgreeToTheSecond(): void
    {
        $clock = new SystemClock();

        $seconds = $clock->now();
        $micro = $clock->microtime();

        self::assertLessThanOrEqual(2.0, \abs($micro - (float) $seconds));
    }
}
