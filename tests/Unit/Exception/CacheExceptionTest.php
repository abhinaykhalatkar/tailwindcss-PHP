<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\TailwindPhpException;

final class CacheExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new CacheException('the cache directory is not writable', ['path' => 'var/cache/tailwind']);
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new CacheException(
            'cannot open /etc/passwd or file:///etc/passwd or " /leading/space"',
            [
                'path' => '\\\\server\\share\\secret',
                'extra' => '/etc/passwd',
                'nested' => ['deep' => 'C:\\Windows\\system32'],
            ]
        );
    }

    /** @test */
    public function itIsARuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, $this->makeException());
    }

    /** @test */
    public function theMessageRoundTrips(): void
    {
        $exception = new CacheException('the cache directory is not writable');

        self::assertSame('the cache directory is not writable', $exception->getMessage());
    }

    /** @test */
    public function theContextRoundTrips(): void
    {
        $context = [
            'path' => 'var/cache/tailwind',
            'attempts' => 3,
        ];

        self::assertSame($context, (new CacheException('nope', $context))->context());
    }

    /** @test */
    public function theContextDefaultsToAnEmptyArray(): void
    {
        self::assertSame([], (new CacheException('nope'))->context());
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new CacheException('nope', [], $previous))->getPrevious());
    }
}
