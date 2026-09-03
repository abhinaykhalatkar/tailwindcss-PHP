<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Diagnostic;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

final class DiagnosticBagTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(DiagnosticBag::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function aNewBagIsEmpty(): void
    {
        $bag = new DiagnosticBag();

        self::assertTrue($bag->isEmpty());
        self::assertFalse($bag->hasErrors());
        self::assertSame(0, $bag->count());
        self::assertSame([], $bag->all());
    }

    /** @test */
    public function itIsCountable(): void
    {
        $bag = new DiagnosticBag([self::error('boom'), self::warning('meh')]);

        self::assertInstanceOf(\Countable::class, $bag);
        self::assertCount(2, $bag);
    }

    /** @test */
    public function insertionOrderIsPreserved(): void
    {
        $first = self::warning('first');
        $second = self::error('second');
        $third = self::warning('third');

        $bag = new DiagnosticBag();
        $bag->add($first);
        $bag->add($second);
        $bag->add($third);

        self::assertSame([$first, $second, $third], $bag->all());
        self::assertSame(3, $bag->count());
        self::assertFalse($bag->isEmpty());
    }

    /** @test */
    public function theConstructorSeedsTheBag(): void
    {
        $seeded = new DiagnosticBag([self::error('boom'), self::warning('meh')]);

        self::assertSame(2, $seeded->count());
        self::assertTrue($seeded->hasErrors());
    }

    /** @test */
    public function errorsAndWarningsArePartitionedByLevel(): void
    {
        $error = self::error('boom');
        $warning = self::warning('meh');
        $info = new Diagnostic(Diagnostic::INFO, Diagnostic::I_DRIVER_SELECTED, 'php');

        $bag = new DiagnosticBag([$warning, $error, $info]);

        self::assertSame([$error], $bag->errors());
        self::assertSame([$warning], $bag->warnings());
        self::assertSame(3, $bag->count());
    }

    /** @test */
    public function hasErrorsIgnoresWarningsAndInfo(): void
    {
        $bag = new DiagnosticBag([
            self::warning('meh'),
            new Diagnostic(Diagnostic::INFO, Diagnostic::I_DRIVER_SELECTED, 'php'),
        ]);

        self::assertFalse($bag->hasErrors());

        $bag->add(self::error('boom'));

        self::assertTrue($bag->hasErrors());
    }

    /** @test */
    public function toArrayProjectsEveryDiagnostic(): void
    {
        $bag = new DiagnosticBag([self::error('boom')]);

        self::assertSame(
            [
                [
                    'level' => Diagnostic::ERROR,
                    'code' => Diagnostic::E_CSS_SYNTAX,
                    'message' => 'boom',
                    'file' => 'assets/styles/main.css',
                    'line' => 7,
                    'candidate' => null,
                ],
            ],
            $bag->toArray()
        );
    }

    private static function error(string $message): Diagnostic
    {
        return new Diagnostic(
            Diagnostic::ERROR,
            Diagnostic::E_CSS_SYNTAX,
            $message,
            'assets/styles/main.css',
            7
        );
    }

    private static function warning(string $message): Diagnostic
    {
        return new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_UTILITY, $message);
    }
}
