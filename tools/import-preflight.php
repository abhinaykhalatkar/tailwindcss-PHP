<?php

/**
 * Regenerate src/Engine/Data/Preflight.php from the pinned official Tailwind CLI
 * (design doc 07 §Layers, decision ENG-A5).
 *
 *   php tools/import-preflight.php                 rewrite the file
 *   php tools/import-preflight.php --check         write nothing; exit 1 when it would change
 *   php tools/import-preflight.php --binary=<path> use a binary inside the repository
 *
 * The binary is installed beforehand — this script never downloads one (T-18) — and is
 * verified against the digest pinned for tests/Conformance/golden/VERSION before it runs
 * (T-04). All of the work is in DataImporter.
 *
 * Exit codes: 0 clean, 1 drift found by --check, 2 usage or environment error.
 */

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__);
$autoload = $repositoryRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(\STDERR, "vendor/autoload.php is missing; run `composer update` first.\n");

    exit(2);
}

require_once $autoload;
require_once __DIR__ . '/CliRunner.php';
require_once __DIR__ . '/GoldenGenerator.php';
require_once __DIR__ . '/DataImporter.php';

exit(TailwindPhp\Tools\DataImporter::main('preflight', $argv, $repositoryRoot));
