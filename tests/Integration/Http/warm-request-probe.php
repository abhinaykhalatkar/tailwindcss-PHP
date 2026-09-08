<?php

/**
 * Whole-warm-request class-budget probe (design doc 13 #7; decision CCP-A33;
 * TASK-0018 acceptance 2).
 *
 * The sibling probe in `tests/Integration/Pipeline/` measures
 * `AssetPipeline::resolve()`. This one measures what an integrator actually
 * pays for: `Tailwind::fromArray()` plus `respond()` on a request that turns
 * out to be cached and fresh — configuration bootstrap, the whole wiring graph
 * and the HTTP layer included.
 *
 * Run in a **fresh process** by {@see \TailwindPhp\Tests\Integration\Http\WarmRequestBudgetTest}:
 * counting `get_declared_classes()` inside the PHPUnit process would measure
 * whatever the rest of the suite happened to autoload first, which is a number
 * about test ordering rather than about the request path.
 *
 * Usage:
 *   php warm-request-probe.php prepare <projectRoot>   cold build, fills the cache
 *   php warm-request-probe.php measure <projectRoot>   warm request, prints the counts
 *
 * Output is one machine-readable line followed by the class list:
 *   served=<0|1> bytes=<n> total=<n> http=<n> forbidden=<comma-separated|none>
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
$root = str_replace('\\', '/', (string) ($argv[2] ?? ''));
$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (($mode !== 'prepare' && $mode !== 'measure') || $root === '' || !is_file($autoload)) {
    fwrite(\STDERR, "usage: warm-request-probe.php prepare|measure <projectRoot>\n");

    exit(2);
}

require $autoload;

/**
 * Our classes loaded so far. The autoloader, PHPUnit and PHP's own classes are
 * not what doc 13 budgets.
 *
 * @return list<string>
 */
$ours = static function (string $prefix = 'TailwindPhp\\'): array {
    return array_values(array_filter(get_declared_classes(), static function (string $class) use ($prefix): bool {
        return strpos($class, $prefix) === 0;
    }));
};

if ($mode === 'prepare') {
    mkdir($root . '/assets/styles', 0777, true);
    mkdir($root . '/templates', 0777, true);
    mkdir($root . '/cache/tailwind', 0777, true);
    file_put_contents($root . '/assets/styles/main.css', "@import \"tailwindcss\";\n");
    file_put_contents($root . '/templates/index.twig', "<div class=\"flex p-4 text-sm\"></div>\n");
}

$config = [
    'project_root' => $root,
    'driver' => 'php',
    'input' => 'assets/styles/main.css',
    'sources' => ['templates'],
    'cache_dir' => 'cache/tailwind',
    'bundles' => ['app' => []],
];

$start = count($ours());

$tailwind = \TailwindPhp\Tailwind::fromArray($config);

// Two buffer levels: `Responder::emit()` discards exactly one, so the stylesheet
// lands in the outer one instead of on this probe's stdout.
ob_start();
ob_start();
$served = $tailwind->respond('assets/styles.css', [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/assets/styles.css',
]);
$body = (string) ob_get_clean();

$loaded = $ours();
$http = $ours('TailwindPhp\\Http\\');

/**
 * Classes that must not appear on a warm, cached request: the compile
 * transaction, the SCSS stage, the console, and — because this project runs the
 * PHP engine — the CLI driver.
 */
$forbiddenPrefixes = [
    'TailwindPhp\\Pipeline\\CompileOrchestrator',
    'TailwindPhp\\PreProcessor\\ScssPreProcessor',
    'TailwindPhp\\Console\\',
    'TailwindPhp\\Compiler\\Cli\\',
];

$forbidden = [];

foreach ($loaded as $class) {
    foreach ($forbiddenPrefixes as $prefix) {
        if (strpos($class, $prefix) === 0) {
            $forbidden[] = $class;
        }
    }
}

printf(
    "served=%d bytes=%d total=%d http=%d forbidden=%s\n",
    $served ? 1 : 0,
    strlen($body),
    count($loaded) - $start,
    count($http),
    $forbidden === [] ? 'none' : implode(',', $forbidden)
);

if ($mode === 'measure') {
    sort($loaded);
    echo implode("\n", $loaded), "\n";
}
