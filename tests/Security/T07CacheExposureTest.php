<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Exception\CacheException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Regex;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-07 — cache directory exposure** (design doc 12).
 *
 * The threat: the cache directory ends up under the document root, someone
 * requests it directly, and the manifest hands them a map of the application —
 * every template path, every watched root, the input stylesheet's location. The
 * primary control is putting the cache outside the web root (the default), so
 * what is tested here is the second line of defence: the guard files, and the
 * fact that nothing but a known artefact name is ever created.
 *
 * The "only whitelisted names" assertion is the one that keeps working as the
 * code grows. A future feature that writes a scratch file into the cache
 * directory fails this test rather than quietly publishing something.
 *
 * @covers-requirement FR-CACHE-5 NFR-SEC-4
 */
final class T07CacheExposureTest extends TestCase
{
    /**
     * The closed artefact set of design doc 05 §Artefacts, as regular
     * expressions over a single directory entry.
     */
    private const ALLOWED_ENTRY_PATTERNS = [
        '#^\.htaccess$#',
        '#^index\.html$#',
        '#^[a-z0-9_-]{1,40}\.meta\.json$#',
        '#^[a-z0-9_-]{1,40}\.css$#',
        '#^[a-z0-9_-]{1,40}\.css\.gz$#',
        '#^[a-z0-9_-]{1,40}\.[a-f0-9]{8,16}\.css$#',
        '#^[a-z0-9_-]{1,40}\.[a-f0-9]{8,16}\.css\.gz$#',
        '#^[a-z0-9_-]{1,40}\.lock$#',
        '#^[a-z0-9_-]{1,40}\.checked$#',
        // The transient CLI temp directory of decision CCP-A2.
        '#^\.cli-[0-9]{1,10}-[a-f0-9]{8}$#',
    ];

    /**
     * Names inside a `.cli-*` temp directory (decision CCP-A2).
     */
    private const CLI_TEMP_NAMES = ['input.css', 'candidates.txt', 'output.css'];

    /**
     * @var list<string>
     */
    private $tempDirs = [];

