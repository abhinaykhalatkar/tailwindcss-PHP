<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolver;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-04 — a binary whose bytes changed must not be executed** (design doc 12;
 * NFR-SEC-3).
 *
 * `BinaryVerifierTest` already pins the verifier's mechanics: which digest is
 * computed, when a cached probe is reused, what `verified` comes out as. None of
 * that is the security property. The property is the *consequence* — that a
 * tampered binary is never handed to `proc_open` — and a verifier can be
 * perfectly correct while a resolver runs the file anyway.
 *
 * So these tests do not inspect a `ProbeResult`. They put a real, runnable binary
 * on disk, resolve a driver the way the pipeline does, and then ask the only
 * question that matters: **did the process start?** The fixture answers it
 * itself, by writing a record the moment it is executed — evidence from inside
 * the child rather than a belief held on this side.
 *
 * The control case is what keeps the rest honest: if the genuine binary did not
 * run, every "it did not run" assertion below would pass for the wrong reason.
 *
 * @covers-requirement NFR-SEC-3
 */
final class T04BinaryTamperTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $cacheDir = '';

    /**
     * Absolute path of the binary under test — a copy, so tampering with it
     * cannot damage the shared fixture.
     *
     * @var string
     */
    private $binary = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t04');
        $this->cacheDir = $this->root . '/cache';

        \mkdir($this->cacheDir, 0777, true);
        \mkdir($this->root . '/vendor/bin', 0777, true);

        $this->binary = $this->installBinary();

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * The control. A binary whose digest is registered is selected *and runs*.
     *
     * Without this the three tests below are vacuous: a harness that could never
     * execute anything would satisfy all of them.
     *
     * @test
     */
    public function agenuineBinaryIsSelectedAndActuallyRuns(): void
    {
        $resolution = $this->resolve('cli', $this->digest());

        self::assertSame('cli', $resolution->compiler()->name());

        $this->compileWith($resolution->compiler());

        self::assertTrue($this->binaryWasExecuted(), 'The genuine binary never ran, so this suite proves nothing.');
    }

    /**
     * One flipped byte and the driver is refused outright.
     *
     * @test
     */
    public function atamperedBinaryIsRefusedUnderAnExplicitCliDriver(): void
    {
        $genuine = $this->digest();

        $this->tamper();

        self::assertNotSame($genuine, $this->digest(), 'The tamper did not change the file.');

        try {
            $this->resolve('cli', $genuine);

            self::fail('A binary with an unknown digest was accepted as the cli driver.');
        } catch (DriverUnavailableException $e) {
            self::assertStringNotContainsString(
                $this->root,
                $e->getMessage(),
                'The refusal leaked an absolute path (T-06).'
            );
        }

        self::assertFalse($this->binaryWasExecuted(), 'A tampered binary was executed.');
    }

    /**
     * Under `auto` the request still succeeds — on the PHP engine. Falling back
     * rather than failing is the point of `auto`, but it must not be a quiet
     * downgrade: the reason has to say why, because that string is what a
     * `doctor` command shows an operator whose binary was swapped.
     *
     * @test
     */
    public function atamperedBinaryFallsBackToThePhpEngineUnderAuto(): void
    {
        $genuine = $this->digest();

        $this->tamper();

        $resolution = $this->resolve('auto', $genuine);

        self::assertSame('php', $resolution->compiler()->name());
        self::assertNotNull($resolution->reason(), 'A silent downgrade tells an operator nothing.');
        self::assertStringNotContainsString($this->root, (string) $resolution->reason());

        $this->compileWith($resolution->compiler());

        self::assertFalse($this->binaryWasExecuted(), 'A tampered binary was executed on the fallback path.');
    }

    /**
     * The opt-out runs it. This is the test that proves the checksum is doing the
     * work above: with the identical tampered file, flipping one config flag is
     * enough to reach `proc_open`. If this failed, the refusals above might be
     * caused by anything at all.
     *
     * @test
     */
    public function verifyFalseIsWhatMakesTheDifference(): void
    {
        $genuine = $this->digest();

        $this->tamper();

        $resolution = $this->resolve('cli', $genuine, false);

        self::assertSame('cli', $resolution->compiler()->name());

        $this->compileWith($resolution->compiler());

        self::assertTrue(
            $this->binaryWasExecuted(),
            'With verification off the binary should run — otherwise the refusals above prove nothing.'
        );
    }

    /**
     * A tamper that preserves size *and* mtime, with a cached probe on hand.
     *
     * This is the honest edge of the design. The probe cache is keyed on
     * `(binary, mtime, size)` so that a warm request costs one `stat` instead of
     * hashing a 100 MB file on every page load (CCP-A4). An attacker who can
     * write the binary can also `touch` it back, so the cache can be made to
     * describe bytes that are no longer there.
     *
     * The mitigation is not in the verifier — it is that an attacker who can
     * already write `vendor/bin` has won regardless (T-04's stated boundary), and
     * that the cache is a *cache*: any legitimate install or upgrade moves mtime
     * and re-hashes. Asserting the observed behaviour rather than the behaviour
     * one might wish for is what keeps this documented instead of forgotten.
     *
     * @test
     */
    public function acachedProbeIsTrustedOnStatAloneWhichIsAdeliberateBound(): void
    {
        $genuine = $this->digest();
        $stat = \stat($this->binary);

        self::assertIsArray($stat);

        $probe = [
            'binary' => 'vendor/bin/' . \basename($this->binary),
            'm' => $stat['mtime'],
            's' => $stat['size'],
            'sha256' => $genuine,
            'version' => '4.1.18',
            'verified' => true,
        ];

        $this->tamperPreservingStat();

        self::assertNotSame($genuine, $this->digest(), 'The tamper did not change the bytes.');
        self::assertSame($stat['size'], \filesize($this->binary), 'The tamper changed the size.');
        self::assertSame($stat['mtime'], \filemtime($this->binary), 'The tamper did not restore mtime.');

        $resolution = $this->resolve('cli', $genuine, true, $probe);

        // Documented, not endorsed: the stat matched, so the cached digest stood
        // in for the file and the changed bytes were not noticed.
        self::assertSame(
            'cli',
            $resolution->compiler()->name(),
            'Behaviour changed: a stat-identical tamper is now caught. That is an improvement — '
            . 'update this test and the CCP-A4 note rather than reverting it.'
        );

        // And the moment anything legitimate touches the file, it is re-hashed
        // and refused. The cache is a cache, not a trust store.
        // clearstatcache is not optional here: PHP caches stat results per
        // request, so without it `touch()` is invisible to the very next
        // `filemtime()` and this assertion passes for the wrong reason.
        // Measured — the stale read returns the pre-touch mtime.
        \touch($this->binary, $stat['mtime'] + 10);
        \clearstatcache(true, $this->binary);

        try {
            $this->resolve('cli', $genuine, true, $probe);

            self::fail('A stale probe survived an mtime change.');
        } catch (DriverUnavailableException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    /**
     * The allowlist is flat: `Checksums::isKnown()` walks every digest in the
     * table with no version or platform gate, so a binary pinned under a
     * *different* release — or a different operating system — is accepted here.
     *
     * That follows from the class's own documentation and is defensible (a digest
     * identifies bytes, and those bytes were official once). Two consequences are
     * worth writing down rather than rediscovering:
     *
     * - removing a release from `resources/checksums.json` is the *only* way to
     *   stop accepting binaries from it, and
     * - a table that is never pruned keeps every historical build executable
     *   indefinitely, including one with a known CVE.
     *
     * Asserted as observed. If this is ever tightened to gate on the resolved
     * version and platform, this test should fail — and that failure is the
     * improvement, not a regression.
     *
     * @test
     */
    public function adigestPinnedUnderAnotherReleaseOrPlatformIsStillAccepted(): void
    {
        $fs = new Filesystem();
        // A well-formed but *different* release, and a platform this host is
        // not. The keys have to be well-formed now — `fromTable()` validates
        // them — so this pins the property that actually remains: the digest
        // lookup itself is flat.
        $checksums = Checksums::fromTable([
            '0.0.1' => ['macos-arm64' => $this->digest()],
        ]);

        $resolver = new DriverResolver(
            new Environment(),
            new BinaryVerifier($checksums, $fs, $this->root),
            new PhpEngineCompiler(),
            function (string $binaryPath, string $version): CompilerInterface {
                return new PhpEngineCompiler();
            }
        );

        $resolution = $resolver->resolve(
            [
                'driver' => 'cli',
                'binary' => $this->binary,
                'verify' => true,
                'projectRoot' => $this->root,
                'allowedRoots' => [$this->root],
                'cacheDir' => $this->cacheDir,
                'timeout' => 20.0,
            ],
            null
        );

        self::assertNotNull(
            $resolution->probe(),
            'A digest pinned under any release is accepted — see this test\'s docblock.'
        );
    }

    // --------------------------------------------------------------- driving

    /**
     * Resolve a driver the way the pipeline does.
     *
     * @param array<string, mixed>|null $cachedProbe
     */
    private function resolve(
        string $driver,
        string $knownDigest,
        bool $verify = true,
        ?array $cachedProbe = null
    ): \TailwindPhp\Compiler\DriverResolution {
        $fs = new Filesystem();
        $checksums = Checksums::fromTable(['4.1.18' => [(new Environment())->platform() => $knownDigest]]);

        $resolver = new DriverResolver(
            new Environment(),
            new BinaryVerifier($checksums, $fs, $this->root),
            new PhpEngineCompiler(),
            function (string $binaryPath, string $version) use ($fs): CompilerInterface {
                return new StandaloneCliCompiler(
                    $binaryPath,
                    $version,
                    $this->cacheDir,
                    20.0,
                    new Process(),
                    $fs,
                    $this->root,
                    new AtomicWriter($this->root),
                    new Environment()
                );
            }
        );

        return $resolver->resolve(
            [
                'driver' => $driver,
                'binary' => $this->binary,
                'verify' => $verify,
                'projectRoot' => $this->root,
                'allowedRoots' => [$this->root],
                'cacheDir' => $this->cacheDir,
                'timeout' => 20.0,
            ],
            $cachedProbe
        );
    }

    /**
     * Drive a compile, ignoring whatever it produces — these tests care only
     * about whether a process started.
     */
    private function compileWith(CompilerInterface $compiler): void
    {
        try {
            $compiler->compile(
                '@import "tailwindcss";',
                new InMemoryImportResolver([]),
                ['flex'],
                new CompileOptions(false, false)
            );
        } catch (\Throwable $e) {
            // A tampered binary may also fail for unrelated reasons; the
            // assertion is always about the record, never about the outcome.
        }
    }

    /**
     * True when the fixture recorded a run of its own accord.
     *
     * `scandir`, never `glob`: the temp directories are dot-prefixed and `glob()`
     * silently does not match those.
     */
    private function binaryWasExecuted(): bool
    {
        $entries = \scandir($this->cacheDir);

        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if (\substr($entry, -10) === '.argv.json') {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- binary

    /**
     * Copy the fixture to `vendor/bin` so the tamper is local to this test.
     *
     * @return string Absolute path of the binary the resolver should pick up.
     */
    private function installBinary(): string
    {
        $source = \dirname(__DIR__) . '/Fixtures/bin/fake-tailwindcss';
        $target = $this->root . '/vendor/bin/tailwindcss';

        \copy($source, $target);
        \chmod($target, 0755);

        if (\DIRECTORY_SEPARATOR !== '\\') {
            return $target;
        }

        // The shim runs the sibling script by name, so both have to travel; the
        // shim is the file the resolver verifies and therefore the one to tamper.
        \copy($source . '.cmd', $target . '.cmd');
        \copy($source, $this->root . '/vendor/bin/fake-tailwindcss');

        return $target . '.cmd';
    }

    private function digest(): string
    {
        \clearstatcache(true, $this->binary);

        return (string) \hash_file('sha256', $this->binary);
    }

    /**
     * Append a byte — the crudest possible modification, which is the point.
     */
    private function tamper(): void
    {
        \file_put_contents($this->binary, "\n", \FILE_APPEND);
        \clearstatcache(true, $this->binary);
    }

    /**
     * Change a byte without changing size or mtime.
     */
    private function tamperPreservingStat(): void
    {
        $stat = \stat($this->binary);
        $contents = (string) \file_get_contents($this->binary);

        self::assertIsArray($stat, 'The binary vanished mid-test.');

        // A comment marker exists in both the script and the shim; swapping its
        // case changes the bytes and nothing else.
        $modified = \strpos($contents, '@echo off') !== false
            ? \str_replace('@echo off', '@ECHO off', $contents)
            : \str_replace('#!/usr/bin/env php', '#!/usr/bin/env PHP', $contents);

        self::assertNotSame($contents, $modified, 'Nothing was changed.');
        self::assertSame(\strlen($contents), \strlen($modified), 'The replacement changed the length.');

        \file_put_contents($this->binary, $modified);
        \touch($this->binary, $stat['mtime']);
        \clearstatcache(true, $this->binary);
    }
}
