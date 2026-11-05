<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\EnvironmentInterface;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolver;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Driver selection (design doc 06, ADR-002) — called on **every** request.
 *
 * Two things are being pinned. The **matrix**: `php` never looks at the CLI,
 * `cli` fails loudly when it cannot be honoured, and `auto` falls back quietly
 * but says why. And the **warm-path cost**: with a cached probe this must be
 * one `stat()` and nothing else, because re-hashing a hundred-megabyte binary
 * per request would be the most expensive thing the library does.
 *
 * @covers-requirement FR-COMP-4
 */
final class DriverResolverTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $binary = '';

    /**
     * @var string
     */
    private $digest = '';

    /**
     * @var RecordingFilesystem
     */
    private $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-driver');
        $this->binary = $this->root . '/vendor/bin/tailwindcss';

        \mkdir($this->root . '/vendor/bin', 0777, true);
        \file_put_contents($this->binary, "#!/bin/sh\necho fake\n");
        @\chmod($this->binary, 0755);

        $this->digest = (string) \hash_file('sha256', $this->binary);
        $this->fs = new RecordingFilesystem();

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ php

    /**
     * `php` is an instruction, not a preference: it must not look at the CLI at
     * all, so a missing or tampered binary is irrelevant to it.
     *
     * @test
     */
    public function thePhpDriverNeverConsultsTheCli(): void
    {
        $this->fs->reset();

        $resolution = $this->resolver(true)->resolve($this->config('php'), null);

        self::assertSame('php', $resolution->compiler()->name());
        self::assertNull($resolution->probe());
        self::assertNull($resolution->reason());
        self::assertSame(0, $this->fs->stats(), 'The php driver touched the filesystem.');
    }

    // ------------------------------------------------------------------ cli

    /** @test */
    public function theCliDriverIsSelectedWhenEverythingHolds(): void
    {
        $resolution = $this->resolver(true)->resolve($this->config('cli'), null);

        self::assertSame('cli-double', $resolution->compiler()->name());
        self::assertNull($resolution->reason(), 'A successful resolution has nothing to explain.');

        $probe = $resolution->probe();

        self::assertIsArray($probe);
        self::assertSame('vendor/bin/tailwindcss', $probe['binary']);
        self::assertSame($this->digest, $probe['sha256']);
    }

    /**
     * Asked for by name, so a problem is an error the operator must see rather
     * than a silent substitution.
     *
     * @test
     */
    public function theCliDriverThrowsWhenProcOpenIsUnavailable(): void
    {
        $this->expectException(DriverUnavailableException::class);

        $this->resolver(true, false)->resolve($this->config('cli'), null);
    }

    /** @test */
    public function theCliDriverThrowsWhenNoBinaryIsFound(): void
    {
        \unlink($this->binary);

        $this->expectException(DriverUnavailableException::class);

        $this->resolver(true)->resolve($this->config('cli'), null);
    }

    /**
     * The T-04 outcome: a binary that exists but does not match a published
     * release must not run.
     *
     * @test
     */
    public function theCliDriverThrowsWhenTheChecksumIsUnknown(): void
    {
        $this->expectException(DriverUnavailableException::class);

        // A resolver whose table does not contain this binary's digest.
        $this->resolver(false)->resolve($this->config('cli'), null);
    }

    // ----------------------------------------------------------------- auto

    /**
     * @test
     *
     * @dataProvider autoFallbacks
     */
    public function autoFallsBackToPhpAndSaysWhy(
        bool $known,
        bool $procOpen,
        bool $removeBinary,
        string $expectedReason
    ): void {
        if ($removeBinary) {
            \unlink($this->binary);
        }

        $resolution = $this->resolver($known, $procOpen)->resolve($this->config('auto'), null);

        self::assertSame('php', $resolution->compiler()->name());
        self::assertNull($resolution->probe());
        self::assertSame('auto: using PHP engine because ' . $expectedReason, $resolution->reason());
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: string}>
     */
    public static function autoFallbacks(): array
    {
        return [
            'proc_open disabled' => [true, false, false, 'proc_open is unavailable on this host'],
            'no binary' => [true, true, true, 'no Tailwind binary was found'],
            'unknown checksum' => [false, true, false, 'the binary does not match a known release checksum'],
        ];
    }

    /**
     * The reason names the function that is missing, not `proc_open` by habit
     * (a free host's `disable_functions` may list only `usleep`).
     *
     * @test
     */
    public function autoNamesTheProcessFunctionThatIsMissing(): void
    {
        $resolution = $this->resolver(true, true, 'usleep')->resolve($this->config('auto'), null);

        self::assertSame('php', $resolution->compiler()->name());
        self::assertSame('auto: using PHP engine because usleep is unavailable on this host', $resolution->reason());
    }

    /** @test */
    public function autoSelectsTheCliWhenEverythingHolds(): void
    {
        $resolution = $this->resolver(true)->resolve($this->config('auto'), null);

        self::assertSame('cli-double', $resolution->compiler()->name());
        self::assertNull($resolution->reason());
    }

    /**
     * An absent or malformed `verify` key must not become a way to run an
     * unverified binary (T-04). Only an explicit `false` opts out.
     *
     * @test
     *
     * @dataProvider verifyValues
     *
     * @param mixed $verify
     */
    public function verificationIsOnUnlessExplicitlyDisabled($verify, bool $expectCli): void
    {
        $config = $this->config('auto');
        $config['verify'] = $verify;

        // A resolver whose table does *not* know this binary.
        $resolution = $this->resolver(false)->resolve($config, null);

        self::assertSame($expectCli ? 'cli-double' : 'php', $resolution->compiler()->name());
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function verifyValues(): array
    {
        return [
            'explicitly false opts out' => [false, true],
            'true keeps verification on' => [true, false],
            'a truthy string is not false' => ['no', false],
            'null is not false' => [null, false],
            'zero is not false' => [0, false],
        ];
    }

    // ------------------------------------------------------------ warm path

    /**
     * The budget of acceptance 6: with a cached probe, resolving costs
     * `function_exists`, `ini_get` and **one `stat`**.
     *
     * **What this test does and does not prove**, corrected after review. It
     * pins the *filesystem-facade* budget: exactly one `stat` and no `read`.
     * It does **not** prove that no hashing happened — `Hash::sha256File()`
     * opens the file directly rather than through `FilesystemInterface`, so a
     * verifier that re-hashed on every request would still show `reads() === 0`
     * here and this test would pass. The "does not re-hash" property is pinned
     * where it can be observed, in `BinaryVerifierTest`
     * (`aMatchingCachedProbeIsReusedWithoutHashing`), against a digest that
     * differs from the file's.
     *
     * Left as a facade-budget assertion rather than rewritten around a spy,
     * because that is a real property worth holding and duplicating the hashing
     * assertion here would put the same guarantee in two places.
     *
     * @test
     */
    public function aWarmResolveCostsOneStatAndNothingElse(): void
    {
        $probe = [
            'binary' => 'vendor/bin/tailwindcss',
            'm' => (int) \filemtime($this->binary),
            's' => (int) \filesize($this->binary),
            'sha256' => $this->digest,
            'version' => '4.1.18',
        ];

        $this->fs->reset();

        $resolution = $this->resolver(true)->resolve($this->config('auto'), $probe);

        self::assertSame('cli-double', $resolution->compiler()->name());
        self::assertSame(0, $this->fs->reads(), 'The warm path read a file.');
        self::assertSame(1, $this->fs->stats(), 'The warm path should stat exactly once.');
        self::assertSame(['stat' => 1], $this->fs->calls());
    }

    /**
     * A stale probe must not be trusted: a changed mtime means the binary may
     * have been swapped, so the digest is recomputed.
     *
     * @test
     */
    public function aProbeWithAStaleMtimeIsNotReused(): void
    {
        $probe = [
            'binary' => 'vendor/bin/tailwindcss',
            'm' => (int) \filemtime($this->binary) - 500,
            's' => (int) \filesize($this->binary),
            // A digest that is *not* the file's, so reuse would be visible.
            'sha256' => \str_repeat('b', 64),
            'version' => '4.1.18',
        ];

        $resolution = $this->resolver(true)->resolve($this->config('auto'), $probe);
        $resolved = $resolution->probe();

        self::assertIsArray($resolved);
        self::assertSame($this->digest, $resolved['sha256'], 'A stale probe was reused.');
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $driver): array
    {
        return [
            'driver' => $driver,
            'binary' => null,
            'verify' => true,
            'projectRoot' => $this->root,
            'allowedRoots' => [$this->root],
        ];
    }

    /**
     * A `driver` that is not exactly one of the three is refused.
     *
     * The dangerous reading was the permissive one: an unrecognised value used
     * to fall through to `auto`, so `'PHP'` — a plausible thing to write, and an
     * explicit request for the engine that spawns nothing — could end up
     * executing the binary. A typo must fail loudly rather than pick a mode the
     * operator did not ask for.
     *
     * @dataProvider unknownDriverValues
     *
     * @test
     */
    public function anUnknownDriverValueIsRejected(string $driver): void
    {
        try {
            $this->resolver(true)->resolve($this->config($driver), null);

            self::fail(\sprintf('Driver "%s" was accepted.', $driver));
        } catch (\InvalidArgumentException $e) {
            // The value came from configuration, so echoing it back is how a
            // config value reaches an error page (T-06). Skipped for the empty
            // string, which is a substring of every message and would make this
            // assertion pass or fail for reasons unrelated to the property.
            if ($driver !== '') {
                self::assertStringNotContainsString($driver, $e->getMessage());
            }

            self::assertStringContainsString('php', $e->getMessage());
        }
    }

    /**
     * A `driver` of the wrong *type* is refused too.
     *
     * It used to fall through to `auto` exactly as an unknown string did, with
     * the same consequence: a config that meant something else could end up
     * executing the binary. `null` is included because an explicitly-null value
     * is a mistake worth reporting, and `isset()` cannot tell it from an absent
     * key — which is why the resolver uses `array_key_exists()`.
     *
     * @param mixed $driver
     *
     * @dataProvider nonStringDriverValues
     *
     * @test
     */
    public function anonStringDriverValueIsRejected($driver): void
    {
        $config = $this->config('auto');
        $config['driver'] = $driver;

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver(true)->resolve($config, null);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringDriverValues(): array
    {
        return [
            'array' => [['cli']],
            'int' => [1],
            'bool' => [true],
            'null' => [null],
        ];
    }

    /**
     * An *absent* key is the one case that legitimately means "use the default",
     * so it must not throw — otherwise the check above would break every caller
     * that simply omits the key.
     *
     * @test
     */
    public function anAbsentDriverKeyStillDefaultsToAuto(): void
    {
        $config = $this->config('auto');

        unset($config['driver']);

        self::assertSame('cli-double', $this->resolver(true)->resolve($config, null)->compiler()->name());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownDriverValues(): array
    {
        return [
            'wrong case' => ['PHP'],
            'trailing space' => ['cli '],
            'nonsense' => ['nonsense'],
            'empty' => [''],
        ];
    }

    /**
     * @param bool $known Whether the checksum table recognises the binary.
     * @param bool $procOpen Whether the host reports `proc_open` as usable.
     */
    private function resolver(bool $known, bool $procOpen = true, ?string $missing = null): DriverResolver
    {
        $digest = $known ? $this->digest : \str_repeat('a', 64);
        $table = ['4.1.18' => ['linux-x64' => $digest]];

        return new DriverResolver(
            $this->environment($missing ?? ($procOpen ? null : 'proc_open')),
            new BinaryVerifier(Checksums::fromTable($table), $this->fs, $this->root),
            new PhpEngineCompiler(),
            function (string $binaryPath, string $version): CompilerInterface {
                return self::cliDouble();
            }
        );
    }

    /**
     * `Environment` answers from `ini_get()` and `function_exists()`, neither of
     * which a test can set, and it is `final` by house rule — hence
     * `EnvironmentInterface`. The double delegates everything except the one
     * answer under test, so path confinement stays genuine rather than stubbed.
     *
     * Both answers are the double's, not the host's: no row here spawns (the CLI
     * compiler is {@see self::cliDouble()}), so the "cli selected" rows run and
     * mean the same on the restricted shared-host cell too (TASK-0061).
     */
    private function environment(?string $missing): EnvironmentInterface
    {
        return new class ($missing) implements EnvironmentInterface {
            /**
             * @var Environment
             */
            private $real;

            /**
             * @var string|null
             */
            private $missing;

            public function __construct(?string $missing)
            {
                $this->real = new Environment();
                $this->missing = $missing;
            }

            public function processFunctionAvailable(): bool
            {
                return $this->missing === null;
            }

            public function missingProcessFunction(): ?string
            {
                return $this->missing;
            }

            /**
             * @param list<string> $allowedRoots
             */
            public function binaryPath(?string $configured, string $projectRoot, array $allowedRoots): ?string
            {
                return $this->real->binaryPath($configured, $projectRoot, $allowedRoots);
            }

            public function platform(): string
            {
                return $this->real->platform();
            }

            /**
             * @return array<string, string>
             */
            public function childEnvironment(string $tmpDir): array
            {
                return $this->real->childEnvironment($tmpDir);
            }
        };
    }

    private static function cliDouble(): CompilerInterface
    {
        return new class () implements CompilerInterface {
            public function name(): string
            {
                return 'cli-double';
            }

            public function version(): string
            {
                return '4.1.18';
            }

            public function compile(
                string $entryCss,
                \TailwindPhp\Engine\Import\ImportResolverInterface $imports,
                array $candidates,
                \TailwindPhp\Compiler\CompileOptions $options
            ): \TailwindPhp\Compiler\CompileResult {
                return new \TailwindPhp\Compiler\CompileResult(
                    '',
                    new \TailwindPhp\Engine\Diagnostic\DiagnosticBag(),
                    'cli-double',
                    '4.1.18',
                    0.0,
                    [],
                    []
                );
            }
        };
    }
}
