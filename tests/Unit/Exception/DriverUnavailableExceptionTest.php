<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\CompilationException;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Exception\TailwindPhpException;

final class DriverUnavailableExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new DriverUnavailableException('cli', 'proc_open() is disabled on this host');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        // The driver is library-supplied and validated, not user text, so the
        // hostile values belong in the free-text reason instead.
        return new DriverUnavailableException(
            'cli',
            'the binary /etc/passwd is missing, tried \\\\server\\share\\secret,'
                . ' file:///etc/passwd and " /leading/space"',
            new DiagnosticBag([
                new Diagnostic(
                    Diagnostic::ERROR,
                    Diagnostic::E_CSS_SYNTAX,
                    'the binary /etc/passwd failed verification',
                    'assets/a.css',
                    3,
                    'C:\\Windows\\system32'
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
    public function theDriverAndReasonRoundTrip(): void
    {
        $exception = new DriverUnavailableException('cli', 'proc_open() is disabled on this host');

        self::assertSame('cli', $exception->driver());
        self::assertSame('proc_open() is disabled on this host', $exception->reason());
    }

    /** @test */
    public function theMessageNamesTheDriverAndTheReason(): void
    {
        $exception = new DriverUnavailableException('cli', 'proc_open() is disabled on this host');

        self::assertSame(
            'Compiler driver "cli" is not available: proc_open() is disabled on this host',
            $exception->getMessage()
        );
    }

    /** @test */
    public function omittingDiagnosticsYieldsAnEmptyBag(): void
    {
        self::assertTrue((new DriverUnavailableException('php', 'nope'))->diagnostics()->isEmpty());
    }

    /** @test */
    public function theSuppliedBagIsKept(): void
    {
        $bag = new DiagnosticBag([
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'boom', 'assets/a.css', 3, null),
        ]);

        self::assertSame($bag, (new DriverUnavailableException('cli', 'nope', $bag))->diagnostics());
    }

    /** @test */
    public function contextCarriesTheDriverAndTheReason(): void
    {
        $context = (new DriverUnavailableException('cli', 'the binary failed verification'))->context();

        self::assertSame('cli', $context['driver']);
        self::assertSame('the binary failed verification', $context['reason']);
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new DriverUnavailableException('cli', 'nope', null, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
