<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\CompileOptions;

/**
 * @covers-requirement FR-COMP-1
 */
final class CompileOptionsTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CompileOptions::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider flagCombinations
     */
    public function everyFlagCombinationRoundTrips(bool $minify, bool $debug, bool $sourceMap): void
    {
        $options = new CompileOptions($minify, $debug, $sourceMap);

        self::assertSame($minify, $options->minify());
        self::assertSame($debug, $options->debug());
        self::assertSame($sourceMap, $options->sourceMap());
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function flagCombinations(): array
    {
        return [
            'nothing on' => [false, false, false],
            'minify only' => [true, false, false],
            'debug only' => [false, true, false],
            'minify and debug' => [true, true, false],
            'source map only' => [false, false, true],
            'everything on' => [true, true, true],
        ];
    }

    /**
     * Source maps are reserved for after 1.0 (Q12); every M1 caller constructs
     * the options with two arguments, so the default has to be off.
     *
     * @test
     */
    public function sourceMapDefaultsToFalse(): void
    {
        self::assertFalse((new CompileOptions(false, false))->sourceMap());
        self::assertFalse((new CompileOptions(true, true))->sourceMap());
    }

    /** @test */
    public function theThreeFlagsAreIndependent(): void
    {
        $options = new CompileOptions(true, false, false);

        self::assertTrue($options->minify());
        self::assertFalse($options->debug());
        self::assertFalse($options->sourceMap());
    }

    /** @test */
    public function thereAreNoSetters(): void
    {
        $reflection = new ReflectionClass(CompileOptions::class);
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            $names[] = $method->getName();
        }

        \sort($names);

        self::assertSame(['__construct', 'debug', 'minify', 'sourceMap'], $names);
    }
}
