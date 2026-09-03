<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\CompilationException;
use TailwindPhp\Exception\TailwindPhpException;

final class CompilationExceptionTest extends ExceptionTestCase
{
    protected function makeException(): TailwindPhpException
    {
        return new CompilationException('the stylesheet could not be compiled', 'php', self::makeBag());
    }

    protected function makeHostileException(): TailwindPhpException
    {
        $bag = new DiagnosticBag([
            new Diagnostic(
                Diagnostic::ERROR,
                Diagnostic::E_CSS_SYNTAX,
                'cannot open /etc/passwd or C:\\Windows\\system32 or file:///etc/passwd',
                'assets/a.css',
                3,
                '\\\\server\\share\\secret'
            ),
        ]);

        return new CompilationException(
            'cannot compile /etc/passwd or file:///etc/passwd or " /leading/space"',
            'php',
            $bag,
            [
                'input' => 'C:\\Windows\\system32',
                'extra' => '/etc/passwd',
                'nested' => ['deep' => '\\\\server\\share\\secret'],
            ]
        );
    }

    /** @test */
    public function itIsARuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, $this->makeException());
    }

    /**
     * The driver is library-supplied; an unknown value is a bug in our own call
     * site, so it is rejected rather than carried into a message. Design doc 06
     * names exactly two drivers.
     *
     * @test
     *
     * @dataProvider unknownDrivers
     */
    public function anUnknownDriverIsRejected(string $driver): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown compiler driver');

        new CompilationException('boom', $driver);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownDrivers(): array
    {
        return [
            'empty' => [''],
            'a path' => ['C:\\Windows\\system32'],
            'made up' => ['node'],
            'wrong case' => ['PHP'],
            'redaction marker' => ['<path>'],
        ];
    }

    /** @test */
    public function bothDocumentedDriversAreAccepted(): void
    {
        self::assertSame('php', (new CompilationException('boom', 'php'))->driver());
        self::assertSame('cli', (new CompilationException('boom', 'cli'))->driver());
    }

    /** @test */
    public function theMessageAndDriverRoundTrip(): void
    {
        $exception = new CompilationException('the stylesheet could not be compiled', 'php');

        self::assertSame('the stylesheet could not be compiled', $exception->getMessage());
        self::assertSame('php', $exception->driver());
    }

    /** @test */
    public function omittingDiagnosticsYieldsAnEmptyBag(): void
    {
        $exception = new CompilationException('boom', 'php');

        self::assertTrue($exception->diagnostics()->isEmpty());
        self::assertSame([], $exception->context()['diagnostics']);
    }

    /** @test */
    public function theSuppliedBagIsKept(): void
    {
        $bag = self::makeBag();
        $exception = new CompilationException('boom', 'php', $bag);

        self::assertSame($bag, $exception->diagnostics());
        self::assertSame(1, $exception->diagnostics()->count());
    }

    /** @test */
    public function contextAlwaysCarriesTheDriverAndTheDiagnostics(): void
    {
        $context = (new CompilationException('boom', 'php'))->context();

        self::assertArrayHasKey('driver', $context);
        self::assertArrayHasKey('diagnostics', $context);
        self::assertSame('php', $context['driver']);
    }

    /** @test */
    public function eachDiagnosticIsProjectedAsALogSafeRow(): void
    {
        $context = (new CompilationException('boom', 'php', self::makeBag()))->context();

        self::assertSame(
            [
                [
                    'level' => Diagnostic::ERROR,
                    'code' => Diagnostic::E_CSS_SYNTAX,
                    'message' => 'boom',
                    'file' => 'assets/a.css',
                    'line' => 3,
                    'candidate' => null,
                ],
            ],
            $context['diagnostics']
        );
    }

    /** @test */
    public function extraContextIsMergedOnTop(): void
    {
        $exception = new CompilationException('boom', 'php', null, ['reason' => 'no entry file']);

        self::assertSame('no entry file', $exception->context()['reason']);
        self::assertSame('php', $exception->context()['driver']);
    }

    /** @test */
    public function thePreviousThrowableIsRetained(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new CompilationException('boom', 'php', null, [], $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    private static function makeBag(): DiagnosticBag
    {
        return new DiagnosticBag([
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'boom', 'assets/a.css', 3, null),
        ]);
    }
}
