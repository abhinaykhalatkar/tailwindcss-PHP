<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\ScanException;
use TailwindPhp\Exception\TailwindPhpException;

final class ScanExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new ScanException('a content root could not be walked', ['path' => 'resources/views']);
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new ScanException(
            'cannot walk /etc/passwd or file:///etc/passwd or " /leading/space"',
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
        $exception = new ScanException('a content root could not be walked');

        self::assertSame('a content root could not be walked', $exception->getMessage());
    }

    /** @test */
    public function theContextRoundTrips(): void
    {
        $context = [
            'path' => 'resources/views',
            'files_seen' => 42,
        ];

        self::assertSame($context, (new ScanException('nope', $context))->context());
    }

    /** @test */
    public function theContextDefaultsToAnEmptyArray(): void
    {
        self::assertSame([], (new ScanException('nope'))->context());
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new ScanException('nope', [], $previous))->getPrevious());
    }
}
