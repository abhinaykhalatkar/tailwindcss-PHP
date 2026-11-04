<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Version;

/**
 * The engine compatibility stamp (design doc 17, FR-FRESH-8).
 *
 * The stamp is folded into every cache fingerprint, so its shape is a contract:
 * a malformed value would not fail loudly, it would silently stop invalidating.
 */
final class VersionTest extends TestCase
{
    /** @test */
    public function compatHasTheDocumentedFormat(): void
    {
        self::assertMatchesRegularExpression('/^compat-\d+\.\d+\.\d+\.\d+$/', Version::COMPAT);
    }

    /** @test */
    public function compatNamesThePinnedTailwindRelease(): void
    {
        // The middle components are the pinned CLI version; a pinned-CLI bump
        // replaces them (release-process skill), so this row moves with that bump.
        self::assertSame('compat-4.1.18.0', Version::COMPAT);
    }

    /** @test */
    public function classIsFinalAndCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(Version::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
