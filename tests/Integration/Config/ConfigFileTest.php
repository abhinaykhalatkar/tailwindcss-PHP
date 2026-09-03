<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Config;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Config\ConfigFile;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Integration because the whole point of the class is executing a real file.
 *
 * {@see ConfigFile} is the only sanctioned `require` site in `src/` (decision
 * HBC-A9), which is why static rule R2 allowlists exactly this path. These tests
 * are the other half of that allowlist entry: every guard that earns it is
 * exercised here, and each one must fail **before** the `require` runs.
 */
final class ConfigFileTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-cfgfile');

        \mkdir($this->root . '/config', 0777, true);
        \mkdir($this->root . '/cache/tailwind', 0777, true);

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function aValidConfigFileIsLoaded(): void
    {
        $path = $this->write('config/tailwind.php', "<?php\n\nreturn ['debug' => true, 'keep_versions' => 4];\n");

        $loaded = ConfigFile::load($path, $this->root);

        self::assertSame(['debug' => true, 'keep_versions' => 4], $loaded);
    }

    /** @test */
    public function anEmptyArrayIsValid(): void
    {
        $path = $this->write('config/empty.php', "<?php\n\nreturn [];\n");

        self::assertSame([], ConfigFile::load($path, $this->root));
    }

    /** @test */
    public function aMissingFileIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigFile::load($this->root . '/config/nope.php', $this->root);
    }

    /** @test */
    public function aDirectoryIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigFile::load($this->root . '/config', $this->root);
    }

    /**
     * The extension check is not cosmetic: it is what stops a `.css`, a `.json`
     * or an uploaded artefact from being executed.
     *
     * @test
     */
    public function aNonPhpFileIsRejected(): void
    {
        $path = $this->write('config/tailwind.txt', "<?php\n\nreturn [];\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('.php file');

        ConfigFile::load($path, $this->root);
    }

    /** @test */
    public function aFileOutsideTheProjectRootIsRejected(): void
    {
        $outside = TempTree::make('tailwindphp-cfgfile-outside');

        try {
            $path = $outside . '/tailwind.php';
            \file_put_contents($path, "<?php\n\nreturn [];\n");

            $this->expectException(ConfigurationException::class);

            ConfigFile::load($path, $this->root);
        } finally {
            TempTree::remove($outside);
        }
    }

    /** @test */
    public function traversalOutOfTheProjectRootIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigFile::load($this->root . '/config/../../etc/passwd.php', $this->root);
    }

    /** @test */
    public function aStreamWrapperIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigFile::load('phar://evil.phar/config.php', $this->root);
    }

    /**
     * Threat T-08: a cache artefact must never be executable configuration. The
     * cache is writable by the web server, so a file placed there is exactly the
     * one an attacker could control.
     *
     * @test
     */
    public function aFileInsideTheCacheDirectoryIsRejected(): void
    {
        $path = $this->write('cache/tailwind/evil.php', "<?php\n\nreturn ['debug' => true];\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cache directory');

        ConfigFile::load($path, $this->root, $this->root . '/cache/tailwind');
    }

    /** @test */
    public function aFileOutsideTheCacheDirectoryIsAcceptedWhenTheCacheIsKnown(): void
    {
        $path = $this->write('config/tailwind.php', "<?php\n\nreturn ['gzip' => false];\n");

        $loaded = ConfigFile::load($path, $this->root, $this->root . '/cache/tailwind');

        self::assertSame(['gzip' => false], $loaded);
    }

    /**
     * @test
     *
     * @dataProvider nonArrayReturns
     */
    public function aFileThatDoesNotReturnAnArrayIsRejected(string $body): void
    {
        $path = $this->write('config/bad.php', $body);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must return an array');

        ConfigFile::load($path, $this->root);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonArrayReturns(): array
    {
        return [
            'returns a string' => ["<?php\n\nreturn 'nope';\n"],
            'returns an int' => ["<?php\n\nreturn 42;\n"],
            'returns null' => ["<?php\n\nreturn null;\n"],
            'returns nothing' => ["<?php\n\n\$x = 1;\n"],
            'returns an object' => ["<?php\n\nreturn new \\stdClass();\n"],
        ];
    }

    /**
     * The `$cacheDir` guard compares path prefixes, so a relative value matches
     * nothing and would silently switch the T-08 check off rather than tighten
     * it. Refusing it is the difference between "cannot check" and "checked and
     * found nothing" — the second is a lie.
     *
     * @test
     *
     * @dataProvider unusableCacheDirs
     */
    public function anUnusableCacheDirectoryIsRefusedRatherThanIgnored(string $cacheDir): void
    {
        $path = $this->write('config/tailwind.php', "<?php\n\nreturn [];\n");

        $this->expectException(ConfigurationException::class);

        ConfigFile::load($path, $this->root, $cacheDir);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableCacheDirs(): array
    {
        return [
            'relative' => ['cache/tailwind'],
            'bare relative segment' => ['cache'],
            'dot' => ['.'],
            'empty' => [''],
            'a stream wrapper' => ['php://temp'],
            'a NUL byte' => ["/tmp/cache\0/x"],
        ];
    }

    /**
     * The guard still has to *work* once given an absolute path — the point of
     * the previous test is that it is never silently skipped, not that it
     * rejects everything handed to it.
     *
     * @test
     */
    public function anAbsoluteCacheDirectoryStillRefusesAFileInsideIt(): void
    {
        $path = $this->write('cache/tailwind/evil.php', "<?php\n\nreturn [];\n");

        $this->expectException(ConfigurationException::class);

        ConfigFile::load($path, $this->root, $this->root . '/cache/tailwind');
    }

    /** @test */
    public function anAbsoluteCacheDirectoryLoadsAFileOutsideIt(): void
    {
        $path = $this->write('config/tailwind.php', "<?php\n\nreturn ['gzip' => false];\n");

        self::assertSame(
            ['gzip' => false],
            ConfigFile::load($path, $this->root, $this->root . '/cache/tailwind')
        );
    }

    /**
     * Null means "the cache directory is not known yet" — an honest "cannot
     * check", unlike a relative path, which only looks like one.
     *
     * @test
     */
    public function theTwoArgumentFormStillLoads(): void
    {
        $path = $this->write('config/tailwind.php', "<?php\n\nreturn ['gzip' => false];\n");

        self::assertSame(['gzip' => false], ConfigFile::load($path, $this->root));
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;

        \file_put_contents($path, $contents);

        return $path;
    }
}
