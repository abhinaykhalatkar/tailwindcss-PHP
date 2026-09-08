<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Freshness;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Freshness\EnvironmentFingerprint;

/**
 * @covers-requirement FR-FRESH-8
 */
final class EnvironmentFingerprintTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(EnvironmentFingerprint::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function exposesEveryConstructorValue(): void
    {
        $fingerprint = new EnvironmentFingerprint('1.0.0', 'abc123', 'php', 'compat-4.1');

        self::assertSame('1.0.0', $fingerprint->packageVersion());
        self::assertSame('abc123', $fingerprint->configHash());
        self::assertSame('php', $fingerprint->driver());
        self::assertSame('compat-4.1', $fingerprint->driverVersion());
    }

    /** @test */
    public function toArrayCarriesTheManifestFieldNames(): void
    {
        $fingerprint = new EnvironmentFingerprint('1.0.0', 'abc123', 'cli', '4.1.18');

        self::assertSame(
            [
                'package_version' => '1.0.0',
                'config_hash' => 'abc123',
                'driver' => 'cli',
                'driver_version' => '4.1.18',
            ],
            $fingerprint->toArray()
        );
    }

    /** @test */
    public function matchesManifestWhenEveryFieldEqual(): void
    {
        $fingerprint = new EnvironmentFingerprint('1.0.0', 'abc123', 'php', 'compat-4.1');

        self::assertTrue($fingerprint->matchesManifest([
            'package_version' => '1.0.0',
            'config_hash' => 'abc123',
            'driver' => 'php',
            'driver_version' => 'compat-4.1',
            'built_at' => 123456,
        ]));
    }

    /**
     * One row per field (design doc 09 §Tests: "EnvironmentFingerprintTest
     * one row per field").
     *
     * @test
     *
     * @dataProvider mismatchCases
     *
     * @param array<string, mixed> $manifest
     */
    public function matchesManifestIsFalseWhenAnyFieldDiffers(array $manifest): void
    {
        $fingerprint = new EnvironmentFingerprint('1.0.0', 'abc123', 'php', 'compat-4.1');

        self::assertFalse($fingerprint->matchesManifest($manifest));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mismatchCases(): array
    {
        $matching = [
            'package_version' => '1.0.0',
            'config_hash' => 'abc123',
            'driver' => 'php',
            'driver_version' => 'compat-4.1',
        ];

        return [
            'package_version differs' => [['package_version' => '2.0.0'] + $matching],
            'config_hash differs' => [['config_hash' => 'different'] + $matching],
            'driver differs' => [['driver' => 'cli'] + $matching],
            'driver_version differs' => [['driver_version' => 'compat-4.2'] + $matching],
            'a field is missing entirely' => [
                ['package_version' => '1.0.0', 'config_hash' => 'abc123', 'driver' => 'php'],
            ],
            'empty manifest' => [[]],
        ];
    }
}
