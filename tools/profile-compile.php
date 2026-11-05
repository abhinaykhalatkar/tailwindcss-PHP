<?php

/**
 * Where one PHP-engine compile spends its time (design doc 13 §Profiling).
 *
 *   php tools/profile-compile.php utilities/layout
 *   php tools/profile-compile.php variants/responsive --runs=10
 *   php tools/profile-compile.php directives/apply --minify
 *
 * Compiles one conformance corpus entry — a candidate list under the goldens'
 * preamble, or a directive entry with its candidates and partials — N times
 * (default 5, at most 50) and prints the best and median milliseconds of each
 * engine phase, the whole `Engine\Compiler` compile and the peak memory. See
 * `tools/CompileProfiler.php` for how the phases are timed without touching
 * `src/Engine`. The key is looked up in the corpus listing, never joined to a
 * path (T-01); nothing is written.
 *
 * Use it when an `EngineBench` budget regresses. Run it with opcache on
 * (`php -d opcache.enable_cli=1 …`) to match the benchmark's assumptions.
 *
 * Exit codes: 0 printed, 2 usage error or unknown key.
 *
 * Needs the dev autoloader (`composer update`): the corpus map, the import
 * resolver and the median come from the tests' support classes.
 */

declare(strict_types=1);

// The command line as a list of strings: `$argv` is not declared to every analysis runtime
// (PHPStan on PHP 8.5), and in the CLI `$_SERVER['argv']` is the same list.
$cliArguments = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));

$repositoryRoot = dirname(__DIR__);
$autoload = $repositoryRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(\STDERR, "vendor/autoload.php is missing; run `composer update` first.\n");

    exit(2);
}

require_once $autoload;
require_once __DIR__ . '/CompileProfiler.php';

$usage = "Usage: php tools/profile-compile.php <corpus-key> [--runs=N] [--minify]\n";

try {
    $arguments = TailwindPhp\Tools\CompileProfiler::parseArguments(array_values(array_slice($cliArguments, 1)));
    $profiler = new TailwindPhp\Tools\CompileProfiler(new TailwindPhp\Tests\Conformance\Support\CorpusPaths());

    if (!in_array($arguments['key'], $profiler->keys(), true)) {
        fwrite(\STDERR, sprintf(
            "\"%s\" is not a compile in the conformance corpus. Keys: %s.\n%s",
            TailwindPhp\Engine\Css\Escape::string(substr($arguments['key'], 0, 60)),
            implode(', ', $profiler->keys()),
            $usage
        ));

        exit(2);
    }

    $profile = $profiler->profile($arguments['key'], $arguments['runs'], $arguments['minify']);
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n" . $usage);

    exit(2);
} catch (RuntimeException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
}

fwrite(\STDOUT, TailwindPhp\Tools\CompileProfiler::render($profile));

exit(0);
