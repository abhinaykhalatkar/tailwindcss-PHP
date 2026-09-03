<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Exception\TailwindPhpException;

final class ManifestCorruptExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new ManifestCorruptException(
            'the JSON payload could not be decoded',
            'var/cache/tailwind/manifest.json'
        );
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new ManifestCorruptException(
            'cannot decode /etc/passwd or C:\\Windows\\system32 or file:///etc/passwd or " /leading/space"',
            '\\\\server\\share\\secret'
        );
    }

    /** @test */
    public function itIsACacheException(): void
    {
        self::assertInstanceOf(CacheException::class, $this->makeException());
    }

    /** @test */
    public function theReasonRoundTrips(): void
    {
        $exception = new ManifestCorruptException('the JSON payload could not be decoded');

        self::assertSame('the JSON payload could not be decoded', $exception->reason());
    }

    /** @test */
    public function theReasonIsInlinedIntoTheMessage(): void
    {
        $exception = new ManifestCorruptException('the JSON payload could not be decoded');

        self::assertSame(
            'Manifest is corrupt: the JSON payload could not be decoded',
            $exception->getMessage()
        );
    }

    /** @test */
    public function contextCarriesTheReasonAndTheRedactedPath(): void
    {
        $exception = new ManifestCorruptException('schema mismatch', 'var/cache/tailwind/manifest.json');

        self::assertSame(
            [
                'reason' => 'schema mismatch',
                'path' => 'var/cache/tailwind/manifest.json',
            ],
            $exception->context()
        );
    }

    /** @test */
    public function anAbsoluteManifestPathIsRedactedFromTheContext(): void
    {
        $exception = new ManifestCorruptException('schema mismatch', '/var/www/app/manifest.json');

        self::assertSame('<absolute path redacted>', $exception->context()['path']);
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('json_decode failed');
        $exception = new ManifestCorruptException('schema mismatch', null, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
