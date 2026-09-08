<?php

declare(strict_types=1);

namespace TailwindPhp;

use Psr\Log\LoggerInterface;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\FlockBuildLock;
use TailwindPhp\Cache\LockInterface;
use TailwindPhp\Cache\NullLock;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolver;
use TailwindPhp\Config\BundleConfig;
use TailwindPhp\Config\Config;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Freshness\CheckThrottle;
use TailwindPhp\Http\Responder;
use TailwindPhp\Http\Response;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\RouteMatcher;
use TailwindPhp\Http\UrlResolver;
use TailwindPhp\Pipeline\AssetPipeline;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Pipeline\CompileOrchestrator;
use TailwindPhp\PreProcessor\NullPreProcessor;
use TailwindPhp\PreProcessor\PreProcessorInterface;
use TailwindPhp\PreProcessor\ScssPreProcessor;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\PrePass\PhpPrePass;
use TailwindPhp\Scanner\PrePass\TwigPrePass;
use TailwindPhp\Scanner\PrePassInterface;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Logging\ErrorLogLogger;
use TailwindPhp\Support\SystemClock;

/**
 * The whole library behind one object (design doc 04 §2, decision HBC-A3).
 *
 * An integrator writes `Tailwind::fromArray([...])` and then either
 * {@see self::respond()} from a front controller or {@see self::url()} from a
 * template. Everything under here — the config, the cache, the scanner, the
 * compiler drivers, the pipeline, the HTTP layer — is wiring that this class
 * owns so that nobody else has to know the order it goes together in.
 *
 * **Everything is lazy and built once.** The constructor does no I/O at all, and
 * each collaborator is created by a memoised private getter on first use. That
 * is not tidiness: doc 13 #7 budgets the classes a warm request may load, and a
 * page that only calls `url()` must not pay for a compiler driver it will never
 * reach. `CompileOrchestrator` and the pre-processors live behind factory
 * closures for the same reason — a closure does not autoload the class named in
 * its body until it is called, so a cached request never loads either.
 *
 * **A foreign route costs nothing.** {@see self::handle()} consults the route
 * matcher — which is pure string work — *before* it builds anything, so a site
 * that mounts this library at one URL does not construct a cache store or read
 * the checksum table for every other request it serves. The matcher then runs a
 * second time inside {@see Responder::handle()}; that is one anchored
 * `preg_match` against a short string, and it is what makes the zero-I/O
 * guarantee a property of this class rather than a promise about another one.
 *
 * One instance per request. There is no static state anywhere in `src/`, so two
 * instances in one process are independent — which is what lets a test build a
 * dozen of them.
 */
