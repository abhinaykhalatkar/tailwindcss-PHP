<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\EnvironmentInterface;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Host capability and binary discovery (design doc 06; threats T-01, T-18).
 *
 * The security-relevant half is {@see Environment::binaryPath()}: whatever it
 * returns is handed to `proc_open`, so a path that escapes the allowed roots
 * escapes into a process. `Config` confines the configured path already; this
 * confines it again at the point of use, and that is what these tests pin.
 */
final class EnvironmentTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $outside = '';

    protected function setUp(): void
    {
        parent::setUp();

        $base = TempTree::make('tailwindphp-env');
        $this->root = $base . '/project';
        $this->outside = $base . '/outside';

        \mkdir($this->root . '/vendor/bin', 0777, true);
        \mkdir($this->outside, 0777, true);

        TempTree::registerShutdownCleanup($base);
    }

    protected function tearDown(): void
    {
        TempTree::remove(\dirname($this->root));

        parent::tearDown();
    }

    /** @test */
    public function itIsAnEnvironment(): void
    {
        self::assertInstanceOf(EnvironmentInterface::class, new Environment());
    }

    /**
     * On this host `proc_open` is available, so the answer is true — but the
     * point of the assertion is that asking is cheap and side-effect free, and
     * that a second call agrees with the first (it is memoised).
     *
     * @test
     */
    public function processAvailabilityIsStableWithinAnInstance(): void
    {
        $environment = new Environment();
        $first = $environment->processFunctionAvailable();

        self::assertSame($first, $environment->processFunctionAvailable());
        self::assertSame(\function_exists('proc_open'), $first);
    }

    /**
     * The platform key must be one the checksum table is indexed by, or every
     * lookup silently misses and the CLI is never verified.
     *
     * @test
     */
    public function theplatformIsOneOfTheDocumentedKeys(): void
    {
        $known = [
            Environment::PLATFORM_LINUX_X64,
            Environment::PLATFORM_LINUX_X64_MUSL,
            Environment::PLATFORM_LINUX_ARM64,
            Environment::PLATFORM_MACOS_X64,
            Environment::PLATFORM_MACOS_ARM64,
            Environment::PLATFORM_WINDOWS_X64,
        ];

        $environment = new Environment();

        self::assertContains($environment->platform(), $known);
        self::assertSame($environment->platform(), $environment->platform(), 'platform() is memoised.');
    }

    /** @test */
    public function theplatformKeysMatchTheShippedChecksumTable(): void
    {
        $table = \json_decode(
            (string) \file_get_contents(\dirname(__DIR__, 4) . '/resources/checksums.json'),
            true
        );

        self::assertIsArray($table);

        $version = \array_keys($table)[0];

        // Every key the detector can emit — except musl, which it never emits
        // because musl is not reliably detectable from PHP — must exist in the
        // table, or a correct host would fail verification for the wrong reason.
        $emitted = [
            Environment::PLATFORM_LINUX_X64,
            Environment::PLATFORM_LINUX_ARM64,
            Environment::PLATFORM_MACOS_X64,
            Environment::PLATFORM_MACOS_ARM64,
            Environment::PLATFORM_WINDOWS_X64,
        ];

        foreach ($emitted as $platform) {
            self::assertArrayHasKey($platform, $table[$version]);
        }
    }

    // ------------------------------------------------------- discovery

    /** @test */
    public function noBinaryAnywhereAnswersNull(): void
    {
        self::assertNull($this->environment()->binaryPath(null, $this->root, [$this->root]));
    }

    /** @test */
    public function thevendorBinaryIsFoundWhenPresent(): void
    {
        $binary = $this->plant($this->root . '/vendor/bin/tailwindcss');

        $found = $this->environment()->binaryPath(null, $this->root, [$this->root]);

        self::assertNotNull($found);
        self::assertSame(\realpath($binary), \realpath($found));
    }

    /**
     * Windows has no executable bit, so the extension is what carries the
     * meaning and a `.cmd` shim must be discoverable (decision CCP-A23).
     *
     * @test
     */
    public function theWindowsShimIsFound(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('The .cmd shim is only discovered on Windows.');
        }

        $binary = $this->plant($this->root . '/vendor/bin/tailwindcss.cmd');

        $found = $this->environment()->binaryPath(null, $this->root, [$this->root]);

        self::assertNotNull($found);
        self::assertSame(\realpath($binary), \realpath($found));
    }

    /** @test */
    public function aconfiguredBinaryIsUsedInPreferenceToTheVendorOne(): void
    {
        $this->plant($this->root . '/vendor/bin/tailwindcss');
        $configured = $this->plant($this->root . '/tools/tw');

        $found = $this->environment()->binaryPath('tools/tw', $this->root, [$this->root]);

        self::assertNotNull($found);
        self::assertSame(\realpath($configured), \realpath($found));
    }

    /**
     * The control this class exists for: whatever it returns goes to
     * `proc_open`, so a configured path outside the allowed roots must answer
     * null rather than a usable path (T-01).
     *
     * @test
     *
     * @dataProvider escapingPaths
     */
    public function aconfiguredBinaryOutsideTheAllowedRootsIsRefused(string $configured): void
    {
        $this->plant($this->outside . '/evil');

        self::assertNull($this->environment()->binaryPath($configured, $this->root, [$this->root]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingPaths(): array
    {
        return [
            'a traversal' => ['../outside/evil'],
            'a deeper traversal' => ['../../outside/evil'],
            'a stream wrapper' => ['php://filter/resource=evil'],
        ];
    }

    /** @test */
    public function aconfiguredBinaryThatDoesNotExistAnswersNull(): void
    {
        self::assertNull($this->environment()->binaryPath('tools/missing', $this->root, [$this->root]));
    }

    /**
     * A directory is not a binary, however well placed.
     *
     * @test
     */
    public function adirectoryIsNotAcceptedAsABinary(): void
    {
        \mkdir($this->root . '/tools/tw', 0777, true);

        self::assertNull($this->environment()->binaryPath('tools/tw', $this->root, [$this->root]));
    }

    /**
     * A binary outside the project but inside an explicitly allowed root is
     * legitimate — that is what `allowed_roots` is for (CSF-A2).
     *
     * @test
     */
    public function abinaryInsideAnotherAllowedRootIsAccepted(): void
    {
        $binary = $this->plant($this->outside . '/tailwindcss');

        $found = $this->environment()->binaryPath(
            $this->outside . '/tailwindcss',
            $this->root,
            [$this->root, $this->outside]
        );

        self::assertNotNull($found);
        self::assertSame(\realpath($binary), \realpath($found));
    }

    private function environment(): Environment
    {
        return new Environment();
    }

    /**
     * The child gets a complete environment, not an addition to this process's.
     *
     * The four entries are the contract on every platform: `PATH` is ours rather
     * than the web server's, `HOME` and `TMPDIR` point into the directory we are
     * about to delete, and `NO_COLOR` keeps ANSI escapes out of the stderr that
     * becomes diagnostics.
     *
     * @test
     */
    public function thechildEnvironmentAlwaysCarriesTheFourFixedEntries(): void
    {
        $environment = $this->environment()->childEnvironment('/tmp/example');

        self::assertSame('/tmp/example', $environment['HOME']);
        self::assertSame('/tmp/example', $environment['TMPDIR']);
        self::assertSame('1', $environment['NO_COLOR']);
        self::assertArrayHasKey('PATH', $environment);
    }

    /**
     * On POSIX those four are the *whole* environment — nothing is inherited.
     *
     * @test
     */
    public function onPosixTheChildEnvironmentIsExactlyThoseFour(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows carries two machine variables as well; asserted by its own row.');
        }

        $environment = $this->environment()->childEnvironment('/tmp/example');

        self::assertSame(['PATH', 'HOME', 'TMPDIR', 'NO_COLOR'], \array_keys($environment));
        self::assertSame('/usr/bin:/bin', $environment['PATH']);
    }

    /**
     * On Windows `SystemRoot` must be there or nothing runs at all.
     *
     * Not a preference: `cmd.exe` cannot execute a `.cmd` binary without it and
     * exits 1 with empty stdout *and* stderr, which is indistinguishable from a
     * binary that ran and produced nothing. `.cmd` is an explicitly supported
     * form (CCP-A22/A23), so this is a supported configuration failing rather
     * than an exotic one.
     *
     * @test
     */
    public function onWindowsTheChildEnvironmentCarriesSystemRoot(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('SystemRoot is a Windows machine variable.');
        }

        $environment = $this->environment()->childEnvironment('C:/temp/example');

        self::assertArrayHasKey('SystemRoot', $environment);
        self::assertNotSame('', $environment['SystemRoot']);

        // The PHP directory leads PATH so a .cmd shim can find php (CCP-A23).
        self::assertStringStartsWith(\dirname(\PHP_BINARY), $environment['PATH']);
    }

    /**
     * Whatever else is in this process's environment stays in it. Without this,
     * "the child inherits nothing" would rest on reading the implementation.
     *
     * @test
     */
    public function thechildEnvironmentDoesNotInheritThisProcess(): void
    {
        \putenv('TAILWINDPHP_ENV_LEAK=must-not-propagate');

        try {
            self::assertArrayNotHasKey(
                'TAILWINDPHP_ENV_LEAK',
                $this->environment()->childEnvironment('/tmp/example')
            );
        } finally {
            \putenv('TAILWINDPHP_ENV_LEAK');
        }
    }

    /**
     * Create an executable file, making intermediate directories.
     */
    private function plant(string $path): string
    {
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        \file_put_contents($path, "#!/bin/sh\necho fake\n");
        @\chmod($path, 0755);

        return $path;
    }
}
