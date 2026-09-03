<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

/**
 * A lock that locks nothing — `lock: 'none'` (design doc 11).
 *
 * Always succeeds, so a single-process site, a CLI build or a host where
 * `flock` is useless still runs the normal code path instead of a special case.
 * {@see self::isSupported()} returns false so a caller can tell the difference
 * between "took the lock" and "there is no lock", and the pipeline can decide
 * whether concurrent compiles are acceptable here.
 */
final class NullLock implements LockInterface
{
    public function tryAcquire(): bool
    {
        return true;
    }

    public function acquire(float $timeoutSec): bool
    {
        return true;
    }

    public function release(): void
    {
    }

    public function isSupported(): bool
    {
        return false;
    }
}
