<?php

/**
 * Generate the conformance goldens with the pinned official Tailwind CLI
 * (design doc 16 §Golden generation, decision ENG-A11).
 *
 *   php tools/generate-goldens.php                       regenerate every golden
 *   php tools/generate-goldens.php --only=variants       one group
 *   php tools/generate-goldens.php --only=variants/responsive   one entry
 *   php tools/generate-goldens.php --check               write nothing; exit 1 on drift
 *
 * The binary is installed beforehand — this script never downloads one (T-18):
 *
 *   php bin/tailwind-php install-cli --version=4.1.18 --dest=tools/.cache/tailwindcss[.exe]
 *
 * and is verified against the digest pinned for `tests/Conformance/golden/VERSION`
 * before every run (T-04). `--only` is looked up in the corpus listing and never
 * joined to a path (T-01); there is no option that skips verification.
 *
 * Exit codes: 0 clean, 1 drift found by --check, 2 usage or environment error.
 *
 * Needs the dev autoloader (`composer update`), because the runner reuses the
 * library's own process and checksum classes and the tests' temp-tree helper.
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
require_once __DIR__ . '/CliRunner.php';
require_once __DIR__ . '/GoldenGenerator.php';

$usage = "Usage: php tools/generate-goldens.php [--only=<group|group/entry>] [--check]\n";
$only = null;
$check = false;

foreach (array_slice($cliArguments, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;

        continue;
    }

    if (strncmp($argument, '--only=', 7) === 0) {
        // An empty selector ('--only=') is rejected by the generator like any other bad one.
        $only = substr($argument, 7);

        continue;
    }

    fwrite(\STDERR, 'Unknown argument "' . substr($argument, 0, 40) . "\".\n" . $usage);

    exit(2);
}

try {
    $paths = new TailwindPhp\Tests\Conformance\Support\CorpusPaths();
    $version = $paths->version();
    $runner = TailwindPhp\Tools\CliRunner::forRepository($repositoryRoot, $version);
    $generator = new TailwindPhp\Tools\GoldenGenerator($paths, [$runner, 'compile'], $version);

    $results = $generator->run($only, $check);
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n" . $usage);

    exit(2);
} catch (RuntimeException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
}

$counts = [];

foreach ($results as $result) {
    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

    if ($result['status'] !== 'unchanged') {
        fwrite(\STDOUT, sprintf("%-9s %s\n", $result['status'], $result['file']));
    }
}

ksort($counts);

$summary = [];

foreach ($counts as $status => $count) {
    $summary[] = $count . ' ' . $status;
}

fwrite(\STDOUT, sprintf(
    "Goldens for Tailwind %s: %s (%d files).\n",
    $version,
    $summary === [] ? 'nothing to do' : implode(', ', $summary),
    count($results)
));

if ($check && TailwindPhp\Tools\GoldenGenerator::hasDrift($results)) {
    fwrite(\STDERR, "The committed goldens are stale; run `composer goldens` and commit the result.\n");

    exit(1);
}

exit(0);
