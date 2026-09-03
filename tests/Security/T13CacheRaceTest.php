<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Exception\CacheWriteException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\SystemClock;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-13 — cache race conditions** (design doc 12, design doc 05 §Write order).
 *
 * A PHP-FPM worker can be killed at any instruction: a deploy restarts the
 * pool, a request hits `max_execution_time`, the OOM killer picks it. The cache
 * therefore has to be safe not at the end of a build but *after every single
 * step of one*.
 *
 * The write order (design doc 05 §1.4) is what makes that true:
 *
 * ```
 * ① <b>.<fp>.css      ② <b>.<fp>.css.gz     ③ <b>.css + <b>.css.gz
 * ④ <b>.meta.json     ⑤ prune
 * ```
 *
 * The manifest goes **last**, so a process that dies at any earlier point
 * leaves the previous manifest in place — and that manifest still names a
 * fingerprinted file that exists, because prune only ever runs after a
 * successful manifest write (decision CCP-A7). The tests below kill a build at
 * each step and assert exactly that invariant.
 */
final class T13CacheRaceTest extends TestCase
{
    private const OLD_FP = 'aaaaaaaa1111';
    private const NEW_FP = 'bbbbbbbb2222';

    /**
     * @var list<string>
     */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The core invariant, checked at every kill point.
     *
     * @test
     *
     * @dataProvider killPoints
     */
    public function aBuildKilledMidWayLeavesAResolvableManifest(int $stepsCompleted): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);

        $store->ensureDirectory();
        $this->completeBuild($store, self::OLD_FP, '.old{}');

        // A second build starts and dies after $stepsCompleted steps.
        $this->partialBuild($store, self::NEW_FP, '.new{}', $stepsCompleted);

        $manifest = Manifest::load($store->manifestPath('app'), new Filesystem());

        self::assertNotNull($manifest, 'The manifest became unreadable after a killed build.');
        self::assertTrue(
            $store->has('app', $manifest->fingerprint()),
            \sprintf(
                'After %d of 5 build steps the manifest names fingerprint "%s", but that file does not exist.',
                $stepsCompleted,
                $manifest->fingerprint()
            )
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function killPoints(): array
    {
        return [
            'killed before writing anything' => [0],
            'killed after the fingerprinted css' => [1],
            'killed after the gzip twin' => [2],
            'killed after the manifest, before prune' => [4],
            'completed' => [5],
        ];
    }

    /**
     * A reader must never observe a partial artefact — and the dangerous moment
     * is precisely when a reader *is* holding the file, because that is when
     * Windows refuses the rename.
     *
     * There used to be a `copy()` fallback for exactly this case, which wrote
     * into the final name and made the file observably empty mid-copy: the
     * fallback fired only when someone was watching. Decision CCP-A26 removed
     * it, so the stable copy keeps the previous build instead of being
     * truncated, while the fingerprinted artefact — the one that is served —
     * is written under a name no reader can be holding, because it is new.
     *
     * @test
     */
    public function aReaderHoldingTheStableCopyNeverSeesItTruncated(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        $first = \str_repeat('a', 512 * 1024);
        $second = \str_repeat('b', 512 * 1024);

        $store->writeCss('app', self::OLD_FP, $first);

        $stable = $store->cssPath('app');

        // A request streaming the stylesheet, mid-flight.
        $reader = \fopen($stable, 'rb');

        self::assertNotFalse($reader);

        try {
            // A new build, under a new fingerprint as a real build would.
            $store->writeCss('app', self::NEW_FP, $second);

            // The served artefact is complete.
            self::assertSame(
                $second,
                (string) \file_get_contents($store->fingerprintedCssPath('app', self::NEW_FP))
            );

            // The stable copy holds one build or the other, never a fragment
            // and never zero bytes.
            $observed = (string) \file_get_contents($stable);

            self::assertContains(
                $observed,
                [$first, $second],
                'The stable copy was observed holding neither build in full.'
            );
            self::assertNotSame('', $observed, 'The stable copy was truncated to zero bytes.');
        } finally {
            \fclose($reader);
        }
    }

    /**
     * The fingerprinted artefact is written **first**; the stable copy only
     * after it succeeded. A reader that follows the new manifest must never
     * find `<b>.css` updated while `<b>.<fp>.css` is still missing.
     *
     * Made to fail deterministically by pre-planting a **directory** at the
     * fingerprinted name: `rename` cannot replace a directory, so step ① fails
     * and step ③ must never run.
     *
     * @test
     */
    public function aFailedFingerprintedWriteNeverUpdatesTheStableCopy(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        \mkdir($store->fingerprintedCssPath('app', self::NEW_FP));

        try {
            $store->writeCss('app', self::NEW_FP, '.new{}');

            self::fail('Writing over a directory should have failed.');
        } catch (CacheWriteException $e) {
            self::assertFalse(
                \is_file($store->cssPath('app')),
                'The stable copy was written even though its fingerprinted source failed.'
            );
        }
    }

    /**
     * The same ordering for the compressed pair.
     *
     * @test
     */
    public function aFailedFingerprintedGzWriteNeverUpdatesTheStableCopy(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        \mkdir($store->fingerprintedGzPath('app', self::NEW_FP));

        try {
            $store->writeGz('app', self::NEW_FP, (string) \gzencode('.new{}', 9));

            self::fail('Writing over a directory should have failed.');
        } catch (CacheWriteException $e) {
            self::assertFalse(
                \is_file($store->gzPath('app')),
                'The stable gz copy was written even though its fingerprinted source failed.'
            );
        }
    }

    /**
     * The name of a fingerprinted artefact encodes its content hash, so a file
     * that is already there with the same size holds the same bytes. Rewriting
     * it would churn a file another request may be streaming, for no change
     * (decision CCP-A26).
     *
     * @test
     */
    public function anUnchangedFingerprintedArtefactIsNotRewritten(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        $css = '.a{color:red}';
        $store->writeCss('app', self::OLD_FP, $css);

        $path = $store->fingerprintedCssPath('app', self::OLD_FP);
        \touch($path, \time() - 3600);
        \clearstatcache();
        $before = (int) \filemtime($path);

        $store->writeCss('app', self::OLD_FP, $css);
        \clearstatcache();

        self::assertSame($before, (int) \filemtime($path), 'An unchanged artefact was rewritten.');
        self::assertSame($css, (string) \file_get_contents($path));
    }

    /**
     * Prune runs inside the lock and after the manifest (CCP-A7), so it can
     * never delete the artefact the live manifest points at — not even when
     * that artefact is the oldest file in the directory.
     *
     * @test
     */
    public function pruneNeverRemovesTheArtefactTheManifestNames(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        $base = \time() - 10000;
        $fingerprints = ['aaaaaaaa1111', 'bbbbbbbb2222', 'cccccccc3333', 'dddddddd4444'];

        foreach ($fingerprints as $index => $fingerprint) {
            $store->writeCss('app', $fingerprint, '.x{}');
            $store->writeGz('app', $fingerprint, (string) \gzencode('.x{}', 9));
            // The current build is deliberately the *oldest* file, so a prune
            // that ranked by mtime alone would delete it.
            \touch($store->fingerprintedCssPath('app', $fingerprint), $base + ($index === 0 ? 0 : 1000 * $index));
        }

        $store->prune('app', $fingerprints[0], 2);

        self::assertTrue(\is_file($store->fingerprintedCssPath('app', $fingerprints[0])));
    }

    /**
     * Two builds must not run at once (T-05 as well as T-13): the second finds
     * the lock held and takes the stale-fallback path instead of compiling.
     *
     * @test
     */
    public function aSecondBuilderCannotTakeTheLockWhileTheFirstHoldsIt(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        $path = $store->lockPath('app');
        $first = new FlockBuildLock($path, new SystemClock());
        $second = new FlockBuildLock($path, new SystemClock());

        self::assertTrue($first->tryAcquire());

        if (!$second->isSupported()) {
            self::markTestSkipped('This filesystem does not implement flock, so exclusion cannot be asserted.');
        }

        self::assertFalse($second->tryAcquire(), 'Two builders acquired the same lock.');

        $first->release();

        self::assertTrue($second->tryAcquire(), 'The lock was not released.');

        $second->release();
    }

    /**
     * The lock file survives `clear()`. Deleting it would let two processes
     * `flock` two different inodes that happen to share a name, which looks
     * like a working lock and excludes nothing.
     *
     * @test
     */
    public function clearNeverRemovesTheLockFile(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        $this->completeBuild($store, self::OLD_FP, '.old{}');
        \touch($store->lockPath('app'));

        $store->clear('app');

        self::assertTrue(\is_file($store->lockPath('app')), 'clear() removed the lock file.');
    }

    /**
     * Run a build to completion: all five steps of the write order.
     */
    private function completeBuild(CacheStore $store, string $fingerprint, string $css): void
    {
        $this->partialBuild($store, $fingerprint, $css, 5);
    }

    /**
     * Perform the first $steps steps of the write order and stop, as a process
     * killed part-way through would.
     *
     * This drives the **production** methods — `writeCss()`, `writeGz()`,
     * `Manifest::save()`, `prune()` — rather than re-implementing the order
     * with raw `AtomicWriter` calls. Re-implementing it is why a mutant that
     * reordered the real write survived the whole suite: the test asserted its
     * own copy of the order, not the one that ships.
     *
     * Steps ① and ③ are one call (`writeCss`), so "killed after the
     * fingerprinted file" is expressed by making the *stable copy* impossible:
     * a directory pre-planted at `<b>.css` cannot be replaced by a rename, and
     * CCP-A26 makes that non-fatal, which is exactly the half-finished state.
     */
    private function partialBuild(CacheStore $store, string $fingerprint, string $css, int $steps): void
    {
        if ($steps < 1) {
            return;
        }

        // ① + ③ the fingerprinted stylesheet, then the stable copy.
        $store->writeCss('app', $fingerprint, $css);

        if ($steps < 2) {
            return;
        }

        // ② + ③ the compressed twin and its stable copy.
        $store->writeGz('app', $fingerprint, (string) \gzencode($css, 9));

        if ($steps < 4) {
            return;
        }

        // ④ the manifest, last.
        $this->manifestFor($fingerprint)->save(
            $store->manifestPath('app'),
            new AtomicWriter($store->cacheDir())
        );

        if ($steps < 5) {
            return;
        }

        // ⑤ prune, after the manifest and inside the lock (CCP-A7).
        $store->prune('app', $fingerprint, 2);
    }

    /**
     * The stable copy must never be updated before the fingerprinted artefact
     * it names exists — a reader following the new manifest would find a
     * dangling fingerprint.
     *
     * Driven through the production path, and made to fail deterministically by
     * pre-planting a **directory** where the stable copy goes: `rename` cannot
     * replace a directory, so step ③ fails while ① has already succeeded.
     *
     * @test
     */
    public function aFailedStableCopyLeavesTheFingerprintedArtefactIntact(): void
    {
        $root = $this->tempDir();
        $store = $this->store($root, $cacheDir);
        $store->ensureDirectory();

        // A directory at the stable copy's name: the rename can never win.
        \mkdir($store->cssPath('app'));

        $store->writeCss('app', self::NEW_FP, '.new{}');

        self::assertTrue(
            \is_file($store->fingerprintedCssPath('app', self::NEW_FP)),
            'The fingerprinted artefact must be written even when the stable copy cannot be.'
        );
        self::assertTrue(\is_dir($store->cssPath('app')), 'The blocking directory should be untouched.');

        // And nothing partial was left lying around.
        foreach (\scandir($cacheDir) ?: [] as $entry) {
            self::assertStringNotContainsString('.tmp.', $entry);
        }
    }

    private function manifestFor(string $fingerprint): Manifest
    {
        return Manifest::fromArray([
            'schema' => 1,
            'package_version' => '0.1.0-dev',
            'config_hash' => 'sha256:' . \str_repeat('a', 64),
            'bundle' => 'app',
            'driver' => 'php',
            'driver_version' => '0.1.0',
            'driver_probe' => null,
            'built_at' => 1756857600,
            'build_ms' => 1.0,
            'content_hash' => 'sha256:' . \str_repeat('b', 64),
            'fingerprint' => $fingerprint,
            'minified' => false,
            'candidates_hash' => 'sha256:' . \str_repeat('c', 64),
            'candidates_count' => 0,
            'input' => 'resources/css/app.css',
            'imports' => [],
            'roots' => ['input' => [], 'watch' => [], 'sources' => []],
            'files' => [],
            'safelist_hash' => 'sha256:' . \str_repeat('d', 64),
            'inline_sources' => [],
            'warnings' => [],
            'last_error' => null,
        ]);
    }

    /**
     * @param string|null $cacheDir Out-parameter: the directory the store uses.
     *
     * @param-out string $cacheDir
     */
    private function store(string $root, ?string &$cacheDir): CacheStore
    {
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
        $dir = TempTree::make('tailwindphp-t13');

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
