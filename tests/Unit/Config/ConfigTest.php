<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\Config;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The frozen public surface of {@see Config}, and the behaviour of
 * `configHash()`.
 *
 * The hash decides when a cached stylesheet is thrown away. Both failure modes
 * are silent, which is why they are asserted directly rather than inferred:
 * hashing too much makes an operational tweak invalidate every cache in the
 * fleet, and hashing too little serves stale CSS after a real change — the exact
 * bug the hash exists to prevent (decision CSF-A4).
 */
final class ConfigTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->makeProject('tailwindphp-conf');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Config::class);

        self::assertTrue($reflection->isFinal());
    }

    // --------------------------------------------------------- path twins

    /**
     * Absolute is what the filesystem needs; project-relative is what goes into
     * a cache artefact (ADR-014, threat T-06).
     *
     * @test
     */
    public function everyPathIsExposedAsAnAbsoluteAndRelativePair(): void
    {
        $config = $this->build([]);

        self::assertSame($this->root . '/cache/tailwind', $config->cacheDir());
        self::assertSame('cache/tailwind', $config->cacheDirRel());

        $bundle = $config->bundle('styles');

        self::assertSame($this->root . '/assets/styles/main.css', $bundle->input());
        self::assertSame('assets/styles/main.css', $bundle->inputRel());
    }

    /** @test */
    public function relativePathsUseForwardSlashesOnEveryPlatform(): void
    {
        $config = $this->build([]);

        self::assertStringNotContainsString('\\', $config->cacheDirRel());
        self::assertStringNotContainsString('\\', $config->bundle('styles')->inputRel());
    }

    /** @test */
    public function theProjectRootIsAlwaysIncludedInTheAllowedRoots(): void
    {
        $config = $this->build([]);

        self::assertContains($this->root, $config->allowedRoots());
    }

    // ------------------------------------------------------------- getters

    /** @test */
    public function gettersReportTheEffectiveValues(): void
    {
        $config = $this->build([
            'driver' => 'php',
            'fingerprint_length' => 16,
            'keep_versions' => 5,
            'freeze' => true,
            'check_interval' => 30,
            'lock_timeout' => 25,
            'gzip' => false,
            'log_level' => 'error',
        ]);

        self::assertSame('php', $config->driver());
        self::assertSame(16, $config->fingerprintLength());
        self::assertSame(5, $config->keepVersions());
        self::assertTrue($config->freeze());
        self::assertSame(30, $config->checkInterval());
        self::assertSame(25, $config->lockTimeout());
        self::assertFalse($config->gzip());
        self::assertSame('error', $config->logLevel());
        self::assertSame('file', $config->checkStore());
        self::assertSame(0755, $config->cacheDirMode());
        self::assertSame(0644, $config->cacheFileMode());
    }

    /** @test */
    public function groupGettersReturnTheirWholeMap(): void
    {
        $config = $this->build([]);

        self::assertSame(20000, $config->limits()['max_files']);
        self::assertSame(31536000, $config->http()['immutable_max_age']);
        self::assertSame(['twig'], $config->scanner()['prepass']);
        self::assertTrue($config->cli()['verify']);
        self::assertNull($config->cli()['binary']);
    }

    // ---------------------------------------------------------- configHash

    /** @test */
    public function theHashIsBareLowercaseHex(): void
    {
        $hash = $this->build([])->configHash();

        self::assertSame(64, \strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertStringNotContainsString('sha256:', $hash);
    }

    /** @test */
    public function theHashIsDeterministic(): void
    {
        self::assertSame($this->build([])->configHash(), $this->build([])->configHash());
    }

    /**
     * Two configs differing only in key order describe the same thing, so the
     * canonical form is recursively key-sorted.
     *
     * @test
     */
    public function keyOrderDoesNotChangeTheHash(): void
    {
        $a = $this->build(['gzip' => false, 'driver' => 'php', 'keep_versions' => 4]);
        $b = $this->build(['keep_versions' => 4, 'driver' => 'php', 'gzip' => false]);

        self::assertSame($a->configHash(), $b->configHash());
    }

    /**
     * The point of storing relative paths: moving a deployment must not throw
     * away its cache (ADR-014).
     *
     * @test
     */
    public function theHashSurvivesAProjectMove(): void
    {
        $elsewhere = $this->makeProject('tailwindphp-moved');

        try {
            $here = ConfigBuilder::fromArray(['project_root' => $this->root]);
            $there = ConfigBuilder::fromArray(['project_root' => $elsewhere]);

            self::assertNotSame($this->root, $elsewhere);
            self::assertSame($here->configHash(), $there->configHash());
        } finally {
            TempTree::remove($elsewhere);
        }
    }

    /**
     * @test
     *
     * @dataProvider operationalKeys
     *
     * @param array<string, mixed> $user
     */
    public function anOperationalKeyDoesNotChangeTheHash(array $user): void
    {
        self::assertSame(
            $this->build([])->configHash(),
            $this->build($user)->configHash(),
            'An operational key changed the hash and would invalidate every cache.'
        );
    }

    /**
     * Everything here can be tuned on a live site without wanting a rebuild.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function operationalKeys(): array
    {
        return [
            'check_interval' => [['check_interval' => 60]],
            'freeze' => [['freeze' => true]],
            'lock' => [['lock' => 'none']],
            'lock_timeout' => [['lock_timeout' => 30]],
            'gzip' => [['gzip' => false]],
            'keep_versions' => [['keep_versions' => 9]],
            'log_level' => [['log_level' => 'debug']],
            'fingerprint' => [['fingerprint' => false]],
            'public_route' => [['public_route' => 'assets/styles.css']],
            'cache modes' => [['cache_dir_mode' => 0700, 'cache_file_mode' => 0600]],
            'http' => [['http' => ['immutable_max_age' => 60, 'handle_options' => true]]],
            'cli verify and timeout' => [['cli' => ['verify' => false, 'timeout' => 99]]],
            'limits other than max_candidates' => [['limits' => ['max_files' => 9, 'max_import_depth' => 4]]],
        ];
    }

    /**
     * @test
     *
     * @dataProvider outputAffectingKeys
     *
     * @param array<string, mixed> $user
     */
    public function anOutputAffectingKeyChangesTheHash(array $user): void
    {
        self::assertNotSame(
            $this->build([])->configHash(),
            $this->build($user)->configHash(),
            'A key that can change the CSS did not change the hash; stale output would be served.'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function outputAffectingKeys(): array
    {
        return [
            'driver' => [['driver' => 'php']],
            'minify' => [['minify' => false]],
            'fingerprint_length' => [['fingerprint_length' => 16]],
            'safelist' => [['safelist' => ['p-4']]],
            'blocklist' => [['blocklist' => ['p-4']]],
            'ignore' => [['ignore' => ['.git']]],
            'input_extensions' => [['input_extensions' => ['css']]],
            'source_extensions' => [['source_extensions' => ['twig']]],
            'engine prefix' => [['engine' => ['prefix' => 'tw']]],
            'engine important' => [['engine' => ['important' => true]]],
            'scanner prepass' => [['scanner' => ['prepass' => []]]],
            'max_candidates' => [['limits' => ['max_candidates' => 10]]],
        ];
    }

    /**
     * `debug` is not hashed itself, but it derives `minify`, which is — so the
     * hash moves for a reason that is genuinely about the output.
     *
     * @test
     */
    public function debugMovesTheHashOnlyThroughMinify(): void
    {
        $plain = $this->build([]);
        $debug = $this->build(['debug' => true]);
        $pinned = $this->build(['debug' => true, 'minify' => true]);

        self::assertNotSame($plain->configHash(), $debug->configHash());
        self::assertSame($plain->configHash(), $pinned->configHash());
    }

    /** @test */
    public function changingASourceRootChangesTheHash(): void
    {
        \mkdir($this->root . '/partials', 0777, true);

        self::assertNotSame(
            $this->build([])->configHash(),
            $this->build(['sources' => ['templates', 'partials']])->configHash()
        );
    }

    /** @test */
    public function eachBundleContributesToTheHash(): void
    {
        \mkdir($this->root . '/assets/admin', 0777, true);
        \file_put_contents($this->root . '/assets/admin/admin.css', '@import "tailwindcss";');

        $one = $this->build(['bundles' => ['site' => []]]);
        $two = $this->build(['bundles' => [
            'site' => [],
            'admin' => ['input' => 'assets/admin/admin.css', 'public_route' => 'assets/admin.css'],
        ]]);

        self::assertNotSame($one->configHash(), $two->configHash());
    }

    // ------------------------------------------------------------ helpers

    private function makeProject(string $prefix): string
    {
        $root = TempTree::make($prefix);

        \mkdir($root . '/assets/styles', 0777, true);
        \mkdir($root . '/templates', 0777, true);
        \file_put_contents($root . '/assets/styles/main.css', '@import "tailwindcss";');

        return $root;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function build(array $user): Config
    {
        $user['project_root'] = $this->root;

        return ConfigBuilder::fromArray($user);
    }
}
