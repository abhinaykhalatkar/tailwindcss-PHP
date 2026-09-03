<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Support\FilesystemInterface;

final class BinaryVerifierTest extends TestCase
{
    /**
     * The bytes of the temp file standing in for the binary. Short, because the
     * point of every test here is *whether* it gets hashed, not how fast.
     */
    private const CONTENT = 'tailwind';

    /**
     * A well-formed digest that is not the digest of self::CONTENT. Recording it
     * in a cached probe is how the "did not re-hash" tests are proved: if the
     * verifier hashed the file it would return something else.
     */
    private const STALE_DIGEST = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private const MTIME = 1700000000;

    private const SIZE = 104857600;

    private const NAME = 'vendor/bin/tailwindcss';

    /**
     * @var list<string>
     */
    private $tempFiles = [];

    /**
     * @var list<string>
     */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }

        foreach (\array_reverse($this->tempDirs) as $dir) {
            if (\is_dir($dir)) {
                \rmdir($dir);
            }
        }

        $this->tempFiles = [];
        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(BinaryVerifier::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function theProjectRelativeNameIsRecorded(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, true);

        self::assertSame(self::NAME, $probe->binary());
    }

    /**
     * The warm path, and the whole reason `driver_probe` exists: a ~100 MB
     * binary must not be re-read on every request. Proved by recording a digest
     * the file does not actually have — the cached value coming back is only
     * possible if `Hash::sha256File()` was never called.
     *
     * @test
     */
    public function aMatchingCachedProbeIsReusedWithoutHashing(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, self::cachedProbe(), true);

        self::assertSame(self::STALE_DIGEST, $probe->sha256());
    }

    /**
     * Decision CCP-A4: the version is cached with the digest precisely so that
     * the warm path costs no process spawn.
     *
     * @test
     */
    public function aMatchingCachedProbeCarriesTheCachedVersion(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, self::cachedProbe(), true);

        self::assertSame('4.1.18', $probe->version());
    }

    /** @test */
    public function aChangedMtimeForcesAReHash(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['m'] = self::MTIME - 1;

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, $cached, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /**
     * Size is checked as well as mtime because a same-second replacement leaves
     * the mtime untouched, and a stale digest is how an unverified binary would
     * reach `proc_open` (threat T-04).
     *
     * @test
     */
    public function aChangedSizeForcesAReHash(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['s'] = self::SIZE - 1;

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, $cached, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /** @test */
    public function aDifferentBinaryNameForcesAReHash(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['binary'] = 'bin/tailwindcss';

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, $cached, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /** @test */
    public function withoutACachedProbeTheFileIsHashed(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /**
     * `BinaryVerifier` never spawns a process, so a cold probe has nothing to
     * put in `version` and says so rather than guessing (CCP-A4).
     *
     * @test
     */
    public function aColdProbeLeavesTheVersionEmpty(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, true);

        self::assertSame('', $probe->version());
    }

    /**
     * The manifest is written to a directory the web server can write, so a
     * probe is untrusted input: a `sha256` that is not a digest must not be
     * reused as one.
     *
     * @test
     */
    public function aProbeWhoseDigestIsMalformedForcesAReHash(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['sha256'] = 'not-a-digest';

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, $cached, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /** @test */
    public function aProbeWhoseMtimeIsAStringForcesAReHash(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['m'] = (string) self::MTIME;

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, $cached, true);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /** @test */
    public function aPinnedDigestIsVerified(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $checksums = Checksums::fromTable(['4.1.18' => ['linux-x64' => self::realDigest()]]);

        $probe = $this->verifier($root, $checksums)->verify($path, null, true);

        self::assertTrue($probe->verified());
    }

    /**
     * Threat T-04: the file on disk is not the file upstream published, so it
     * does not get to run.
     *
     * @test
     */
    public function aTamperedBinaryIsNotVerified(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $checksums = Checksums::fromTable([
            '4.1.18' => ['linux-x64' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ]);

        $probe = $this->verifier($root, $checksums)->verify($path, null, true);

        self::assertFalse($probe->verified());
    }

    /**
     * `cli.verify: false` is the operator saying "I know what this binary is".
     * The table is not consulted at all, so a private build needs no entry.
     *
     * @test
     */
    public function verificationDisabledAcceptsAnUnknownDigest(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, false);

        self::assertTrue($probe->verified());
    }

    /**
     * The digest is still recorded when verification is off, so that turning it
     * back on does not need a re-hash.
     *
     * @test
     */
    public function verificationDisabledStillRecordsTheDigest(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, false);

        self::assertSame(self::realDigest(), $probe->sha256());
    }

    /** @test */
    public function aMissingBinaryIsReportedAsDriverUnavailable(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $verifier = new BinaryVerifier(self::emptyTable(), self::filesystem(null), $root);

        $this->expectException(DriverUnavailableException::class);

        $verifier->verify($path, null, true);
    }

    /**
     * Decision CCP-A3: a binary outside the project root has no project-relative
     * name. That is recorded as null, not treated as a failure — one shared copy
     * under `/usr/local/bin` is an ordinary setup.
     *
     * @test
     */
    public function aBinaryOutsideTheProjectHasNoName(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root . '/elsewhere', self::emptyTable())
            ->verify($path, null, true);

        self::assertNull($probe->binary());
    }

    /** @test */
    public function aBinaryOutsideTheProjectIsOtherwiseComplete(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root . '/elsewhere', self::emptyTable())
            ->verify($path, null, false);

        self::assertSame(
            [
                'binary' => null,
                'm' => self::MTIME,
                's' => self::SIZE,
                'sha256' => self::realDigest(),
                'version' => '',
            ],
            $probe->toArray()
        );
    }

    /**
     * An out-of-project binary has no project-relative name, so `(null, m, s)`
     * would match *any* such binary with the same mtime and size — a narrow but
     * real way for a cached digest to stand in for different bytes. That
     * configuration therefore always re-hashes (CCP-A3, ruling of 2026-09-03).
     *
     * The cost is one `sha256File` per request, paid only by the out-of-project
     * case, which an operator opts into explicitly by pointing `cli.binary`
     * outside the project.
     *
     * @test
     */
    public function aNamelessCachedProbeIsNeverReusedAndAlwaysReHashes(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $cached = self::cachedProbe();
        $cached['binary'] = null;

        $probe = $this->verifier($root . '/elsewhere', self::emptyTable())
            ->verify($path, $cached, true);

        // The stale digest is what would come back if the probe had been reused;
        // the real one is proof the file was hashed instead.
        self::assertNotSame(self::STALE_DIGEST, $probe->sha256(), 'A nameless cached probe was reused.');
        self::assertSame(\hash('sha256', self::CONTENT), $probe->sha256());
    }

    /** @test */
    public function theStatIsWhatTheProbeRecords(): void
    {
        [$root, $path] = $this->projectWithBinary();

        $probe = $this->verifier($root, self::emptyTable())
            ->verify($path, null, true);

        self::assertSame(self::MTIME, $probe->m());
        self::assertSame(self::SIZE, $probe->s());
    }

    private function verifier(string $projectRoot, Checksums $checksums): BinaryVerifier
    {
        return new BinaryVerifier(
            $checksums,
            self::filesystem(['mtime' => self::MTIME, 'size' => self::SIZE]),
            $projectRoot
        );
    }

    /**
     * A cached probe that agrees with what {@see self::verifier()} will compute,
     * carrying a digest the file does not have.
     *
     * @return array<string, mixed>
     */
    private static function cachedProbe(): array
    {
        return [
            'binary' => self::NAME,
            'm' => self::MTIME,
            's' => self::SIZE,
            'sha256' => self::STALE_DIGEST,
            'version' => '4.1.18',
        ];
    }

    private static function realDigest(): string
    {
        return \hash('sha256', self::CONTENT);
    }

    private static function emptyTable(): Checksums
    {
        return Checksums::fromTable([]);
    }

    /**
     * A real file at `<temp>/twphp-verifier-<hex>/vendor/bin/tailwindcss`.
     *
     * Real because `Hash::sha256File()` reads the disk directly rather than
     * going through `FilesystemInterface` — a fake that only pretended the file
     * existed would prove nothing about the hashing path.
     *
     * @return array{0: string, 1: string} Project root, then absolute binary path.
     */
    private function projectWithBinary(): array
    {
        $root = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/twphp-verifier-' . \bin2hex(\random_bytes(8));

        $directory = $root . '/vendor/bin';

        if (!\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            self::fail('Could not create the temporary project directory.');
        }

        $this->tempDirs[] = $root;
        $this->tempDirs[] = $root . '/vendor';
        $this->tempDirs[] = $directory;

        $path = $directory . '/tailwindcss';

        if (\file_put_contents($path, self::CONTENT) === false) {
            self::fail('Could not create the temporary binary.');
        }

        $this->tempFiles[] = $path;

        return [$root, $path];
    }

    /**
     * A filesystem whose `stat()` is the only method the verifier may touch;
     * everything else throws, so an accidental extra syscall shows up as a
     * failure rather than as a quiet regression on the warm path.
     *
     * @param array{mtime: int, size: int}|null $stat Null ⇒ nothing is there.
     */
    private static function filesystem(?array $stat): FilesystemInterface
    {
        return new class ($stat) implements FilesystemInterface {
            /**
             * @var array{mtime: int, size: int}|null
             */
            private $stat;

            /**
             * @param array{mtime: int, size: int}|null $stat
             */
            public function __construct(?array $stat)
            {
                $this->stat = $stat;
            }

            public function exists(string $path): bool
            {
                throw new \RuntimeException('exists() must not be called.');
            }

            public function isFile(string $path): bool
            {
                throw new \RuntimeException('isFile() must not be called.');
            }

            public function isDir(string $path): bool
            {
                throw new \RuntimeException('isDir() must not be called.');
            }

            public function stat(string $path): ?array
            {
                return $this->stat;
            }

            public function read(string $path, int $maxBytes): string
            {
                throw new \RuntimeException('read() must not be called.');
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
                throw new \RuntimeException('mkdir() must not be called.');
            }

            public function touch(string $path, ?int $mtime = null): void
            {
                throw new \RuntimeException('touch() must not be called.');
            }

            public function unlink(string $path): bool
            {
                throw new \RuntimeException('unlink() must not be called.');
            }

            public function clearStatCache(string $path): void
            {
                throw new \RuntimeException('clearStatCache() must not be called.');
            }
        };
    }
}
