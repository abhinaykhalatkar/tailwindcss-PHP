<?php

/**
 * Generate the T1 utilities corpus from the independent inventory (design doc 08
 * §Acceptance thresholds, doc 16 §Corpus layout, decision ENG-A10).
 *
 *   php tools/generate-corpus.php                       write every family file
 *   php tools/generate-corpus.php --only=spacing-sizing one family
 *   php tools/generate-corpus.php --check               write nothing; exit 1 on drift
 *
 * The files land in `tests/Conformance/corpus/utilities/<family>.txt`. Their goldens
 * are produced afterwards by `php tools/generate-goldens.php --only=utilities/<family>`:
 * this script never runs the Tailwind binary (T-04, T-18) and never downloads
 * anything.
 *
 * `--only` is matched against the inventory's family list and never joined to a
 * path (T-01), and a file that does not start with the generated-file header is
 * never overwritten, so the hand-written `edge-*.txt` files are safe.
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
require_once __DIR__ . '/CorpusGenerator.php';

$usage = "Usage: php tools/generate-corpus.php [--only=<family>] [--check]\n";
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
    $generator = new TailwindPhp\Tools\CorpusGenerator(
        new TailwindPhp\Tests\Conformance\Support\Inventory(),
        TailwindPhp\Engine\Data\DefaultTheme::vars(),
        $repositoryRoot . '/tests/Conformance/corpus/utilities'
    );

    $results = $generator->run($only, $check);
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n" . $usage);

    exit(2);
} catch (RuntimeException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
} catch (LogicException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");

    exit(2);
}

$total = 0;

foreach ($results as $result) {
    $total += $result['lines'];

    fwrite(\STDOUT, sprintf("%-10s %-42s %5d lines\n", $result['status'], $result['file'], $result['lines']));
}

fwrite(\STDOUT, sprintf("Corpus: %d families, %d candidate lines.\n", count($results), $total));

if ($check && TailwindPhp\Tools\CorpusGenerator::hasDrift($results)) {
    fwrite(\STDERR, "The committed corpus is stale; run `php tools/generate-corpus.php` and commit the result.\n");

    exit(1);
}

exit(0);
