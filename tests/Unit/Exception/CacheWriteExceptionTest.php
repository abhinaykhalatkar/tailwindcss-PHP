<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\CacheWriteException;
use TailwindPhp\Exception\TailwindPhpException;

final class CacheWriteExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new CacheWriteException('rename', 'var/cache/tailwind/app.css');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new CacheWriteException(
            'rename /etc/passwd to C:\\Windows\\system32 (file:///etc/passwd, " /leading/space")',
            '\\\\server\\share\\secret'
        );
    }

    /** @test */
    public function itIsACacheException(): void
    {
        self::assertInstanceOf(CacheException::class, $this->makeException());
    }

    /** @test */
    public function theOperationAndPathRoundTrip(): void
    {
        $exception = new CacheWriteException('rename', 'var/cache/tailwind/app.css');

        self::assertSame('rename', $exception->operation());
        self::assertSame('var/cache/tailwind/app.css', $exception->relativePath());
        self::assertSame(
            'Cannot rename cache artefact "var/cache/tailwind/app.css".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function anAbsolutePathIsRedacted(): void
    {
        $exception = new CacheWriteException('mkdir', '/var/www/app/var/cache');

        self::assertSame('<absolute path redacted>', $exception->relativePath());
        self::assertSame(
            'Cannot mkdir cache artefact "<absolute path redacted>".',
            $exception->getMessage()
        );
        self::assertStringNotContainsString('/var/www', $exception->getMessage());
    }

    /** @test */
    public function contextCarriesTheOperationAndTheRedactedPath(): void
    {
        $exception = new CacheWriteException('fwrite', 'var/cache/tailwind/app.css');

        self::assertSame(
            [
                'operation' => 'fwrite',
                'path' => 'var/cache/tailwind/app.css',
            ],
            $exception->context()
        );
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('disk full');
        $exception = new CacheWriteException('fwrite', 'var/cache/tailwind/app.css', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
