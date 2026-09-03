<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\CliProcessException;
use TailwindPhp\Exception\CompilationException;
use TailwindPhp\Exception\TailwindPhpException;

final class CliProcessExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new CliProcessException(1, 'Error: unknown option --foo');
    }

    protected function makeHostileException(): TailwindPhpException
    {
        return new CliProcessException(
            1,
            "Error: cannot read /etc/passwd\n"
                . "Error: cannot write C:\\Windows\\system32\n"
                . "Error: cannot reach \\\\server\\share\\secret\n"
                . "Error: cannot resolve file:///etc/passwd\n"
                . 'Error: cannot stat " /leading/space"',
            new DiagnosticBag([
                new Diagnostic(
                    Diagnostic::ERROR,
                    Diagnostic::E_CLI_FAILED,
                    'the CLI could not read /etc/passwd',
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
    public function theExitCodeAndStderrRoundTrip(): void
    {
        $exception = new CliProcessException(9, 'Error: unknown option --foo');

        self::assertSame(9, $exception->exitCode());
        self::assertSame('Error: unknown option --foo', $exception->stderr());
    }

    /** @test */
    public function theDriverIsAlwaysTheCli(): void
    {
        self::assertSame('cli', (new CliProcessException(1, 'boom'))->driver());
    }

    /** @test */
    public function theMessageNamesTheExitCode(): void
    {
        self::assertSame(
            'The Tailwind CLI failed with exit code 137.',
            (new CliProcessException(137, 'boom'))->getMessage()
        );
    }

    /** @test */
    public function stderrIsTrimmedOfSurroundingWhitespace(): void
    {
        $exception = new CliProcessException(1, "\n\t  Error: unknown option --foo  \n\n");

        self::assertSame('Error: unknown option --foo', $exception->stderr());
    }

    /** @test */
    public function stderrIsTruncatedToABoundedLength(): void
    {
        $exception = new CliProcessException(1, \str_repeat('x', 2500));

        self::assertSame(2000 + \strlen('…'), \strlen($exception->stderr()));
        self::assertStringEndsWith('…', $exception->stderr());
    }

    /**
     * Invalid UTF-8 at the cut must not swallow the whole excerpt. A run of
     * continuation bytes longer than any real sequence used to make the
     * character-boundary back-off give up and return an empty body, throwing
     * away 2 KB of diagnostics over five stray bytes.
     *
     * @test
     */
    public function stderrSurvivesInvalidUtf8AtTheCut(): void
    {
        $stderr = \str_repeat('x', 1995) . \str_repeat("\x80", 5) . \str_repeat('y', 10);

        $exception = new CliProcessException(1, $stderr);

        self::assertGreaterThanOrEqual(1995, \strlen($exception->stderr()));
        self::assertStringStartsWith('xxxxx', $exception->stderr());
    }

    /**
     * A genuine multi-byte character straddling the cut is dropped whole, so
     * the excerpt stays valid UTF-8.
     *
     * @test
     */
    public function aMultiByteCharacterStraddlingTheCutIsDroppedWhole(): void
    {
        // 'é' is two bytes; placing it at 1999..2000 means the cut at 2000
        // lands inside it and the whole character must go.
        $stderr = \str_repeat('x', 1999) . 'é' . \str_repeat('y', 10);

        $exception = new CliProcessException(1, $stderr);
        $body = \substr($exception->stderr(), 0, -\strlen('…'));

        self::assertSame(1999, \strlen($body));
        self::assertStringEndsWith('x', $body);
    }

    /** @test */
    public function contextCarriesTheExitCodeAndTheStderr(): void
    {
        $context = (new CliProcessException(2, '  boom  '))->context();

        self::assertSame('cli', $context['driver']);
        self::assertSame(2, $context['exit_code']);
        self::assertSame('boom', $context['stderr']);
    }

    /** @test */
    public function theSuppliedBagIsKept(): void
    {
        $bag = new DiagnosticBag([
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CLI_FAILED, 'boom', 'assets/a.css', 3, null),
        ]);

        self::assertSame($bag, (new CliProcessException(1, 'boom', $bag))->diagnostics());
    }

    /** @test */
    public function omittingDiagnosticsYieldsAnEmptyBag(): void
    {
        self::assertTrue((new CliProcessException(1, 'boom'))->diagnostics()->isEmpty());
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new CliProcessException(1, 'boom', null, $previous))->getPrevious());
    }
}
