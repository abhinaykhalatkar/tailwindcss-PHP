<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Console\Application;
use TailwindPhp\Console\Command\InstallCliCommand;
use TailwindPhp\Console\Output;
use TailwindPhp\Tests\Support\FailingStream;
use TailwindPhp\Tests\Support\RequiresSpawning;
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
        FailingStream::unregister();

        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * The stream path (hosts without cURL): a read that fails after the
     * release opened is a failed download, never a short body that the digest
     * then judges. Driven through the private copy loop with a wrapper source,
     * because this host has cURL and user wrappers do not reach cURL.
     *
     * @test
     */
    public function theStreamPathTreatsAFailedReadAsAFailedDownload(): void
    {
        FailingStream::register();
        [$ok, $written, $err] = $this->copy(FailingStream::SCHEME . '://read-fails', 'php://memory');

        self::assertFalse($ok);
        self::assertSame(0, $written);
        self::assertStringContainsString('Download failed: reading the release failed.', $err);
    }

    /**
     * A short write (a full disk) on the stream path: the temporary file would
     * be truncated while the digest of the whole body still matched, so a
     * truncated binary would pass verification. The write is checked, and the
     * digest only sees what was written. The cURL path already aborts on a short
     * write: its write callback returns what `fwrite()` wrote, and cURL fails the
     * transfer when that is not the chunk length.
     *
     * @test
     */
    public function theStreamPathTreatsAShortWriteAsAFailedDownload(): void
    {
        FailingStream::register();
        $source = 'data://application/octet-stream;base64,' . \base64_encode(\str_repeat('x', 1000));
        [$ok, , $err, $digest] = $this->copy($source, FailingStream::SCHEME . '://short-write');

        self::assertFalse($ok);
        self::assertStringContainsString('Download failed: writing the temporary file failed', $err);
        self::assertSame(\hash('sha256', ''), $digest, 'nothing short-written was hashed');
    }

    /**
     * After the close, the temporary file must hold every byte that was hashed,
     * on both paths. `fclose()` reports true whatever its final flush did, so
     * the size is the check that can fire: a file shorter than the body is a
     * failed download, never a binary renamed into place.
     *
     * @test
     */
    public function aTemporaryFileShorterThanTheBodyIsAFailedDownload(): void
    {
        $file = $this->root . '/short.download';
        \file_put_contents($file, \str_repeat('x', 10));

        [$ok, $err] = $this->writtenInFull($file, 20);

        self::assertFalse($ok);
        self::assertStringContainsString('Download failed: the temporary file holds 10 of 20 bytes.', $err);
    }

    /** @test */
    public function aTemporaryFileHoldingTheWholeBodyPasses(): void
    {
        $file = $this->root . '/whole.download';
        \file_put_contents($file, \str_repeat('x', 20));

        [$ok, $err] = $this->writtenInFull($file, 20);

        self::assertTrue($ok);
        self::assertSame('', $err);
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
     * A digest differing only in its **last** hex character.
     *
     * A prefix comparison, a truncated compare, or a `strncmp` with the wrong
     * length would all pass the row above — the tampered blob differs from the
     * first byte. This one differs only at the very end, so nothing short of a
     * full constant-time comparison survives it.
     *
     * @test
     */
    public function aDigestDifferingInItsLastCharacterIsRefused(): void
    {
        $almost = \substr(self::GOOD_DIGEST, 0, -1) . (\substr(self::GOOD_DIGEST, -1) === 'f' ? 'e' : 'f');

        self::assertNotSame(self::GOOD_DIGEST, $almost);
        self::assertSame(\strlen(self::GOOD_DIGEST), \strlen($almost));

        [$code, $out, $err] = $this->install(
            ['--platform=linux-x64'],
            [self::VERSION => ['linux-x64' => $almost]]
        );

        self::assertSame(1, $code);
        self::assertStringContainsString('Checksum mismatch', $err);
        self::assertSame('', $out);
        self::assertFileDoesNotExist($this->root . '/vendor/bin/tailwindcss');
    }

    /**
     * Threat T-14: a **junction** at `vendor/bin` pointing outside the project.
     *
     * The path still reads as being inside — `<root>/vendor/bin/tailwindcss` —
     * so a lexical confinement check passes it and the executable lands
     * wherever the link goes. Only resolving through the filesystem catches it.
     *
     * @test
     */
    public function aJunctionedDestinationIsRefused(): void
    {
        $root = (string) $this->root;
        $outside = \dirname($root) . '/t18-outside-' . \getmypid();

        if (!@\mkdir($outside, 0777, true)) {
            self::markTestSkipped('could not create the outside directory');
        }

        try {
            \mkdir($root . '/vendor', 0777, true);

            if (!$this->link($root . '/vendor/bin', $outside)) {
                self::markTestSkipped('this host cannot create a junction or symlink');
            }

            [$code, $out] = $this->install(
                ['--platform=linux-x64'],
                [self::VERSION => ['linux-x64' => self::GOOD_DIGEST]]
            );

            self::assertSame(2, $code, 'a junctioned destination must be refused');
            self::assertSame('', $out);
            self::assertFileDoesNotExist($outside . '/tailwindcss', 'the binary escaped the project');
        } finally {
            @\unlink($outside . '/tailwindcss');
            @\rmdir($outside);
        }
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
            $this->base(),
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
     * The fixture release server's base URL, started on first use; a host that
     * cannot spawn (the restricted cell) or bind a port skips the row.
     */
    private function base(): string
    {
        if ($this->base === null) {
            RequiresSpawning::check();
            $this->base = $this->startServer();

            if ($this->base === null) {
                self::markTestSkipped('could not bind a loopback port for the fixture release server');
            }
        }

        return $this->base;
    }

    /**
     * The installer's size check after the close, run on its own.
     *
     * @return array{0: bool, 1: string} ok, stderr
     */
    private function writtenInFull(string $file, int $written): array
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $method = new \ReflectionMethod(InstallCliCommand::class, 'writtenInFull');
        $ok = $method->invoke(
            new InstallCliCommand((string) $this->root),
            $file,
            $written,
            new Output($stdout, $stderr, false),
            'https://example.invalid/release'
        );

        \rewind($stderr);

        return [(bool) $ok, (string) \stream_get_contents($stderr)];
    }

    /**
     * The installer's stream copy loop, run on its own.
     *
     * @return array{0: bool, 1: int, 2: string, 3: string} ok, bytes counted, stderr, digest of what was hashed
     */
    private function copy(string $sourceUrl, string $targetUrl): array
    {
        $source = \fopen($sourceUrl, 'rb');
        $target = \fopen($targetUrl, 'wb');
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        self::assertIsResource($source);
        self::assertIsResource($target);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $hash = \hash_init('sha256');
        $written = 0;
        $method = new \ReflectionMethod(InstallCliCommand::class, 'copyStream');
        $ok = $method->invokeArgs(
            new InstallCliCommand((string) $this->root),
            [$source, $target, $hash, &$written, new Output($stdout, $stderr, false), 'https://example.invalid/release']
        );

        \fclose($source);
        \fclose($target);
        \rewind($stderr);

        return [(bool) $ok, $written, (string) \stream_get_contents($stderr), \hash_final($hash)];
    }

    /**
     * A directory junction on Windows, a symlink elsewhere.
     *
     * Windows `symlink()` needs a privilege ordinary accounts lack, so the
     * junction is made with `mklink /J`, which does not. Either way the test
     * skips rather than fails when the host refuses.
     */
    private function link(string $link, string $target): bool
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            return @\symlink($target, $link);
        }

        // `mklink` runs through `proc_open`: skipped where the host disables it.
        RequiresSpawning::check();

        $command = 'cmd /c mklink /J '
            . \escapeshellarg(\str_replace('/', '\\', $link))
            . ' '
            . \escapeshellarg(\str_replace('/', '\\', $target));

        $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            \stream_get_contents($pipe);
            \fclose($pipe);
        }

        \proc_close($process);

        return \is_dir($link);
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
