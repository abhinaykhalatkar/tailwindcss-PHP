<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

/**
 * Mutual exclusion around a compile (ADR-006, FR-CACHE-3).
 *
 * The lock exists for one situation: a cold cache under concurrent traffic. A
 * hundred simultaneous requests must produce one compile and ninety-nine waits
 * or stale-serves, not a hundred compilers fighting over the same files
 * (threat T-05).
 *
 * It is held **only** during the compile, never across the whole request, and
 * the lock file is never deleted — deleting it is how two processes end up
 * holding locks on two different inodes with the same name.
 */
interface LockInterface
{
    /**
     * Take the lock if it is free right now; never blocks.
     */
    public function tryAcquire(): bool;

    /**
     * Take the lock, waiting up to $timeoutSec.
     *
     * @return bool False on timeout — the caller decides whether to serve stale
     *              or fail, which is a pipeline decision, not a lock one.
     */
    public function acquire(float $timeoutSec): bool;

    /**
     * Release the lock. Safe to call when it is not held.
     */
    public function release(): void;

    /**
     * Whether this lock actually excludes anything.
     *
     * False for {@see NullLock}, and for a `flock` lock on a filesystem that
     * silently does not implement it — NFS being the usual case. Callers use it
     * to decide whether "one compile at a time" is a guarantee or a hope.
     */
    public function isSupported(): bool;
}
