<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\Defaults;
use TailwindPhp\Config\Driver;
use TailwindPhp\Config\Schema;

/**
 * The schema and the defaults table describe the same set of keys from two
 * sides, and the `hash` flag decides what invalidates a cache. Neither shows up
 * as a failure anywhere else, so both are asserted here directly.
 */
final class SchemaTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Schema::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * The guard against the two tables drifting apart: a key added to one and
     * forgotten in the other fails here and nowhere else.
     *
     * @test
     */
    public function theSchemaAndTheDefaultsDescribeTheSameKeys(): void
    {
        $schemaKeys = \array_keys(Schema::table());
        $defaultKeys = \array_keys(Defaults::table());

        \sort($schemaKeys);
        \sort($defaultKeys);

        self::assertSame($defaultKeys, $schemaKeys);
    }

    /**
     * @test
     *
     * @dataProvider specKeys
     */
    public function everySpecCarriesTheFullSetOfFlags(string $flag): void
    {
        foreach (Schema::table() as $key => $spec) {
            self::assertArrayHasKey($flag, $spec, \sprintf('Key "%s" has no "%s".', $key, $flag));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function specKeys(): array
    {
        return [
            'type' => ['type'],
            'nullable' => ['nullable'],
            'enum' => ['enum'],
            'range' => ['range'],
            'nested' => ['nested'],
            'hash' => ['hash'],
            'bundle' => ['bundle'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider hashedKeyCases
     */
    public function theHashSetIsExactlyTheKeysThatCanChangeTheOutput(string $key, bool $hashed): void
    {
        $keys = Schema::hashedKeys();

        if ($hashed) {
            self::assertContains($key, $keys, \sprintf('"%s" should take part in the config hash.', $key));

            return;
        }

        self::assertNotContains($key, $keys, \sprintf('"%s" must not take part in the config hash.', $key));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hashedKeyCases(): array
    {
        return [
            'input is hashed' => ['input', true],
            'input_extensions is hashed' => ['input_extensions', true],
            'watch is hashed' => ['watch', true],
            'sources is hashed' => ['sources', true],
            'source_extensions is hashed' => ['source_extensions', true],
            'ignore is hashed' => ['ignore', true],
            'allowed_roots is hashed' => ['allowed_roots', true],
            'safelist is hashed' => ['safelist', true],
            'blocklist is hashed' => ['blocklist', true],
            'minify is hashed' => ['minify', true],
            'driver is hashed' => ['driver', true],
            'fingerprint_length is hashed' => ['fingerprint_length', true],
            'preprocessor is hashed' => ['preprocessor', true],
            'preprocessor_options is hashed' => ['preprocessor_options', true],
            'scanner.prepass is hashed' => ['scanner.prepass', true],
            'cli.binary is hashed' => ['cli.binary', true],
            'bundles is hashed' => ['bundles', true],
            'debug is not hashed' => ['debug', false],
            'logger is not hashed' => ['logger', false],
            'log_level is not hashed' => ['log_level', false],
            'check_interval is not hashed' => ['check_interval', false],
            'check_store is not hashed' => ['check_store', false],
            'freeze is not hashed' => ['freeze', false],
            'lock is not hashed' => ['lock', false],
            'lock_timeout is not hashed' => ['lock_timeout', false],
            'gzip is not hashed' => ['gzip', false],
            'keep_versions is not hashed' => ['keep_versions', false],
            'cache_dir is not hashed' => ['cache_dir', false],
            'cache_dir_mode is not hashed' => ['cache_dir_mode', false],
            'cache_file_mode is not hashed' => ['cache_file_mode', false],
            'project_root is not hashed' => ['project_root', false],
            'public_route is not hashed' => ['public_route', false],
            'fingerprint is not hashed' => ['fingerprint', false],
            'cli.verify is not hashed' => ['cli.verify', false],
            'cli.timeout is not hashed' => ['cli.timeout', false],
        ];
    }

    /** @test */
    public function theBundleOverridableKeysAreExactlyTheDocumentedSet(): void
    {
        $expected = [
            'input',
            'watch',
            'sources',
            'input_extensions',
            'source_extensions',
            'ignore',
            'safelist',
            'blocklist',
            'public_route',
            'minify',
            'engine',
            'preprocessor',
            'preprocessor_options',
        ];
        $actual = Schema::bundleKeys();

        \sort($expected);
        \sort($actual);

        self::assertSame($expected, $actual);
    }

    /** @test */
    public function theDriverEnumIsTheDriverList(): void
    {
        $spec = Schema::table()['driver'];

        self::assertSame(Driver::all(), $spec['enum']);
    }

    /** @test */
    public function theFingerprintLengthRangeIsEightToSixteen(): void
    {
        $spec = Schema::table()['fingerprint_length'];

        self::assertSame([8, 16], $spec['range']);
    }

    /**
     * @test
     *
     * @dataProvider enumCases
     *
     * @param list<string> $expected
     */
    public function theEnumIsExactlyTheDocumentedSet(string $key, array $expected): void
    {
        $spec = Schema::table()[$key];

        self::assertSame($expected, $spec['enum']);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function enumCases(): array
    {
        return [
            'check_store' => ['check_store', ['file', 'apcu']],
            'lock' => ['lock', ['flock', 'none']],
        ];
    }
}
