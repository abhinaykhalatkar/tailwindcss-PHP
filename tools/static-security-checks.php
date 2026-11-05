<?php

/**
 * CI front end for the static security rules of design doc 16 §Security suite.
 *
 * The rules themselves live in StaticSecurityChecks so this script and
 * tests/Security/StaticChecksTest.php cannot drift apart; run by the `security`
 * job of design doc 20 §ci.yml.
 *
 * Deliberately free of Composer: it is a maintainer script that must work in a
 * checkout where `vendor/` has not been installed.
 *
 * Exit code 0 when clean, 1 when any rule fires.
 */

declare(strict_types=1);

// Maintainer tooling, command line only: under a web SAPI it would see no arguments and run its
// default action. Nothing is printed, because `STDERR` does not exist there.
if (\PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/StaticSecurityChecks.php';

$repositoryRoot = dirname(__DIR__);
$checks = new TailwindPhp\Tools\StaticSecurityChecks();

$violations = $checks->run(
    $repositoryRoot . '/src',
    $repositoryRoot . '/tests/Fixtures/manifests'
);

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(\STDERR, sprintf(
            "%s:%d %s: %s\n",
            $violation['file'],
            $violation['line'],
            $violation['rule'],
            $violation['text']
        ));
    }

    fwrite(\STDERR, sprintf("\nStatic security checks: %d violation(s).\n", count($violations)));

    exit(1);
}

fwrite(\STDOUT, sprintf("Static security checks: OK (%d files scanned)\n", $checks->scannedFiles()));

exit(0);
