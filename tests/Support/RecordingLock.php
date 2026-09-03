<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Cache\LockInterface;

/**
 * A lock that excludes nothing and remembers everything (ADR-006, FR-CACHE-3).
 *
 * The pipeline's concurrency behaviour is a decision tree — try, fall back to a
 * bounded wait, serve stale on timeout, always release — and the branch taken is
 * invisible in the CSS that comes out. What distinguishes "we compiled" from "we
 * waited and then compiled" from "we gave up and served stale" is the *sequence*
 * of lock calls, so that sequence is what this double records.
 *
 * It also lets a test script the answers, because the interesting cases are the
 * ones a real lock cannot be made to produce on demand: a lock already held by
 * another process (`$acquirable = false`), and a filesystem where `flock` is a
 * no-op — NFS being the usual case (`$supported = false`). Forking a second
 * process to hold a real lock is possible on Linux CI only and is reserved for
 * the concurrency tests that genuinely need it (design doc 16 §Integration test
 * conventions).
 *
 * The two flags are independent on purpose: {@see NullLock} is supported=false
 * yet always acquires, and that combination has to be representable.
 */
final class RecordingLock implements LockInterface
{
    /**
     * @var bool
     */
    private $supported;

    /**
     * @var bool
     */
    private $acquirable;

    /**
     * @var bool
     */
    private $held = false;

    /**
     * @var list<string> Method names in call order.
     */
    private $calls = [];

    /**
     * @param bool $supported What {@see self::isSupported()} answers.
     * @param bool $acquirable Whether acquisition succeeds; false models the
     *                         lock being held by another process.
     */
    public function __construct(bool $supported = true, bool $acquirable = true)
    {
        $this->supported = $supported;
        $this->acquirable = $acquirable;
    }

    public function tryAcquire(): bool
    {
        $this->calls[] = 'tryAcquire';

        return $this->take();
    }

    /**
     * The timeout is recorded only as "acquire happened": this double does not
     * sleep, because a test that waits a real second to observe a timeout is a
     * test that costs a real second on every run.
     */
    public function acquire(float $timeoutSec): bool
    {
        $this->calls[] = 'acquire';

        return $this->take();
    }

    public function release(): void
    {
        $this->calls[] = 'release';

        // Releasing a lock that is not held is explicitly safe on the real
        // interface, so it is safe here too — the call is still recorded,
        // because a double release is a bug worth being able to assert on.
        $this->held = false;
    }

    public function isSupported(): bool
    {
        $this->calls[] = 'isSupported';

        return $this->supported;
    }

    /**
     * @return list<string> Every call in order, including isSupported() — a
     *                      caller that decides to wait without first asking
     *                      whether the lock means anything is itself worth
     *                      catching.
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Whether the lock is currently taken, so a test can assert it was released
     * on every path out of a compile — including the throwing one.
     */
    public function isHeld(): bool
    {
        return $this->held;
    }

    private function take(): bool
    {
        if (!$this->acquirable) {
            return false;
        }

        $this->held = true;

        return true;
    }
}
