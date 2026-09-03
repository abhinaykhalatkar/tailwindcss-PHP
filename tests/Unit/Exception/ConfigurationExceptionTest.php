<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\TailwindPhpException;

final class ConfigurationExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new ConfigurationException(['the "content" option must not be empty']);
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new ConfigurationException([
            'input /etc/passwd does not exist',
            'output C:\\Windows\\system32 is not writable',
            'content root \\\\server\\share\\secret cannot be walked',
            'the "input" option must not be file:///etc/passwd',
            'the "output" option must not be " /leading/space"',
        ]);
    }

    /** @test */
    public function itIsAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, $this->makeException());
    }

    /** @test */
    public function aSingleProblemIsInlinedIntoTheMessage(): void
    {
        $exception = new ConfigurationException(['the "content" option must not be empty']);

        self::assertSame(
            'Invalid configuration: the "content" option must not be empty',
            $exception->getMessage()
        );
    }

    /** @test */
    public function severalProblemsAreListedOnSeparateLines(): void
    {
        $exception = new ConfigurationException([
            'the "content" option must not be empty',
            'the "output" option must be a relative path',
        ]);

        self::assertSame(
            "Invalid configuration:\n"
            . "  - the \"content\" option must not be empty\n"
            . '  - the "output" option must be a relative path',
            $exception->getMessage()
        );
    }

    /** @test */
    public function noProblemsYieldsTheBareMessage(): void
    {
        self::assertSame('Invalid configuration.', (new ConfigurationException([]))->getMessage());
    }

    /** @test */
    public function problemsRoundTrip(): void
    {
        $problems = [
            'the "content" option must not be empty',
            'the "output" option must be a relative path',
        ];

        self::assertSame($problems, (new ConfigurationException($problems))->problems());
    }

    /** @test */
    public function contextCarriesTheProblemList(): void
    {
        $problems = ['the "content" option must not be empty'];

        self::assertSame(['problems' => $problems], (new ConfigurationException($problems))->context());
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new ConfigurationException(['bad'], $previous))->getPrevious());
    }
}
