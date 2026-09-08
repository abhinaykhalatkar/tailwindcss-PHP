<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

use Psr\Log\LoggerInterface;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Compiler\DriverResolution;
use TailwindPhp\Compiler\DriverResolverInterface;
use TailwindPhp\Config\BundleConfig;
use TailwindPhp\Config\Config;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Freshness\CheckThrottle;
use TailwindPhp\Freshness\EnvironmentFingerprint;
use TailwindPhp\Freshness\FileSnapshot;
use TailwindPhp\Freshness\StalenessChecker;
use TailwindPhp\Freshness\StalenessReport;
use TailwindPhp\Scanner\CandidateSet;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Version;

/**
 * The request path (design doc 04 §4; digest §4.2).
 *
 * `resolve()` answers one question — *what should this request serve?* — in the
 * cheapest order that can answer it. The steps are numbered after design doc 04
 * and appear here in that order deliberately: each is a gate that lets the
 * common case out early. A warm, fresh bundle reads exactly one file (its
 * manifest), walks the source tree once, and returns; it never loads a
 * `Compiler\*` class, never takes a lock and never opens the stylesheet
 * (invariant I4, doc 13 #7).
 *
 * Everything from step 4.2 onwards runs inside one fail-soft `catch`
 * (CCP-A20). A stylesheet that cannot be built must never take the page down
 * with it: in debug the failure becomes a visible overlay, in production a
 * previous good build is served stale, and only with nothing at all to serve
 * does the caller get a 500 with a constant body (design doc 15).
 *
 * @phpstan-type ScanContext array{0: FileSnapshot, 1: ScanCache}
 */
