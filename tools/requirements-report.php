#!/usr/bin/env php
<?php

/**
 * Requirements traceability report (design doc 16 §Requirement traceability,
 * doc 20 §CI).
 *
 * Reads the requirement table from `tools/requirements.json` (the committed
 * source of truth: the planning documents are not part of the repository),
 * scans `tests/` for `@covers-requirement <ID>[, <ID>…]` and
 * `@covers-invariant I<n>` docblock tags, and prints one line per requirement
 * with the tests that cover it. A tag on a class docblock covers every test in
 * that class and is reported as `File::*`.
 *
 *   php tools/requirements-report.php            table on stdout, exit 0
 *   php tools/requirements-report.php --check    table + the uncovered MUST list, exit 0
 *   php tools/requirements-report.php --strict   as --check, exit 1 when a MUST is uncovered
 *   php tools/requirements-report.php --json     one JSON object on stdout
 *
 * Maintainer tooling: PHP 7.3, no dependencies, not autoloaded by the library.
 * Written as a script of closures so the file declares no symbols (PSR-1).
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    fwrite(\STDERR, "requirements-report must be run from the command line.\n");
    exit(2);
}

$root = dirname(__DIR__);
$options = array_slice($argv, 1);
$strict = in_array('--strict', $options, true);
$check = $strict || in_array('--check', $options, true);
$json = in_array('--json', $options, true);

foreach ($options as $option) {
    if (!in_array($option, ['--check', '--strict', '--json'], true)) {
        fwrite(\STDERR, sprintf("Unknown option \"%s\". Use --check, --strict or --json.\n", $option));
        exit(2);
    }
}

$table = json_decode((string) file_get_contents($root . '/tools/requirements.json'), true);

if (!is_array($table) || $table === []) {
    fwrite(\STDERR, "tools/requirements.json is missing or empty.\n");
    exit(2);
}

/** @var array<string, array{level: string, title: string}> $requirements */
$requirements = [];

foreach ($table as $id => $row) {
    if (is_string($id) && is_array($row) && isset($row['level'], $row['title'])) {
        $requirements[$id] = ['level' => (string) $row['level'], 'title' => (string) $row['title']];
    }
}

/**
 * Every PHP file under a directory, sorted.
 */
$testFiles = static function (string $dir): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
};

/**
 * The IDs named after one tag in one docblock.
 */
$tagsIn = static function (string $docblock, string $tag): array {
    $ids = [];
    $lines = preg_split('/\R/', $docblock);

    foreach ($lines === false ? [] : $lines as $line) {
        $position = strpos($line, '@' . $tag);

        if ($position === false) {
            continue;
        }

        $rest = substr($line, $position + strlen($tag) + 1);
        $parts = preg_split('/[\s,]+/', trim($rest));

        foreach ($parts === false ? [] : $parts as $part) {
            $part = rtrim($part, '.;');

            if ($part !== '' && preg_match('/^(?:N?FR-[A-Z]+-\d+|I\d+)$/', $part) === 1) {
                $ids[] = $part;
            }
        }
    }

    return $ids;
};

/**
 * Walk one test file and return [id => list of "File::method"].
 */
$coverageIn = static function (string $path) use ($root, $tagsIn): array {
    $tokens = token_get_all((string) file_get_contents($path));
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    $coverage = [];
    $pending = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === \T_DOC_COMMENT) {
            $pending = array_merge($tagsIn($token[1], 'covers-requirement'), $tagsIn($token[1], 'covers-invariant'));

            continue;
        }

        if ($pending === []) {
            continue;
        }

        if ($token[0] === \T_CLASS) {
            foreach ($pending as $id) {
                $coverage[$id][] = $relative . '::*';
            }
            $pending = [];

            continue;
        }

        if ($token[0] === \T_FUNCTION) {
            $name = '';

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === \T_STRING) {
                    $name = $tokens[$j][1];

                    break;
                }

                if ($tokens[$j] === '(') {
                    break;
                }
            }

            foreach ($pending as $id) {
                $coverage[$id][] = $relative . '::' . ($name === '' ? '{closure}' : $name);
            }
            $pending = [];
        }
    }

    return $coverage;
};

/** @var array<string, list<string>> $coverage */
$coverage = [];

foreach ($testFiles($root . '/tests') as $file) {
    foreach ($coverageIn($file) as $id => $tests) {
        foreach ($tests as $test) {
            $coverage[$id][] = $test;
        }
    }
}

$uncoveredMust = [];
$report = [];

foreach ($requirements as $id => $row) {
    $tests = array_values(array_unique($coverage[$id] ?? []));
    $report[$id] = ['level' => $row['level'], 'title' => $row['title'], 'tests' => $tests];

    if ($row['level'] === 'MUST' && $tests === []) {
        $uncoveredMust[] = $id;
    }
}

$invariants = [];
$unknown = [];

foreach ($coverage as $id => $tests) {
    if ($id[0] === 'I') {
        $invariants[$id] = array_values(array_unique($tests));
    } elseif (!isset($requirements[$id])) {
        $unknown[] = $id;
    }
}

ksort($invariants, \SORT_NATURAL);

if ($json) {
    echo json_encode([
        'requirements' => $report,
        'invariants' => $invariants,
        'uncovered_must' => $uncoveredMust,
        'unknown_ids' => $unknown,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";

    exit($strict && $uncoveredMust !== [] ? 1 : 0);
}

$idWidth = 14;

foreach ($report as $id => $row) {
    echo str_pad($id, $idWidth), ' ', str_pad($row['level'], 6), ' ';
    echo $row['tests'] === [] ? '—' : count($row['tests']) . ' test(s)', "\n";

    foreach ($row['tests'] as $test) {
        echo str_repeat(' ', $idWidth + 8), $test, "\n";
    }
}

echo "\n";

foreach ($invariants as $id => $tests) {
    echo str_pad($id, $idWidth), ' ', implode(', ', $tests), "\n";
}

if ($unknown !== []) {
    echo "\nTags naming unknown requirements: ", implode(', ', $unknown), "\n";
}

if ($check) {
    $uncoveredAll = array_filter($report, static function (array $row): bool {
        return $row['tests'] === [];
    });

    $covered = count($requirements) - count($uncoveredAll);
    echo sprintf("\n%d of %d requirements covered.\n", $covered, count($requirements));

    if ($uncoveredMust === []) {
        echo "Every MUST requirement has at least one test.\n";
    } else {
        echo sprintf(
            "Uncovered MUST requirements (%d): %s\n",
            count($uncoveredMust),
            implode(', ', $uncoveredMust)
        );
    }
}

exit($strict && $uncoveredMust !== [] ? 1 : 0);
