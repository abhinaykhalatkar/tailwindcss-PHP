<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Version;

final class VersionTest extends TestCase
{
    /** @test */
    public function versionConstantMatchesSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            Version::VERSION
        );
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Version::class);

        self::assertTrue($reflection->isFinal());
    }
}
