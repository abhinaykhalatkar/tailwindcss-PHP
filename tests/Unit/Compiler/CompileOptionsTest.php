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

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $names[] = $method->getName();
        }

        \sort($names);

        self::assertSame(['__construct', 'debug', 'entryRelPath', 'minify', 'sourceMap'], $names);
    }

    /** @test */
    public function theEntryPathDefaultsToTheProjectRoot(): void
    {
        self::assertSame('', (new CompileOptions(false, false))->entryRelPath());
    }

    /**
     * @test
     *
     * @dataProvider relativeEntries
     */
    public function aProjectRelativeEntryPathRoundTrips(string $path): void
    {
        self::assertSame($path, (new CompileOptions(false, false, false, $path))->entryRelPath());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relativeEntries(): array
    {
        return [
            'nested' => ['assets/styles/main.css'],
            'at the root' => ['main.css'],
            'dot segment' => ['./assets/main.css'],
            'dots in a name' => ['assets/..styles/a..b.css'],
        ];
    }

    /**
     * T-01 (ADR-014): only a project-relative path is accepted; the resolver
     * confines whatever reaches it as well.
     *
     * @test
     *
     * @dataProvider refusedEntries
     */
    public function anythingButAProjectRelativeEntryPathIsRefused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CompileOptions(false, false, false, $path);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedEntries(): array
    {
        return [
            'absolute' => ['/etc/passwd'],
            'backslash absolute' => ['\share\x.css'],
            'drive' => ['C:/x/main.css'],
            'drive, relative' => ['c:main.css'],
            'parent segment' => ['../outside.css'],
            'inner parent segment' => ['assets/../../x.css'],
            'backslash parent segment' => ['assets\..\..\x.css'],
            'stream wrapper' => ['file:///etc/passwd'],
            'php wrapper' => ['php://filter/resource=x'],
            'nul byte' => ["main.css\0.php"],
        ];
    }
}
