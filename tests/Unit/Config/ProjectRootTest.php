<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\ProjectRoot;

/**
 * Detection has three fallbacks and no way to force one, so the assertions are
 * about the contract every strategy must satisfy: an existing, canonical,
 * forward-slashed directory that is stable within a process.
 */
final class ProjectRootTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ProjectRoot::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function detectReturnsAnExistingDirectory(): void
    {
        $result = ProjectRoot::detect();

        self::assertNotSame('', $result);
        self::assertDirectoryExists($result);
        self::assertTrue(\is_dir($result));
    }

    /** @test */
    public function theResultUsesForwardSlashesOnly(): void
    {
        $result = ProjectRoot::detect();

        self::assertFalse(
            \strpos($result, '\\'),
            \sprintf('The detected project root "%s" still contains a backslash.', $result)
        );
    }

    /** @test */
    public function theResultIsAlreadyCanonical(): void
    {
        $result = ProjectRoot::detect();
        $real = \realpath($result);

        self::assertNotFalse($real);

        $canonical = \str_replace('\\', '/', (string) $real);

        if (\DIRECTORY_SEPARATOR === '\\') {
            // Windows paths differ only by case at worst; the drive letter's
            // case is not something detection controls.
            self::assertSame(0, \strcasecmp($canonical, $result));

            return;
        }

        self::assertSame($canonical, $result);
    }

    /** @test */
    public function detectIsStableAcrossCalls(): void
    {
        self::assertSame(ProjectRoot::detect(), ProjectRoot::detect());
    }

    /**
     * Running from this repository, every strategy must land on the package root
     * itself — the directory holding `composer.json`.
     *
     * @test
     */
    public function detectFindsTheDirectoryHoldingComposerJson(): void
    {
        $result = ProjectRoot::detect();

        self::assertTrue(
            \is_file($result . '/composer.json'),
            \sprintf('No composer.json in the detected project root "%s".', $result)
        );
    }
}
