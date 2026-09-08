<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-17 — overly permissive cache files** (design doc 12, NFR-SEC-8).
 *
 * On shared hosting the cache directory is often reachable by other accounts on
 * the same machine. A world-writable artefact is then not a hygiene problem but
 * an injection point: someone rewrites `app.<fp>.css` and the site serves their
 * bytes to every visitor.
 *
 * Two properties matter, and the second is the subtle one:
 *
 * - Files land at `cache_file_mode` (0644) and directories at `cache_dir_mode`
 *   (0755) — never wider.
 * - The mode is set on the **temp file, before the rename** (design doc 05
 *   §1.4). Setting it afterwards leaves a window in which the artefact is
 *   readable under its final name at whatever the umask happened to give it.
 *
 * POSIX modes are not modelled on Windows, so every assertion here skips there
 * with a reason rather than pretending to pass.
 *
 * @covers-requirement FR-CACHE-5 NFR-SEC-8
 */
final class T17FilePermissionsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file modes are not modelled on Windows; NTFS ACLs govern access there.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @test */
    public function writtenArtefactsAreNotGroupOrWorldWritable(): void
    {
        $store = $this->store($cacheDir);

        $store->ensureDirectory();
        $store->writeCss('app', 'aaaaaaaa1111', '.a{}');
        $store->writeGz('app', 'aaaaaaaa1111', (string) \gzencode('.a{}', 9));

        foreach ($this->files($cacheDir) as $path) {
            $mode = $this->mode($path);

            self::assertSame(
                0,
                $mode & 0022,
                \sprintf('%s is group- or world-writable (mode %04o).', \basename($path), $mode)
            );
        }
    }

    /** @test */
    public function writtenArtefactsUseTheConfiguredFileMode(): void
    {
        $store = $this->store($cacheDir);

        $store->ensureDirectory();
        $store->writeCss('app', 'aaaaaaaa1111', '.a{}');

        self::assertSame(0644, $this->mode($cacheDir . '/app.aaaaaaaa1111.css'));
        self::assertSame(0644, $this->mode($cacheDir . '/app.css'));
        self::assertSame(0644, $this->mode($cacheDir . '/.htaccess'));
        self::assertSame(0644, $this->mode($cacheDir . '/index.html'));
    }

    /**
     * A stricter mode must be honoured — an operator who asks for 0600 gets it.
     *
     * @test
     */
    public function aStricterFileModeIsHonoured(): void
    {
        $root = $this->tempDir();
        $cacheDir = $root . '/cache/tailwind';

        $store = new CacheStore(
            $cacheDir,
            [$root],
            $root,
            0700,
            0600,
            true,
            new Filesystem(),
            new AtomicWriter($root),
            null
        );

        $store->ensureDirectory();
        $store->writeCss('app', 'aaaaaaaa1111', '.a{}');

        self::assertSame(0600, $this->mode($cacheDir . '/app.aaaaaaaa1111.css'));
    }

    /** @test */
    public function theCacheDirectoryUsesTheConfiguredDirectoryMode(): void
    {
        $store = $this->store($cacheDir);

        $store->ensureDirectory();

        self::assertSame(0755, $this->mode($cacheDir));
    }

    /**
     * The mode must not depend on the process umask. A permissive umask is
     * common in CLI and cron contexts, and the artefact has to come out the
     * same either way — which is only true because `AtomicWriter` chmods
     * explicitly instead of relying on `fopen`'s mode argument.
     *
     * @test
     */
    public function theModeIsIndependentOfTheUmask(): void
    {
        $previous = \umask(0);

        try {
            $store = $this->store($cacheDir);

            $store->ensureDirectory();
            $store->writeCss('app', 'aaaaaaaa1111', '.a{}');

            self::assertSame(0644, $this->mode($cacheDir . '/app.aaaaaaaa1111.css'));
        } finally {
            \umask($previous);
        }
    }

    /**
     * The window this closes: between `rename` and a later `chmod`, the file
     * exists under its final name with whatever mode it was created with. Since
     * `AtomicWriter` chmods the temp file first, no such window exists — proven
     * here by a permissive umask, under which a create-then-rename-then-chmod
     * order would briefly expose a 0666 artefact.
     *
     * @test
     */
    public function theModeIsAppliedBeforeTheFileIsVisibleUnderItsFinalName(): void
    {
        $previous = \umask(0);

        try {
            $root = $this->tempDir();
            $cacheDir = $root . '/cache/tailwind';
            \mkdir($cacheDir, 0755, true);

            $writer = new AtomicWriter($root);
            $target = $cacheDir . '/app.aaaaaaaa1111.css';

            $writer->write($target, '.a{}', 0640);

            self::assertSame(0640, $this->mode($target));

            // And nothing is left behind at a wider mode either.
            foreach ($this->files($cacheDir) as $path) {
                self::assertStringNotContainsString('.tmp.', \basename($path));
            }
        } finally {
            \umask($previous);
        }
    }

    private function mode(string $path): int
    {
        \clearstatcache(true, $path);

        $perms = \fileperms($path);

        self::assertNotFalse($perms, \sprintf('Could not stat %s.', \basename($path)));

        return $perms & 0777;
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        $names = \scandir($dir);

        if ($names === false) {
            return [];
        }

        $files = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (\is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param string|null $cacheDir Out-parameter: the directory the store uses.
     *
     * @param-out string $cacheDir
     */
    private function store(?string &$cacheDir): CacheStore
    {
        $root = $this->tempDir();
        $cacheDir = $root . '/cache/tailwind';

        return new CacheStore(
            $cacheDir,
            [$root],
            $root,
            0755,
            0644,
            true,
            new Filesystem(),
            new AtomicWriter($root),
            null
        );
    }

    private function tempDir(): string
    {
        $dir = TempTree::make('tailwindphp-t17');

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
