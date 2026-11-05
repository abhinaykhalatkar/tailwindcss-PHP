<?php

/**
 * Compile one project with the PHP engine and with the official CLI and compare the two stylesheets
 * (TASK-0054, the last M2 acceptance bullet; design doc 23 §M2).
 *
 *   php tools/compare-drivers.php <project-root> [--entry=<relative css file>] [--minify]
 *
 * The project's own configuration (`tailwind.php` or `config/tailwind.php`, or the defaults) says where the entry
 * stylesheet and the template directories are. The CLI is the reference: a rule it writes that the engine does not
 * is `FAIL_MISSING`, a rule the engine adds is `FAIL_EXTRA`, a different declaration `FAIL_VALUE`, a different
 * position `FAIL_ORDER`. The official binary must be installed (`vendor/bin/tailwind-php install-cli`).
 *
 * Nothing is written into the project and no path is printed (T-01, T-06); see `DriverComparison` for the rules.
 *
 * Exit codes: 0 identical, 1 any difference (or a driver that reported errors), 2 usage or environment.
 */

declare(strict_types=1);

// Maintainer tooling, command line only: under a web SAPI it would see no arguments and run its
// default action. Nothing is printed, because `STDERR` does not exist there.
if (\PHP_SAPI !== 'cli') {
    exit(2);
}

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
require_once __DIR__ . '/DriverComparison.php';

exit(TailwindPhp\Tools\DriverComparison::execute(
    array_slice($cliArguments, 1),
    static function (string $text): void {
        fwrite(\STDOUT, $text);
    },
    static function (string $text): void {
        fwrite(\STDERR, $text);
    }
));
