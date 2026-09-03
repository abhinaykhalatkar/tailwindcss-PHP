<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Exception\TailwindPhpException;

final class ConsoleExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new ConsoleException('unknown command "compile"', 'compile');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new ConsoleException(
            'cannot open /etc/passwd or C:\\Windows\\system32 or file:///etc/passwd or " /leading/space"',
            '\\\\server\\share\\secret'
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
        self::assertSame('unknown command "compile"', $this->makeException()->getMessage());
    }

    /** @test */
    public function theUsageExitCodeIsTwo(): void
    {
        self::assertSame(2, ConsoleException::EXIT_CODE);
    }

    /** @test */
    public function theExitCodeAccessorReturnsTwo(): void
    {
        self::assertSame(2, (new ConsoleException('nope'))->exitCode());
    }

    /** @test */
    public function theThrowableCodeIsAlsoTwo(): void
    {
        self::assertSame(2, (new ConsoleException('nope'))->getCode());
    }

    /** @test */
    public function contextCarriesTheExitCodeAndTheCommand(): void
    {
        self::assertSame(
            [
                'exit_code' => 2,
                'command' => 'build',
            ],
            (new ConsoleException('missing argument', 'build'))->context()
        );
    }

    /** @test */
    public function anUnknownCommandStaysNullInTheContext(): void
    {
        self::assertSame(
            [
                'exit_code' => 2,
                'command' => null,
            ],
            (new ConsoleException('missing argument'))->context()
        );
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new ConsoleException('nope', null, $previous))->getPrevious());
    }
}
