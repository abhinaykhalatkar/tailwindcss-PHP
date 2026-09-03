<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

final class CompileResultTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CompileResult::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyFieldRoundTripsThroughItsGetter(): void
    {
        $diagnostics = new DiagnosticBag();

        $result = new CompileResult(
            '.flex{display:flex}',
            $diagnostics,
            'php',
            'compat-4.1',
            12.5,
            ['assets/styles/theme.css'],
            ['underline']
        );

        self::assertSame('.flex{display:flex}', $result->css());
        self::assertSame($diagnostics, $result->diagnostics());
        self::assertSame('php', $result->driver());
        self::assertSame('compat-4.1', $result->driverVersion());
        self::assertSame(12.5, $result->durationMs());
        self::assertSame(['assets/styles/theme.css'], $result->imports());
        self::assertSame(['underline'], $result->inlineSources());
    }

    /** @test */
    public function theCliDriverRoundTripsToo(): void
    {
        $result = self::makeResult(new DiagnosticBag(), 'cli', '4.1.18');

        self::assertSame('cli', $result->driver());
        self::assertSame('4.1.18', $result->driverVersion());
    }

    /** @test */
    public function emptyListsAreAccepted(): void
    {
        $result = new CompileResult('', new DiagnosticBag(), 'php', 'compat-4.1', 0.0, [], []);

        self::assertSame('', $result->css());
        self::assertSame([], $result->imports());
        self::assertSame([], $result->inlineSources());
        self::assertSame(0.0, $result->durationMs());
    }

    /**
     * The manifest's `imports` and `inline_sources` are JSON arrays; a gapped
     * PHP array would encode as an object and break the schema.
     *
     * @test
     */
    public function listArgumentsAreReindexed(): void
    {
        $result = new CompileResult(
            '',
            new DiagnosticBag(),
            'php',
            'compat-4.1',
            0.0,
            [3 => 'assets/a.css', 9 => 'assets/b.css'],
            [7 => 'underline']
        );

        self::assertSame(['assets/a.css', 'assets/b.css'], $result->imports());
        self::assertSame(['underline'], $result->inlineSources());
    }

    /** @test */
    public function hasErrorsIsTrueWhenTheBagHoldsAnError(): void
    {
        $bag = new DiagnosticBag([
            new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_UTILITY, 'Unknown utility.'),
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'Unexpected token.'),
        ]);

        self::assertTrue(self::makeResult($bag)->hasErrors());
    }

    /** @test */
    public function hasErrorsIsFalseWhenTheBagHoldsOnlyWarnings(): void
    {
        $bag = new DiagnosticBag([
            new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_UTILITY, 'Unknown utility.'),
            new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_VARIANT, 'Unknown variant.'),
        ]);

        self::assertFalse(self::makeResult($bag)->hasErrors());
    }

    /** @test */
    public function hasErrorsIsFalseForAnEmptyBag(): void
    {
        self::assertFalse(self::makeResult(new DiagnosticBag())->hasErrors());
    }

    /**
     * The bag is a live collector, not a snapshot: the pipeline keeps appending
     * to it after the compiler has returned.
     *
     * @test
     */
    public function hasErrorsFollowsTheBagAfterConstruction(): void
    {
        $bag = new DiagnosticBag();
        $result = self::makeResult($bag);

        self::assertFalse($result->hasErrors());

        $bag->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_THEME_INVALID, 'Bad @theme.'));

        self::assertTrue($result->hasErrors());
    }

    /** @test */
    public function thereAreNoSetters(): void
    {
        $reflection = new ReflectionClass(CompileResult::class);
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            $names[] = $method->getName();
        }

        \sort($names);

        self::assertSame(
            [
                '__construct',
                'css',
                'diagnostics',
                'driver',
                'driverVersion',
                'durationMs',
                'hasErrors',
                'imports',
                'inlineSources',
            ],
            $names
        );
    }

    private static function makeResult(
        DiagnosticBag $bag,
        string $driver = 'php',
        string $driverVersion = 'compat-4.1'
    ): CompileResult {
        return new CompileResult('.flex{display:flex}', $bag, $driver, $driverVersion, 1.0, [], []);
    }
}
