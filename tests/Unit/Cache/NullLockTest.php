<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Cache\LockInterface;
use TailwindPhp\Cache\NullLock;

final class NullLockTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(NullLock::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function itIsALock(): void
    {
        self::assertInstanceOf(LockInterface::class, new NullLock());
    }

    /** @test */
    public function tryAcquireAlwaysSucceeds(): void
    {
        $lock = new NullLock();

        self::assertTrue($lock->tryAcquire());
        self::assertTrue($lock->tryAcquire());
    }

    /**
     * A zero timeout must still succeed: `lock: 'none'` never waits, so the
     * normal code path runs unchanged instead of becoming a special case.
     *
     * @test
     */
    public function acquireSucceedsImmediatelyWithAZeroTimeout(): void
    {
        $lock = new NullLock();

        self::assertTrue($lock->acquire(0.0));
    }

    /** @test */
    public function acquireSucceedsWithAnyTimeout(): void
    {
        $lock = new NullLock();

        self::assertTrue($lock->acquire(5.0));
        self::assertTrue($lock->acquire(-1.0));
    }

    /**
     * `release()` is documented as safe when the lock is not held, and the
     * pipeline releases in a `finally` — so a double release must not blow up.
     *
     * @test
     */
    public function releaseIsSafeToCallRepeatedly(): void
    {
        $lock = new NullLock();

        $lock->release();
        $lock->release();

        self::assertTrue($lock->tryAcquire());
    }

    /**
     * The whole point of the class: it reports that it excludes nothing, so a
     * caller can tell "took the lock" from "there is no lock".
     *
     * @test
     */
    public function isSupportedIsFalse(): void
    {
        self::assertFalse((new NullLock())->isSupported());
    }
}
