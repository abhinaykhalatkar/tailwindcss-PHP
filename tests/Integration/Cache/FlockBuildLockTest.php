<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Cache\LockInterface;
use TailwindPhp\Support\FrozenClock;
use TailwindPhp\Support\SystemClock;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The `flock` build lock against real files (ADR-006, design doc 05, FR-CACHE-3).
 *
 * An integration test rather than a unit one because there is nothing to test
 * without the kernel: the whole value of `flock` over a lock *file* is that the
 * OS releases it when the process dies, and a double cannot demonstrate that.
 *
 * Two `FlockBuildLock` objects over the same path exclude each other even inside
 * one process, because each opens its own file descriptor and `flock` locks per
 * descriptor rather than per process. That is what makes the exclusion testable
 * here at all.
 */
final class FlockBuildLockTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var list<LockInterface>
     */
    private $locks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-flock');

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->locks as $lock) {
            $lock->release();
        }

        $this->locks = [];

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * The warm request path builds this object and usually never locks
     * anything, so construction must cost nothing (design doc 18).
     *
     * @test
     */
    public function theConstructorPerformsNoInputOrOutput(): void
    {
        $path = $this->root . '/does-not-exist/app.lock';

        $lock = $this->lock($path);

        self::assertFalse(\is_dir($this->root . '/does-not-exist'));
        self::assertFalse(\is_file($path));
        self::assertInstanceOf(LockInterface::class, $lock);
    }

    /** @test */
    public function theLockFileIsCreatedOnFirstUse(): void
    {
        $path = $this->root . '/app.lock';

        $lock = $this->lock($path);

        self::assertFalse(\is_file($path));
        self::assertTrue($lock->tryAcquire());
        self::assertTrue(\is_file($path));
        self::assertSame(0, (int) \filesize($path), 'The lock file must stay empty.');
    }

    /** @test */
    public function aFreeLockIsAcquired(): void
    {
        $lock = $this->lock($this->root . '/app.lock');

        self::assertTrue($lock->tryAcquire());
        self::assertTrue($lock->isSupported());
    }

    /**
     * The holder can ask again without deadlocking itself — the compile path
     * calls `tryAcquire()` and then `acquire()` on the same object.
     *
     * @test
     */
    public function theHolderCanAcquireAgain(): void
    {
        $lock = $this->lock($this->root . '/app.lock');

        self::assertTrue($lock->tryAcquire());
        self::assertTrue($lock->tryAcquire());
        self::assertTrue($lock->acquire(0.0));
    }

    /**
     * The reason the lock exists: one compile, not a hundred (threat T-05).
     *
     * @test
     */
    public function aSecondLockCannotAcquireWhileTheFirstHoldsIt(): void
    {
        $path = $this->root . '/app.lock';

        $first = $this->lock($path);
        $second = $this->lock($path);

        self::assertTrue($first->tryAcquire());

        if (!$second->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        self::assertFalse($second->tryAcquire(), 'Two locks were held over the same file at once.');
    }

    /** @test */
    public function releaseFreesTheLockForSomebodyElse(): void
    {
        $path = $this->root . '/app.lock';

        $first = $this->lock($path);
        $second = $this->lock($path);

        self::assertTrue($first->tryAcquire());

        if (!$second->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        self::assertFalse($second->tryAcquire());

        $first->release();

        self::assertTrue($second->tryAcquire(), 'The lock was not released.');
    }

    /**
     * The lock file itself is never deleted. Deleting it lets two processes lock
     * two different inodes that happen to share a name — which looks like a
     * working lock and excludes nothing.
     *
     * @test
     */
    public function releaseDoesNotDeleteTheLockFile(): void
    {
        $path = $this->root . '/app.lock';

        $lock = $this->lock($path);

        self::assertTrue($lock->tryAcquire());

        $lock->release();

        self::assertTrue(\is_file($path));
    }

    /** @test */
    public function releaseIsSafeOnALockThatWasNeverAcquired(): void
    {
        $lock = $this->lock($this->root . '/app.lock');

        $lock->release();
        $lock->release();

        self::assertTrue($lock->tryAcquire(), 'A redundant release left the lock unusable.');
    }

    /**
     * A timeout is a normal outcome, not an error: the caller decides whether to
     * serve stale or fail, so `acquire()` returns false rather than throwing.
     *
     * @test
     */
    public function acquireReturnsFalseAfterTheTimeout(): void
    {
        $path = $this->root . '/app.lock';

        $first = $this->lock($path);
        $second = $this->lock($path);

        self::assertTrue($first->tryAcquire());

        if (!$second->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        $started = \microtime(true);
        $acquired = $second->acquire(0.2);
        $waited = \microtime(true) - $started;

        self::assertFalse($acquired);
        self::assertGreaterThan(0.15, $waited, 'acquire() returned before its deadline.');
        self::assertLessThan(2.0, $waited, 'acquire() waited far longer than its deadline.');
    }

    /**
     * The deadline comes from `ClockInterface::microtime()`, so a frozen clock
     * never reaches it and the loop is driven entirely by the injected time.
     * Proving the seam exists matters: a lock that read the wall clock directly
     * could not be tested for its timeout at all.
     *
     * @test
     */
    public function theDeadlineIsMeasuredWithTheInjectedClock(): void
    {
        $path = $this->root . '/app.lock';

        $first = $this->lock($path);

        self::assertTrue($first->tryAcquire());

        $clock = new FrozenClock(1756857600);
        $second = new FlockBuildLock($path, $clock);
        $this->locks[] = $second;

        if (!$second->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        // A zero timeout with a stopped clock: one attempt, then the deadline
        // has already passed, so it returns immediately rather than spinning.
        $started = \microtime(true);

        self::assertFalse($second->acquire(0.0));
        self::assertLessThan(1.0, \microtime(true) - $started);
    }

    /**
     * A clock that never advances must not wedge a worker.
     *
     * The retry loop was bounded by the wall clock alone, so a stopped clock —
     * a test double here, a misconfigured host in the wild — meant the deadline
     * was never reached and `acquire()` spun forever holding the request. It is
     * now bounded by attempts as well.
     *
     * @test
     */
    public function acquireTerminatesEvenWhenTheClockNeverAdvances(): void
    {
        $path = $this->root . '/app.lock';

        $first = $this->lock($path);

        self::assertTrue($first->tryAcquire());

        $frozen = new FlockBuildLock($path, new FrozenClock(1756857600));
        $this->locks[] = $frozen;

        if (!$frozen->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        $started = \microtime(true);

        // A non-zero timeout: with a frozen clock the deadline is unreachable,
        // so only the attempt bound can end this.
        self::assertFalse($frozen->acquire(0.5));
        self::assertLessThan(
            5.0,
            \microtime(true) - $started,
            'acquire() did not terminate on a non-advancing clock.'
        );
    }

    /**
     * `acquire()` succeeds the moment the lock frees, without waiting out the
     * whole timeout.
     *
     * @test
     */
    public function acquireSucceedsImmediatelyWhenTheLockIsFree(): void
    {
        $lock = $this->lock($this->root . '/app.lock');

        $started = \microtime(true);

        self::assertTrue($lock->acquire(5.0));
        self::assertLessThan(1.0, \microtime(true) - $started, 'acquire() slept even though the lock was free.');
    }

    /**
     * A path that cannot be opened at all — the directory is missing — must not
     * throw. Wedging every request because a lock file could not be created
     * would be worse than allowing a concurrent compile, so it fails soft and
     * reports itself unsupported.
     *
     * @test
     */
    public function anUnopenableLockPathFailsSoftRatherThanThrowing(): void
    {
        $lock = $this->lock($this->root . '/does-not-exist/app.lock');

        self::assertTrue($lock->tryAcquire(), 'A lock that cannot be opened must not block the build.');
        self::assertFalse($lock->isSupported(), 'An unopenable lock must report itself unsupported.');

        $lock->release();
    }

    /**
     * The fail-soft path is reported once, not once per attempt: a request that
     * logged on every `tryAcquire()` would fill the log with the same line.
     *
     * @test
     */
    public function theUnsupportedWarningIsLoggedOnlyOnce(): void
    {
        // Anonymous, and extending AbstractLogger with an untyped log() for the
        // same reason ErrorLogLogger does: psr/log 3 declares
        // `string|\Stringable $message`, a type that does not exist on the PHP
        // 7.3 floor, and widening a parameter satisfies all three majors.
        $logger = new class () extends AbstractLogger {
            /**
             * @var list<string>
             */
            public $records = [];

            /**
             * @param mixed $level
             * @param mixed $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = \is_string($message) ? $message : '';
            }
        };

        $lock = new FlockBuildLock($this->root . '/does-not-exist/app.lock', new SystemClock(), $logger);
        $this->locks[] = $lock;

        $lock->tryAcquire();
        $lock->tryAcquire();
        $lock->tryAcquire();

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('locking is unavailable', $logger->records[0]);
    }

    private function lock(string $path): LockInterface
    {
        $lock = new FlockBuildLock($path, new SystemClock());

        $this->locks[] = $lock;

        return $lock;
    }
}
