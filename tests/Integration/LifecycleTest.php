<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\LockInterface;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolver;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Config\BundleConfig;
use TailwindPhp\Config\Config;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\LockTimeoutException;
use TailwindPhp\Freshness\CheckThrottle;
use TailwindPhp\Pipeline\AssetPipeline;
use TailwindPhp\Pipeline\CompileOrchestrator;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\PreProcessor\NullPreProcessor;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FrozenClock;
use TailwindPhp\Support\Hash;
use TailwindPhp\Tests\Support\CountingCompiler;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\RecordingLock;
use TailwindPhp\Tests\Support\RecordingLogger;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The non-HTTP rows of the lifecycle matrix (design doc 16 §Lifecycle matrix,
 * digest §6). One test per row, each asserting the two things the matrix
 * actually pins: **what was served** (`source`) and **what it cost** (compiles,
 * and for the cached rows, reads).
 *
 * These run against a real temporary project through the real `CacheStore`,
 * `FileFinder`, `ScanCache` and `DriverResolver` — only the compiler (counted),
 * the clock (frozen) and, where a row is about locking, the lock are doubles.
 * A matrix asserted against mocks would prove the mocks agree with themselves.
 *
 * The HTTP rows (304 / 302 / HEAD / gzip negotiation) belong to wave 3.
 *
 * @covers-requirement FR-PIPE-1
 * @covers-requirement FR-FRESH-1
 *
 * @covers-requirement FR-PIPE-4 FR-FRESH-1 FR-FRESH-2 FR-FRESH-3 FR-FRESH-4 FR-FRESH-5
 * @covers-requirement FR-FRESH-7 FR-FRESH-8 FR-CACHE-1 FR-CACHE-3 FR-CACHE-4 FR-COMP-4
 * @covers-requirement FR-ERR-1 FR-ERR-2 FR-ERR-3
 */
final class LifecycleTest extends TestCase
{
    /**
     * @var TempProject|null
     */
    private $project;

    /**
     * @var CountingCompiler|null
     */
    private $compiler;

    /**
     * @var RecordingFilesystem|null
     */
    private $fs;

    /**
     * @var FrozenClock|null
     */
    private $clock;

    /**
     * @var LockInterface|null Set by a row that is about locking.
     */
    private $lock;

    /**
     * @var Checksums|null Set by a row that installs the fake binary.
     */
    private $checksums;

