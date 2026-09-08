<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

use Psr\Log\LoggerInterface;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Cache\LockInterface;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Cache\NullLock;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\DriverResolution;
use TailwindPhp\Compiler\DriverResolverInterface;
use TailwindPhp\Config\BundleConfig;
use TailwindPhp\Config\Config;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\FilesystemImportResolver;
use TailwindPhp\Exception\CompilationException;
use TailwindPhp\Exception\LockTimeoutException;
use TailwindPhp\Freshness\FileSnapshot;
use TailwindPhp\PreProcessor\PreProcessorInterface;
use TailwindPhp\Scanner\CandidateSet;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\ClockInterface;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Hash;
use TailwindPhp\Version;

/**
 * The compile transaction (design doc 04 §4.9, doc 05; digest §4.3; ADR-006).
 *
 * One bundle, one lock, one write order. Everything that can leave the cache
 * directory in a state a later request would misread happens inside the lock,
 * in the order design doc 05 fixes: artefact first, manifest **last**. A
 * process killed halfway therefore leaves the *previous* manifest in place,
 * still naming a fingerprinted file that exists (invariant I1/I2).
 *
 * The lock is not a performance detail. Without it, N cold-cache requests each
 * spawn a compile — on the CLI driver that is N Node processes for one
 * stylesheet (threat T-05). A loser that finds a usable previous build serves
 * it stale rather than queueing (invariant I3); only a loser with nothing to
 * serve waits.
 *
 * Two collaborators arrive as closures because the pipeline owns the knowledge
 * they need and this class must not duplicate it: `$scanSupplier` yields the
 * snapshot and the {@see \TailwindPhp\Scanner\ScanCache} that produced it — the
 * pipeline may already have walked the tree at step 4.6, and walking it twice
 * for one request would be both slower and, with a template edited between the
 * two walks, inconsistent. `$preProcessorFactory` maps a bundle to its
 * pre-processor, which is a per-bundle setting (CSF-A20).
 *
 * @phpstan-type ScanContext array{0: FileSnapshot, 1: ScanCache}
 */
