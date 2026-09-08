<?php

/**
 * Warm-path class-budget probe (design doc 13 #7; TASK-0015 acceptance 9).
 *
 * Run in a **fresh process** by {@see \TailwindPhp\Tests\Integration\Pipeline\WarmPathBudgetTest}:
 * counting `get_declared_classes()` inside the PHPUnit process would measure
 * whatever the rest of the suite happened to autoload first, which is a number
 * about test ordering rather than about the request path.
 *
 * Usage:
 *   php warm-path-probe.php prepare <projectRoot>   cold compile, fills the cache
 *   php warm-path-probe.php measure <projectRoot>   warm resolve, prints the counts
 *
 * Output is one machine-readable line:
 *   source=<cached|compiled|...> bootstrap=<n> wiring=<n> resolve=<n> total=<n>
 *
 * `resolve` is the number the budget is about: classes autoloaded by
 * `AssetPipeline::resolve()` on a bundle that turns out to be FRESH.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
$root = str_replace('\\', '/', (string) ($argv[2] ?? ''));
$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (($mode !== 'prepare' && $mode !== 'measure') || $root === '' || !is_file($autoload)) {
    fwrite(\STDERR, "usage: warm-path-probe.php prepare|measure <projectRoot>\n");

    exit(2);
}

require $autoload;

/**
 * Library classes loaded so far. Only ours: the autoloader, PHPUnit and PHP's
 * own classes are not what doc 13 budgets.
 */
$count = static function (): int {
    return count(array_filter(get_declared_classes(), static function (string $class): bool {
        return strpos($class, 'TailwindPhp\\') === 0;
    }));
};

$start = $count();

if ($mode === 'prepare') {
    mkdir($root . '/assets/styles', 0777, true);
    mkdir($root . '/templates', 0777, true);
    mkdir($root . '/cache/tailwind', 0777, true);
    file_put_contents($root . '/assets/styles/main.css', "@import \"tailwindcss\";\n");
    file_put_contents($root . '/templates/index.twig', "<div class=\"flex p-4 text-sm\"></div>\n");
}

$config = \TailwindPhp\Config\ConfigBuilder::fromArray([
    'project_root' => $root,
    'driver' => 'php',
    'input' => 'assets/styles/main.css',
    'sources' => ['templates'],
    'cache_dir' => 'cache/tailwind',
    'bundles' => ['app' => []],
]);

$afterBootstrap = $count();

$fs = new \TailwindPhp\Support\Filesystem();
$clock = new \TailwindPhp\Support\SystemClock();
$writer = new \TailwindPhp\Cache\AtomicWriter($config->projectRoot());
$limits = $config->limits();
$bundle = $config->bundle('app');

$store = new \TailwindPhp\Cache\CacheStore(
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

$resolver = new \TailwindPhp\Compiler\DriverResolver(
    new \TailwindPhp\Compiler\Cli\Environment(),
    new \TailwindPhp\Compiler\Cli\BinaryVerifier(
        \TailwindPhp\Compiler\Cli\Checksums::fromTable([]),
        $fs,
        $config->projectRoot()
    ),
    new \TailwindPhp\Compiler\PhpEngineCompiler(),
    static function (string $binary, string $version): \TailwindPhp\Compiler\CompilerInterface {
        throw new \LogicException('The CLI driver is not part of this probe.');
    }
);

$scanCacheFactory = static function (array $previousFiles) use ($fs, $config, $limits): \TailwindPhp\Scanner\ScanCache {
    return new \TailwindPhp\Scanner\ScanCache(
        $fs,
        new \TailwindPhp\Scanner\CandidateExtractor(),
        static function (string $rel) {
            return null;
        },
        $previousFiles,
        (int) $limits['max_file_size'],
        $config->projectRoot()
    );
};

$throttleFactory = static function (string $stamp) use ($fs, $clock, $config): \TailwindPhp\Freshness\CheckThrottle {
    return new \TailwindPhp\Freshness\CheckThrottle(
        $fs,
        $clock,
        $stamp,
        $config->checkInterval(),
        $config->checkStore()
    );
};

$orchestratorFactory = static function (
    callable $scanSupplier,
    \TailwindPhp\Engine\Diagnostic\DiagnosticBag $bag
) use (
    $config,
    $store,
    $writer,
    $resolver,
    $fs,
    $clock
): \TailwindPhp\Pipeline\CompileOrchestrator {
    return new \TailwindPhp\Pipeline\CompileOrchestrator(
        $config,
        $store,
        $writer,
        $resolver,
        $scanSupplier,
        static function (\TailwindPhp\Config\BundleConfig $b): \TailwindPhp\PreProcessor\NullPreProcessor {
            return new \TailwindPhp\PreProcessor\NullPreProcessor();
        },
        $bag,
        $fs,
        $clock
    );
};

$pipeline = new \TailwindPhp\Pipeline\AssetPipeline(
    $config,
    $store,
    new \TailwindPhp\Scanner\FileFinder($config->cacheDir(), $bundle->ignore(), (int) $limits['max_files']),
    $scanCacheFactory,
    $throttleFactory,
    $resolver,
    $orchestratorFactory,
    $fs,
    null
);

$afterWiring = $count();

$result = $pipeline->resolve('app');

$end = $count();

printf(
    "source=%s bootstrap=%d wiring=%d resolve=%d total=%d\n",
    $result->source(),
    $afterBootstrap - $start,
    $afterWiring - $afterBootstrap,
    $end - $afterWiring,
    $end - $start
);
