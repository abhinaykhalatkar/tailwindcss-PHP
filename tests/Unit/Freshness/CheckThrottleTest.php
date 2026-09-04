<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Freshness;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Freshness\CheckThrottle;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\FrozenClock;

final class CheckThrottleTest extends TestCase
{
    /** @test */
    public function intervalZeroAlwaysChecks(): void
    {
        $filesystem = self::stampedAt(1000);
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 0);

        self::assertTrue($throttle->shouldCheck());
    }

    /** @test */
    public function aMissingStampAlwaysChecks(): void
    {
        $filesystem = self::noStamp();
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        self::assertTrue($throttle->shouldCheck());
    }

    /**
     * design decision CSF-A14 worked example: interval 5, clock +2 s → skip.
     *
     * @test
     */
    public function withinTheIntervalDoesNotCheck(): void
    {
        $filesystem = self::stampedAt(1000);
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        $clock->advance(2);

        self::assertFalse($throttle->shouldCheck());
    }

    /**
     * design decision CSF-A14 worked example: interval 5, clock +6 s → check.
     *
     * @test
     */
    public function pastTheIntervalChecksAgain(): void
    {
        $filesystem = self::stampedAt(1000);
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        $clock->advance(6);

        self::assertTrue($throttle->shouldCheck());
    }

    /** @test */
    public function exactlyAtTheIntervalBoundaryChecks(): void
    {
        $filesystem = self::stampedAt(1000);
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        $clock->advance(5);

        self::assertTrue($throttle->shouldCheck());
    }

    /** @test */
    public function markCheckedTouchesTheStampWithTheClocksTime(): void
    {
        $filesystem = self::noStamp();
        $clock = new FrozenClock(12345);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        $throttle->markChecked();

        $stat = $filesystem->stat('/cache/styles.checked');
        self::assertNotNull($stat);
        self::assertSame(12345, $stat['mtime']);
    }

    /** @test */
    public function markCheckedThenShouldCheckReflectsTheNewStamp(): void
    {
        $filesystem = self::noStamp();
        $clock = new FrozenClock(1000);
        $throttle = new CheckThrottle($filesystem, $clock, '/cache/styles.checked', 5);

        $throttle->markChecked();
        self::assertFalse($throttle->shouldCheck());

        $clock->advance(6);
        self::assertTrue($throttle->shouldCheck());
    }

    /** @test */
    public function rejectsAnyStoreOtherThanFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckThrottle(self::noStamp(), new FrozenClock(0), '/cache/styles.checked', 5, 'apcu');
    }

    private static function stampedAt(int $mtime): FilesystemInterface
    {
        return self::fakeWithStamp(['mtime' => $mtime, 'size' => 0]);
    }

    private static function noStamp(): FilesystemInterface
    {
        return self::fakeWithStamp(null);
    }

    /**
     * @param array{mtime: int, size: int}|null $initialStamp
     */
    private static function fakeWithStamp(?array $initialStamp): FilesystemInterface
    {
        return new class ($initialStamp) implements FilesystemInterface {
            /**
             * @var array{mtime: int, size: int}|null
             */
            private $stamp;

            /**
             * @param array{mtime: int, size: int}|null $stamp
             */
            public function __construct(?array $stamp)
            {
                $this->stamp = $stamp;
            }

            public function exists(string $path): bool
            {
                return $this->stamp !== null;
            }

            public function isFile(string $path): bool
            {
                return $this->stamp !== null;
            }

            public function isDir(string $path): bool
            {
                return false;
            }

            public function stat(string $path): ?array
            {
                return $this->stamp;
            }

            public function read(string $path, int $maxBytes): string
            {
                throw new \RuntimeException('read() must not be called.');
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
            }

            public function touch(string $path, ?int $mtime = null): void
            {
                $this->stamp = ['mtime' => $mtime ?? 0, 'size' => 0];
            }

            public function unlink(string $path): bool
            {
                return false;
            }

            public function clearStatCache(string $path): void
            {
            }
        };
    }
}
