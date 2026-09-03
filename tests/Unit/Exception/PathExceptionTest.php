<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Exception\TailwindPhpException;

final class PathExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new PathException('the path escapes the project root', 'assets/../../secret.css');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new PathException(
            'cannot open /etc/passwd or C:\\Windows\\system32 or \\\\server\\share\\secret'
                . ' or file:///etc/passwd or " /leading/space"',
            '/etc/passwd'
        );
    }

    /** @test */
    public function itIsAConfigurationException(): void
    {
        self::assertInstanceOf(ConfigurationException::class, $this->makeException());
    }

    /** @test */
    public function aRelativePathIsKeptVerbatim(): void
    {
        $exception = new PathException('the path escapes the project root', 'assets/app.css');

        self::assertSame('assets/app.css', $exception->path());
        self::assertSame(
            'Invalid configuration: the path escapes the project root (assets/app.css)',
            $exception->getMessage()
        );
    }

    /**
     * @test
     *
     * @dataProvider absolutePaths
     */
    public function anAbsolutePathIsRedacted(string $path): void
    {
        $exception = new PathException('the path escapes the project root', $path);

        self::assertSame('<absolute path redacted>', $exception->path());
        self::assertSame(
            'Invalid configuration: the path escapes the project root (<absolute path redacted>)',
            $exception->getMessage()
        );
        self::assertStringNotContainsString($path, $exception->getMessage());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function absolutePaths(): array
    {
        return [
            'posix root' => ['/etc/passwd'],
            'windows drive' => ['C:\Windows\x'],
            'unc share' => ['\\\\server\\share'],
        ];
    }

    /** @test */
    public function aPathContainingANulByteIsRedacted(): void
    {
        $exception = new PathException('the path is malformed', "assets/app\0.css");

        self::assertSame('<invalid path redacted>', $exception->path());
        self::assertSame(
            'Invalid configuration: the path is malformed (<invalid path redacted>)',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aNullPathLeavesTheMessageUnadorned(): void
    {
        $exception = new PathException('no content roots were configured');

        self::assertNull($exception->path());
        self::assertSame(
            'Invalid configuration: no content roots were configured',
            $exception->getMessage()
        );
        self::assertStringNotContainsString('(', $exception->getMessage());
    }

    /** @test */
    public function contextCarriesTheProblemAndThePath(): void
    {
        $exception = new PathException('the path escapes the project root', 'assets/app.css');

        self::assertSame(
            [
                'problems' => ['the path escapes the project root (assets/app.css)'],
                'path' => 'assets/app.css',
            ],
            $exception->context()
        );
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new PathException('the path is malformed', 'assets/app.css', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
