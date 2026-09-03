<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use Psr\Log\LoggerInterface;
use TailwindPhp\Support\ClockInterface;

/**
 * The `flock` build lock (ADR-006, design doc 05).
 *
 * `flock` is chosen over a lock *file* because the kernel releases it when the
 * process dies: a PHP-FPM worker killed mid-compile must not leave a stale lock
 * that blocks the site until someone deletes a file by hand.
 *
 * **NFS fail-soft.** On some network filesystems `flock` neither locks nor
 * errors — it just returns false with `$wouldBlock` unset. Treating that as
 * "someone else is compiling" would wedge the site forever, so it is detected
 * once, logged once, and from then on this behaves like {@see NullLock}:
 * concurrent compiles are wasteful but correct, a jammed site is neither.
 *
 * No I/O in the constructor (design doc 18): the handle is opened on first use,
 * so building the object costs nothing on the warm path where no lock is taken.
 */
final class FlockBuildLock implements LockInterface
{
    /**
     * Wait between attempts, jittered so competing workers do not resynchronise
     * into lockstep after every failure.
     */
    private const MIN_SLEEP_US = 20000;
    private const MAX_SLEEP_US = 60000;

    /**
     * @var string
     */
    private $lockPath;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var resource|null
     */
    private $handle;

    /**
     * @var bool
     */
    private $held = false;

    /**
     * @var bool
     */
    private $supported = true;

    /**
     * @var bool
     */
    private $reportedUnsupported = false;

    public function __construct(string $lockPath, ClockInterface $clock, ?LoggerInterface $logger = null)
    {
        $this->lockPath = $lockPath;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * @phpstan-impure Takes an OS lock and updates $held, so two calls in a row
     *                 legitimately return different answers — which is exactly
     *                 what the retry loop in acquire() depends on.
     */
    public function tryAcquire(): bool
    {
        if ($this->held) {
            return true;
        }

        $handle = $this->handle();

        if ($handle === null) {
            return true;
        }

        $wouldBlock = 0;

        // Suppressed and immediately checked: a contended lock is the expected
        // outcome, not an error worth a warning (design doc 18).
        if (@\flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock)) {
            $this->held = true;

            return true;
        }

        if ($wouldBlock === 1) {
            // Genuinely held by someone else — the lock is working.
            return false;
        }

        // Failed without contention: the filesystem does not implement flock.
        $this->markUnsupported();

        return true;
    }

    public function acquire(float $timeoutSec): bool
    {
        if ($this->tryAcquire()) {
            return true;
        }

        $deadline = $this->clock->microtime() + $timeoutSec;

        // Bounded by attempts as well as by the deadline. The clock is injected,
        // so a test clock — or a stopped one on a misconfigured host — never
        // advances and the deadline is never reached: a wall-clock-only loop
        // spins forever holding a worker. The attempt count is what that same
        // timeout would allow at the shortest sleep, plus one.
        $attempts = (int) \ceil($timeoutSec * 1000000 / self::MIN_SLEEP_US) + 1;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            if ($this->clock->microtime() >= $deadline) {
                return false;
            }

            \usleep(\random_int(self::MIN_SLEEP_US, self::MAX_SLEEP_US));

            if ($this->tryAcquire()) {
                return true;
            }
        }

        return false;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        if ($this->held) {
            // Suppressed and not checked: the `fclose()` below releases the lock
            // regardless, so an explicit unlock that fails changes nothing —
            // and there is no useful action left to take while releasing.
            @\flock($this->handle, \LOCK_UN);
            $this->held = false;
        }

        \fclose($this->handle);
        $this->handle = null;
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    /**
     * @return resource|null Null when the lock file cannot be opened at all, in
     *                       which case this degrades to a no-op lock.
     */
    private function handle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        if (!$this->supported) {
            return null;
        }

        // 'c' — create if missing, do not truncate. The lock file is a 0-byte
        // rendezvous point and is never deleted.
        $handle = @\fopen($this->lockPath, 'c');

        if ($handle === false) {
            $this->markUnsupported();

            return null;
        }

        $this->handle = $handle;

        return $handle;
    }

    private function markUnsupported(): void
    {
        $this->supported = false;

        if ($this->reportedUnsupported) {
            return;
        }

        $this->reportedUnsupported = true;

        if ($this->logger !== null) {
            $this->logger->warning(
                'File locking is unavailable here; concurrent builds are possible. '
                . 'This is usual on NFS. Set lock: none to silence this.'
            );
        }
    }
}
