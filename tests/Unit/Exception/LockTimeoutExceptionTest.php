<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\LockTimeoutException;
use TailwindPhp\Exception\TailwindPhpException;

final class LockTimeoutExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new LockTimeoutException(1.5, 'var/cache/tailwind/compile.lock');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new LockTimeoutException(1.5, '/etc/passwd');
    }

    /** @test */
    public function itIsACacheException(): void
    {
        self::assertInstanceOf(CacheException::class, $this->makeException());
    }

    /** @test */
    public function theWaitRoundTrips(): void
    {
        self::assertSame(1.5, (new LockTimeoutException(1.5))->secondsWaited());
    }

    /** @test */
    public function theMessageCarriesTheWaitWithThreeDecimals(): void
    {
        $message = (new LockTimeoutException(1.5))->getMessage();

        self::assertStringContainsString('1.500', $message);
    }

    /** @test */
    public function contextCarriesTheWaitAndTheRedactedPath(): void
    {
        $exception = new LockTimeoutException(2.25, 'var/cache/tailwind/compile.lock');

        self::assertSame(
            [
                'seconds_waited' => 2.25,
                'path' => 'var/cache/tailwind/compile.lock',
            ],
            $exception->context()
        );
    }

    /** @test */
    public function anAbsoluteLockPathIsRedactedFromTheContext(): void
    {
        $exception = new LockTimeoutException(0.5, '/var/www/app/compile.lock');

        self::assertSame('<absolute path redacted>', $exception->context()['path']);
    }

    /** @test */
    public function aNullPathStaysNullInTheContext(): void
    {
        self::assertNull((new LockTimeoutException(0.5))->context()['path']);
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('flock failed');

        self::assertSame($previous, (new LockTimeoutException(0.5, null, $previous))->getPrevious());
    }
}
