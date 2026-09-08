<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Console\Application;
use TailwindPhp\Console\Command\InstallCliCommand;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-18 — the binary download** (design doc 06 §install-cli, doc 12; decisions
 * HBC-A12, HBC-A13).
 *
 * This is the one place the library reaches the network, and what it fetches
 * becomes an **executable the library later runs**. So the question is not "does
 * the download work" but "what does it take to make this write a file it should
 * not have written", and every row below is an attempt at that.
 *
 * The download is exercised against a real HTTP server — `php -S` on loopback,
 * serving `tests/Fixtures/release` — rather than a mocked stream. A double
 * would prove the code calls the functions it calls; it would not prove that a
 * tampered body is caught, because the tampering happens in the bytes.
 *
 * **Production is HTTPS-only.** The base URL is a constant with no CLI option;
 * the constructor argument used here is reachable only by building the object,
 * which means already being inside the process. The plain-HTTP loopback server
 * is a property of this test, not of the command.
 *
 * @covers-requirement NFR-SEC-7
 */
final class T18InstallCliTest extends TestCase
{
    private const VERSION = '1.0.0';

    /**
     * sha256 of `tests/Fixtures/release/v1.0.0/tailwindcss-linux-x64`.
     */
    private const GOOD_DIGEST = 'eaf44c904b13f3ff6a44d70c84b4a01efac4d33af5ec1117850d7f19747c2e81';

    /**
     * @var resource|null
     */
    private $server;

    /**
     * @var string|null
     */
    private $base;

    /**
     * @var string|null
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = TempTree::make('tailwindphp-t18');
        $this->base = $this->startServer();

        if ($this->base === null) {
            self::markTestSkipped('could not bind a loopback port for the fixture release server');
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \proc_terminate($this->server);

            // `proc_close()` waits for the child, so terminate first or this
            // blocks until the server exits on its own — which it never does.
            \proc_close($this->server);
        }

        $this->server = null;

        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * The positive control. Without it every refusal below could pass on a
     * command that refuses everything.
     *
     * @test
     */
    public function averifiedBinaryIsInstalled(): void
    {
        [$code, $out] = $this->install(['--platform=linux-x64'], [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]]);

        self::assertSame(0, $code, 'a pinned, matching download should install');
        self::assertStringContainsString(self::GOOD_DIGEST, $out);
        self::assertStringContainsString('verified', $out);

        $dest = $this->root . '/vendor/bin/tailwindcss';

