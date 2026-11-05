<?php

/**
 * Render the known-differences report (design doc 08 §Known differences policy,
 * decisions ENG-A9, ENG-A12).
 *
 *   php tools/known-differences.php            write docs/compat/KNOWN-DIFFERENCES.md
 *   php tools/known-differences.php --check    write nothing; exit 1 when it is stale
 *
 * The truth is `tests/Conformance/known-differences.php`; the Markdown is only its
 * rendering for people. The only file this script writes is
 * `docs/compat/KNOWN-DIFFERENCES.md` below the repository root: no path comes from
 * the command line (T-01). The Markdown stays local until the owner decides to
 * whitelist it in `.gitignore`.
 *
 * Exit codes: 0 clean, 1 drift found by --check, 2 usage or environment error.
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
require_once __DIR__ . '/KnownDifferences.php';

$usage = "Usage: php tools/known-differences.php [--check]\n";
$check = false;

foreach (array_slice($cliArguments, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;

        continue;
    }

    fwrite(\STDERR, 'Unknown argument "' . substr($argument, 0, 40) . "\".\n" . $usage);

    exit(2);
}

$target = $repositoryRoot . '/docs/compat/KNOWN-DIFFERENCES.md';

try {
    $entries = TailwindPhp\Tools\KnownDifferences::load($repositoryRoot . '/tests/Conformance/known-differences.php');
    $version = (new TailwindPhp\Tests\Conformance\Support\CorpusPaths())->version();
    $text = TailwindPhp\Tools\KnownDifferences::render($entries, $version);
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
} catch (RuntimeException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
}

$counts = [];

foreach ($entries as $entry) {
    $counts[$entry['class']] = ($counts[$entry['class']] ?? 0) + 1;
}

ksort($counts);

$summary = [];

foreach ($counts as $class => $count) {
    $summary[] = $count . ' ' . $class;
}

$existing = is_file($target) ? file_get_contents($target) : false;

if ($existing === $text) {
    fwrite(\STDOUT, sprintf("Known differences for Tailwind %s: unchanged (%s).\n", $version, implode(', ', $summary)));

    exit(0);
}

if ($check) {
    fwrite(\STDERR, "docs/compat/KNOWN-DIFFERENCES.md is stale; run `php tools/known-differences.php`.\n");

    exit(1);
}

$directory = dirname($target);

if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(\STDERR, "Could not create docs/compat.\n");

    exit(2);
}

if (file_put_contents($target, $text) === false) {
    fwrite(\STDERR, "Could not write docs/compat/KNOWN-DIFFERENCES.md.\n");

    exit(2);
}

fwrite(\STDOUT, sprintf("Known differences for Tailwind %s: written (%s).\n", $version, implode(', ', $summary)));

exit(0);
