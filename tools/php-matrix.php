<?php

/**
 * The local PHP version matrix (TASK-0056): every supported minor (from the floor in composer.json's require.php;
 * older installs are listed as unsupported and skipped), each resolving its own dependency set (plus a lowest cell
 * for the floor's minor and 8.4) and running lint, the four suites and compare-drivers, in one table.
 *
 *   php tools/php-matrix.php --list                   what is installed (scoop, XAMPP)
 *   php tools/php-matrix.php                          the whole matrix
 *   php tools/php-matrix.php --only=8.2,8.5           some minors
 *   php tools/php-matrix.php --no-lowest              the highest cells only
 *   php tools/php-matrix.php --only=8.2 --skip-install  today's vendor/, no resolution
 *   php tools/php-matrix.php --kit=<dir>              also compare-drivers on a live project
 *                                                     (or the TAILWINDPHP_MATRIX_KIT environment variable)
 *   php tools/php-matrix.php --no-kit                 never the live project
 *   php tools/php-matrix.php --restore                put vendor/ back after an interrupted run
 *
 * The versions are installed with scoop (`scoop install versions/php82 versions/php83 …`); see CONTRIBUTING.md.
 * `vendor/` is moved to `.matrix/vendor-current/` while the matrix runs and always moved back (so give it its own
 * worktree); each cell's set and lock stay under `.matrix/php<NN>[-lowest]/`. Nothing else is written. See
 * `tools/PhpMatrix.php` for the rules.
 *
 * Exit codes: 0 every cell passes, 1 a cell fails, 2 usage, a missing version or an environment problem.
 *
 * Needs no autoloader: the class is required by path, so it keeps working while `vendor/` is swapped.
 */

declare(strict_types=1);

require_once __DIR__ . '/PhpMatrix.php';

$repositoryRoot = dirname(__DIR__);
$environment = [];

foreach (getenv() as $name => $value) {
    $environment[(string) $name] = (string) $value;
}

$scoop = $environment['SCOOP'] ?? (($environment['USERPROFILE'] ?? '') . '/scoop');

$matrix = new TailwindPhp\Tools\PhpMatrix(
    $repositoryRoot,
    TailwindPhp\Tools\PhpMatrix::processRunner($repositoryRoot . '/.matrix/tmp'),
    static function (string $text): void {
        fwrite(\STDOUT, $text);
    },
    $environment
);

exit($matrix->execute(array_values(array_slice($argv, 1)), $scoop . '/apps', TailwindPhp\Tools\PhpMatrix::XAMPP_PHP));
