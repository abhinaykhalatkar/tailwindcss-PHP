<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Support\SystemClock;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The seven invariants of design doc 04 §8, one row each, asserted through the
 * public facade so a reader can find every invariant in one place.
 *
 * Where a committed suite already pins the same property with a sharper
 * instrument (the recording filesystem in {@see LifecycleTest}, the fork in
 * {@see Concurrency\ColdCacheForkTest}) the row here says so and asserts the
 * externally observable consequence.
 */
final class InvariantsTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';
    private const EDITED = '<div class="flex p-4 text-sm rotate-45"></div>';

    /**
     * @var list<TempProject>
     */
    private $projects = [];

    /**
     * @var FlockBuildLock|null
     */
    private $held;

    protected function tearDown(): void
    {
        if ($this->held !== null) {
            $this->held->release();
            $this->held = null;
        }

        foreach ($this->projects as $project) {
            $project->remove();
        }

        $this->projects = [];
    }

    /**
     * I1. resolve() never leaves a partially written artefact visible. A
     * directory planted at the final name makes the publish step fail; what
     * this row pins is the consequence — the previous build is served, no
     * temp file survives, nothing appears under the new name. That the write
     * itself goes through a temp name and a rename is pinned by
     * AtomicWriterTest (tests/Unit/Cache).
     *
     * @covers-requirement FR-CACHE-2
     * @covers-invariant I1
     *
     * @test
     */
    public function i1NoPartialArtefactIsEverVisible(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');

        // A sibling with the edited template tells us the next fingerprint.
        $sibling = $this->project();
        $sibling->writeFile('templates/index.twig', self::EDITED);
        $next = $sibling->tailwind()->resolve('app')->fingerprint();
        self::assertNotSame($first->fingerprint(), $next);

        $project->writeFile('templates/index.twig', self::EDITED);
        $project->touchFile('templates/index.twig', 5);
        $cacheDir = $project->root() . '/cache/tailwind';
        \mkdir($cacheDir . '/app.' . $next . '.css');

        $result = $project->tailwind()->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        self::assertDirectoryExists($cacheDir . '/app.' . $next . '.css');

        $leftovers = \array_filter(
            (array) \scandir($cacheDir),
            static function ($name): bool {
                return \is_string($name) && (\strpos($name, '.tmp') !== false || \strpos($name, '.gz.') !== false);
            }
        );
        self::assertSame([], \array_values($leftovers), 'no temp file survives a failed rename');
    }

    /**
     * I2. If a valid cache existed before a failed compile, it is still valid
     * after: the entry grows past limits.max_total_css, the build fails, and
     * the previous manifest and artefact are what the next request serves.
     *
     * @covers-requirement FR-FRESH-7 FR-ERR-2
     * @covers-invariant I2
     *
     * @test
     */
    public function i2AValidCacheSurvivesAFailedCompile(): void
    {
        $project = $this->project();
        $first = $project->tailwind(['log_level' => 'emergency'])->resolve('app');
        $manifestBefore = $project->manifest('app');
        self::assertNotNull($manifestBefore);

        $project->writeFile('assets/styles/main.css', "@import \"tailwindcss\";\n" . \str_repeat('/* pad */', 400));
        $project->touchFile('assets/styles/main.css', 5);

        $result = $project->tailwind(['limits' => ['max_total_css' => 1024], 'log_level' => 'emergency'])
            ->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        self::assertFileExists((string) $first->path());

        $manifestAfter = $project->manifest('app');
        self::assertNotNull($manifestAfter);
        self::assertSame($manifestBefore['fingerprint'] ?? null, $manifestAfter['fingerprint'] ?? null);
        self::assertSame($manifestBefore['built_at'] ?? null, $manifestAfter['built_at'] ?? null);
        self::assertNotNull($manifestAfter['last_error'] ?? null, 'the failure is recorded, the build is kept');
    }

    /**
     * I3. Two concurrent requests result in exactly one compile: with the
     * build lock held by another party a warm request serves the previous
     * build without compiling. The twenty-fork variant lives in
     * {@see Concurrency\ColdCacheForkTest}; the lock-held rows in
     * {@see Concurrency\LockHeldTest}.
     *
     * @covers-requirement FR-CACHE-3
     * @covers-invariant I3
     *
     * @test
     */
    public function i3OnlyOneProcessCompilesABundle(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');

        $project->writeFile('templates/index.twig', self::EDITED);
        $project->touchFile('templates/index.twig', 5);

        $lock = new FlockBuildLock($project->root() . '/cache/tailwind/app.lock', new SystemClock());

        if (!$lock->isSupported()) {
            self::markTestSkipped('flock() is not supported on this host');
        }

        self::assertTrue($lock->tryAcquire());
        $this->held = $lock;

        $result = $project->tailwind()->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        self::assertSame($first->fingerprint(), $project->manifest('app')['fingerprint'] ?? null);
    }

    /**
     * I4. A cached BuildResult involved zero file reads other than the
     * manifest. The recording filesystem in {@see LifecycleTest} counts the
     * reads (exactly one); this row asserts the observable consequence: a
     * template whose bytes change while its size and mtime do not is *not*
     * noticed, because nothing read it.
     *
     * @covers-requirement FR-FRESH-1 NFR-PERF-1
     * @covers-invariant I4
     *
     * @test
     */
    public function i4ACachedServeReadsNothingButTheManifest(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');
        self::assertSame('compiled', $first->source());

        $template = $project->root() . '/templates/index.twig';
        $original = (string) \file_get_contents($template);
        $mtime = (int) \filemtime($template);

        // Same length, different classes; mtime restored to the recorded one.
        $swapped = \str_replace('text-sm', 'text-lg', $original);
        self::assertSame(\strlen($original), \strlen($swapped));
        \file_put_contents($template, $swapped);
        \touch($template, $mtime);
        \clearstatcache(true, $template);

        $result = $project->tailwind()->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint(), 'no read, no re-extraction, no rebuild');
    }

    /**
     * I5. The fingerprint in the URL helper equals the fingerprint in the
     * served ETag.
     *
     * @covers-requirement FR-HTTP-8 FR-HTTP-2
     * @covers-invariant I5
     *
     * @test
     */
    public function i5TheUrlFingerprintIsTheServedEtag(): void
    {
        $project = $this->project();
        $tailwind = $project->tailwind();
        $tailwind->resolve('app');

        $url = $tailwind->url('/');
        self::assertSame(1, \preg_match('#^/assets/styles\.([a-f0-9]+)\.css$#', $url, $match), $url);
        $fingerprint = $match[1] ?? '';

        $response = $project->request(self::ROUTE);
        self::assertNotNull($response);
        self::assertSame('"' . $fingerprint . '"', $response->headerValue('ETag'));

        $fingerprinted = $project->request('assets/styles.' . $fingerprint . '.css');
        self::assertNotNull($fingerprinted);
        self::assertSame(200, $fingerprinted->status());
    }

    /**
     * I6. The plain route never sends `immutable`; only the fingerprinted one
     * does.
     *
     * @covers-requirement FR-HTTP-6
     * @covers-invariant I6
     *
     * @test
     */
    public function i6ThePlainRouteIsNeverImmutable(): void
    {
        $project = $this->project();
        $fingerprint = $project->tailwind()->resolve('app')->fingerprint();

        $plain = $project->request(self::ROUTE);
        self::assertNotNull($plain);
        self::assertSame(200, $plain->status());
        self::assertStringNotContainsString('immutable', (string) $plain->headerValue('Cache-Control'));

        $versioned = $project->request('assets/styles.' . $fingerprint . '.css');
        self::assertNotNull($versioned);
        self::assertStringContainsString('immutable', (string) $versioned->headerValue('Cache-Control'));
    }

    /**
     * I7. In debug mode the response is never cacheable: `no-store`, no ETag,
     * no Last-Modified, and a conditional request is still answered 200.
     *
     * @covers-requirement FR-HTTP-7 FR-FRESH-4
     * @covers-invariant I7
     *
     * @test
     */
    public function i7DebugResponsesAreNeverCacheable(): void
    {
        $project = $this->project();
        $fingerprint = $project->tailwind()->resolve('app')->fingerprint();

        $response = $project->request(
            self::ROUTE,
            ['HTTP_IF_NONE_MATCH' => '"' . $fingerprint . '"'],
            ['debug' => true]
        );

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($response->headerValue('ETag'));
        self::assertNull($response->headerValue('Last-Modified'));
    }

    private function project(): TempProject
    {
        $project = TempProject::make();
        $this->projects[] = $project;

        return $project;
    }
}
