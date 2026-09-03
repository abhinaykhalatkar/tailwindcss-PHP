<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\Driver;

final class DriverTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Driver::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function theConstantsAreTheDocumentedStrings(): void
    {
        self::assertSame('auto', Driver::AUTO);
        self::assertSame('php', Driver::PHP);
        self::assertSame('cli', Driver::CLI);
    }

    /**
     * The order is load-bearing: it is the enum written into the schema and, via
     * it, into every configuration error message.
     *
     * @test
     */
    public function allReturnsTheThreeDriversInOrder(): void
    {
        self::assertSame(['auto', 'php', 'cli'], Driver::all());
    }

    /**
     * @test
     *
     * @dataProvider driverCases
     */
    public function isValidAcceptsOnlyTheThreeExactStrings(string $driver, bool $valid): void
    {
        self::assertSame($valid, Driver::isValid($driver));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function driverCases(): array
    {
        return [
            'auto' => ['auto', true],
            'php' => ['php', true],
            'cli' => ['cli', true],
            'empty string' => ['', false],
            'an unrelated name' => ['node', false],
            'the wrong case' => ['PHP', false],
            'a trailing space' => ['auto ', false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider validDrivers
     */
    public function assertPassesForAValidDriver(string $driver): void
    {
        Driver::assert($driver);

        // assert() returns void and throws on failure, so reaching this line is
        // the observation under test.
        self::assertContains($driver, Driver::all());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validDrivers(): array
    {
        return [
            'auto' => ['auto'],
            'php' => ['php'],
            'cli' => ['cli'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider invalidDrivers
     */
    public function assertRejectsAnUnknownDriverAndNamesIt(string $driver): void
    {
        try {
            Driver::assert($driver);
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(\sprintf('"%s"', $driver), $e->getMessage());

            return;
        }

        self::fail(\sprintf('Driver::assert() accepted "%s".', $driver));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDrivers(): array
    {
        return [
            'empty string' => [''],
            'an unrelated name' => ['node'],
            'the wrong case' => ['PHP'],
            'a trailing space' => ['auto '],
        ];
    }
}
