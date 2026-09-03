<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\CliTimeoutException;
use TailwindPhp\Exception\CompilationException;
use TailwindPhp\Exception\TailwindPhpException;

final class CliTimeoutExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new CliTimeoutException(2.5);
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new CliTimeoutException(
            2.5,
            new DiagnosticBag([
                new Diagnostic(
                    Diagnostic::ERROR,
                    Diagnostic::E_CLI_TIMEOUT,
                    'timed out reading /etc/passwd and C:\\Windows\\system32 and file:///etc/passwd',
                    'assets/a.css',
                    3,
                    '\\\\server\\share\\secret'
                ),
            ])
        );
    }

    /** @test */
    public function itIsACompilationException(): void
    {
        self::assertInstanceOf(CompilationException::class, $this->makeException());
    }

    /** @test */
    public function theWaitRoundTrips(): void
    {
        self::assertSame(2.5, (new CliTimeoutException(2.5))->secondsWaited());
    }

    /** @test */
    public function theDriverIsAlwaysTheCli(): void
    {
        self::assertSame('cli', (new CliTimeoutException(2.5))->driver());
    }

    /** @test */
    public function theMessageCarriesTheWaitWithThreeDecimals(): void
    {
        self::assertStringContainsString('2.500', (new CliTimeoutException(2.5))->getMessage());
    }

    /** @test */
    public function contextCarriesTheWaitAndTheDriver(): void
    {
        $context = (new CliTimeoutException(0.75))->context();

        self::assertSame('cli', $context['driver']);
        self::assertSame(0.75, $context['seconds_waited']);
    }

    /** @test */
    public function omittingDiagnosticsYieldsAnEmptyBag(): void
    {
        self::assertTrue((new CliTimeoutException(2.5))->diagnostics()->isEmpty());
    }

    /** @test */
    public function theSuppliedBagIsKept(): void
    {
        $bag = new DiagnosticBag([
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CLI_TIMEOUT, 'boom', 'assets/a.css', 3, null),
        ]);

        self::assertSame($bag, (new CliTimeoutException(2.5, $bag))->diagnostics());
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new CliTimeoutException(2.5, null, $previous))->getPrevious());
    }
}
