<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Concurrency;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Support\SystemClock;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Concurrency without a fork (design doc 16 §Integration conventions): the
 * test process holds the bundle's real build lock and a request arrives.
 *
 * Runs on every platform, so this — not the fork variant — is what pins
 * invariant I3 on Windows CI and on this host.
 *
 * @covers-requirement FR-CACHE-3
 * @covers-invariant I3
 */
final class LockHeldTest extends TestCase
{
    /**
     * @var TempProject|null
     */
    private $project;

    /**
     * @var FlockBuildLock|null
     */
    private $held;

    protected function tearDown(): void
    {
        if ($this->held !== null) {
            $this->held->release();
            $this->held = null;
        }

        if ($this->project !== null) {
            $this->project->remove();
            $this->project = null;
        }
    }

    /**
     * A second handle on the same lock file must be refused while the first
     * holds it — on Linux because flock() locks are per open file
     * description, on Windows because LockFileEx locks are per handle. If this
     * ever passes trivially the rows below prove nothing, so it is asserted
     * first.
     *
     * @test
     */
    public function theHostRefusesASecondHandleWhileTheLockIsHeld(): void
    {
        $project = $this->project();
        $this->holdLock($project);

        $second = new FlockBuildLock($this->lockPath($project), new SystemClock());

        self::assertFalse($second->tryAcquire(), 'flock() did not exclude a second handle on this host');
        self::assertFalse($second->acquire(0.2), 'a bounded wait must time out while the lock is held');
    }

    /**
     * Cold cache, lock held by someone else, nothing to fall back on: the
     * loser waits out lock_timeout and then fails soft with the constant body.
     * Nothing is compiled and no manifest appears.
     *
     * @test
     */
    public function aColdCacheLoserWaitsOutTheTimeoutAndFailsSoft(): void
    {
        $project = $this->project();
        $this->holdLock($project);

        $started = \microtime(true);
        $response = $project->request('assets/styles.css', [], ['lock_timeout' => 1]);
        $elapsed = \microtime(true) - $started;

        self::assertNotNull($response);
        self::assertSame(500, $response->status());
        self::assertSame(ErrorRenderer::productionBody(), $response->body());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($project->manifest('app'), 'nothing was built while the lock was held');
        self::assertGreaterThanOrEqual(0.9, $elapsed, 'the loser must actually wait for lock_timeout');
    }

    /**
     * Warm cache, lock held, a template changed: the request serves the
     * previous build (stale-fallback) instead of waiting, and the manifest on
     * disk is untouched — I3 for the warm case.
     *
     * @test
     */
    public function aWarmLoserServesThePreviousBuildWithoutWaiting(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');
        self::assertSame('compiled', $first->source());
        $manifestBefore = (string) \file_get_contents($project->manifestPath('app'));

        $project->writeFile('templates/index.twig', '<div class="flex p-4 text-sm rotate-45"></div>');
        $project->touchFile('templates/index.twig', 5);
        $this->holdLock($project);

        $started = \microtime(true);
        $result = $project->tailwind()->resolve('app');
        $elapsed = \microtime(true) - $started;

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        self::assertLessThan(1.0, $elapsed, 'a warm loser must not wait for the lock');
        self::assertSame($manifestBefore, (string) \file_get_contents($project->manifestPath('app')));
        self::assertFileExists((string) $first->path());
    }

    /**
     * Once the holder releases, the very next request compiles the change.
     *
     * @test
     */
    public function theNextRequestAfterReleaseCompiles(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');

        $project->writeFile('templates/index.twig', '<div class="flex p-4 text-sm rotate-45"></div>');
        $project->touchFile('templates/index.twig', 5);
        $this->holdLock($project);
        self::assertSame('stale-fallback', $project->tailwind()->resolve('app')->source());

        $this->releaseLock();
        $result = $project->tailwind()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertNotSame($first->fingerprint(), $result->fingerprint());
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = TempProject::make();
        }

        return $this->project;
    }

    private function holdLock(TempProject $project): void
    {
        $path = $this->lockPath($project);
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }

        $lock = new FlockBuildLock($path, new SystemClock());

        if (!$lock->isSupported()) {
            self::markTestSkipped('flock() is not supported on this host');
        }

        self::assertTrue($lock->tryAcquire(), 'the test process could not take the build lock');
        $this->held = $lock;
    }

    private function releaseLock(): void
    {
        if ($this->held !== null) {
            $this->held->release();
            $this->held = null;
        }
    }

    private function lockPath(TempProject $project): string
    {
        return $project->root() . '/cache/tailwind/app.lock';
    }
}