final class AssetPipeline implements BuildResolverInterface
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
     * @var FileFinder
     */
    private $fileFinder;

    /**
     * @var callable(array<string, array<string, mixed>>): ScanCache
     */
    private $scanCacheFactory;

    /**
     * @var callable(string): CheckThrottle
     */
    private $throttleFactory;

    /**
     * @var DriverResolverInterface
     */
    private $driverResolver;

    /**
     * @var callable(callable, DiagnosticBag): CompileOrchestrator
     */
    private $orchestratorFactory;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * Per-request state, all of it filled inside {@see self::resolve()}.
     *
     * @var DiagnosticBag
     */
    private $bag;

    /**
     * @var FileSnapshot|null Memoised: the tree is walked at most once.
     */
    private $snapshot;

    /**
     * @var ScanCache|null
     */
    private $scanCache;

    /**
     * @var CompileOrchestrator|null
     */
    private $orchestrator;

    /**
     * @var BundleConfig|null The bundle this request is resolving.
     */
    private $currentBundle;

    /**
     * @var Manifest|null The manifest this request loaded, if any.
     */
    private $currentManifest;

    /**
     * @var DriverResolution|null What step 4.2 chose for this request.
     */
    private $resolution;

    /**
     * `I_DRIVER_SELECTED` is a once-per-process line (design doc 15). A static
     * flag would make it once-per-*class*, which no test could reset and which
     * would silently suppress the line for a second bundle in the same request;
     * the facade builds one pipeline per request, so an instance flag is the
     * right grain.
     *
     * @var bool
     */
    private $loggedDriver = false;

    /**
     * No I/O here (design doc 18): a request that turns out to be a 304 must be
     * able to construct this object and never touch the disk.
     *
     * Two collaborators arrive as factories rather than ready-made objects, for
     * reasons that are not stylistic. A `ScanCache` is built from the *previous
     * manifest's* `files` map, which nothing knows until step 4.1 has run. And
     * the orchestrator must share **this** request's snapshot and scan cache: a
     * second walk would cost a second traversal and, worse, lose the extracted
     * candidate lists that belong in `manifest.files[].c`, so the next request
     * would re-read every template. Handing the orchestrator over ready-made
     * would make that sharing circular — and would load `CompileOrchestrator`
     * on the warm path, where doc 13 #7 counts every class.
     *
     * @param callable(array<string, array<string, mixed>>): ScanCache $scanCacheFactory
     * @param callable(string): CheckThrottle $throttleFactory Given the stamp path.
     * @param callable(callable, DiagnosticBag): CompileOrchestrator $orchestratorFactory
     */
    public function __construct(
        Config $config,
        CacheStore $store,
        FileFinder $fileFinder,
        callable $scanCacheFactory,
        callable $throttleFactory,
        DriverResolverInterface $driverResolver,
        callable $orchestratorFactory,
        FilesystemInterface $fs,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->fileFinder = $fileFinder;
        $this->scanCacheFactory = $scanCacheFactory;
        $this->throttleFactory = $throttleFactory;
        $this->driverResolver = $driverResolver;
        $this->orchestratorFactory = $orchestratorFactory;
        $this->fs = $fs;
        $this->logger = $logger;
        $this->bag = new DiagnosticBag();
    }

    /**
     * @throws ConfigurationException When the bundle is unknown, or a watched
     *                                root has vanished and there is no manifest
     *                                to fall back on.
     */
    public function resolve(string $bundle): BuildResult
    {
        $bundleConfig = $this->config->bundle($bundle);

        $this->bag = new DiagnosticBag();
        $this->snapshot = null;
        $this->scanCache = null;
        $this->orchestrator = null;
        $this->currentBundle = $bundleConfig;
        $this->currentManifest = null;
        $this->resolution = null;

        // 4.1 — outside the fail-soft catch: a manifest that cannot be read is
        // reported as a warning and treated as absent, never as a failure.
        $diagnostic = null;
        $manifest = Manifest::load(
            $this->store->manifestPath($bundle),
            $this->fs,
            Manifest::HARD_READ_CAP,
            $diagnostic
        );

        if ($diagnostic !== null) {
            $this->bag->add($diagnostic);
            $this->warn($diagnostic);
        }

        // CCP-A17: a manifest naming a file that is gone is not a cache.
        if ($manifest !== null && !$this->store->has($bundle, $manifest->fingerprint())) {
            $manifest = null;
        }

        $this->currentManifest = $manifest;

        try {
            return $this->decide($bundleConfig, $manifest);
        } catch (ConfigurationException $e) {
            // CCP-A20: a misconfiguration is the operator's to fix and must not
            // be papered over with a stale stylesheet.
            throw $e;
        } catch (\Throwable $e) {
            return $this->failSoft($bundleConfig, $manifest, $e);
        }
    }

    /**
     * Steps 4.2 – 4.10.
     */
    private function decide(BundleConfig $bundle, ?Manifest $manifest): BuildResult
    {
        $name = $bundle->name();

        // 4.2 — the cached probe is what keeps this cheap: no spawn, no re-hash.
        $resolution = $this->driverResolver->resolve(
            DriverResolverInput::from($this->config),
            $manifest === null ? null : $manifest->driverProbe()
        );
        $this->resolution = $resolution;

        $this->logDriver($resolution);

        $decision = FreshnessDecision::NO_CACHE;

        if ($manifest !== null) {
            $decision = $this->environmentMatches($manifest, $resolution)
                ? FreshnessDecision::FRESH
                : FreshnessDecision::ENV_CHANGED;
        }

        // 4.3 — debug always rebuilds and never serves a cache (CCP-A15).
        if ($this->config->debug()) {
            return $this->compile($bundle, $manifest, null, FreshnessDecision::DEBUG);
        }

        // CCP-A18.
        if ($decision === FreshnessDecision::NO_CACHE || $decision === FreshnessDecision::ENV_CHANGED) {
            return $this->compile($bundle, $manifest, null, $decision);
        }

        // Past this point a usable manifest exists; PHPStan cannot see that
        // from the branch above, and neither can a reader in a hurry.
        if ($manifest === null) {
            throw new \LogicException('A FRESH decision without a manifest is unreachable.');
        }

        $throttle = $this->throttle($name);

        // 4.4 — freeze: never look at the filesystem again.
        if ($this->config->freeze()) {
            return $this->serveCached($manifest, $name, $throttle);
        }

        // 4.5 — inside the check interval, the answer is the same as last time.
        if (!$throttle->shouldCheck()) {
            return $this->serveCached($manifest, $name, $throttle);
        }

        // 4.6
        $snapshot = $this->snapshot($bundle, $manifest);
        $report = StalenessChecker::compare($snapshot, $manifest->files());

        // 4.7
        if ($report->isFresh()) {
            return $this->serveCached($manifest, $name, $throttle);
        }

        // 4.8
        if ($report->kind() === StalenessReport::TEMPLATES_CHANGED) {
            return $this->afterTemplateChange($bundle, $manifest, $snapshot, $throttle);
        }

        // 4.9
        return $this->compile($bundle, $manifest, null, FreshnessDecision::INPUTS_CHANGED);
    }

    /**
     * 4.8 — templates moved, but they may still yield the same candidates. A
     * touch is two orders of magnitude cheaper than a compile, and for the
     * common edit (whitespace, copy, a changed attribute value) it is the
     * correct answer.
     */
    private function afterTemplateChange(
        BundleConfig $bundle,
        Manifest $manifest,
        FileSnapshot $snapshot,
        CheckThrottle $throttle
    ): BuildResult {
        $name = $bundle->name();
        $scanCache = $this->scanCache($manifest);
        $limits = $this->config->limits();

        $perFile = [];

        foreach ($snapshot->files() as $rel => $entry) {
            if ($entry['k'] !== 's') {
                continue;
            }

            // Unchanged files answer from the previous manifest's `c` without a
            // read; only the changed and added ones cost anything.
            $perFile[$rel] = $scanCache->extract($rel, $entry['k'], $entry['m'], $entry['s']);
        }

        $set = new CandidateSet(
            $perFile,
            $bundle->safelist(),
            $bundle->blocklist(),
            [],
            (int) $limits['max_candidates']
        );

        if ($set->wasTruncated()) {
            $warning = new Diagnostic(
                Diagnostic::WARNING,
                Diagnostic::W_CANDIDATE_LIMIT,
                \sprintf(
                    'The candidate list reached the limit of %d and was truncated.',
                    (int) $limits['max_candidates']
                )
            );
            $this->bag->add($warning);
            $this->warn($warning);
        }

        // CSF-A3: the manifest stores the prefixed form.
        if ('sha256:' . $set->hash() !== $manifest->candidatesHash()) {
            return $this->compile($bundle, $manifest, $set->all(), FreshnessDecision::TEMPLATES_CHANGED);
        }

        // The manifest is republished under the lock and with `last_error`
        // cleared (TASK-0015 round 4); a request that cannot take the lock still
        // serves `touched`, the bookkeeping just waits for the next one.
        $updated = $manifest->withFiles($this->filesWithCandidates($snapshot, $scanCache))->withLastError(null);
        $this->orchestrator()->recordTouched($name, $updated);
        $this->store->touchCss($name, $manifest->fingerprint());
        $this->markChecked($throttle);

        return $this->serve($updated, $name, 'touched');
    }

    /**
     * 4.6 — one walk per request, memoised so the orchestrator shares it.
     *
     * @throws ConfigurationException
     */
    private function snapshot(BundleConfig $bundle, ?Manifest $manifest): FileSnapshot
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $snapshot = $this->fileFinder->snapshot($this->roots($bundle));
        $missing = $snapshot->missingRoots();

        if ($missing !== []) {
            if ($manifest === null) {
                // Nothing to fall back on: a root that does not exist is a
                // configuration error, and guessing would silently ship a
                // stylesheet missing every class in that directory.
                throw new ConfigurationException(\array_map(
                    static function (string $rel): string {
                        return \sprintf('Root "%s" does not exist.', $rel);
                    },
                    $missing
                ));
            }

            foreach ($missing as $rel) {
                $this->bag->add(new Diagnostic(
                    Diagnostic::WARNING,
                    Diagnostic::W_ROOT_MISSING,
                    \sprintf('Root "%s" is missing; its files are kept from the previous build.', $rel)
                ));
            }

            $snapshot = self::keepMissingRootFiles($snapshot, $manifest->files());
        }

        $this->snapshot = $snapshot;

        return $snapshot;
    }

    /**
     * A vanished mount must not read as "every template was deleted". The
     * previous manifest's entries under that root are carried forward verbatim,
     * so the comparison sees them unchanged and the build stays reproducible.
     *
     * @param array<string, array<string, mixed>> $previousFiles
     */
    private static function keepMissingRootFiles(FileSnapshot $snapshot, array $previousFiles): FileSnapshot
    {
        $files = $snapshot->files();
        $missing = $snapshot->missingRoots();

        foreach ($previousFiles as $rel => $entry) {
            if (\array_key_exists($rel, $files)) {
                continue;
            }

            foreach ($missing as $root) {
                if ($root !== '' && \strpos($rel, \rtrim($root, '/') . '/') === 0) {
                    /** @var array{k: string, m: int, s: int} $entry */
                    $files[$rel] = $entry;

                    break;
                }
            }
        }

        return new FileSnapshot($files, $missing);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function filesWithCandidates(FileSnapshot $snapshot, ScanCache $scanCache): array
    {
        $files = $snapshot->files();

        foreach ($scanCache->records() as $rel => $record) {
            if (\array_key_exists($rel, $files)) {
                $files[$rel] = $record;
            }
        }

        return $files;
    }

    /**
     * @param list<string>|null $candidates
     */
    private function compile(
        BundleConfig $bundle,
        ?Manifest $manifest,
        ?array $candidates,
        string $decision
    ): BuildResult {
        return $this->orchestrator()->compile($bundle, $manifest, $candidates, $decision);
    }

    private function serveCached(Manifest $manifest, string $bundle, CheckThrottle $throttle): BuildResult
    {
        $this->markChecked($throttle);

        return $this->serve($manifest, $bundle, 'cached');
    }

    /**
     * The `check_interval` stamp exists only when the interval is in use, and
     * `freeze` means "never touch the filesystem again" — a warm cached request
     * under either setting must not cost a `touch()` (TASK-0015 round 4).
     */
    private function markChecked(CheckThrottle $throttle): void
    {
        if ($this->config->checkInterval() > 0 && !$this->config->freeze()) {
            $throttle->markChecked();
        }
    }

    /**
     * 4.10 — CCP-A17: the fingerprinted artefact, not `<b>.css`.
     */
    private function serve(Manifest $manifest, string $bundle, string $source): BuildResult
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
            $this->bag
        );
    }

    /**
     * Design doc 04 §6 / doc 15: fail soft, and never leak a path (T-06).
     */
    private function failSoft(BundleConfig $bundle, ?Manifest $manifest, \Throwable $e): BuildResult
    {
        $name = $bundle->name();
        $diagnostics = $this->diagnosticsOf($e);
        $driver = $this->driverName();

        $this->error($name, $e);

        if ($this->config->debug()) {
            return BuildResult::inline(
                200,
                ErrorRenderer::debugOverlayCss($e, $name, $driver, $diagnostics),
                'error',
                $e,
                $diagnostics
            );
        }

        if ($manifest !== null && $this->store->has($name, $manifest->fingerprint())) {
            // CCP-A21: only while holding the lock, and never at the cost of
            // blocking the request that is already failing.
            $this->orchestrator()->recordLastError($name, $manifest, $e);

            return $this->serve($manifest, $name, 'stale-fallback');
        }

        return BuildResult::inline(500, ErrorRenderer::productionBody(), 'error', $e, $diagnostics);
    }

    /**
     * @return list<Root>
     */
    private function roots(BundleConfig $bundle): array
    {
        $roots = [];

        foreach ($bundle->inputRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], Root::INPUT, $root['extensions']);
        }

        foreach ($bundle->sourceRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], Root::SOURCE, $root['extensions']);
        }

        return $roots;
    }

    private function environmentMatches(Manifest $manifest, DriverResolution $resolution): bool
    {
        $compiler = $resolution->compiler();

        // CSF-A3 (TASK-0009 review): the manifest stores the prefixed hash, so
        // the fingerprint must be built with the prefixed form. Comparing a bare
        // hex here forces ENV_CHANGED on every single request — a pipeline that
        // passes every test except the one that counts compiles.
        $environment = new EnvironmentFingerprint(
            Version::VERSION,
            'sha256:' . $this->config->configHash(),
            $compiler->name(),
            $compiler->version()
        );

        return $environment->matchesManifest($manifest->toArray());
    }

    private function orchestrator(): CompileOrchestrator
    {
        if ($this->orchestrator === null) {
            $factory = $this->orchestratorFactory;
            $this->orchestrator = $factory([$this, 'scanContext'], $this->bag);
        }

        return $this->orchestrator;
    }

    /**
     * The scan supplier handed to the orchestrator. Public because it is a
     * callable seam, not because anything else should call it.
     *
     * @return ScanContext
     *
     * @internal
     */
    public function scanContext(): array
    {
        if ($this->currentBundle === null) {
            throw new \LogicException('The scan context is only available while resolve() is running.');
        }

        // Lazy on purpose: a COMPILE reached from step 4.3 (cold cache, changed
        // environment, debug) has not walked the tree yet, and a COMPILE reached
        // from 4.8 has. Both get the same snapshot and the same scan cache.
        return [
            $this->snapshot($this->currentBundle, $this->currentManifest),
            $this->scanCache($this->currentManifest),
        ];
    }

    private function scanCache(?Manifest $manifest): ScanCache
    {
        if ($this->scanCache === null) {
            $factory = $this->scanCacheFactory;
            $this->scanCache = $factory($manifest === null ? [] : $manifest->files());
        }

        return $this->scanCache;
    }

    private function throttle(string $bundle): CheckThrottle
    {
        $factory = $this->throttleFactory;

        return $factory($this->store->stampPath($bundle));
    }

    /**
     * The driver that actually ran, once step 4.2 has chosen one; before that
     * (a failure inside the manifest load, say) the configured value is the
     * best available label.
     */
    private function driverName(): string
    {
        if ($this->resolution !== null) {
            return $this->resolution->compiler()->name();
        }

        $driver = $this->config->driver();

        return $driver === 'cli' ? 'cli' : 'php';
    }

    private function diagnosticsOf(\Throwable $e): DiagnosticBag
    {
        if ($e instanceof \TailwindPhp\Exception\CompilationException) {
            return $e->diagnostics();
        }

        return $this->bag;
    }

    private function logDriver(DriverResolution $resolution): void
    {
        $reason = $resolution->reason();

        if ($this->logger === null || $this->loggedDriver || $reason === null) {
            return;
        }

        $this->loggedDriver = true;

        $this->logger->info('tailwindcss-php: driver selected', [
            'code' => Diagnostic::I_DRIVER_SELECTED,
            'driver' => $resolution->compiler()->name(),
            'reason' => $reason,
        ]);

        // `DriverResolver` states the fallback reason in prose and leaves the
        // warning code to the pipeline (its docblock says so); a binary that
        // exists but cannot be verified is the one case an operator must see.
        if (\strpos($reason, 'checksum') !== false) {
            $this->logger->warning('tailwindcss-php: ' . $reason, [
                'code' => Diagnostic::W_CHECKSUM_UNKNOWN,
            ]);
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

    private function error(string $bundle, \Throwable $e): void
    {
        if ($this->logger === null) {
            return;
        }

        // LogSafety has already scrubbed our own exceptions; a third-party one
        // is scrubbed here rather than trusted.
        $this->logger->error('tailwindcss-php: build failed', [
            'bundle' => $bundle,
            'exception' => \get_class($e),
            'message' => \TailwindPhp\Exception\LogSafety::scrubText($e->getMessage()),
        ]);
    }
}
