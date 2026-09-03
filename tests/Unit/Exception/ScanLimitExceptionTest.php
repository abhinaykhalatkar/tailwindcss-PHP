<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\ScanException;
use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Exception\TailwindPhpException;

final class ScanLimitExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new ScanLimitException('max_files', 5000);
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new ScanLimitException(
            '/etc/passwd C:\\Windows\\system32 \\\\server\\share\\secret file:///etc/passwd  /leading/space',
            5000
        );
    }

    /** @test */
    public function itIsAScanException(): void
    {
        self::assertInstanceOf(ScanException::class, $this->makeException());
    }

    /** @test */
    public function theLimitRoundTrips(): void
    {
        $exception = new ScanLimitException('max_files', 5000);

        self::assertSame('max_files', $exception->limitName());
        self::assertSame(5000, $exception->limitValue());
    }

    /** @test */
    public function theMessageNamesTheLimitAndItsValue(): void
    {
        $exception = new ScanLimitException('max_files', 5000);

        self::assertSame(
            'Scan aborted: limit "max_files" of 5000 was reached.',
            $exception->getMessage()
        );
    }

    /** @test */
    public function contextCarriesTheLimitAndItsValue(): void
    {
        $exception = new ScanLimitException('max_bytes', 1048576);

        self::assertSame(
            [
                'limit' => 'max_bytes',
                'value' => 1048576,
            ],
            $exception->context()
        );
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new ScanLimitException('max_files', 1, $previous))->getPrevious());
    }
}