    /**
     * @var RecordingLogger|null Built lazily; one per test.
     */
    private $logger;

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            $this->project->remove();
            $this->project = null;
        }

        $this->compiler = null;
        $this->fs = null;
        $this->clock = null;
        $this->lock = null;
        $this->checksums = null;
        $this->logger = null;
    }

    /**
     * @test
     */
    public function coldCacheCompiles(): void
    {
        $pipeline = $this->pipeline();

        $result = $pipeline->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
        self::assertFileExists($result->path() ?? '');
        self::assertNotNull($this->project()->manifest('app'));
    }

    /**
     * @test
     */
    public function warmFreshServesTheCache(): void
    {
        $pipeline = $this->pipeline();
        $first = $pipeline->resolve('app');

        $this->compiler()->resetCalls();
        $second = $this->pipeline()->resolve('app');

        self::assertSame('cached', $second->source());
        self::assertSame(0, $this->compiler()->calls());
        self::assertSame($first->fingerprint(), $second->fingerprint());
    }

    /**
     * I4 (digest §4.5): a cached answer reads the manifest and nothing else.
     * The stat calls that back the freshness walk are not reads (CSF-A18); a
     * second `read()` would mean the stylesheet or a template was opened for a
     * request that already knew the answer.
     *
     * @test
     */
    public function servingTheCachePerformsExactlyOneRead(): void
    {
        $this->pipeline()->resolve('app');

        $this->filesystem()->reset();
        $result = $this->pipeline()->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(1, $this->filesystem()->reads());
    }

    /**
     * @test
     */
    public function touchedTemplateWithTheSameCandidatesIsTouchedNotCompiled(): void
    {
        $first = $this->pipeline()->resolve('app');

        $this->compiler()->resetCalls();
        $this->project()->touchFile('templates/index.twig', 120);

        $result = $this->pipeline()->resolve('app');

        self::assertSame('touched', $result->source());
        self::assertSame(0, $this->compiler()->calls());
        self::assertSame($first->fingerprint(), $result->fingerprint());
    }

    /**
     * @test
     */
    public function editedTemplateWithANewCandidateCompiles(): void
    {
        $first = $this->pipeline()->resolve('app');

        $this->compiler()->resetCalls();
        $this->project()->writeFile('templates/index.twig', '<div class="flex p-4 text-sm rotate-45"></div>');

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
        self::assertNotSame($first->fingerprint(), $result->fingerprint());
    }

    /**
     * @test
     */
    public function editedInputStylesheetCompiles(): void
    {
        $this->pipeline()->resolve('app');

        $this->compiler()->resetCalls();
        $this->project()->writeFile('assets/styles/main.css', "@import \"tailwindcss\";\n/* edited */\n");

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function aChangedConfigHashCompiles(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        // `safelist` is a hashed key (CSF-A4), so the environment fingerprint
        // moves and the cache is no longer the cache for *this* configuration.
        $result = $this->pipeline(['safelist' => ['underline']])->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function aCorruptManifestRebuildsAndWarns(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        \file_put_contents($this->project()->manifestPath('app'), '{ not json');

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
        self::assertTrue(
            self::bagHasCode($result->diagnostics()->all(), Diagnostic::W_MANIFEST_CORRUPT),
            'the corrupt manifest should be reported as a warning, not silently discarded'
        );
    }

    /**
     * CCP-A17: the manifest names a fingerprinted file. If that file is gone the
     * manifest is not a cache, whatever it says about itself.
     *
     * @test
     */
    public function aMissingArtefactRebuilds(): void
    {
        $first = $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        \unlink($first->path() ?? '');

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function freezeServesTheCacheWithoutLookingAtTheTree(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');

        $result = $this->pipeline(['freeze' => true])->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(0, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function insideTheCheckIntervalTheTreeIsNotConsulted(): void
    {
        $this->pipeline(['check_interval' => 600])->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');

        $result = $this->pipeline(['check_interval' => 600])->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(0, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function pastTheCheckIntervalTheTreeIsConsulted(): void
    {
        $this->pipeline(['check_interval' => 600])->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');
        $this->clock()->advance(601);

        $result = $this->pipeline(['check_interval' => 600])->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function debugAlwaysCompilesAndNeverServesACache(): void
    {
        $this->pipeline(['debug' => true])->resolve('app');
        $this->compiler()->resetCalls();

        $result = $this->pipeline(['debug' => true])->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function aFailedCompileInDebugRendersTheOverlay(): void
    {
        $pipeline = $this->pipeline(['debug' => true]);
        $this->compiler()->failWith(new \RuntimeException('engine exploded'));

        $result = $pipeline->resolve('app');

        self::assertTrue($result->isInline());
        self::assertSame(200, $result->status());
        self::assertSame('error', $result->source());
        self::assertStringContainsString('html::before', $result->body());
    }

    /**
     * @test
     */
    public function aFailedCompileInProductionWithoutACacheServesTheConstantBody(): void
    {
        $pipeline = $this->pipeline();
        $this->compiler()->failWith(new \RuntimeException('engine exploded'));

        $result = $pipeline->resolve('app');

        self::assertTrue($result->isInline());
        self::assertSame(500, $result->status());
        self::assertSame(ErrorRenderer::PRODUCTION_BODY, $result->body());
    }

    /**
     * CCP-A21 — and I2: the cache that was valid before the failed compile is
     * still valid after it.
     *
     * @test
     */
    public function aFailedCompileInProductionWithACacheServesStaleAndRecordsTheError(): void
    {
        $first = $this->pipeline()->resolve('app');

        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');
        $this->compiler()->failWith(new \RuntimeException('engine exploded'));

        $result = $this->pipeline()->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertIsArray($manifest['last_error']);
        self::assertArrayHasKey('message_short', $manifest['last_error']);
    }

    /**
     * I3: a request that loses the lock and has something to serve serves it
     * rather than queueing behind the winner — one compile, never two.
     *
     * @test
     */
    public function aHeldLockWithACacheServesStale(): void
    {
        $first = $this->pipeline()->resolve('app');

        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');
        $this->compiler()->resetCalls();
        $this->lock = new RecordingLock(true, false);

        $result = $this->pipeline()->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame(0, $this->compiler()->calls());
        self::assertSame($first->fingerprint(), $result->fingerprint());
    }

    /**
     * @test
     */
    public function aHeldLockWithoutACacheTimesOut(): void
    {
        $this->lock = new RecordingLock(true, false);
        $pipeline = $this->pipeline(['lock_timeout' => 1]);

        $result = $pipeline->resolve('app');

        // Fail-soft turns the timeout into the production body rather than
        // letting a LockTimeoutException reach the page (design doc 15).
        self::assertTrue($result->isInline());
        self::assertSame(500, $result->status());
        self::assertInstanceOf(LockTimeoutException::class, $result->cause());
    }

    /**
     * @test
     */
    public function aMissingRootWithoutAManifestIsAConfigurationError(): void
    {
        $pipeline = $this->pipeline();
        $this->removeDirectory($this->project()->root() . '/templates');

        $this->expectException(ConfigurationException::class);

        $pipeline->resolve('app');
    }

    /**
     * @test
     */
    public function aMissingRootWithAManifestKeepsTheKnownFilesAndWarns(): void
    {
        // The same pipeline instance throughout: `ConfigBuilder` refuses a root
        // that does not exist, so this row is only reachable the way it happens
        // in production — a directory that vanishes after the config was built.
        $pipeline = $this->pipeline();
        $first = $pipeline->resolve('app');
        $this->compiler()->resetCalls();

        $this->removeDirectory($this->project()->root() . '/templates');

        $result = $pipeline->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(0, $this->compiler()->calls());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        self::assertTrue(self::bagHasCode($result->diagnostics()->all(), Diagnostic::W_ROOT_MISSING));
    }

    /**
     * @test
     */
    public function theCandidateCapIsReportedAsAWarning(): void
    {
        $pipeline = $this->pipeline(['limits' => ['max_candidates' => 2]]);

        $pipeline->resolve('app');

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame(2, $manifest['candidates_count']);
        self::assertTrue(
            self::rowsHaveCode($manifest['warnings'], Diagnostic::W_CANDIDATE_LIMIT),
            'the truncation must be recorded in the manifest, where doctor can find it'
        );
    }

    /**
     * @test
     */
    public function gzipWritesTheCompressedTwin(): void
    {
        if (!\function_exists('gzencode')) {
            self::markTestSkipped('zlib is not available.');
        }

        $result = $this->pipeline(['gzip' => true])->resolve('app');

        $gzPath = $result->gzPath();
        self::assertNotNull($gzPath);
        self::assertFileExists($gzPath);
    }

    // ------------------------------------------------ rows added in round 5

    /**
     * The configured driver reaches the resolver (TASK-0015 round 4, M1): with
     * `driver: cli` and a verified binary the CLI runs, and the manifest says so.
     *
     * @test
     */
    public function theConfiguredCliDriverIsHonoured(): void
    {
        $binary = $this->installFakeBinary();

        $result = $this->pipeline(['driver' => 'cli', 'cli' => ['binary' => $binary]])->resolve('app');

        self::assertSame('compiled', $result->source());

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame('cli', $manifest['driver']);
        self::assertIsArray($manifest['driver_probe']);
        self::assertNotSame([], $this->cliRecords(), 'the fake binary must have run and written its argv record');
        self::assertSame(0, $this->compiler()->calls(), 'the PHP engine must not run under driver: cli');
    }

    /**
     * `driver: php` is a guarantee, not a preference: a verified binary sitting
     * in vendor/bin must never be spawned.
     *
     * @test
     */
    public function thePhpDriverNeverSpawnsEvenWhenABinaryIsPresent(): void
    {
        $binary = $this->installFakeBinary();

        $result = $this->pipeline(['driver' => 'php', 'cli' => ['binary' => $binary]])->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame('php', $manifest['driver']);
        self::assertNull($manifest['driver_probe']);
        self::assertSame([], $this->cliRecords(), 'no process may have been started');
    }

    /**
     * T-05: an entry above `limits.max_total_css` fails the build instead of
     * being silently truncated and cached as a valid stylesheet.
     *
     * @test
     */
    public function anOversizedEntryStylesheetFailsTheBuildInsteadOfTruncating(): void
    {
        $this->project()->writeFile(
            'assets/styles/main.css',
            "@import \"tailwindcss\";\n" . \str_repeat("/* padding */\n", 200)
        );

        $result = $this->pipeline(['limits' => ['max_total_css' => 1024]])->resolve('app');

        self::assertTrue($result->isInline());
        self::assertSame(500, $result->status());
        self::assertNull($this->project()->manifest('app'), 'nothing may be cached from a rejected entry');
        self::assertSame(0, $this->compiler()->calls());

        $cause = $result->cause();
        self::assertInstanceOf(\TailwindPhp\Exception\CompilationException::class, $cause);
        self::assertTrue(self::bagHasCode($cause->diagnostics()->all(), Diagnostic::E_BUILD_FAILED));
    }

    /**
     * T-06: a third-party exception carrying an absolute path is scrubbed
     * before the overlay renders it.
     *
     * @test
     */
    public function theDebugOverlayCarriesNoAbsolutePath(): void
    {
        $root = $this->project()->root();
        $this->compiler()->failWith(new \RuntimeException('engine exploded at ' . $root . '/assets/styles/main.css'));

        $result = $this->pipeline(['debug' => true])->resolve('app');

        self::assertTrue($result->isInline());
        self::assertSame(200, $result->status());
        $body = (string) $result->body();
        self::assertStringContainsString('engine exploded', $body);
        self::assertStringNotContainsString($root, $body);
        self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $body);
    }

    /**
     * T-14: a cache directory that is a link out of the project is refused
     * before anything — including the lock file — is created behind it.
     *
     * @test
     */
    public function aLinkedCacheDirectoryWritesNothingOutsideNotEvenTheLock(): void
    {
        $root = $this->project()->root();
        $outside = \dirname($root) . '/lifecycle-outside-' . \bin2hex(\random_bytes(3));
        \mkdir($outside, 0777, true);

        // The configuration is built while the cache dir is a plain directory
        // (ConfigBuilder refuses a link at config time — defence in depth); the
        // link is planted afterwards, which is the runtime case this row pins.
        $pipeline = $this->pipeline();
        $this->removeDirectory($root . '/cache/tailwind');

        if (!$this->link($root . '/cache/tailwind', $outside)) {
            $this->removeDirectory($outside);
            self::markTestSkipped('this host cannot create a directory link');
        }

        try {
            $result = $pipeline->resolve('app');

            self::assertTrue($result->isInline());
            self::assertSame(500, $result->status());
            self::assertSame(
                [],
                \array_values(\array_diff(\scandir($outside) ?: [], ['.', '..'])),
                'nothing may be written outside the project'
            );
        } finally {
            @\rmdir($root . '/cache/tailwind');
            $this->removeDirectory($outside);
        }
    }

    /**
     * The `touched` outcome republishes the manifest under the lock, clears a
     * stale `last_error`, and the request after it is a plain cache hit.
     *
     * @test
     */
    public function aTouchAfterARecordedFailureClearsTheErrorAndTheNextRequestIsCached(): void
    {
        $original = '<div class="flex p-4 text-sm"></div>';
        $this->project()->writeFile('templates/index.twig', $original);
        $this->pipeline()->resolve('app');

        // A failed build leaves `last_error` behind …
        $this->project()->writeFile('templates/index.twig', '<div class="rotate-45"></div>');
        $this->compiler()->failWith(new \RuntimeException('engine exploded'));
        $this->pipeline()->resolve('app');
        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertIsArray($manifest['last_error']);

        // … the author reverts the template: same candidates, new mtime.
        $this->compiler = null;
        $this->project()->writeFile('templates/index.twig', $original);
        $this->project()->touchFile('templates/index.twig', 240);

        $touched = $this->pipeline()->resolve('app');
        self::assertSame('touched', $touched->source());

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertNull($manifest['last_error']);

        $this->filesystem()->reset();
        $next = $this->pipeline()->resolve('app');
        self::assertSame('cached', $next->source());
        self::assertSame(1, $this->filesystem()->reads());
    }

    /**
     * With no `check_interval`, a warm cached request touches nothing.
     *
     * @test
     */
    public function aWarmCachedRequestDoesNotTouchTheStamp(): void
    {
        $this->pipeline()->resolve('app');

        $this->filesystem()->reset();
        $result = $this->pipeline()->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertArrayNotHasKey('touch', $this->filesystem()->calls());
        self::assertFileDoesNotExist($this->project()->root() . '/cache/tailwind/app.checked');
    }

    /**
     * A binary that exists but cannot be verified is the one fallback an
     * operator must hear about: `auto` picks the PHP engine and says why.
     *
     * @test
     */
    public function anUnverifiableBinaryUnderAutoIsLoggedAsAChecksumWarning(): void
    {
        $binary = $this->installFakeBinary();
        $this->checksums = Checksums::fromTable([
            '4.1.18' => [(new Environment())->platform() => \str_repeat('0', 64)],
        ]);

        $result = $this->pipeline(['driver' => 'auto', 'cli' => ['binary' => $binary]])->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls(), 'the PHP engine took over');
        self::assertSame([], $this->cliRecords(), 'the unverified binary must not run');
        self::assertTrue($this->logger()->has('warning', Diagnostic::W_CHECKSUM_UNKNOWN));
    }

    /**
     * The overlay names the driver that actually ran, not the configured word:
     * `auto` that resolved to the CLI says `cli`.
     *
     * @test
     */
    public function theOverlayNamesTheDriverThatRan(): void
    {
        $binary = $this->installFakeBinary();
        $this->project()->writeFile(
            'assets/styles/main.css',
            ":root{--fake:'fake: fail 2'}\n@import \"tailwindcss\";\n"
        );

        $result = $this->pipeline(['driver' => 'auto', 'debug' => true, 'cli' => ['binary' => $binary]])
            ->resolve('app');

        self::assertTrue($result->isInline());
        self::assertSame(200, $result->status());
        self::assertStringContainsString('driver: cli', (string) $result->body());
    }

    /**
     * @test
     */
    public function theCandidateLimitWarningIsLogged(): void
    {
        $this->pipeline(['limits' => ['max_candidates' => 2]])->resolve('app');

        self::assertTrue($this->logger()->has('warning', Diagnostic::W_CANDIDATE_LIMIT));
    }

    /**
     * @test
     */
    public function aTemplateAddedWithAnOldMtimeCompiles(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->writeFile('templates/late.twig', '<p class="underline"></p>');
        $this->project()->touchFile('templates/late.twig', -86400);

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source(), 'an added file is a change whatever its mtime says');
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function aRemovedTemplateWhoseCandidatesVanishCompiles(): void
    {
        $this->project()->writeFile('templates/extra.twig', '<p class="underline"></p>');
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->deleteFile('templates/extra.twig');

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function anEditedImportedStylesheetCompiles(): void
    {
        $this->project()->writeFile('assets/styles/partial.css', ".a { color: red; }\n");
        $this->project()->writeFile('assets/styles/main.css', "@import \"tailwindcss\";\n@import \"./partial.css\";\n");
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();

        $this->project()->writeFile('assets/styles/partial.css', ".a { color: blue; }\n");

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * The same edit under `driver: cli` (TASK-0034): the entry's relative
     * import is resolved against the entry's own directory and inlined into
     * what reaches the binary.
     *
     * @test
     */
    public function anEditedImportedStylesheetCompilesWithTheCliDriver(): void
    {
        $binary = $this->installFakeBinary();
        $overrides = ['driver' => 'cli', 'cli' => ['binary' => $binary]];
        $this->project()->writeFile('assets/styles/partial.css', ".a { color: red; }\n");
        $this->project()->writeFile('assets/styles/main.css', "@import \"tailwindcss\";\n@import \"./partial.css\";\n");
        $this->pipeline($overrides)->resolve('app');

        $this->project()->writeFile('assets/styles/partial.css', ".a { color: blue; }\n");

        $result = $this->pipeline($overrides)->resolve('app');

        self::assertSame('compiled', $result->source());

        $inputs = [];

        foreach ($this->cliRecords() as $name) {
            $path = $this->project()->root() . '/cache/tailwind/' . $name;
            $record = \json_decode((string) \file_get_contents($path), true);
            $inputs[] = \is_array($record) ? (string) ($record['input'] ?? '') : '';
        }

        self::assertStringContainsString('color: blue', \implode("\n", $inputs), 'the partial was inlined');
        self::assertStringNotContainsString('@import "./partial.css"', \implode("\n", $inputs));
    }

    /**
     * @test
     */
    public function aPackageVersionChangeCompiles(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();
        $this->editManifest('app', static function (array $manifest): array {
            $manifest['package_version'] = '0.0.0-elsewhere';

            return $manifest;
        });

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * @test
     */
    public function aDriverVersionChangeCompiles(): void
    {
        $this->pipeline()->resolve('app');
        $this->compiler()->resetCalls();
        $this->editManifest('app', static function (array $manifest): array {
            $manifest['driver_version'] = 'stub-m0';

            return $manifest;
        });

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertSame(1, $this->compiler()->calls());
    }

    /**
     * CCP-A19: a loser that had nothing to serve waits, and when the winner has
     * finished meanwhile it serves the winner's build without re-checking.
     *
     * @test
     */
    public function aWaitingLoserServesTheWinnersBuildWithoutCompiling(): void
    {
        // The winner: a normal compile in a sibling project sharing the cache.
        $this->pipeline()->resolve('app');
        $manifestPath = $this->project()->manifestPath('app');
        $manifestJson = (string) \file_get_contents($manifestPath);
        $cacheDir = \dirname($manifestPath);
        $artefacts = [];
        foreach (\scandir($cacheDir) ?: [] as $entry) {
            if (\substr($entry, 0, 4) === 'app.' && \substr($entry, -4) === '.css') {
                $artefacts[$entry] = (string) \file_get_contents($cacheDir . '/' . $entry);
            }
        }
        // Now make this request look like a cold-cache loser: no manifest, no
        // artefacts, a lock somebody else holds.
        $this->removeDirectory($cacheDir);
        \mkdir($cacheDir, 0777, true);
        $this->compiler()->resetCalls();

        $this->lock = new class ($manifestPath, $manifestJson, $artefacts) implements LockInterface {
            /** @var string */
            private $manifestPath;
            /** @var string */
            private $manifestJson;
            /** @var array<string, string> */
            private $artefacts;

            /**
             * @param array<string, string> $artefacts
             */
            public function __construct(string $manifestPath, string $manifestJson, array $artefacts)
            {
                $this->manifestPath = $manifestPath;
                $this->manifestJson = $manifestJson;
                $this->artefacts = $artefacts;
            }

            public function tryAcquire(): bool
            {
                return false;
            }

            public function acquire(float $timeoutSec): bool
            {
                // The winner finishes while we wait: its files appear.
                foreach ($this->artefacts as $name => $contents) {
                    \file_put_contents(\dirname($this->manifestPath) . '/' . $name, $contents);
                }
                \file_put_contents($this->manifestPath, $this->manifestJson);

                return true;
            }

            public function release(): void
            {
            }

            public function isSupported(): bool
            {
                return true;
            }
        };

        $result = $this->pipeline(['lock_timeout' => 1])->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(0, $this->compiler()->calls());
    }

    /**
     * The fast path has the waiting path's race in miniature: this request
     * loaded no manifest, the winner published one and released the lock in
     * the gap, and tryAcquire() now succeeds. Compiling again would be a second
     * build of the same content (I3), so the orchestrator looks once more
     * before it builds.
     *
     * @covers-requirement FR-CACHE-3
     * @covers-invariant I3
     *
     * @test
     */
    public function aFastPathWinnerThatFindsAFreshManifestServesItInsteadOfRebuilding(): void
    {
        $this->pipeline()->resolve('app');
        $manifestPath = $this->project()->manifestPath('app');
        $manifestJson = (string) \file_get_contents($manifestPath);
        $cacheDir = \dirname($manifestPath);
        $artefacts = [];

        foreach (\scandir($cacheDir) ?: [] as $entry) {
            if (\substr($entry, 0, 4) === 'app.' && \substr($entry, -4) === '.css') {
                $artefacts[$entry] = (string) \file_get_contents($cacheDir . '/' . $entry);
            }
        }

        $this->removeDirectory($cacheDir);
        \mkdir($cacheDir, 0777, true);
        $this->compiler()->resetCalls();

        $this->lock = new class ($manifestPath, $manifestJson, $artefacts) implements LockInterface {
            /** @var string */
            private $manifestPath;
            /** @var string */
            private $manifestJson;
            /** @var array<string, string> */
            private $artefacts;

            /**
             * @param array<string, string> $artefacts
             */
            public function __construct(string $manifestPath, string $manifestJson, array $artefacts)
            {
                $this->manifestPath = $manifestPath;
                $this->manifestJson = $manifestJson;
                $this->artefacts = $artefacts;
            }

            public function tryAcquire(): bool
            {
                // The winner released a moment ago and its files are already there.
                foreach ($this->artefacts as $name => $contents) {
                    \file_put_contents(\dirname($this->manifestPath) . '/' . $name, $contents);
                }
                \file_put_contents($this->manifestPath, $this->manifestJson);

                return true;
            }

            public function acquire(float $timeoutSec): bool
            {
                return true;
            }

            public function release(): void
            {
            }

            public function isSupported(): bool
            {
                return true;
            }
        };

        $result = $this->pipeline()->resolve('app');

        self::assertSame('cached', $result->source());
        self::assertSame(0, $this->compiler()->calls(), 'the fresh manifest is served, not rebuilt');
    }

    /**
     * The cache directory and its guards are created by the pipeline itself
     * when absent (`ensureDirectory()` runs before the lock).
     *
     * @test
     */
    public function anAbsentCacheDirectoryIsCreatedWithItsGuards(): void
    {
        $cacheDir = $this->project()->root() . '/cache/tailwind';
        $this->removeDirectory($cacheDir);

        $result = $this->pipeline()->resolve('app');

        self::assertSame('compiled', $result->source());
        self::assertFileExists($cacheDir . '/.htaccess');
        self::assertFileExists($cacheDir . '/index.html');
    }

    /**
     * I1: a build whose artefact cannot be written leaves the previous manifest
     * naming an artefact that exists, and nothing half-written under the new
     * name.
     *
     * @test
     */
    public function aFailedArtefactWriteLeavesThePreviousBuildIntact(): void
    {
        $first = $this->pipeline()->resolve('app');

        // The next build's fingerprint is a function of its content alone, so
        // a sibling project with the new template tells us the name in advance.
        $edited = '<div class="flex p-4 text-sm rotate-45"></div>';
        $sibling = $this->siblingPipeline($edited);
        $next = $sibling['pipeline']->resolve('app');
        $sibling['project']->remove();
        self::assertNotSame($first->fingerprint(), $next->fingerprint());

        $this->project()->writeFile('templates/index.twig', $edited);
        $cacheDir = $this->project()->root() . '/cache/tailwind';
        \mkdir($cacheDir . '/app.' . $next->fingerprint() . '.css');

        $result = $this->pipeline()->resolve('app');

        self::assertSame('stale-fallback', $result->source());
        self::assertSame($first->fingerprint(), $result->fingerprint());
        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame($first->fingerprint(), $manifest['fingerprint']);
        self::assertFileExists((string) $first->path());
        self::assertDirectoryExists(
            $cacheDir . '/app.' . $next->fingerprint() . '.css',
            'the pre-planted directory is untouched: no partial artefact was written under the new name'
        );
    }

    // ---------------------------------------------------------------- harness

    /**
     * @param array<string, mixed> $overrides
     */
    private function pipeline(array $overrides = []): AssetPipeline
    {
        $project = $this->project();

        $user = \array_merge([
            'project_root' => $project->root(),
            'driver' => 'php',
            'input' => 'assets/styles/main.css',
            'sources' => ['templates'],
            'cache_dir' => 'cache/tailwind',
            'bundles' => ['app' => []],
        ], $overrides);

        $config = ConfigBuilder::fromArray($user);
        $bundle = $config->bundle('app');
        $fs = $this->filesystem();
        $clock = $this->clock();
        $writer = new AtomicWriter($config->projectRoot());

        $store = new CacheStore(
            $config->cacheDir(),
            $config->allowedRoots(),
            $config->projectRoot(),
            $config->cacheDirMode(),
            $config->cacheFileMode(),
            $config->gzip(),
            $fs,
            $writer,
            null,
            $clock
        );

        $limits = $config->limits();
        $resolver = $this->driverResolver($config);

        $scanCacheFactory = function (array $previousFiles) use ($fs, $config, $limits): ScanCache {
            return new ScanCache(
                $fs,
                new CandidateExtractor(),
                static function (string $rel) {
                    return null;
                },
                $previousFiles,
                (int) $limits['max_file_size'],
                $config->projectRoot()
            );
        };

        $throttleFactory = function (string $stampPath) use ($fs, $clock, $config): CheckThrottle {
            return new CheckThrottle($fs, $clock, $stampPath, $config->checkInterval(), $config->checkStore());
        };

        $lock = $this->lock;
        $lockFactory = $lock === null
            ? null
            : static function (string $path) use ($lock): LockInterface {
                return $lock;
            };

        $orchestratorFactory = function (
            callable $scanSupplier,
            DiagnosticBag $requestBag
        ) use (
            $config,
            $store,
            $writer,
            $resolver,
            $fs,
            $clock,
            $lockFactory
        ): CompileOrchestrator {
            return new CompileOrchestrator(
                $config,
                $store,
                $writer,
                $resolver,
                $scanSupplier,
                static function (BundleConfig $bundle): NullPreProcessor {
                    return new NullPreProcessor();
                },
                $requestBag,
                $fs,
                $clock,
                $this->logger(),
                $lockFactory
            );
        };

        return new AssetPipeline(
            $config,
            $store,
            new FileFinder($config->cacheDir(), $bundle->ignore(), (int) $limits['max_files']),
            $scanCacheFactory,
            $throttleFactory,
            $resolver,
            $orchestratorFactory,
            $fs,
            $this->logger()
        );
    }

    private function driverResolver(Config $config): DriverResolver
    {
        $fs = $this->filesystem();
        $root = $config->projectRoot();
        $cacheDir = $config->cacheDir();

        return new DriverResolver(
            new Environment(),
            new BinaryVerifier($this->checksums ?? Checksums::fromTable([]), $fs, $root),
            $this->compiler(),
            static function (string $binaryPath, string $version) use ($fs, $root, $cacheDir): CompilerInterface {
                return new StandaloneCliCompiler(
                    $binaryPath,
                    $version,
                    $cacheDir,
                    20.0,
                    new Process(),
                    $fs,
                    $root,
                    new AtomicWriter($root),
                    new Environment()
                );
            }
        );
    }

    /**
     * Copy the fixture binary into the project's vendor/bin and register its
     * digest, exactly as TASK-0014's tests do.
     *
     * @return string The project-relative binary path for `cli.binary`.
     */
    private function installFakeBinary(): string
    {
        $root = $this->project()->root();
        $source = \dirname(__DIR__) . '/Fixtures/bin/fake-tailwindcss';
        \mkdir($root . '/vendor/bin', 0777, true);
        $target = $root . '/vendor/bin/tailwindcss';
        \copy($source, $target);
        \chmod($target, 0755);

        if (\DIRECTORY_SEPARATOR === '\\') {
            \copy($source . '.cmd', $target . '.cmd');
            \copy($source, $root . '/vendor/bin/fake-tailwindcss');
            $target .= '.cmd';
        }

        $this->checksums = Checksums::fromTable([
            '4.1.18' => [(new Environment())->platform() => Hash::sha256File($target)],
        ]);

        return 'vendor/bin/' . \basename($target);
    }

    /**
     * The argv records the fake binary leaves beside its temp directory.
     *
     * @return list<string>
     */
    private function cliRecords(): array
    {
        $cacheDir = $this->project()->root() . '/cache/tailwind';
        $records = [];

        foreach (\scandir($cacheDir) ?: [] as $entry) {
            if (\substr($entry, -10) === '.argv.json') {
                $records[] = $entry;
            }
        }

        return $records;
    }

    private function logger(): RecordingLogger
    {
        if ($this->logger === null) {
            $this->logger = new RecordingLogger();
        }

        return $this->logger;
    }

    /**
     * Rewrite the manifest on disk through a callback — for rows that simulate
     * a build made elsewhere (another package version, another driver).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    private function editManifest(string $bundle, callable $edit): void
    {
        $path = $this->project()->manifestPath($bundle);
        $manifest = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($manifest);
        \file_put_contents($path, \json_encode($edit($manifest), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * A directory link: a junction on Windows, a symlink elsewhere.
     */
    private function link(string $link, string $target): bool
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $output = [];
            $code = 1;
            $command = 'cmd /c mklink /J '
                . \escapeshellarg(\str_replace('/', '\\', $link)) . ' '
                . \escapeshellarg(\str_replace('/', '\\', $target)) . ' 2>&1';
            \exec($command, $output, $code);

            return $code === 0 && \is_dir($link);
        }

        return @\symlink($target, $link);
    }

    /**
     * A second, independent project with the given template, so a row can learn
     * what fingerprint a build *would* produce before making it fail.
     *
     * @return array{pipeline: AssetPipeline, project: TempProject}
     */
    private function siblingPipeline(string $template): array
    {
        $project = TempProject::make([]);
        $project->writeFile('templates/index.twig', $template);

        $saved = [$this->project, $this->fs, $this->compiler, $this->clock, $this->logger];
        $this->project = $project;
        $this->fs = null;
        $this->compiler = null;
        $this->logger = null;
        $pipeline = $this->pipeline();
        [$this->project, $this->fs, $this->compiler, $this->clock, $this->logger] = $saved;

        return ['pipeline' => $pipeline, 'project' => $project];
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = TempProject::make([]);
        }

        return $this->project;
    }

    private function compiler(): CountingCompiler
    {
        if ($this->compiler === null) {
            $this->compiler = new CountingCompiler(new PhpEngineCompiler());
        }

        return $this->compiler;
    }

    private function filesystem(): RecordingFilesystem
    {
        if ($this->fs === null) {
            $this->fs = new RecordingFilesystem(new Filesystem());
        }

        return $this->fs;
    }

    private function clock(): FrozenClock
    {
        if ($this->clock === null) {
            $this->clock = new FrozenClock(1700000000);
        }

        return $this->clock;
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private static function bagHasCode(array $diagnostics, string $code): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $rows
     */
    private static function rowsHaveCode($rows, string $code): bool
    {
        if (!\is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (\is_array($row) && ($row['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (\is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            \unlink($path);
        }

        \rmdir($dir);
    }
}
