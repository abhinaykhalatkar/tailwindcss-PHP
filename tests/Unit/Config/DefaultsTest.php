<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\Defaults;

/**
 * The defaults table is the single source of both the default values and the
 * set of legal top-level keys, so each documented default is asserted by value
 * rather than by shape: a silent change here changes every consuming project.
 *
 * @covers-requirement FR-PIPE-4
 */
final class DefaultsTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Defaults::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function tableIsStableAcrossCalls(): void
    {
        self::assertSame(Defaults::table(), Defaults::table());
    }

    /**
     * @test
     *
     * @dataProvider topLevelDefaults
     */
    public function everyDocumentedKeyIsPresent(string $key): void
    {
        self::assertArrayHasKey($key, Defaults::table());
    }

    /**
     * @test
     *
     * @dataProvider topLevelDefaults
     *
     * @param mixed $expected
     */
    public function theDefaultValueIsExactlyAsDocumented(string $key, $expected): void
    {
        self::assertSame($expected, Defaults::table()[$key]);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function topLevelDefaults(): array
    {
        return [
            'debug' => ['debug', false],
            'project_root' => ['project_root', null],
            'input' => ['input', 'assets/styles/main.css'],
            'watch' => ['watch', []],
            'input_extensions' => ['input_extensions', ['css', 'scss']],
            'sources' => ['sources', ['templates']],
            'ignore' => ['ignore', ['.git', 'node_modules', 'vendor', 'cache']],
            'allowed_roots' => ['allowed_roots', []],
            'safelist' => ['safelist', []],
            'blocklist' => ['blocklist', []],
            'public_route' => ['public_route', 'assets/styles.css'],
            'fingerprint' => ['fingerprint', true],
            'fingerprint_length' => ['fingerprint_length', 12],
            'keep_versions' => ['keep_versions', 3],
            'cache_dir' => ['cache_dir', 'cache/tailwind'],
            'cache_dir_mode' => ['cache_dir_mode', 0755],
            'cache_file_mode' => ['cache_file_mode', 0644],
            'freeze' => ['freeze', false],
            'check_interval' => ['check_interval', 0],
            'check_store' => ['check_store', 'file'],
            'lock' => ['lock', 'flock'],
            'lock_timeout' => ['lock_timeout', 10],
            'gzip' => ['gzip', true],
            'driver' => ['driver', 'auto'],
            'minify' => ['minify', null],
            'preprocessor' => ['preprocessor', null],
            'preprocessor_options' => ['preprocessor_options', []],
            'logger' => ['logger', null],
            'log_level' => ['log_level', 'warning'],
            'bundles' => ['bundles', []],
        ];
    }

    /** @test */
    public function sourceExtensionsIsAListOfStrings(): void
    {
        /** @var array<int, mixed> $extensions */
        $extensions = Defaults::table()['source_extensions'];

        self::assertNotSame([], $extensions);
        self::assertSame(\array_keys($extensions), \range(0, \count($extensions) - 1));

        foreach ($extensions as $extension) {
            self::assertIsString($extension);
        }
    }

    /**
     * @test
     *
     * @dataProvider requiredSourceExtensions
     */
    public function sourceExtensionsContainsTheDocumentedEntries(string $extension): void
    {
        /** @var array<int, mixed> $extensions */
        $extensions = Defaults::table()['source_extensions'];

        self::assertContains($extension, $extensions);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredSourceExtensions(): array
    {
        return [
            'blade templates are matched by their double extension' => ['blade.php'],
            'json' => ['json'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider nestedDefaults
     *
     * @param mixed $expected
     */
    public function theNestedDefaultValueIsExactlyAsDocumented(string $parent, string $child, $expected): void
    {
        /** @var array<string, mixed> $table */
        $table = Defaults::table()[$parent];

        self::assertArrayHasKey($child, $table);
        self::assertSame($expected, $table[$child]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function nestedDefaults(): array
    {
        return [
            'cli.binary' => ['cli', 'binary', null],
            'cli.verify' => ['cli', 'verify', true],
            'cli.timeout' => ['cli', 'timeout', 20],
            'engine.prefix' => ['engine', 'prefix', null],
            'engine.important' => ['engine', 'important', false],
            'engine.preflight' => ['engine', 'preflight', null],
            'limits.max_file_size' => ['limits', 'max_file_size', 2097152],
            'limits.max_files' => ['limits', 'max_files', 20000],
            'limits.max_candidates' => ['limits', 'max_candidates', 200000],
            'limits.max_import_depth' => ['limits', 'max_import_depth', 16],
            'limits.max_total_css' => ['limits', 'max_total_css', 4194304],
            'limits.manifest_soft_bytes' => ['limits', 'manifest_soft_bytes', 2097152],
            'http.immutable_max_age' => ['http', 'immutable_max_age', 31536000],
            'http.extra_headers' => ['http', 'extra_headers', []],
            'http.handle_options' => ['http', 'handle_options', false],
            'scanner.prepass' => ['scanner', 'prepass', ['twig']],
        ];
    }

    /**
     * @test
     *
     * @dataProvider nestedTables
     *
     * @param array<string, mixed> $expected
     */
    public function theWholeNestedTableIsExactlyAsDocumented(string $parent, array $expected): void
    {
        self::assertSame($expected, Defaults::table()[$parent]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function nestedTables(): array
    {
        return [
            'cli' => ['cli', ['binary' => null, 'verify' => true, 'timeout' => 20]],
            'engine' => ['engine', ['prefix' => null, 'important' => false, 'preflight' => null]],
            'scanner' => ['scanner', ['prepass' => ['twig']]],
        ];
    }

    /**
     * `scanner.prepass` is the only pre-pass switch; `scanner.php_strip_php` was
     * removed by decision CSF-A9 and must not come back unnoticed.
     *
     * @test
     */
    public function scannerHasNoPhpStripPhpKey(): void
    {
        /** @var array<string, mixed> $scanner */
        $scanner = Defaults::table()['scanner'];

        self::assertFalse(\array_key_exists('php_strip_php', $scanner));
    }
}
