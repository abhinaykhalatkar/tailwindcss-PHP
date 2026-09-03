<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The naming, confinement, prune and clear rules of design doc 05 §Artefacts
 * (FR-CACHE-6/7/8, threats T-02, T-07, T-13).
 *
 * A real temporary directory is used rather than an in-memory double: `prune()`
 * and `clear()` are built on `scandir()` and mtimes, and a double would only
 * restate the implementation.
 */
final class CacheStoreTest extends TestCase
{
    private const FINGERPRINT = 'abcdef01';

    /**
     * A build far enough in the past that the mtimes below are stable and
     * ordered regardless of when the suite runs.
     */
    private const BASE_MTIME = 1600000000;

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $cacheDir = '';

    /**
     * @var CacheStore
     */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-store');
        $this->cacheDir = $this->root . '/cache';

        \mkdir($this->cacheDir, 0777, true);

        $this->store = $this->makeStore(true);

        // Backup cleanup: tearDown handles the normal path, this catches a
        // fatal that skips it (design doc 16 §Integration test conventions).
        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CacheStore::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function cacheDirIsReportedBack(): void
    {
        self::assertSame($this->cacheDir, $this->store->cacheDir());
    }

    // -------------------------------------------------------- token validation

    /**
     * A filename here is assembled only from validated tokens, because nothing
     * else stands between a crafted route and the filesystem (threat T-02).
     *
     * @test
     *
     * @dataProvider invalidBundles
     */
    public function everyPathBuilderRejectsAnInvalidBundle(string $bundle): void
    {
        foreach ($this->pathBuilders() as $label => $builder) {
            try {
                $builder($this->store, $bundle);

                self::fail(\sprintf('%s() accepted an invalid bundle name.', $label));
            } catch (PathException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBundles(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../etc'],
            'uppercase' => ['App'],
            'space' => ['a b'],
            'forward slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'single dot' => ['.'],
            'double dot' => ['..'],
            'too long' => [\str_repeat('a', 41)],
            'nul byte' => ["ap\0p"],
            // PCRE's `$` matches before a final newline unless the `D` modifier
            // is set, so these validated and travelled into a filename.
            // Built with chr() rather than "\n": php-cs-fixer's single_quote
            // rule rewrites a double-quoted escape into a literal newline, and
            // in doing so collapsed these two rows into one.
            'trailing newline' => ['app' . \chr(10)],
            'trailing crlf' => ['app' . \chr(13) . \chr(10)],
        ];
    }

    /**
     * @test
     *
     * @dataProvider validBundles
     */
    public function everyPathBuilderAcceptsAValidBundle(string $bundle): void
    {
        foreach ($this->pathBuilders() as $label => $builder) {
            $path = $builder($this->store, $bundle);

            self::assertStringStartsWith($this->cacheDir . '/', $path, $label);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validBundles(): array
    {
        return [
            'app' => ['app'],
            'single character' => ['a'],
            'digits and separators' => ['ok_bundle-1'],
            'at the length limit' => [\str_repeat('a', 40)],
        ];
    }

    /**
     * @test
     *
     * @dataProvider invalidFingerprints
     */
    public function theFingerprintedPathsRejectAnInvalidFingerprint(string $fingerprint): void
    {
        $this->expectException(PathException::class);

        $this->store->fingerprintedCssPath('app', $fingerprint);
    }

    /**
     * @test
     *
     * @dataProvider invalidFingerprints
     */
    public function theFingerprintedGzPathRejectsAnInvalidFingerprint(string $fingerprint): void
    {
        $this->expectException(PathException::class);

        $this->store->fingerprintedGzPath('app', $fingerprint);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidFingerprints(): array
    {
        return [
            'too short' => ['abcdef1'],
            'too long' => [\str_repeat('a', 17)],
            'uppercase hex' => ['ABCDEF01'],
            'traversal' => ['../aaaaaaaa'],
            'empty' => [''],
            'trailing newline' => ['abcdef01' . \chr(10)],
            'trailing crlf' => ['abcdef01' . \chr(13) . \chr(10)],
        ];
    }

    // ------------------------------------------------------------- confinement

    /**
     * The tokens already make an escape unreachable; the confinement makes it
     * unreachable a second time, which is the right ratio for the one component
     * that writes files.
     *
     * @test
     */
    public function everyPathIsConfinedToTheCacheDirectory(): void
    {
        foreach ($this->pathBuilders() as $label => $builder) {
            $path = $builder($this->store, 'app');

            self::assertStringStartsWith($this->cacheDir . '/', $path, $label);
            self::assertNotContains('..', \explode('/', $path), $label);
        }
    }

    // ------------------------------------------------------------------ naming

    /** @test */
    public function theArtefactNamesAreExactlyTheDocumentedOnes(): void
    {
        self::assertSame('app.meta.json', \basename($this->store->manifestPath('app')));
        self::assertSame('app.css', \basename($this->store->cssPath('app')));
        self::assertSame('app.css.gz', \basename($this->store->gzPath('app')));
        self::assertSame('app.lock', \basename($this->store->lockPath('app')));
        self::assertSame('app.checked', \basename($this->store->stampPath('app')));

        self::assertSame(
            'app.' . self::FINGERPRINT . '.css',
            \basename($this->store->fingerprintedCssPath('app', self::FINGERPRINT))
        );
        self::assertSame(
            'app.' . self::FINGERPRINT . '.css.gz',
            \basename($this->store->fingerprintedGzPath('app', self::FINGERPRINT))
        );
    }

    // ------------------------------------------------------------------- gzip

    /** @test */
    public function gzipEnabledReflectsTheConstructorFlag(): void
    {
        self::assertTrue($this->store->gzipEnabled());
        self::assertFalse($this->makeStore(false)->gzipEnabled());
    }

    /** @test */
    public function writeGzIsANoOpWhenGzipIsDisabled(): void
    {
        $store = $this->makeStore(false);

        $store->writeGz('app', self::FINGERPRINT, 'compressed');

        self::assertFalse(\is_file($store->gzPath('app')));
        self::assertFalse(\is_file($store->fingerprintedGzPath('app', self::FINGERPRINT)));
        self::assertSame([], $this->entries($this->cacheDir));
    }

    // ------------------------------------------------------------ write / read

    /** @test */
    public function writeCssWritesTheFingerprintedFileAndThePlainCopy(): void
    {
        $css = ".a{color:red}\n";

        $this->store->writeCss('app', self::FINGERPRINT, $css);

        $fingerprinted = $this->store->fingerprintedCssPath('app', self::FINGERPRINT);
        $plain = $this->store->cssPath('app');

        self::assertSame($css, (string) \file_get_contents($fingerprinted));
        self::assertSame($css, (string) \file_get_contents($plain));
    }

    /** @test */
    public function writeGzWritesBothCompressedArtefactsWhenGzipIsEnabled(): void
    {
        $bytes = "\x1f\x8b\x08\x00compressed";

        $this->store->writeGz('app', self::FINGERPRINT, $bytes);

        self::assertSame($bytes, (string) \file_get_contents($this->store->gzPath('app')));
        self::assertSame(
            $bytes,
            (string) \file_get_contents($this->store->fingerprintedGzPath('app', self::FINGERPRINT))
        );
    }

    /** @test */
    public function hasAnswersForTheFingerprintedArtefact(): void
    {
        self::assertFalse($this->store->has('app', self::FINGERPRINT));

        $this->store->writeCss('app', self::FINGERPRINT, 'x');

        self::assertTrue($this->store->has('app', self::FINGERPRINT));
        self::assertFalse($this->store->has('app', 'ffffffff'));
    }

    // ------------------------------------------------------------------- touch

    /** @test */
    public function touchCssMovesTheMtimeOfTheArtefactsForward(): void
    {
        $this->store->writeCss('app', self::FINGERPRINT, 'x');

        $fingerprinted = $this->store->fingerprintedCssPath('app', self::FINGERPRINT);
        $plain = $this->store->cssPath('app');
        $old = \time() - 3600;

        \touch($fingerprinted, $old);
        \touch($plain, $old);
        \clearstatcache(true);

        $this->store->touchCss('app', self::FINGERPRINT);
        \clearstatcache(true);

        self::assertGreaterThan($old, (int) \filemtime($fingerprinted));
        self::assertGreaterThan($old, (int) \filemtime($plain));
    }

    /** @test */
    public function touchCssDoesNotCreateArtefactsThatAreAbsent(): void
    {
        $this->store->writeCss('app', self::FINGERPRINT, 'x');

        $this->store->touchCss('app', self::FINGERPRINT);

        self::assertFalse(\is_file($this->store->gzPath('app')));
        self::assertFalse(\is_file($this->store->fingerprintedGzPath('app', self::FINGERPRINT)));
        self::assertSame(
            ['app.' . self::FINGERPRINT . '.css', 'app.css'],
            $this->entries($this->cacheDir)
        );
    }

    // ------------------------------------------------------------------- prune

    /**
     * The current fingerprint is kept by identity, not by mtime: here it is the
     * oldest of the four, and the two most recent *others* fill the remaining
     * slots.
     *
     * @test
     */
    public function pruneKeepsTheCurrentBuildAndTheMostRecentOthers(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->seed('app', 'cccccccc', self::BASE_MTIME + 20);
        $this->seed('app', '12345678', self::BASE_MTIME + 30);

        $removed = $this->store->prune('app', 'aaaaaaaa', 3);

        self::assertSame(2, $removed);
        self::assertTrue($this->store->has('app', 'aaaaaaaa'));
        self::assertTrue($this->store->has('app', '12345678'));
        self::assertTrue($this->store->has('app', 'cccccccc'));
        self::assertFalse($this->store->has('app', 'bbbbbbbb'));
    }

    /**
     * A request must never find a stylesheet whose compressed twin has just
     * vanished, nor the reverse (threat T-13).
     *
     * @test
     */
    public function pruneRemovesCssAndGzAsAPair(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->seed('app', 'cccccccc', self::BASE_MTIME + 20);

        $this->store->prune('app', 'cccccccc', 2);

        foreach (['aaaaaaaa', 'bbbbbbbb', 'cccccccc'] as $fingerprint) {
            $css = \is_file($this->store->fingerprintedCssPath('app', $fingerprint));
            $gz = \is_file($this->store->fingerprintedGzPath('app', $fingerprint));

            self::assertSame($css, $gz, \sprintf('%s was left half-removed.', $fingerprint));
        }
    }

    /** @test */
    public function pruneWithKeepOneLeavesOnlyTheCurrentPair(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->seed('app', 'cccccccc', self::BASE_MTIME + 20);

        $removed = $this->store->prune('app', 'bbbbbbbb', 1);

        self::assertSame(4, $removed);
        self::assertTrue($this->store->has('app', 'bbbbbbbb'));
        self::assertFalse($this->store->has('app', 'aaaaaaaa'));
        self::assertFalse($this->store->has('app', 'cccccccc'));
    }

    /**
     * `keep_versions: 0` is clamped rather than obeyed: keeping nothing would
     * delete the build that is being served.
     *
     * @test
     */
    public function pruneClampsAKeepCountBelowOne(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);

        $removed = $this->store->prune('app', 'bbbbbbbb', 0);

        self::assertSame(2, $removed);
        self::assertTrue($this->store->has('app', 'bbbbbbbb'));
        self::assertFalse($this->store->has('app', 'aaaaaaaa'));
    }

    /** @test */
    public function pruneNeverRemovesAProtectedFingerprint(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->seed('app', 'cccccccc', self::BASE_MTIME + 20);

        $removed = $this->store->prune('app', 'cccccccc', 1, ['aaaaaaaa']);

        self::assertSame(2, $removed);
        self::assertTrue($this->store->has('app', 'aaaaaaaa'));
        self::assertTrue($this->store->has('app', 'cccccccc'));
        self::assertFalse($this->store->has('app', 'bbbbbbbb'));
    }

    /** @test */
    public function pruneLeavesTheStableCopiesTheStampTheLockAndTheGuards(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->plant('app.meta.json', '{}');
        $this->plant('app.checked', '');
        $this->plant('app.lock', '');
        $this->plant('.htaccess', 'deny');
        $this->plant('index.html', '');

        $this->store->prune('app', 'bbbbbbbb', 1);

        $protected = ['app.css', 'app.css.gz', 'app.meta.json', 'app.checked', 'app.lock', '.htaccess', 'index.html'];

        foreach ($protected as $n) {
            self::assertTrue(\is_file($this->cacheDir . '/' . $n), $n . ' was removed by prune.');
        }
    }

    /** @test */
    public function pruneDoesNotTouchAnotherBundle(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('admin', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('admin', 'bbbbbbbb', self::BASE_MTIME + 10);

        $this->store->prune('app', 'aaaaaaaa', 1);

        self::assertTrue($this->store->has('admin', 'aaaaaaaa'));
        self::assertTrue($this->store->has('admin', 'bbbbbbbb'));
        self::assertTrue(\is_file($this->store->fingerprintedGzPath('admin', 'bbbbbbbb')));
    }

    // ------------------------------------------------------------------- clear

    /** @test */
    public function clearRemovesTheBundlesArtefactsAndReportsHowMany(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);
        $this->plant('app.meta.json', '{}');
        $this->plant('app.checked', '');

        $removed = $this->store->clear('app');

        // manifest + stamp + app.css + app.css.gz + two fingerprinted pairs.
        self::assertSame(8, $removed);

        foreach (['app.meta.json', 'app.checked', 'app.css', 'app.css.gz'] as $name) {
            self::assertFalse(\is_file($this->cacheDir . '/' . $name), $name . ' survived clear.');
        }

        self::assertFalse($this->store->has('app', 'aaaaaaaa'));
        self::assertFalse($this->store->has('app', 'bbbbbbbb'));
        self::assertFalse(\is_file($this->store->fingerprintedGzPath('app', 'aaaaaaaa')));
    }

    /**
     * Deleting the lock is how two processes end up locking different inodes
     * with the same name, and a cache directory without its guards is exposed
     * until the next build (threat T-07).
     *
     * @test
     */
    public function clearKeepsTheLockTheGuardsAndTheDirectory(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->plant('app.lock', '');
        $this->plant('.htaccess', 'deny');
        $this->plant('index.html', '');

        $this->store->clear('app');

        self::assertTrue(\is_dir($this->cacheDir));
        self::assertSame(['.htaccess', 'app.lock', 'index.html'], $this->entries($this->cacheDir));
    }

    /** @test */
    public function clearLeavesAnotherBundleAlone(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('admin', 'bbbbbbbb', self::BASE_MTIME);
        $this->plant('admin.meta.json', '{}');

        $this->store->clear('app');

        self::assertTrue($this->store->has('admin', 'bbbbbbbb'));
        self::assertTrue(\is_file($this->cacheDir . '/admin.meta.json'));
        self::assertTrue(\is_file($this->store->cssPath('admin')));
    }

    /** @test */
    public function clearRejectsAnInvalidBundle(): void
    {
        $this->expectException(PathException::class);

        $this->store->clear('../etc');
    }

    // ---------------------------------------------------- cli temp directories

    /** @test */
    public function clearRemovesAStaleCliTemporaryDirectory(): void
    {
        $dir = $this->plantCliDirectory('.cli-1234-0a1b2c3d', ['input.css', 'candidates.txt', 'output.css']);

        $removed = $this->store->clear('app');

        self::assertSame(1, $removed);
        self::assertFalse(\is_dir($dir));
    }

    /**
     * Something we did not put there: leave the whole directory alone rather
     * than delete around it (decision CCP-A2).
     *
     * @test
     */
    public function clearLeavesACliDirectoryHoldingAnUnexpectedFile(): void
    {
        $dir = $this->plantCliDirectory('.cli-1234-0a1b2c3d', ['input.css', 'notes.txt']);

        $removed = $this->store->clear('app');

        self::assertSame(0, $removed);
        self::assertTrue(\is_dir($dir));
        self::assertTrue(\is_file($dir . '/input.css'));
        self::assertTrue(\is_file($dir . '/notes.txt'));
    }

    /** @test */
    public function clearLeavesADirectoryWhoseNameDoesNotMatchThePattern(): void
    {
        $dir = $this->plantCliDirectory('.cli-nope-0a1b2c3d', ['input.css']);
        $other = $this->plantCliDirectory('assets', ['input.css']);

        $removed = $this->store->clear('app');

        self::assertSame(0, $removed);
        self::assertTrue(\is_dir($dir));
        self::assertTrue(\is_dir($other));
    }

    /**
     * An orphaned `.gz` — its plain twin already gone — was invisible to the
     * enumeration, which listed `.css` only, so it stayed forever. HBC-A11 says
     * the gz files go too.
     *
     * @test
     */
    public function clearRemovesAnOrphanedCompressedArtefact(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);

        // The plain twin vanishes — a half-finished prune, or a hand-edit.
        \unlink($this->store->fingerprintedCssPath('app', 'aaaaaaaa'));

        $this->store->clear('app');

        self::assertFalse(
            \is_file($this->store->fingerprintedGzPath('app', 'aaaaaaaa')),
            'An orphaned .css.gz survived clear().'
        );
    }

    /** @test */
    public function pruneRemovesAnOrphanedCompressedArtefact(): void
    {
        $this->seed('app', 'aaaaaaaa', self::BASE_MTIME);
        $this->seed('app', 'bbbbbbbb', self::BASE_MTIME + 10);

        \unlink($this->store->fingerprintedCssPath('app', 'aaaaaaaa'));

        $this->store->prune('app', 'bbbbbbbb', 1);

        self::assertFalse(\is_file($this->store->fingerprintedGzPath('app', 'aaaaaaaa')));
    }

    /**
     * A process killed between `fopen('xb')` and the rename leaves a temp file
     * under a predictable name in a writable directory, and nothing used to
     * remove it — they simply accumulated (decision CCP-A28).
     *
     * @test
     */
    public function clearRemovesLeftoverTemporaryFiles(): void
    {
        $leftover = $this->plant('app.css.tmp.1234.abcdef01', 'half a stylesheet');
        $other = $this->plant('admin.css.tmp.1234.abcdef01', 'another bundle');

        $this->store->clear('app');

        self::assertFalse(\is_file($leftover), 'A leftover temp file survived clear().');
        self::assertTrue(\is_file($other), 'clear() removed another bundle\'s temp file.');
    }

    /**
     * `prune()` runs during a build, so it must not remove a temp file that a
     * *concurrent* write owns — only debris far older than any live write.
     *
     * @test
     */
    public function pruneRemovesOnlyTemporaryFilesOlderThanAnHour(): void
    {
        $fresh = $this->plant('app.css.tmp.1111.abcdef01', 'in flight');
        $stale = $this->plant('app.css.tmp.2222.abcdef02', 'abandoned');

        \touch($stale, \time() - 7200);
        \clearstatcache(true);

        $this->store->prune('app', self::FINGERPRINT, 3);

        self::assertTrue(\is_file($fresh), 'prune() removed a temp file a live write may own.');
        self::assertFalse(\is_file($stale), 'prune() left hour-old debris behind.');
    }

    /**
     * Listing the cache directory among its own allowed roots re-creates the
     * defect CCP-A27 fixed: `isSymlinkEscape()` resolves its roots, so the
     * directory would always resolve "inside" itself and the T-14 check would
     * pass for exactly the case it exists to catch. Refused at construction,
     * where it is a programming error.
     *
     * @test
     *
     * @dataProvider cacheDirSpellings
     */
    public function theCacheDirectoryMayNotBeOneOfItsOwnAllowedRoots(bool $trailingSlash, bool $upperCase): void
    {
        $entry = $this->cacheDir;

        if ($trailingSlash) {
            $entry .= '/';
        }

        if ($upperCase) {
            if (\DIRECTORY_SEPARATOR !== '\\') {
                self::markTestSkipped('Path comparison is case-sensitive off Windows.');
            }

            $entry = \strtoupper($entry);
        }

        $this->expectException(CacheException::class);

        new CacheStore(
            $this->cacheDir,
            [$this->root, $entry],
            $this->root,
            0755,
            0644,
            true,
            new Filesystem(),
            new AtomicWriter($this->root),
            null
        );
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function cacheDirSpellings(): array
    {
        return [
            'exactly' => [false, false],
            'with a trailing slash' => [true, false],
            'in a different case' => [false, true],
        ];
    }

    /**
     * The message names the problem in prose and quotes no path — it is thrown
     * from a constructor that may be handed anything (T-06).
     *
     * @test
     */
    public function theAllowedRootsRejectionQuotesNoPath(): void
    {
        try {
            new CacheStore(
                $this->cacheDir,
                [$this->cacheDir],
                $this->root,
                0755,
                0644,
                true,
                new Filesystem(),
                new AtomicWriter($this->root),
                null
            );

            self::fail('The cache directory was accepted among its own allowed roots.');
        } catch (CacheException $e) {
            self::assertStringNotContainsString($this->cacheDir, $e->getMessage());
            self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $e->getMessage());
        }
    }

    /**
     * A legitimate root that merely *contains* the cache directory is fine —
     * that is the normal arrangement.
     *
     * @test
     */
    public function anAncestorOfTheCacheDirectoryIsAValidRoot(): void
    {
        $store = new CacheStore(
            $this->cacheDir,
            [$this->root],
            $this->root,
            0755,
            0644,
            true,
            new Filesystem(),
            new AtomicWriter($this->root),
            null
        );

        self::assertSame($this->cacheDir, $store->cacheDir());
    }

    /**
     * The facade reports failures with an **absolute** path. Chaining it as
     * `previous` kept the top-level message clean while any logger walking the
     * chain still printed the server layout (T-06).
     *
     * @test
     */
    public function noAbsolutePathAppearsAnywhereInAFailureChain(): void
    {
        // A file where the cache directory should be: mkdir cannot succeed.
        $root = TempTree::make('tailwindphp-chain');
        $blocked = $root . '/cache';
        \file_put_contents($blocked, 'blocker');

        $store = new CacheStore(
            $blocked . '/tailwind',
            [$root],
            $root,
            0755,
            0644,
            true,
            new Filesystem(),
            new AtomicWriter($root),
            null
        );

        try {
            $store->ensureDirectory();

            self::fail('Creating a directory over a file should have failed.');
        } catch (CacheException $e) {
            for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                self::assertStringNotContainsString(
                    $root,
                    $t->getMessage(),
                    \sprintf('%s leaked an absolute path.', \get_class($t))
                );
                self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $t->getMessage());
            }
        } finally {
            TempTree::remove($root);
        }
    }

    // ----------------------------------------------------------------- helpers

    private function makeStore(bool $gzip): CacheStore
    {
        return new CacheStore(
            $this->cacheDir,
            [$this->root],
            $this->root,
            0755,
            0644,
            $gzip,
            new Filesystem(),
            new AtomicWriter($this->root),
            null
        );
    }

    /**
     * Write a fingerprinted pair and stamp it with a controlled mtime, so the
     * prune ordering is deterministic without sleeping.
     */
    private function seed(string $bundle, string $fingerprint, int $mtime): void
    {
        $this->store->writeCss($bundle, $fingerprint, 'css-' . $fingerprint);
        $this->store->writeGz($bundle, $fingerprint, 'gz-' . $fingerprint);

        \touch($this->store->fingerprintedCssPath($bundle, $fingerprint), $mtime);
        \touch($this->store->fingerprintedGzPath($bundle, $fingerprint), $mtime);
        \clearstatcache(true);
    }

    private function plant(string $name, string $contents): string
    {
        $path = $this->cacheDir . '/' . $name;

        \file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param list<string> $names
     */
    private function plantCliDirectory(string $name, array $names): string
    {
        $dir = $this->cacheDir . '/' . $name;

        \mkdir($dir, 0777, true);

        foreach ($names as $file) {
            \file_put_contents($dir . '/' . $file, 'x');
        }

        return $dir;
    }

    /**
     * Directory entries, sorted, without the dot entries.
     *
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $found = \scandir($dir);

        self::assertNotFalse($found);

        $result = [];

        foreach ($found as $name) {
            if ($name !== '.' && $name !== '..') {
                $result[] = $name;
            }
        }

        \sort($result);

        return $result;
    }

    /**
     * One closure per path builder, so the token rules can be asserted for all
     * of them at once.
     *
     * @return array<string, \Closure(CacheStore, string): string>
     */
    private function pathBuilders(): array
    {
        return [
            'manifestPath' => static function (CacheStore $store, string $bundle): string {
                return $store->manifestPath($bundle);
            },
            'cssPath' => static function (CacheStore $store, string $bundle): string {
                return $store->cssPath($bundle);
            },
            'gzPath' => static function (CacheStore $store, string $bundle): string {
                return $store->gzPath($bundle);
            },
            'lockPath' => static function (CacheStore $store, string $bundle): string {
                return $store->lockPath($bundle);
            },
            'stampPath' => static function (CacheStore $store, string $bundle): string {
                return $store->stampPath($bundle);
            },
            'fingerprintedCssPath' => static function (CacheStore $store, string $bundle): string {
                return $store->fingerprintedCssPath($bundle, self::FINGERPRINT);
            },
            'fingerprintedGzPath' => static function (CacheStore $store, string $bundle): string {
                return $store->fingerprintedGzPath($bundle, self::FINGERPRINT);
            },
        ];
    }
}
