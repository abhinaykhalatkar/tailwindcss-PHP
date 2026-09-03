<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * `CacheStore` against a real directory (design doc 05, FR-CACHE-6/7/8).
 *
 * The unit test covers naming and token validation, which need no disk. What is
 * left is the part that only a real filesystem shows: that the guards contain
 * the bytes we shipped, that a full build cycle leaves the directory in exactly
 * the expected state, and that running the cycle twice is idempotent.
 */
final class CacheStoreIntegrationTest extends TestCase
{
    private const FP_OLD = 'aaaaaaaa1111';
    private const FP_NEW = 'bbbbbbbb2222';

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
        $this->cacheDir = $this->root . '/cache/tailwind';

        TempTree::registerShutdownCleanup($this->root);

        $this->store = new CacheStore(
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
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // -------------------------------------------------------- ensureDirectory

    /** @test */
    public function ensureDirectoryCreatesTheTreeAndBothGuards(): void
    {
        self::assertFalse(\is_dir($this->cacheDir));

        $this->store->ensureDirectory();

        self::assertTrue(\is_dir($this->cacheDir));
        self::assertSame(['.htaccess', 'index.html'], $this->entries());
    }

    /**
     * The guard is the shipped resource, not a copy that can drift from it.
     *
     * @test
     */
    public function theHtaccessGuardIsTheBundledResourceByteForByte(): void
    {
        $this->store->ensureDirectory();

        $shipped = \file_get_contents(\dirname(__DIR__, 3) . '/resources/cache-htaccess');

        self::assertNotFalse($shipped);
        self::assertSame($shipped, \file_get_contents($this->cacheDir . '/.htaccess'));
    }

    /** @test */
    public function theIndexGuardIsEmpty(): void
    {
        $this->store->ensureDirectory();

        self::assertSame('', (string) \file_get_contents($this->cacheDir . '/index.html'));
    }

    /**
     * Two workers arriving on a cold cache together must both succeed, so an
     * existing directory is not an error.
     *
     * @test
     */
    public function ensureDirectoryIsIdempotent(): void
    {
        $this->store->ensureDirectory();
        $this->store->ensureDirectory();
        $this->store->ensureDirectory();

        self::assertSame(['.htaccess', 'index.html'], $this->entries());
    }

    /**
     * An operator who has tightened the guard keeps their version — the file is
     * written only when missing.
     *
     * @test
     */
    public function ensureDirectoryDoesNotOverwriteACustomisedGuard(): void
    {
        $this->store->ensureDirectory();

        \file_put_contents($this->cacheDir . '/.htaccess', "Require all denied\n# ours\n");
        \file_put_contents($this->cacheDir . '/index.html', '<!-- ours -->');

        $this->store->ensureDirectory();

        self::assertStringContainsString('# ours', (string) \file_get_contents($this->cacheDir . '/.htaccess'));
        self::assertSame('<!-- ours -->', (string) \file_get_contents($this->cacheDir . '/index.html'));
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * The whole cycle in one test, asserting the directory contents after each
     * step. Written as a sequence rather than split up because the interesting
     * property is what each step does to the *previous* state.
     *
     * @test
     */
    public function theFullBuildLifecycleLeavesTheExpectedDirectory(): void
    {
        $this->store->ensureDirectory();

        // ① and ③: the fingerprinted artefact plus the stable copy.
        $this->store->writeCss('app', self::FP_OLD, '.old{}');

        self::assertSame(
            ['.htaccess', 'app.aaaaaaaa1111.css', 'app.css', 'index.html'],
            $this->entries()
        );
        self::assertSame('.old{}', (string) \file_get_contents($this->cacheDir . '/app.aaaaaaaa1111.css'));
        self::assertSame('.old{}', (string) \file_get_contents($this->cacheDir . '/app.css'));

        // ②: the compressed twins.
        $this->store->writeGz('app', self::FP_OLD, (string) \gzencode('.old{}', 9));

        self::assertTrue($this->store->has('app', self::FP_OLD));
        self::assertSame('.old{}', (string) \gzdecode((string) \file_get_contents($this->cacheDir . '/app.css.gz')));

        // ④: the manifest, last.
        $this->manifest(self::FP_OLD)->save($this->store->manifestPath('app'), new AtomicWriter($this->root));

        $loaded = Manifest::load($this->store->manifestPath('app'), new Filesystem());

        self::assertNotNull($loaded);
        self::assertSame(self::FP_OLD, $loaded->fingerprint());

        // A second build, then ⑤ prune keeping two versions.
        $this->store->writeCss('app', self::FP_NEW, '.new{}');
        $this->store->writeGz('app', self::FP_NEW, (string) \gzencode('.new{}', 9));

        // Stamp the ages explicitly. Three builds in one test land in the same
        // second, and prune's tie-break for equal mtimes is the fingerprint —
        // so without this the "oldest" here is whichever sorts first, not
        // whichever was written first.
        $this->age(self::FP_OLD, 3000);
        $this->age(self::FP_NEW, 2000);

        $this->store->prune('app', self::FP_NEW, 2);

        self::assertSame(
            [
                '.htaccess',
                'app.aaaaaaaa1111.css',
                'app.aaaaaaaa1111.css.gz',
                'app.bbbbbbbb2222.css',
                'app.bbbbbbbb2222.css.gz',
                'app.css',
                'app.css.gz',
                'app.meta.json',
                'index.html',
            ],
            $this->entries(),
            'Pruning with keep = 2 should have retained both versions.'
        );

        // A third build pushes the oldest out.
        $this->store->writeCss('app', 'cccccccc3333', '.third{}');
        $this->store->writeGz('app', 'cccccccc3333', (string) \gzencode('.third{}', 9));

        $this->age(self::FP_OLD, 3000);
        $this->age(self::FP_NEW, 2000);
        $this->age('cccccccc3333', 1000);

        $this->store->prune('app', 'cccccccc3333', 2);

        self::assertFalse(\is_file($this->cacheDir . '/app.aaaaaaaa1111.css'));
        self::assertFalse(\is_file($this->cacheDir . '/app.aaaaaaaa1111.css.gz'));
        self::assertTrue(\is_file($this->cacheDir . '/app.cccccccc3333.css'));

        // clear() empties the bundle but keeps the directory and the guards.
        \touch($this->store->lockPath('app'));
        \touch($this->store->stampPath('app'));

        $this->store->clear('app');

        self::assertSame(['.htaccess', 'app.lock', 'index.html'], $this->entries());
        self::assertTrue(\is_dir($this->cacheDir));
    }

    /** @test */
    public function touchCssMovesTheArtefactMtimeForward(): void
    {
        $this->store->ensureDirectory();
        $this->store->writeCss('app', self::FP_OLD, '.a{}');
        $this->store->writeGz('app', self::FP_OLD, (string) \gzencode('.a{}', 9));

        $stale = \time() - 3600;

        foreach (['app.aaaaaaaa1111.css', 'app.aaaaaaaa1111.css.gz', 'app.css', 'app.css.gz'] as $name) {
            \touch($this->cacheDir . '/' . $name, $stale);
        }

        \clearstatcache();

        $this->store->touchCss('app', self::FP_OLD);
        \clearstatcache();

        foreach (['app.aaaaaaaa1111.css', 'app.aaaaaaaa1111.css.gz', 'app.css', 'app.css.gz'] as $name) {
            self::assertGreaterThan(
                $stale,
                (int) \filemtime($this->cacheDir . '/' . $name),
                \sprintf('%s was not touched.', $name)
            );
        }
    }

    /** @test */
    public function touchCssDoesNotCreateFilesThatAreAbsent(): void
    {
        $this->store->ensureDirectory();

        $this->store->touchCss('app', self::FP_OLD);

        self::assertSame(['.htaccess', 'index.html'], $this->entries());
    }

    /**
     * Two bundles share the directory and must not interfere.
     *
     * @test
     */
    public function bundlesAreIndependent(): void
    {
        $this->store->ensureDirectory();
        $this->store->writeCss('app', self::FP_OLD, '.app{}');
        $this->store->writeCss('admin', self::FP_NEW, '.admin{}');

        $this->store->clear('app');

        self::assertFalse(\is_file($this->cacheDir . '/app.css'));
        self::assertTrue(\is_file($this->cacheDir . '/admin.css'));
        self::assertTrue(\is_file($this->cacheDir . '/admin.bbbbbbbb2222.css'));
    }

    /**
     * With gzip off, no `.gz` is written at all — the caller does not have to
     * ask first.
     *
     * @test
     */
    public function noCompressedArtefactIsWrittenWhenGzipIsDisabled(): void
    {
        $store = new CacheStore(
            $this->cacheDir,
            [$this->root],
            $this->root,
            0755,
            0644,
            false,
            new Filesystem(),
            new AtomicWriter($this->root),
            null
        );

        $store->ensureDirectory();
        $store->writeCss('app', self::FP_OLD, '.a{}');
        $store->writeGz('app', self::FP_OLD, (string) \gzencode('.a{}', 9));

        self::assertFalse($store->gzipEnabled());
        self::assertSame(['.htaccess', 'app.aaaaaaaa1111.css', 'app.css', 'index.html'], $this->entries());
    }

    /**
     * Decision HBC-A11: a stale `.cli-*` directory left by a CLI compile that
     * died before its `finally` is swept, but only when it holds nothing but
     * the three files we put there (CCP-A2).
     *
     * @test
     */
    public function clearSweepsAStaleCliTemporaryDirectoryButLeavesAnUnfamiliarOne(): void
    {
        $this->store->ensureDirectory();

        $ours = $this->cacheDir . '/.cli-1234-abcdef01';
        \mkdir($ours);
        \file_put_contents($ours . '/input.css', '@source none;');
        \file_put_contents($ours . '/output.css', '.a{}');

        $theirs = $this->cacheDir . '/.cli-5678-abcdef02';
        \mkdir($theirs);
        \file_put_contents($theirs . '/something-else.txt', 'not ours');

        $unrelated = $this->cacheDir . '/.cli-nope';
        \mkdir($unrelated);

        $this->store->clear('app');

        self::assertFalse(\is_dir($ours), 'A stale CLI temp directory was not swept.');
        self::assertTrue(\is_dir($theirs), 'A directory holding an unexpected file was emptied.');
        self::assertTrue(\is_file($theirs . '/something-else.txt'));
        self::assertTrue(\is_dir($unrelated), 'A directory that does not match the pattern was removed.');
    }

    /**
     * POSIX modes, skipped on Windows where NTFS ACLs govern access instead
     * (threat T-17).
     *
     * @test
     */
    public function theDirectoryAndArtefactsUseTheConfiguredModes(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file modes are not modelled on Windows.');
        }

        $this->store->ensureDirectory();
        $this->store->writeCss('app', self::FP_OLD, '.a{}');

        \clearstatcache();

        self::assertSame(0755, (int) \fileperms($this->cacheDir) & 0777);
        self::assertSame(0644, (int) \fileperms($this->cacheDir . '/app.aaaaaaaa1111.css') & 0777);
        self::assertSame(0644, (int) \fileperms($this->cacheDir . '/.htaccess') & 0777);
    }

    /**
     * Backdate a fingerprinted pair so prune's ordering is established by the
     * test rather than by how fast the machine ran.
     */
    private function age(string $fingerprint, int $secondsAgo): void
    {
        $when = \time() - $secondsAgo;

        foreach (['.css', '.css.gz'] as $suffix) {
            $path = $this->cacheDir . '/app.' . $fingerprint . $suffix;

            if (\is_file($path)) {
                \touch($path, $when);
            }
        }

        \clearstatcache();
    }

    private function manifest(string $fingerprint): Manifest
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
            'build_ms' => 1.5,
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
     * Directory entries, sorted, without the dot entries.
     *
     * @return list<string>
     */
    private function entries(): array
    {
        \clearstatcache();

        $names = \scandir($this->cacheDir);

        self::assertNotFalse($names);

        $result = [];

        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $result[] = $name;
            }
        }

        \sort($result);

        return $result;
    }
}