    /**
     * Junctions must be removed with rmdir, never followed.
     *
     * @var list<string>
     */
    private $junctions = [];

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->junctions) as $junction) {
            if (TempTree::entryExists($junction)) {
                \rmdir($junction);
            }
        }

        $this->junctions = [];

        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @test */
    public function ensureDirectoryWritesBothGuards(): void
    {
        $store = $this->store($cacheDir);

        $store->ensureDirectory();

        self::assertTrue(\is_file($cacheDir . '/.htaccess'), 'The .htaccess guard was not created.');
        self::assertTrue(\is_file($cacheDir . '/index.html'), 'The index.html guard was not created.');
        self::assertSame('', \file_get_contents($cacheDir . '/index.html'));
    }

    /**
     * Both Apache generations must be answered, and each must be wrapped so the
     * other's module being absent does not 500 the server.
     *
     * @test
     */
    public function theHtaccessGuardDeniesOnApache22And24(): void
    {
        $store = $this->store($cacheDir);
        $store->ensureDirectory();

        $contents = \file_get_contents($cacheDir . '/.htaccess');

        self::assertIsString($contents);
        self::assertStringContainsString('Require all denied', $contents, 'The Apache 2.4 deny directive is missing.');
        self::assertStringContainsString('Deny from all', $contents, 'The Apache 2.2 deny directive is missing.');
        self::assertStringContainsString('<IfModule mod_authz_core.c>', $contents);
        self::assertStringContainsString('<IfModule mod_access_compat.c>', $contents);
    }

    /**
     * An operator who has tightened the guard keeps their version; a rebuild
     * must not silently reopen the directory either way.
     *
     * @test
     */
    public function ensureDirectoryDoesNotOverwriteACustomisedGuard(): void
    {
        $store = $this->store($cacheDir);
        $store->ensureDirectory();

        \file_put_contents($cacheDir . '/.htaccess', "Require all denied\n# tightened by hand\n");

        $store->ensureDirectory();

        self::assertStringContainsString('# tightened by hand', (string) \file_get_contents($cacheDir . '/.htaccess'));
    }

    /**
     * The whole point of the rule: a complete build cycle leaves nothing in the
     * directory that is not on the published list.
     *
     * @test
     */
    public function afullLifecycleWritesOnlyWhitelistedNames(): void
    {
        $store = $this->store($cacheDir);

        $store->ensureDirectory();
        $store->writeCss('app', 'aaaaaaaa1111', '.a{color:red}');
        $store->writeGz('app', 'aaaaaaaa1111', (string) \gzencode('.a{color:red}', 9));
        $store->writeCss('marketing_2', 'bbbbbbbbbbbb2222', '.b{}');
        \touch($store->lockPath('app'));
        \touch($store->stampPath('app'));
        \file_put_contents($store->manifestPath('app'), '{}');
        $store->touchCss('app', 'aaaaaaaa1111');
        $store->prune('app', 'aaaaaaaa1111', 2);

        foreach ($this->entries($cacheDir) as $entry) {
            self::assertTrue(
                $this->isAllowedEntry($entry),
                \sprintf('The cache directory holds an unexpected entry "%s".', $entry)
            );
        }
    }

    /**
     * No temp file may outlive a write. One left behind under a predictable
     * name is both a leak and a target (T-13/T-14).
     *
     * @test
     */
    public function noTemporaryFileSurvivesAWrite(): void
    {
        $store = $this->store($cacheDir);
        $store->ensureDirectory();

        for ($i = 0; $i < 5; ++$i) {
            $store->writeCss('app', 'aaaaaaaa1111', \str_repeat('x', 1024 * $i));
        }

        foreach ($this->entries($cacheDir) as $entry) {
            self::assertStringNotContainsString('.tmp.', $entry, 'A temporary file was left in the cache directory.');
        }
    }

    /**
     * A cache directory that is really a link to somewhere else is refused
     * before anything is written into it (T-14).
     *
     * This runs on **both** platforms: `mklink /J` needs no elevation, and the
     * previous Windows skip cited a reason that was simply untrue. That mattered,
     * because the guard itself was inert — passing the cache directory as one of
     * its own allowed roots made `Path::isSymlinkEscape()` always answer
     * "inside", so a junctioned cache directory was accepted and every guard and
     * artefact landed outside the project (decision CCP-A27).
     *
     * @test
     *
     * @dataProvider linkedCacheLayouts
     */
    public function aLinkedCacheDirectoryIsRefused(bool $linkTheLeaf): void
    {
        $root = $this->tempDir();
        $outside = $root . '/outside';
        $project = $root . '/project';

        \mkdir($outside, 0777, true);
        \mkdir($project . '/cache', 0777, true);

        // Either the cache directory itself is the link, or a component above
        // it is — both land the writes outside the project.
        $cacheDir = $linkTheLeaf ? $project . '/cache/tailwind' : $project . '/cache/linked/tailwind';
        $link = $linkTheLeaf ? $cacheDir : $project . '/cache/linked';

        if (!$this->makeLink($link, $outside)) {
            self::markTestSkipped('This environment does not permit creating a directory link.');
        }

        $store = new CacheStore(
            $cacheDir,
            [$project],
            $project,
            0755,
            0644,
            true,
            new Filesystem(),
            new AtomicWriter($project),
            null
        );

        try {
            $store->ensureDirectory();

            self::fail('A linked cache directory was accepted.');
        } catch (CacheException $e) {
            self::assertSame([], $this->entries($outside), 'Files were written outside the project.');
        }
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function linkedCacheLayouts(): array
    {
        return [
            'the cache directory itself is a link' => [true],
            'a component above it is a link' => [false],
        ];
    }

    /**
     * `is_link()` returns **false** for a Windows junction, so the `.cli-*`
     * sweep's guard was no guard at all: a junction wearing a temp directory's
     * name led the sweep out of the cache directory and deleted the target's
     * files. It now uses the same `Path::isSymlinkEscape()` control the guards
     * use.
     *
     * @test
     */
    public function theCliTempSweepDoesNotFollowALinkOutOfTheCacheDirectory(): void
    {
        $root = $this->tempDir();
        $cacheDir = $root . '/cache/tailwind';

        // A filesystem that clears the stat cache before answering. Without it
        // this test is not load-bearing: on Windows `is_dir()` answers *false*
        // for a junction created moments earlier in the same process, so the
        // sweep skipped the directory for an accidental reason and the old
        // `is_link()` guard "passed" while still being no guard at all. This is
        // the conforming behaviour a real filesystem gives after a request
        // boundary — verified by reverting the fix and watching this go red.
        $freshStat = new class () implements FilesystemInterface {
            /**
             * @var Filesystem
             */
            private $inner;

            public function __construct()
            {
                // `Filesystem` is final, so this delegates rather than extends.
                $this->inner = new Filesystem();
            }

            public function exists(string $path): bool
            {
                \clearstatcache(true, $path);

                return $this->inner->exists($path);
            }

            public function isFile(string $path): bool
            {
                \clearstatcache(true, $path);

                return $this->inner->isFile($path);
            }

            public function isDir(string $path): bool
            {
                \clearstatcache(true, $path);

                return $this->inner->isDir($path);
            }

            /**
             * @return array{mtime: int, size: int}|null
             */
            public function stat(string $path): ?array
            {
                \clearstatcache(true, $path);

                return $this->inner->stat($path);
            }

            public function read(string $path, int $maxBytes): string
            {
                return $this->inner->read($path, $maxBytes);
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
                $this->inner->mkdir($dir, $mode);
            }

            public function touch(string $path, ?int $mtime = null): void
            {
                $this->inner->touch($path, $mtime);
            }

            public function unlink(string $path): bool
            {
                return $this->inner->unlink($path);
            }

            public function clearStatCache(string $path): void
            {
                $this->inner->clearStatCache($path);
            }
        };

        $store = new CacheStore(
            $cacheDir,
            [$root],
            $root,
            0755,
            0644,
            true,
            $freshStat,
            new AtomicWriter($root),
            null
        );

        $store->ensureDirectory();

        $victims = $root . '/victims';
        \mkdir($victims, 0777, true);

        foreach (self::CLI_TEMP_NAMES as $name) {
            \file_put_contents($victims . '/' . $name, 'precious');
        }

        if (!$this->makeLink($cacheDir . '/.cli-1-abcdef12', $victims)) {
            self::markTestSkipped('This environment does not permit creating a directory link.');
        }

        $store->clear('app');

        self::assertTrue(\is_dir($victims), 'The sweep removed a directory outside the cache.');

        foreach (self::CLI_TEMP_NAMES as $name) {
            self::assertTrue(
                \is_file($victims . '/' . $name),
                \sprintf('The sweep followed a link and deleted %s.', $name)
            );
        }
    }

    /**
     * Create a directory link: a junction on Windows (no elevation needed), a
     * symlink elsewhere.
     */
    private function makeLink(string $link, string $target): bool
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            return \symlink($target, $link);
        }

        // `mklink` runs through `exec`: skipped where the host disables it.
        RequiresSpawning::checkExec();

        $command = \sprintf(
            'mklink /J "%s" "%s"',
            \str_replace('/', '\\', $link),
            \str_replace('/', '\\', $target)
        );

        $output = [];
        $status = 0;

        // Test-only helper; the library never spawns a process.
        \exec('cmd /c ' . $command . ' 2>&1', $output, $status);

        if ($status !== 0) {
            return false;
        }

        $this->junctions[] = $link;

        return \is_dir($link);
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

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $names = \scandir($dir);

        if ($names === false) {
            return [];
        }

        $entries = [];

        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    private function isAllowedEntry(string $entry): bool
    {
        foreach (self::ALLOWED_ENTRY_PATTERNS as $pattern) {
            // Regex::match, not preg_match: the "PCRE lives in one place" rule
            // of design doc 16 §Security suite holds in the tests too.
            if (Regex::match($pattern, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function tempDir(): string
    {
        $dir = TempTree::make('tailwindphp-t07');

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