final class CompileOrchestrator
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var CacheStore
     */
    private $store;

    /**
     * @var DriverResolverInterface
     */
    private $driverResolver;

    /**
     * @var callable(): ScanContext
     */
    private $scanSupplier;

    /**
     * @var \TailwindPhp\Cache\AtomicWriter
     */
    private $writer;

    /**
     * @var callable(BundleConfig): PreProcessorInterface
     */
    private $preProcessorFactory;

    /**
     * The request-wide warning bag the pipeline also writes into. Shared rather
     * than copied: a `W_MANIFEST_CORRUPT` raised at step 4.1, long before this
     * class exists, still has to reach both the served result and the manifest
     * it is about to write.
     *
     * @var DiagnosticBag
     */
    private $requestBag;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var callable(string): LockInterface|null
     */
    private $lockFactory;

    /**
     * No I/O here (design doc 18).
     *
     * $scanSupplier is memoised by the caller and called at most once per
     * request. $requestBag is shared with the pipeline: everything in it reaches
     * the manifest's `warnings[]` (CCP-A25) and the served result's diagnostics.
     * $lockFactory is a test seam — given the absolute lock path it returns the
     * lock to use, and defaults to the `lock` config key (`flock` | `none`).
     *
     * @param callable(): ScanContext $scanSupplier
     * @param callable(BundleConfig): PreProcessorInterface $preProcessorFactory
     * @param callable(string): LockInterface|null $lockFactory
     */
    public function __construct(
        Config $config,
        CacheStore $store,
        \TailwindPhp\Cache\AtomicWriter $writer,
        DriverResolverInterface $driverResolver,
        callable $scanSupplier,
        callable $preProcessorFactory,
        DiagnosticBag $requestBag,
        FilesystemInterface $fs,
        ClockInterface $clock,
        ?LoggerInterface $logger = null,
        ?callable $lockFactory = null
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->writer = $writer;
        $this->driverResolver = $driverResolver;
        $this->scanSupplier = $scanSupplier;
        $this->preProcessorFactory = $preProcessorFactory;
        $this->requestBag = $requestBag;
        $this->fs = $fs;
        $this->clock = $clock;
        $this->logger = $logger;
        $this->lockFactory = $lockFactory;
    }

    /**
     * @param Manifest|null $previous The manifest this request loaded, or null
     *                                for a cold cache.
     * @param list<string>|null $candidates The candidate union the pipeline
     *                                      already computed (step 4.8), or null
     *                                      to extract it here.
     * @param string $decision One of {@see FreshnessDecision}; recorded for the
     *                         log line, never for control flow.
     *
     * @throws LockTimeoutException When a loser with nothing to serve waits out `lock_timeout`.
     * @throws CompilationException When the pre-processor or the compiler reports errors.
     * @throws \TailwindPhp\Exception\CacheException When the cache directory is unusable.
     */
    public function compile(
        BundleConfig $bundle,
        ?Manifest $previous,
        ?array $candidates,
        string $decision
    ): BuildResult {
        FreshnessDecision::assert($decision);

        $name = $bundle->name();

        // Before the lock, always (TASK-0015 round 4): the lock file is created
        // inside the cache directory, so the T-14 escape check has to run before
        // `flock` opens anything — otherwise a junctioned cache dir is refused
        // one line too late, with `<b>.lock` already written outside the project.
        // Idempotent and race-tolerant, so every request may call it.
        $this->store->ensureDirectory();

        $lock = $this->lockFor($name);

        if (!$lock->tryAcquire()) {
            // I3/T-05: a build is already running. Anything servable beats
            // waiting, and it beats a second compile absolutely.
            if ($previous !== null && $this->isUsable($previous, $name)) {
                return $this->serve($previous, $name, 'stale-fallback');
            }

            $timeout = (float) $this->config->lockTimeout();

            if (!$lock->acquire($timeout)) {
                throw new LockTimeoutException($timeout, \basename($this->store->lockPath($name)));
            }

            // CCP-A19: the winner has finished while we waited, so its manifest
            // is fresh by construction — serve it without re-checking freshness.
            $reloaded = $this->reload($name);

            if ($reloaded !== null && $this->isUsable($reloaded, $name)) {
                $lock->release();

                return $this->serve($reloaded, $name, 'cached');
            }

            // The winner failed, or wrote nothing usable. We are now the builder,
            // and its manifest — not the one this request loaded — is what
            // `prune()` must protect and what a later failure falls back to.
            $previous = $reloaded;
        } elseif ($previous === null) {
            // The fast path has the same race in miniature: this request loaded
            // no manifest, and a winner may have published one and released the
            // lock in the gap before tryAcquire() succeeded. Rebuilding would be
            // a second compile of the same content (I3), so look once more.
            $reloaded = $this->reload($name);

            if ($reloaded !== null && $this->isUsable($reloaded, $name)) {
                $lock->release();

                return $this->serve($reloaded, $name, 'cached');
            }
        }

        try {
            return $this->build($bundle, $previous, $candidates, $decision);
        } finally {
            $lock->release();
        }
    }

    /**
     * Everything below runs with the lock held.
     *
     * @param list<string>|null $candidates
     *
     * @throws CompilationException
     */
    private function build(
        BundleConfig $bundle,
        ?Manifest $previous,
        ?array $candidates,
        string $decision
    ): BuildResult {
        $startedNs = \hrtime(true);
        $name = $bundle->name();
        $limits = $this->config->limits();

        // `ensureDirectory()` already ran in `compile()`, before the lock.
        $resolution = $this->driverResolver->resolve(
            DriverResolverInput::from($this->config),
            $previous === null ? null : $previous->driverProbe()
        );
        $compiler = $resolution->compiler();
        $driver = $compiler->name();

        $entryText = $this->readEntry($bundle, (int) $limits['max_total_css'], $driver);
        $processed = $this->preProcess($bundle, $entryText, $driver);

        if ($candidates === null) {
            [$candidates, $files] = $this->extractAll($bundle, $limits);
        } else {
            $files = $this->filesFrom();
        }

        $warnings = $this->collectWarnings();

        $imports = new FilesystemImportResolver(
            $this->config->projectRoot(),
            $this->config->allowedRoots(),
            $this->fs,
            (int) $limits['max_file_size']
        );

        $result = $compiler->compile(
            $processed->css(),
            $imports,
            $candidates,
            new CompileOptions($bundle->minify(), $this->config->debug())
        );

        foreach ($result->diagnostics()->warnings() as $warning) {
            $warnings->add($warning);
        }

        if ($result->hasErrors()) {
            throw new CompilationException('build failed', $driver, $result->diagnostics());
        }

        $css = $result->css();
        $hex = Hash::sha256($css);
        $fingerprint = Fingerprint::fromContentHash($hex, $this->config->fingerprintLength());

        // Write order (design doc 05, pinned by TASK-0013): artefact, then its
        // compressed twin, then the manifest, then prune — all inside the lock.
        $this->store->writeCss($name, $fingerprint, $css);

        if ($this->config->gzip() && \function_exists('gzencode')) {
            $gz = \gzencode($css, 9);

            if (\is_string($gz)) {
                $this->store->writeGz($name, $fingerprint, $gz);
            }
        }

        $buildMs = (float) (\hrtime(true) - $startedNs) / 1000000.0;

        $manifest = Manifest::fromArray($this->manifestData(
            $bundle,
            $resolution,
            $processed->imports(),
            $result->imports(),
            $files,
            $candidates,
            $hex,
            $fingerprint,
            $buildMs,
            $warnings
        ));

        $manifest->save(
            $this->store->manifestPath($name),
            $this->writer,
            $this->config->cacheFileMode()
        );

        // CCP-A7: after the manifest, still inside the lock. The previous
        // fingerprint is protected so a request that read the old manifest a
        // microsecond ago still finds its file (I1).
        $protected = $previous === null ? [] : [$previous->fingerprint()];
        $this->store->prune($name, $fingerprint, $this->config->keepVersions(), $protected);

        $this->markChecked($name);

        $this->log($name, $decision, $driver, $buildMs);

        return $this->serve($manifest, $name, 'compiled');
    }

    /**
     * CCP-A14: a pre-processor failure is a CSS problem, not an infrastructure
     * one, so it arrives as a single `E_CSS_SYNTAX` diagnostic the debug overlay
     * can render rather than as a raw `\Throwable` from a third-party library.
     *
     * @throws CompilationException
     */
    private function preProcess(
        BundleConfig $bundle,
        string $entryText,
        string $driver
    ): \TailwindPhp\PreProcessor\PreProcessResult {
        $factory = $this->preProcessorFactory;
        $preProcessor = $factory($bundle);

        try {
            return $preProcessor->process($entryText, $bundle->inputRel());
        } catch (\Throwable $e) {
            $bag = new DiagnosticBag();
            $bag->add(new Diagnostic(
                Diagnostic::ERROR,
                Diagnostic::E_CSS_SYNTAX,
                $e->getMessage(),
                $bundle->inputRel()
            ));

            throw new CompilationException('preprocessor failed', $driver, $bag, [], $e);
        }
    }

    /**
     * Full extraction over the snapshot: every source file, through the
     * per-request memo in `ScanCache`.
     *
     * @param array<string, mixed> $limits
     *
     * @return array{0: list<string>, 1: array<string, array<string, mixed>>}
     */
    private function extractAll(BundleConfig $bundle, array $limits): array
    {
        [$snapshot, $scanCache] = $this->scan();

        $perFile = [];

        foreach ($snapshot->files() as $rel => $entry) {
            if ($entry['k'] !== 's') {
                continue;
            }

            $perFile[$rel] = $scanCache->extract($rel, $entry['k'], $entry['m'], $entry['s']);
        }

        // CCP-A12: `@source inline(...)` discovery is M2, so the third list is
        // empty here rather than guessed at.
        $set = new CandidateSet(
            $perFile,
            $bundle->safelist(),
            $bundle->blocklist(),
            [],
            (int) $limits['max_candidates']
        );

        if ($set->wasTruncated()) {
            $warning = self::candidateLimitWarning((int) $limits['max_candidates']);
            $this->requestBag->add($warning);
            $this->warn($warning);
        }

        return [$set->all(), $this->filesFrom()];
    }

    /**
     * The manifest's `files` map: the snapshot identities with the candidate
     * list `c` attached for every source file the scan cache actually read.
     *
     * @return array<string, array<string, mixed>>
     */
    private function filesFrom(): array
    {
        [$snapshot, $scanCache] = $this->scan();

        $files = $snapshot->files();
        $records = $scanCache->records();

        foreach ($records as $rel => $record) {
            if (\array_key_exists($rel, $files)) {
                $files[$rel] = $record;
            }
        }

        return $files;
    }

    /**
     * The entry stylesheet, bounded by `limits.max_total_css` (T-05).
     *
     * Read one byte past the cap on purpose: the facade truncates silently, and
     * a truncated stylesheet compiled as if it were whole would be cached and
     * served as a valid build (TASK-0015 round 4). Exceeding the cap is a build
     * failure the operator can see, not a smaller stylesheet.
     *
     * @throws CompilationException
     */
    private function readEntry(BundleConfig $bundle, int $maxBytes, string $driver): string
    {
        $text = $this->fs->read($bundle->input(), $maxBytes + 1);

        if (\strlen($text) <= $maxBytes) {
            return $text;
        }

        $bag = new DiagnosticBag();
        $bag->add(new Diagnostic(
            Diagnostic::ERROR,
            Diagnostic::E_BUILD_FAILED,
            \sprintf('The entry stylesheet exceeds limits.max_total_css (%d bytes).', $maxBytes),
            $bundle->inputRel()
        ));

        throw new CompilationException('entry stylesheet exceeds limits.max_total_css', $driver, $bag);
    }

    private static function candidateLimitWarning(int $max): Diagnostic
    {
        return new Diagnostic(
            Diagnostic::WARNING,
            Diagnostic::W_CANDIDATE_LIMIT,
            \sprintf('The candidate list reached the limit of %d and was truncated.', $max)
        );
    }

    /**
     * The manifest's `warnings[]`: everything this request collected, from the
     * scan cache's own file-level notes and from the shared request bag the
     * pipeline writes into. Capped at the schema ceiling so a pathological
     * project cannot make its own manifest unreadable.
     */
    private function collectWarnings(): DiagnosticBag
    {
        [, $scanCache] = $this->scan();

        $merged = new DiagnosticBag();
        $count = 0;

        foreach (\array_merge($this->requestBag->all(), $scanCache->warnings()->all()) as $diagnostic) {
            if ($count >= \TailwindPhp\Cache\ManifestValidator::MAX_WARNINGS) {
                break;
            }

            $merged->add($diagnostic);
            ++$count;
        }

        return $merged;
    }

    /**
     * @param list<string> $preProcessorImports Project-relative, kind `i`.
     * @param list<string> $compilerImports
     * @param array<string, array<string, mixed>> $files
     * @param list<string> $candidates
     *
     * @return array<string, mixed>
     */
    private function manifestData(
        BundleConfig $bundle,
        DriverResolution $resolution,
        array $preProcessorImports,
        array $compilerImports,
        array $files,
        array $candidates,
        string $contentHashHex,
        string $fingerprint,
        float $buildMs,
        DiagnosticBag $warnings
    ): array {
        $compiler = $resolution->compiler();

        $imports = [];

        foreach (\array_merge($preProcessorImports, $compilerImports) as $import) {
            $imports[$import] = true;
        }

        $imports = \array_keys($imports);
        \sort($imports, \SORT_STRING);

        $inputRoots = $bundle->inputRoots();

        return [
            'schema' => 1,
            'package_version' => Version::VERSION,
            // CSF-A3: bare hex in PHP, `sha256:`-prefixed in the document.
            'config_hash' => 'sha256:' . $this->config->configHash(),
            'bundle' => $bundle->name(),
            'driver' => $compiler->name(),
            'driver_version' => $compiler->version(),
            'driver_probe' => $resolution->probe(),
            'built_at' => $this->clock->now(),
            'build_ms' => $buildMs,
            'content_hash' => 'sha256:' . $contentHashHex,
            'fingerprint' => $fingerprint,
            'minified' => $bundle->minify(),
            'candidates_hash' => 'sha256:' . Hash::sha256Lines($candidates),
            'candidates_count' => \count($candidates),
            'input' => $bundle->inputRel(),
            'imports' => $imports,
            // `ConfigBuilder` folds `watch` into `input_roots` behind the entry
            // stylesheet's own directory, which is always pushed first; doc 05's
            // three-way split is reconstructed from that order.
            'roots' => [
                'input' => $inputRoots === [] ? [] : [$inputRoots[0]['rel']],
                'watch' => \array_values(\array_map(
                    static function (array $root): string {
                        return $root['rel'];
                    },
                    \array_slice($inputRoots, 1)
                )),
                'sources' => \array_map(
                    static function (array $root): string {
                        return $root['rel'];
                    },
                    $bundle->sourceRoots()
                ),
            ],
            'files' => $files,
            'safelist_hash' => 'sha256:' . Hash::sha256Lines($bundle->safelist()),
            'inline_sources' => [],
            // CCP-A25: rows are exactly Diagnostic::toArray().
            'warnings' => $warnings->toArray(),
            'last_error' => null,
        ];
    }

    /**
     * CCP-A21 — record why the last build failed, for `doctor` and the logs.
     *
     * Written only while holding the lock: `last_error` is a field of the
     * manifest, so writing it without the lock would republish a whole manifest
     * on top of one a concurrent build is in the middle of replacing. The lock
     * is taken with `tryAcquire()` and never waited for — this runs on a request
     * that has *already* failed, and making it slow as well would turn a
     * degraded stylesheet into a degraded page.
     */
    public function recordLastError(string $bundle, Manifest $manifest, \Throwable $e): void
    {
        $lock = $this->lockFor($bundle);

        if (!$lock->tryAcquire()) {
            return;
        }

        try {
            $current = $this->reload($bundle);

            // The build that owns the lock may already have replaced the
            // manifest; stamping our failure onto its successful build would be
            // a lie. Only the manifest we actually failed over is annotated.
            if ($current === null || $current->fingerprint() !== $manifest->fingerprint()) {
                return;
            }

            $updated = $current->withLastError([
                'code' => self::errorCode($e),
                'at' => $this->clock->now(),
                'message_short' => self::shortMessage($e),
            ]);

            $updated->save(
                $this->store->manifestPath($bundle),
                $this->writer,
                $this->config->cacheFileMode()
            );
        } catch (\Throwable $ignored) {
            // Fail-soft means fail-soft: a cache that cannot record why it is
            // stale must not turn a served stylesheet into a 500.
        } finally {
            $lock->release();
        }
    }

    private static function errorCode(\Throwable $e): string
    {
        if ($e instanceof CompilationException) {
            foreach ($e->diagnostics()->errors() as $error) {
                return $error->code();
            }

            return Diagnostic::E_CSS_SYNTAX;
        }

        // Anything else is infrastructure — a lock, a write, a third-party
        // library — not the CLI, which has its own codes. `doctor` must not
        // report a CLI failure for a project that never spawns one.
        return Diagnostic::E_BUILD_FAILED;
    }

    /**
     * Step 4.8's "touched" outcome, written under the lock (TASK-0015 round 4).
     *
     * The manifest is republished with the fresh `files` map; without the lock
     * this would race a build that is replacing the same file, and without
     * clearing `last_error` a long-fixed failure would be reported forever. The
     * lock is only tried, never waited for: a request that cannot get it still
     * serves `touched` correctly, it just leaves the bookkeeping to the next one.
     *
     * @return bool Whether the manifest was written.
     */
    public function recordTouched(string $bundle, Manifest $updated): bool
    {
        $lock = $this->lockFor($bundle);

        if (!$lock->tryAcquire()) {
            return false;
        }

        try {
            // Same guard as recordLastError(): if a concurrent build has already
            // published a newer manifest, our `files` map describes a build that
            // is gone — writing it would roll the winner back (round-5 verifier).
            $current = $this->reload($bundle);

            if ($current === null || $current->fingerprint() !== $updated->fingerprint()) {
                return false;
            }

            $updated->withLastError(null)->save(
                $this->store->manifestPath($bundle),
                $this->writer,
                $this->config->cacheFileMode()
            );

            return true;
        } finally {
            $lock->release();
        }
    }

    private function warn(Diagnostic $diagnostic): void
    {
        if ($this->logger !== null) {
            $this->logger->warning('tailwindcss-php: ' . $diagnostic->message(), [
                'code' => $diagnostic->code(),
            ]);
        }
    }

    /**
     * Short, scrubbed and length-capped: this string ends up in a JSON document
     * that `doctor` prints and that the debug overlay may echo (T-06).
     */
    private static function shortMessage(\Throwable $e): string
    {
        $message = \TailwindPhp\Exception\LogSafety::scrubText($e->getMessage());

        if (\strlen($message) > 200) {
            $message = \substr($message, 0, 197) . '...';
        }

        return $message;
    }

    /**
     * CCP-A17: the manifest is usable only when the file it names exists.
     */
    public function isUsable(Manifest $manifest, string $bundle): bool
    {
        return $this->store->has($bundle, $manifest->fingerprint());
    }

    /**
     * CCP-A17: `path()` is the fingerprinted artefact, never `<b>.css`.
     */
    public function serve(Manifest $manifest, string $bundle, string $source): BuildResult
    {
        $fingerprint = $manifest->fingerprint();
        $path = $this->store->fingerprintedCssPath($bundle, $fingerprint);
        $gzPath = $this->store->fingerprintedGzPath($bundle, $fingerprint);
        $stat = $this->fs->stat($path);

        return BuildResult::file(
            $path,
            $this->fs->isFile($gzPath) ? $gzPath : null,
            \substr($manifest->contentHash(), 7),
            $fingerprint,
            $stat === null ? $manifest->builtAt() : $stat['mtime'],
            $source,
            $this->requestBag
        );
    }

    /**
     * The `check_interval` stamp is touched on every outcome (design doc 04
     * §4.5), so a bundle sitting on the boundary checks every interval rather
     * than every request.
     */
    public function markChecked(string $bundle): void
    {
        if ($this->config->checkInterval() > 0) {
            $this->fs->touch($this->store->stampPath($bundle), $this->clock->now());
        }
    }

    /**
     * @return ScanContext
     */
    private function scan(): array
    {
        $supplier = $this->scanSupplier;

        return $supplier();
    }

    private function reload(string $bundle): ?Manifest
    {
        $diagnostic = null;

        return Manifest::load($this->store->manifestPath($bundle), $this->fs, Manifest::HARD_READ_CAP, $diagnostic);
    }

    private function lockFor(string $bundle): LockInterface
    {
        $path = $this->store->lockPath($bundle);

        if ($this->lockFactory !== null) {
            $factory = $this->lockFactory;

            return $factory($path);
        }

        if ($this->config->lock() === 'none') {
            return new NullLock();
        }

        return new FlockBuildLock($path, $this->clock, $this->logger);
    }

    private function log(string $bundle, string $decision, string $driver, float $buildMs): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info(
            'tailwindcss-php: compiled bundle',
            [
                'bundle' => $bundle,
                'decision' => $decision,
                'driver' => $driver,
                'build_ms' => \round($buildMs, 3),
            ]
        );
    }
}