final class Tailwind
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var FilesystemInterface|null
     */
    private $fs;

    /**
     * @var SystemClock|null
     */
    private $clock;

    /**
     * @var AtomicWriter|null
     */
    private $writer;

    /**
     * @var CacheStore|null
     */
    private $store;

    /**
     * @var DriverResolver|null
     */
    private $driverResolver;

    /**
     * @var AssetPipeline|null
     */
    private $pipeline;

    /**
     * @var RouteMatcher|null
     */
    private $matcher;

    /**
     * @var ResponseBuilder|null
     */
    private $responseBuilder;

    /**
     * @var Responder|null
     */
    private $responder;

    /**
     * @var UrlResolver|null
     */
    private $urlResolver;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $config The user's configuration array, in the
     *                                     shape `config/tailwind.php` returns.
     *
     * @throws \TailwindPhp\Exception\ConfigurationException When it does not
     *                                                       validate. Nothing
     *                                                       is swallowed: a
     *                                                       misconfigured site
     *                                                       must fail loudly at
     *                                                       wiring time rather
     *                                                       than serve
     *                                                       something odd.
     */
    public static function fromArray(array $config): self
    {
        return new self(ConfigBuilder::fromArray($config));
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * @throws \TailwindPhp\Exception\ConfigurationException When no such bundle
     *                                                       is configured.
     */
    public function bundle(string $name): BundleConfig
    {
        return $this->config->bundle($name);
    }

    /**
     * Build or fetch a bundle's stylesheet.
     *
     * @param string|null $bundle Null means the first configured bundle, which
     *                            is the only sensible default for the common
     *                            single-bundle project.
     */
    public function resolve(?string $bundle = null): BuildResult
    {
        return $this->pipeline()->resolve($this->bundleName($bundle));
    }

    /**
     * @param array<string, mixed> $server Server variables; only the five keys
     *                                     {@see \TailwindPhp\Http\ServerRequest}
     *                                     reads are looked at.
     *
     * @return Response|null Null when the route is not ours — and nothing has
     *                       been built, opened or read to determine that.
     */
    public function handle(string $route, array $server): ?Response
    {
        if ($this->matcher()->match($route) === null) {
            return null;
        }

        return $this->responder()->handle($route, $server);
    }

    /**
     * Handle the route and put the response on the wire.
     *
     * @param array<string, mixed> $server
     *
     * @return bool False when the route is not ours, in which case the
     *              integrator's own routing continues and nothing has been
     *              written.
     */
    public function respond(string $route, array $server): bool
    {
        $response = $this->handle($route, $server);

        if ($response === null) {
            return false;
        }

        $this->responder()->emit($response);

        return true;
    }

    /**
     * The URL to put in a `<link>` (FR-HTTP-8, ADR-004).
     *
     * @param string $prefix Passed through untouched — `'/'` for a root-mounted
     *                       site, `''` for a relative link.
     * @param array<string, mixed> $options `bundle` names a bundle other than
     *                                      the first; `build => true` resolves
     *                                      before reading the manifest, for a
     *                                      deploy step that renders templates
     *                                      ahead of the first request.
     */
    public function url(string $prefix = '', array $options = []): string
    {
        $bundle = isset($options['bundle']) ? (string) $options['bundle'] : $this->bundleName(null);

        if (isset($options['build']) && $options['build'] === true) {
            $this->resolve($bundle);
        }

        return $this->urlResolver()->url($bundle, $prefix);
    }

    // ------------------------------------------------------------------ wiring

    /**
     * @throws \TailwindPhp\Exception\ConfigurationException When no bundle is
     *                                                       configured at all.
     */
    private function bundleName(?string $bundle): string
    {
        if ($bundle !== null) {
            return $bundle;
        }

        foreach ($this->config->bundles() as $name => $ignored) {
            return (string) $name;
        }

        // Unreachable through ConfigBuilder, which requires at least one
        // bundle; asserted rather than assumed because the alternative is a
        // confusing failure much further down.
        throw new ConfigurationException(['No bundle is configured.']);
    }

    private function filesystem(): FilesystemInterface
    {
        if ($this->fs === null) {
            $this->fs = new Filesystem();
        }

        return $this->fs;
    }

    private function clock(): SystemClock
    {
        if ($this->clock === null) {
            $this->clock = new SystemClock();
        }

        return $this->clock;
    }

    private function writer(): AtomicWriter
    {
        if ($this->writer === null) {
            $this->writer = new AtomicWriter($this->config->projectRoot());
        }

        return $this->writer;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $configured = $this->config->logger();

            $this->logger = $configured instanceof LoggerInterface
                ? $configured
                : new ErrorLogLogger($this->config->logLevel());
        }

        return $this->logger;
    }

    private function store(): CacheStore
    {
        if ($this->store === null) {
            $this->store = new CacheStore(
                $this->config->cacheDir(),
                $this->config->allowedRoots(),
                $this->config->projectRoot(),
                $this->config->cacheDirMode(),
                $this->config->cacheFileMode(),
                $this->config->gzip(),
                $this->filesystem(),
                $this->writer(),
                $this->logger(),
                $this->clock()
            );
        }

        return $this->store;
    }

    /**
     * The driver resolver, with the CLI compiler behind a factory so that a
     * project on the PHP engine never constructs one (ADR-015).
     */
    private function driverResolver(): DriverResolver
    {
        if ($this->driverResolver === null) {
            $fs = $this->filesystem();
            $root = $this->config->projectRoot();
            $cacheDir = $this->config->cacheDir();
            $timeout = (float) $this->config->cli()['timeout'];

            // Both as closures, not instances: with `driver: php` the resolver
            // returns before it touches either, and building them eagerly cost
            // every warm request three `Compiler\Cli\*` class loads plus a read
            // of `resources/checksums.json` — for a driver the project said it
            // did not want.
            $this->driverResolver = new DriverResolver(
                static function (): Environment {
                    return new Environment();
                },
                static function () use ($fs, $root): BinaryVerifier {
                    return new BinaryVerifier(Checksums::load(), $fs, $root);
                },
                new \TailwindPhp\Compiler\PhpEngineCompiler(),
                static function (
                    string $binaryPath,
                    string $version
                ) use (
                    $fs,
                    $root,
                    $cacheDir,
                    $timeout
                ): CompilerInterface {
                    return new StandaloneCliCompiler(
                        $binaryPath,
                        $version,
                        $cacheDir,
                        $timeout,
                        new Process(),
                        $fs,
                        $root,
                        new AtomicWriter($root),
                        new Environment()
                    );
                }
            );
        }

        return $this->driverResolver;
    }

    /**
     * The request pipeline.
     *
     * **Decision HBC-A22, a known M1 limitation.** `AssetPipeline` takes a
     * single `FileFinder`, and a finder is built with one bundle's `ignore`
     * list, so a multi-bundle project applies the **first** bundle's ignore
     * patterns to every bundle. A project whose second bundle needs to ignore
     * something different will scan more files than it meant to — never fewer,
     * so this cannot drop a candidate and silently lose a utility class. A
     * per-bundle finder is an M3 item.
     */
    private function pipeline(): AssetPipeline
    {
        if ($this->pipeline === null) {
            $config = $this->config;
            $limits = $config->limits();
            $fs = $this->filesystem();
            $clock = $this->clock();
            $store = $this->store();
            $writer = $this->writer();
            $resolver = $this->driverResolver();
            $logger = $this->logger();
            $prepassSelector = $this->prepassSelector();

            $scanCacheFactory = static function (array $previousFiles) use (
                $fs,
                $config,
                $limits,
                $prepassSelector
            ): ScanCache {
                return new ScanCache(
                    $fs,
                    new CandidateExtractor(),
                    $prepassSelector,
                    $previousFiles,
                    (int) $limits['max_file_size'],
                    $config->projectRoot()
                );
            };

            $throttleFactory = static function (string $stampPath) use ($fs, $clock, $config): CheckThrottle {
                return new CheckThrottle($fs, $clock, $stampPath, $config->checkInterval(), $config->checkStore());
            };

            $lockFactory = self::lockFactory($config->lock(), $clock, $logger);
            $preProcessorFactory = self::preProcessorFactory($config->projectRoot());

            $orchestratorFactory = static function (
                callable $scanSupplier,
                DiagnosticBag $requestBag
            ) use (
                $config,
                $store,
                $writer,
                $resolver,
                $preProcessorFactory,
                $fs,
                $clock,
                $logger,
                $lockFactory
            ): CompileOrchestrator {
                return new CompileOrchestrator(
                    $config,
                    $store,
                    $writer,
                    $resolver,
                    $scanSupplier,
                    $preProcessorFactory,
                    $requestBag,
                    $fs,
                    $clock,
                    $logger,
                    $lockFactory
                );
            };

            $this->pipeline = new AssetPipeline(
                $config,
                $store,
                new FileFinder(
                    $config->cacheDir(),
                    $config->bundle($this->bundleName(null))->ignore(),
                    (int) $limits['max_files']
                ),
                $scanCacheFactory,
                $throttleFactory,
                $resolver,
                $orchestratorFactory,
                $fs,
                $logger
            );
        }

        return $this->pipeline;
    }

    /**
     * Decision CCP-A34, in one place so the console and the bridge inherit it.
     */
    private static function preProcessorFactory(string $projectRoot): callable
    {
        return static function (BundleConfig $bundle) use ($projectRoot): PreProcessorInterface {
            $name = $bundle->preprocessor();

            if ($name === null) {
                return new NullPreProcessor();
            }

            if ($name === 'scss') {
                return new ScssPreProcessor($projectRoot, $bundle->preprocessorOptions());
            }

            // A fully-qualified class name. `ConfigBuilder` has already proved
            // it exists and implements the interface, so the only contract left
            // to state is the constructor's, which CCP-A34 fixes as
            // `(string $projectRoot, array $options)`.
            $instance = new $name($projectRoot, $bundle->preprocessorOptions());

            if (!$instance instanceof PreProcessorInterface) {
                // Unreachable through `ConfigBuilder`, which checks the class
                // with `is_subclass_of()`. Asserted at the construction site
                // anyway: this is the only place the library instantiates a
                // class name it was handed, and the alternative to a clear
                // message here is a `TypeError` from inside the compile
                // transaction.
                throw new ConfigurationException([
                    \sprintf('The configured pre-processor must implement %s.', PreProcessorInterface::class),
                ]);
            }

            return $instance;
        };
    }

    /**
     * @return callable(string): LockInterface
     */
    private static function lockFactory(string $lock, SystemClock $clock, LoggerInterface $logger): callable
    {
        if ($lock === 'none') {
            return static function (string $path): LockInterface {
                return new NullLock();
            };
        }

        return static function (string $path) use ($clock, $logger): LockInterface {
            return new FlockBuildLock($path, $clock, $logger);
        };
    }

    /**
     * Which pre-pass applies to a file, by extension.
     *
     * The map is built on the **first file scanned**, not when the pipeline is
     * wired: a warm request answers from the cache without scanning anything,
     * and building the map eagerly put `TwigPrePass` on the warm path for no
     * reason (measured — it was one of the classes over doc 13's budget).
     * After that it is captured by reference, so a project with a thousand
     * templates constructs one `TwigPrePass`, not a thousand.
     *
     * @return callable(string): (PrePassInterface|null)
     */
    private function prepassSelector(): callable
    {
        $scanner = $this->config->scanner();
        $configured = isset($scanner['prepass']) && \is_array($scanner['prepass']) ? $scanner['prepass'] : [];
        $map = null;

        return static function (string $rel) use ($configured, &$map): ?PrePassInterface {
            if ($map === null) {
                $map = self::prepasses($configured);
            }

            $dot = \strrpos($rel, '.');

            if ($dot === false) {
                return null;
            }

            $extension = \strtolower(\substr($rel, $dot + 1));

            return $map[$extension] ?? null;
        };
    }

    /**
     * @param array<int, mixed> $configured From `scanner.prepass`.
     *
     * @return array<string, PrePassInterface> Extension => pre-pass.
     */
    private static function prepasses(array $configured): array
    {
        $map = [];

        foreach ($configured as $name) {
            if ($name === 'twig') {
                $map['twig'] = new TwigPrePass();
            } elseif ($name === 'php') {
                $map['php'] = new PhpPrePass();
            }
        }

        return $map;
    }

    private function matcher(): RouteMatcher
    {
        if ($this->matcher === null) {
            $routes = [];

            foreach ($this->config->bundles() as $name => $ignored) {
                $routes[(string) $name] = $this->config->bundle((string) $name)->publicRoute();
            }

            $this->matcher = new RouteMatcher($routes);
        }

        return $this->matcher;
    }

    private function responseBuilder(): ResponseBuilder
    {
        if ($this->responseBuilder === null) {
            $http = $this->config->http();

            $this->responseBuilder = new ResponseBuilder(
                isset($http['extra_headers']) && \is_array($http['extra_headers']) ? $http['extra_headers'] : [],
                (int) $http['immutable_max_age'],
                $this->config->debug()
            );
        }

        return $this->responseBuilder;
    }

    private function responder(): Responder
    {
        if ($this->responder === null) {
            $http = $this->config->http();

            $this->responder = new Responder(
                $this->matcher(),
                $this->pipeline(),
                $this->store(),
                $this->responseBuilder(),
                $this->filesystem(),
                isset($http['handle_options']) && $http['handle_options'] === true
            );
        }

        return $this->responder;
    }

    private function urlResolver(): UrlResolver
    {
        if ($this->urlResolver === null) {
            $this->urlResolver = new UrlResolver($this->config, $this->store(), $this->filesystem());
        }

        return $this->urlResolver;
    }
}