        self::assertFileExists($dest);
        self::assertSame(self::GOOD_DIGEST, \hash_file('sha256', $dest));
        self::assertFileDoesNotExist($dest . '.tmp', 'the temporary file was left behind');

        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::assertSame('0755', \substr(\sprintf('%o', \fileperms($dest)), -4));
        }
    }

    /**
     * One byte of difference is the whole threat: a mirror, a proxy or a
     * compromised release can serve *almost* the right binary.
     *
     * @test
     */
    public function aTamperedDownloadIsRefusedAndDeleted(): void
    {
        // The table pins the good digest; the server is asked for the tampered
        // blob, so the bytes and the expectation disagree by one line.
        [$code, $out, $err] = $this->install(
            ['--platform=linux-arm64'],
            [self::VERSION => ['linux-arm64' => self::GOOD_DIGEST]]
        );

        self::assertSame(1, $code);
        self::assertStringContainsString('Checksum mismatch', $err);
        self::assertSame('', $out, 'nothing may be reported as installed');

        $dest = $this->root . '/vendor/bin/tailwindcss';

        self::assertFileDoesNotExist($dest, 'a tampered binary was installed');
        self::assertFileDoesNotExist($dest . '.tmp', 'the tampered download was left on disk');
    }

    /**
     * An unpinned version is refused **before** the network is touched.
     *
     * @test
     */
    public function anUnpinnedVersionIsRefused(): void
    {
        [$code, $out, $err] = $this->install(
            ['--platform=linux-x64', '--version=9.9.9'],
            [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]]
        );

        self::assertSame(2, $code);
        self::assertStringContainsString('not pinned', $err);
        self::assertSame('', $out);
        self::assertFileDoesNotExist($this->root . '/vendor/bin/tailwindcss');
    }

    /**
     * HBC-A12: `--allow-unverified` does **not** enable an unchecked download.
     * There would be nothing to compare the bytes against, so the flag only
     * makes the refusal explainable — no artefact is written, and the warning
     * is the record.
     *
     * @test
     */
    public function allowUnverifiedStillWritesNothing(): void
    {
        [$code, $out, $err] = $this->install(
            ['--platform=linux-x64', '--version=9.9.9', '--allow-unverified'],
            [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]]
        );

        self::assertSame(2, $code);
        self::assertStringContainsString('nothing to compare', $err);
        self::assertSame('', $out);
        self::assertFileDoesNotExist($this->root . '/vendor/bin/tailwindcss');
    }

    /**
     * HBC-A13: a web request must never reach this, and the refusal happens
     * before a socket is opened.
     *
     * @test
     */
    public function aNonCliSapiIsRefusedBeforeAnyNetworkUse(): void
    {
        [$code, $out, $err] = $this->install(
            ['--platform=linux-x64'],
            [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]],
            'fpm-fcgi'
        );

        self::assertSame(3, $code);
        self::assertStringContainsString('command line', $err);
        self::assertSame('', $out);
        self::assertFileDoesNotExist($this->root . '/vendor/bin/tailwindcss');
    }

    /**
     * Threat T-02: `--dest` is an operator-supplied path that becomes an
     * **executable**, so it is confined to the project like every other path.
     *
     * @dataProvider escapingDestinations
     *
     * @test
     */
    public function aDestinationOutsideTheProjectIsRefused(string $dest): void
    {
        [$code, $out] = $this->install(
            ['--platform=linux-x64', '--dest=' . $dest],
            [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]]
        );

        self::assertSame(2, $code, 'a destination outside the project must be a usage error');
        self::assertSame('', $out);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapingDestinations(): array
    {
        return [
            'parent traversal' => ['../outside/tailwindcss'],
            'deep traversal' => ['../../../../tmp/tailwindcss'],
            'absolute unix path' => ['/usr/local/bin/tailwindcss'],
        ];
    }

    /**
     * Run the command against the fixture server.
     *
     * @param list<string> $arguments
     * @param array<string, array<string, string>> $table
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function install(array $arguments, array $table, string $sapi = 'cli'): array
    {
        $command = new InstallCliCommand(
            (string) $this->root,
            $sapi,
            (string) $this->base,
            Checksums::fromTable($table)
        );

        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $argv = \array_merge(['bin', 'install-cli'], $arguments);
        $code = (new Application([$command]))->run($argv, $stdout, $stderr);

        \rewind($stdout);
        \rewind($stderr);

        return [$code, (string) \stream_get_contents($stdout), (string) \stream_get_contents($stderr)];
    }

    /**
     * Start `php -S` over the fixture release directory.
     *
     * @return string|null The base URL, or null when no port could be bound —
     *                     a restricted CI box is a skip, not a failure.
     */
    private function startServer(): ?string
    {
        $docroot = \dirname(__DIR__) . '/Fixtures/release';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = \random_int(20000, 45000);

            // `bypass_shell` is load-bearing on Windows. Without it `proc_open`
            // runs the command through `cmd.exe`, so `proc_terminate()` kills
            // the shell and leaves `php -S` listening — a suite run leaves one
            // orphaned server per row behind, which is how this test first
            // presented (sixteen stray processes after one run).
            $process = \proc_open(
                \escapeshellarg(\PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . \escapeshellarg($docroot),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );

            if (!\is_resource($process)) {
                continue;
            }

            $this->server = $process;

            if ($this->waitForPort($port)) {
                return 'http://127.0.0.1:' . $port;
            }

            \proc_terminate($process);
            \proc_close($process);
            $this->server = null;
        }

        return null;
    }

    /**
     * The server needs a moment; polling beats a fixed sleep because it is both
     * faster when the box is quick and more patient when it is not.
     */
    private function waitForPort(int $port): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if (\is_resource($socket)) {
                \fclose($socket);

                return true;
            }

            \usleep(100000);
        }

        return false;
    }
}
