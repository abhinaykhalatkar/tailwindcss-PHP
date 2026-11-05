<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

/**
 * The local PHP version matrix (TASK-0056): every supported PHP minor,
 * installed with scoop, each resolving its own dependency set and running the
 * whole suite, so the `require.php` promise of `composer.json` is checked on
 * this machine and not only by CI.
 *
 *   php tools/php-matrix.php [--only=8.2,8.5] [--no-lowest] [--skip-install] [--no-kit] [--kit=<dir>]
 *                            [--list] [--restore]
 *
 * **Supported versions.** The root manifest's `require.php` decides: an
 * installed version it does not allow — below its floor, at a strict lower
 * bound (`>8.2.35`) or above an upper bound (`>=8.2 <8.4`) — is listed as
 * "unsupported, skipped" and never run. The floor (the lowest version the
 * constraint allows, `>=8.2` → 8.2.0, `>8.2.35` → 8.2.36) names the lowest
 * cell. Discovery trusts the `SCOOP` or `USERPROFILE` location: every binary
 * found there is run once with `-n -r` to read its version.
 *
 * **Discovery.** A version is `<scoop apps>/php<major><minor>/current/php.exe`,
 * the scoop apps root being `%SCOOP%\apps` or `%USERPROFILE%\scoop\apps`.
 * XAMPP's `C:\xampp\php\php.exe` is listed and skipped when scoop has the same
 * minor. Every binary is asked for `PHP_VERSION` before use, and one that
 * answers another minor than its directory says is refused.
 *
 * **The cells.** One per supported minor with the highest dependencies, and,
 * as in CI, one more with the lowest (`--prefer-lowest --prefer-stable`) for
 * the floor's minor and for 8.4, run just before its highest cell.
 * `--no-lowest` and `--skip-install` drop the lowest cells.
 *
 * **Per cell, in order:**
 * 1. A manifest copy in `.matrix/php<NN>[-lowest]/composer.json`: the root
 *    manifest with the autoload paths prefixed `../../` and the `scripts`
 *    block dropped, nothing else changed. (The copy is re-encoded, so numbers
 *    may change form, and path repositories and `config.bin-dir` are not
 *    adjusted; the root manifest has neither.) It is resolved by that
 *    version's PHP with `composer update --no-plugins --no-scripts` (no global
 *    plugin under `COMPOSER_HOME` loads), the lock beside it and the packages
 *    in the repository's `vendor/`. Composer's advisory filter stays on; one
 *    attempt, no retry, and a failure is a finding.
 * 2. `php -l` over `src/`, `tools/` and `bin/`.
 * 3. The four suites (`Unit,Integration,Conformance,Security`).
 * 4. `tools/compare-drivers.php` on the fixture project and, given `--kit`,
 *    on the live kit.
 * 5. On the 8.3 highest cell only, where CI runs it, PHPStan with both
 *    configurations.
 *
 * **The shared `vendor/`.** It is moved to `.matrix/vendor-current/` before
 * the first resolution and always moved back at the end, in a `finally`; a
 * failed restore exits 2. Each cell's set is parked in
 * `.matrix/php<NN>[-lowest]/vendor/` between runs, so a rerun resolves
 * quickly. After a crash, `--restore` puts the original back. A run and
 * `--restore` hold `.matrix/lock` (created exclusively, with the pid inside,
 * removed at the end); a lock that is already there is reported with exit 2
 * and never broken. `vendor/` and `.matrix/` must be plain directories: a
 * link, a junction or a file there is refused with exit 2, and leftovers are
 * removed without following a link at any depth. Run the matrix in its own
 * worktree, never beside other work in the same checkout.
 *
 * **Children.** They inherit the parent environment, minus every inherited
 * spelling (Windows names are case-insensitive) of the variables the tool
 * sets itself: `COMPOSER`, `COMPOSER_VENDOR_DIR`,
 * `COMPOSER_NO_SECURITY_BLOCKING`, `COMPOSER_NO_INTERACTION`, `PHPRC` and
 * `PHP_INI_SCAN_DIR`. A step's time limit ends only the direct child (Windows
 * has no process-group kill here): after a step reports "[stopped after N
 * seconds]" or the run says "Stopped", check for orphaned `php.exe`.
 *
 * **Each child's ini.** Scoop's PHPs load no `php.ini` by default and its
 * installs point the user variable `PHP_INI_SCAN_DIR` at whichever version
 * was installed last. So every child gets
 * `PHPRC=.matrix/php<NN>/` — a written ini with that version's `extension_dir`,
 * `memory_limit=-1` and the extensions composer and the suites need, where the
 * DLL exists — and an empty `PHP_INI_SCAN_DIR`. Processes the tests start
 * inherit both.
 *
 * **Security (T-01, T-06, T-08).**
 * - Processes are started with argument arrays through `proc_open`: no shell,
 *   no command string.
 * - The only executables are the discovered `php.exe` files, and composer
 *   through them.
 * - Every argument is validated, and traversal and control bytes are refused.
 * - Writes stay under `.matrix/` and `vendor/`.
 * - The output carries versions, test names and assertion lines. The
 *   repository root is printed as `.` and the home directory as `~`, matched
 *   case-insensitively and in the `\`, `/` and `/d/…` forms; the only other
 *   paths printed are the php binaries and the kit root. Anything thrown is
 *   one scrubbed line ("Stopped: …", exit 2), never a trace.
 * - The kit cell runs `compare-drivers`, which loads the kit's own
 *   `config/tailwind.php` exactly as the library does.
 *
 * Maintainer tooling under `tools/`: not autoloaded, not shipped, required by
 * path. No regular expressions (R1), no `exec`/`shell_exec`/`system`.
 *
 * @phpstan-type RunResult array{exit: int, output: string, seconds: float}
 * @phpstan-type Options array{
 *     only: list<string>|null, skipInstall: bool, list: bool, noKit: bool, kit: string|null, restore: bool,
 *     noLowest: bool
 * }
 * @phpstan-type Php array{version: string, minor: string, path: string, source: string}
 * @phpstan-type Tests array{
 *     status: string, run: int, failures: int, errors: int, skipped: int, seconds: float, summary: string
 * }
 * @phpstan-type Row array{
 *     minor: string,
 *     version: string,
 *     resolved: string,
 *     lowest: bool,
 *     lint: string,
 *     tests: Tests,
 *     fixture: string,
 *     kit: string,
 *     stan: string,
 *     pass: bool,
 *     findings: list<string>
 * }
 */
final class PhpMatrix
{
    /**
     * The minors the matrix covers, in order.
     */
    public const VERSIONS = ['7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /**
     * Besides the floor's minor, the one CI also resolves with
     * `--prefer-lowest --prefer-stable`.
     */
    public const LOWEST_ALSO = '8.4';

    /**
     * Where PHPStan runs, as CI.
     */
    public const STAN_VERSION = '8.3';

    public const COMPOSER = 'C:/composer/composer.phar';

    public const XAMPP_PHP = 'C:/xampp/php/php.exe';

    /**
     * The environment variable naming the live kit's root when `--kit` is
     * not given (the kit is the owner's project, not part of this library).
     */
    public const KIT_ENV = 'TAILWINDPHP_MATRIX_KIT';

    /**
     * Seconds any one child may run before it is stopped and reported.
     */
    public const TIMEOUT = 900;

    /**
     * Seconds the four suites may run: on this host, under load and without
     * opcache, 8.2 had reached 94 % after 900 s (still progressing, not hung).
     */
    public const TEST_TIMEOUT = 2700;

    /**
     * Extensions written into each version's ini when its DLL exists.
     */
    public const EXTENSIONS = ['openssl', 'mbstring', 'curl', 'fileinfo', 'zip'];

    public const SUITES = 'Unit,Integration,Conformance,Security';

    public const FIXTURE = 'tests/Fixtures/project-basic';

    /**
     * Composer's opt-out of its advisory filter: never set, and removed from
     * an inherited environment, so every cell resolves with the filter on.
     */
    private const UNBLOCK = 'COMPOSER_NO_SECURITY_BLOCKING';

    /**
     * Variables the tool sets (or keeps unset) itself; an inherited one, in any
     * spelling, never reaches a child.
     */
    private const OWNED_VARIABLES = [
        'COMPOSER',
        'COMPOSER_VENDOR_DIR',
        self::UNBLOCK,
        'COMPOSER_NO_INTERACTION',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
    ];

    /**
     * Lines of a failing step kept for the findings.
     */
    private const EXCERPT_LINES = 12;

    /**
     * @var string
     */
    private $repo;

    /**
     * @var callable(list<string>, string, array<string, string>, int): RunResult
     */
    private $runner;

    /**
     * @var callable(string): void
     */
    private $out;

    /**
     * @var array<string, string>
     */
    private $baseEnv;

    /**
     * @param callable(list<string>, string, array<string, string>, int): RunResult $runner
     * @param callable(string): void $out
     * @param array<string, string> $baseEnv The environment every child starts from.
     */
    public function __construct(string $repo, callable $runner, callable $out, array $baseEnv)
    {
        $this->repo = \rtrim(\str_replace('\\', '/', $repo), '/');
        $this->runner = $runner;
        $this->out = $out;
        $this->baseEnv = $baseEnv;
    }

    public static function usage(): string
    {
        return 'Usage: php tools/php-matrix.php [--only=8.2,8.5] [--no-lowest] [--skip-install] [--no-kit]'
            . " [--kit=<dir>] [--list] [--restore]\n";
    }

    /**
     * @param list<string> $arguments
     *
     * @throws \InvalidArgumentException On anything but the documented options.
     *
     * @return Options
     */
    public static function parseArguments(array $arguments): array
    {
        $options = [
            'only' => null,
            'skipInstall' => false,
            'list' => false,
            'noKit' => false,
            'kit' => null,
            'restore' => false,
            'noLowest' => false,
        ];
        $flags = [
            '--skip-install' => 'skipInstall',
            '--list' => 'list',
            '--no-kit' => 'noKit',
            '--restore' => 'restore',
            '--no-lowest' => 'noLowest',
        ];

        foreach ($arguments as $argument) {
            self::assertClean($argument);

            if (isset($flags[$argument])) {
                $options[$flags[$argument]] = true;

                continue;
            }

            if (\strncmp($argument, '--only=', 7) === 0) {
                $options['only'] = self::minors((string) \substr($argument, 7));

                continue;
            }

            if (\strncmp($argument, '--kit=', 6) === 0) {
                $options['kit'] = self::directory((string) \substr($argument, 6));

                continue;
            }

            throw new \InvalidArgumentException(\sprintf('Unknown argument "%s".', \substr($argument, 0, 40)));
        }

        return $options;
    }

    /**
     * The PHP binaries under the scoop apps root, by minor, each answering its
     * own version; and XAMPP's, listed under `xampp` (used only when scoop
     * lacks its minor).
     *
     * @param callable(list<string>, string, array<string, string>, int): RunResult $runner
     *
     * @return array{found: array<string, Php>, xampp: Php|null, refused: list<string>}
     */
    public static function discover(string $scoopApps, ?string $xampp, callable $runner, string $cwd): array
    {
        $found = [];
        $refused = [];

        $apps = \rtrim(\str_replace('\\', '/', $scoopApps), '/');

        foreach (self::VERSIONS as $minor) {
            $path = $apps . '/php' . \str_replace('.', '', $minor) . '/current/php.exe';

            if (!\is_file($path)) {
                continue;
            }

            $version = self::versionOf($path, $runner, $cwd);

            if ($version === null || self::minorOf($version) !== $minor) {
                $refused[] = \sprintf('%s answers %s, not %s', $path, $version ?? 'nothing', $minor);

                continue;
            }

            $found[$minor] = ['version' => $version, 'minor' => $minor, 'path' => $path, 'source' => 'scoop'];
        }

        $xamppPhp = null;

        if ($xampp !== null && \is_file($xampp)) {
            $version = self::versionOf($xampp, $runner, $cwd);

            if ($version !== null && \in_array(self::minorOf($version), self::VERSIONS, true)) {
                $xamppPhp = [
                    'version' => $version,
                    'minor' => self::minorOf($version),
                    'path' => $xampp,
                    'source' => 'xampp',
                ];

                if (!isset($found[$xamppPhp['minor']])) {
                    $found[$xamppPhp['minor']] = $xamppPhp;
                }
            }
        }

        \uksort($found, static function (string $a, string $b): int {
            return \version_compare($a, $b);
        });

        return ['found' => $found, 'xampp' => $xamppPhp, 'refused' => $refused];
    }

    /**
     * The lowest version a `require.php` constraint allows, as `x.y.z`: the
     * smallest lower bound over its `||` alternatives, each the largest over its
     * space- or comma-separated parts (`>=8.2`, `^8.2`, `~8.2.35`, `8.2.*`,
     * `>= 8.2 <9`); a strict bound is exclusive (`>8.2.35` → 8.2.36, `>8.2` →
     * 8.2.1); `0.0.0` for an alternative with none (`*`, `<9`); null for
     * anything unreadable (`dev-main`, `>=8.2@dev`, an empty string).
     */
    public static function floorOf(string $constraint): ?string
    {
        $floor = null;

        foreach (\explode('|', \str_replace('||', '|', $constraint)) as $alternative) {
            $tokens = self::tokens($alternative);

            if ($tokens === []) {
                return null;
            }

            $lower = '0.0.0';

            foreach ($tokens as $token) {
                if (self::tokenAllows($token, '0.0.0') === null) {
                    return null;
                }

                if ($token === '*' || $token[0] === '<' || \strncmp($token, '!=', 2) === 0) {
                    continue;
                }

                $bound = self::bound(\ltrim($token, '>=^~'));

                if ($bound === null) {
                    return null;
                }

                $parts = self::padded($bound['parts']);
                $low = $token[0] === '>' && ($token[1] ?? '') !== '='
                    ? self::bumped($parts, 2)
                    : \implode('.', $parts);

                if (\version_compare($low, $lower, '>')) {
                    $lower = $low;
                }
            }

            if ($floor === null || \version_compare($lower, $floor, '<')) {
                $floor = $lower;
            }
        }

        return $floor;
    }

    /**
     * Whether a `require.php` constraint allows a reported `PHP_VERSION`: any
     * `||` alternative whose every part allows it (`>=`, `>`, `<=`, `<`, `!=`,
     * `=` or a bare version, `^`, `~`, `x.y.*`, `*`). An unreadable constraint
     * allows nothing.
     */
    public static function allows(string $constraint, string $version): bool
    {
        foreach (\explode('|', \str_replace('||', '|', $constraint)) as $alternative) {
            $tokens = self::tokens($alternative);

            if ($tokens === []) {
                continue;
            }

            $all = true;

            foreach ($tokens as $token) {
                if (self::tokenAllows($token, $version) !== true) {
                    $all = false;

                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cells for the selected minors, in order: a lowest cell just before
     * the highest one for the floor's minor and for 8.4 (when `$withLowest`).
     *
     * @param list<string> $minors
     *
     * @return list<array{minor: string, lowest: bool}>
     */
    public static function cells(array $minors, string $floor, bool $withLowest): array
    {
        $cells = [];

        foreach ($minors as $minor) {
            if ($withLowest && ($minor === self::minorOf($floor) || $minor === self::LOWEST_ALSO)) {
                $cells[] = ['minor' => $minor, 'lowest' => true];
            }

            $cells[] = ['minor' => $minor, 'lowest' => false];
        }

        return $cells;
    }

    /**
     * The root manifest as one version resolves it from `.matrix/php<NN>/`:
     * autoload paths prefixed `../../`, `scripts` dropped; nothing else
     * changed (objects stay objects).
     */
    public static function rewriteManifest(\stdClass $manifest): \stdClass
    {
        /** @var \stdClass $copy */
        $copy = \json_decode((string) \json_encode($manifest));

        unset($copy->scripts);

        foreach (['autoload', 'autoload-dev'] as $section) {
            if (!isset($copy->{$section}) || !$copy->{$section} instanceof \stdClass) {
                continue;
            }

            $autoload = $copy->{$section};

            foreach (['psr-4', 'psr-0'] as $kind) {
                if (isset($autoload->{$kind}) && $autoload->{$kind} instanceof \stdClass) {
                    foreach (\get_object_vars($autoload->{$kind}) as $namespace => $paths) {
                        $autoload->{$kind}->{$namespace} = self::prefixed($paths);
                    }
                }
            }

            foreach (['files', 'classmap', 'exclude-from-classmap'] as $kind) {
                if (isset($autoload->{$kind}) && \is_array($autoload->{$kind})) {
                    $autoload->{$kind} = self::prefixed(\array_values(\array_map('strval', $autoload->{$kind})));
                }
            }
        }

        return $copy;
    }

    /**
     * One version's ini: its `extension_dir`, no memory limit, and the
     * extensions whose DLL it ships.
     */
    public static function ini(string $phpPath): string
    {
        $extDir = \str_replace('\\', '/', \dirname($phpPath)) . '/ext';
        $ini = "; Written by tools/php-matrix.php for one matrix cell (TASK-0056).\n"
            . 'extension_dir = "' . $extDir . "\"\n"
            . "memory_limit = -1\n";

        foreach (self::EXTENSIONS as $extension) {
            if (\is_file($extDir . '/php_' . $extension . '.dll')) {
                $ini .= 'extension = ' . $extension . "\n";
            }
        }

        return $ini;
    }

    /**
     * The PHPUnit summary of one run: its status and counts, from the last
     * lines PHPUnit prints (9.6, 10 and 11 alike).
     *
     * @return Tests
     */
    public static function testSummary(string $output, int $exit, float $seconds): array
    {
        $summary = ['status' => $exit === 0 ? 'ok' : 'failed', 'run' => 0, 'failures' => 0, 'errors' => 0,
            'skipped' => 0, 'seconds' => $seconds, 'summary' => ''];
        $lines = \explode("\n", \str_replace("\r\n", "\n", self::withoutAnsi($output)));

        foreach (\array_reverse($lines) as $line) {
            $line = \trim($line);

            if (\strncmp($line, 'OK (', 4) === 0) {
                // `OK (7553 tests, 5191811 assertions)`
                $summary['run'] = (int) \substr($line, 4);
                $summary['summary'] = $line;

                break;
            }

            if (\strncmp($line, 'Tests: ', 7) === 0) {
                $summary['summary'] = $line;

                foreach (\explode(',', \rtrim($line, '.')) as $part) {
                    $pair = \explode(':', $part, 2);

                    if (\count($pair) !== 2) {
                        continue;
                    }

                    $key = \strtolower(\trim($pair[0]));
                    $value = (int) \trim($pair[1]);

                    if ($key === 'tests') {
                        $summary['run'] = $value;
                    } elseif ($key === 'failures') {
                        $summary['failures'] = $value;
                    } elseif ($key === 'errors') {
                        $summary['errors'] = $value;
                    } elseif ($key === 'skipped') {
                        $summary['skipped'] = $value;
                    }
                }

                break;
            }
        }

        if ($summary['summary'] === '') {
            $summary['status'] = 'not run';
        }

        return $summary;
    }

    /**
     * `PHPUnit 9.6.20` from a run's banner, or '' when there is none.
     */
    public static function phpunitVersion(string $output): string
    {
        foreach (\explode("\n", self::withoutAnsi($output)) as $line) {
            $line = \trim($line);

            if (\strncmp($line, 'PHPUnit ', 8) === 0) {
                $end = \strpos($line, ' ', 8);

                return $end === false ? $line : \substr($line, 0, $end);
            }
        }

        return '';
    }

    /**
     * The plain-text table: one line per version (and the PHPStan cell).
     *
     * @param list<Row> $rows
     */
    public static function render(array $rows): string
    {
        $head = ['version', 'resolved', 'lint', 'tests (run/fail/err/skip, s)', 'fixture', 'kit', 'phpstan', 'result'];
        $table = [$head];

        foreach ($rows as $row) {
            $tests = $row['tests'];
            $table[] = [
                self::label($row),
                $row['resolved'],
                $row['lint'],
                $tests['status'] === 'not run'
                    ? 'not run'
                    : \sprintf(
                        '%d/%d/%d/%d, %.0f',
                        $tests['run'],
                        $tests['failures'],
                        $tests['errors'],
                        $tests['skipped'],
                        $tests['seconds']
                    ),
                $row['fixture'],
                $row['kit'],
                $row['stan'],
                $row['pass'] ? 'PASS' : 'FAIL',
            ];
        }

        $widths = [];

        foreach ($table as $line) {
            foreach ($line as $index => $cell) {
                $widths[$index] = \max($widths[$index] ?? 0, \strlen($cell));
            }
        }

        $text = '';

        foreach ($table as $line) {
            $cells = [];

            foreach ($line as $index => $cell) {
                $cells[] = \str_pad($cell, $widths[$index]);
            }

            $text .= \rtrim(\implode(' | ', $cells)) . "\n";
        }

        foreach ($rows as $row) {
            foreach ($row['findings'] as $finding) {
                $text .= "\n[" . $row['minor'] . ($row['lowest'] ? ' lowest' : '') . '] ' . $finding . "\n";
            }
        }

        return $text;
    }

    /**
     * 0 when every row passes, 1 otherwise.
     *
     * @param list<Row> $rows
     */
    public static function exitCode(array $rows): int
    {
        foreach ($rows as $row) {
            if (!$row['pass']) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * The whole command: 0 all green, 1 a failure, 2 usage or environment.
     * Anything thrown becomes one scrubbed line and exit 2.
     *
     * @param list<string> $arguments
     */
    public function execute(array $arguments, string $scoopApps, ?string $xampp): int
    {
        try {
            return $this->command($arguments, $scoopApps, $xampp);
        } catch (\Throwable $throwable) {
            $this->say('Stopped: ' . $this->oneLine($throwable->getMessage()) . "\n");

            return 2;
        }
    }

    /**
     * Put the original `vendor/` back after a run or a crash; true when there
     * is nothing left to restore. The caller holds the lock.
     */
    public function restore(): bool
    {
        $saved = $this->repo . '/.matrix/vendor-current';
        $savedKind = self::kind($saved);

        if ($savedKind === 'none') {
            return true;
        }

        if ($savedKind !== 'dir') {
            $this->say(".matrix/vendor-current is not a plain directory; nothing restored.\n");

            return false;
        }

        $vendor = $this->repo . '/vendor';
        $kind = self::kind($vendor);

        if ($kind === 'other') {
            $this->say('vendor/ is not a plain directory (a link, a junction or a file); nothing restored,'
                . " the original stays in .matrix/vendor-current.\n");

            return false;
        }

        if ($kind === 'dir') {
            $parked = $this->repo . '/.matrix/vendor-interrupted';
            self::removeTree($parked, $this->repo . '/.matrix');

            if (!@\rename($vendor, $parked)) {
                $this->say("Cannot move the interrupted vendor/ aside; nothing restored.\n");

                return false;
            }
        }

        if (!@\rename($saved, $vendor)) {
            $this->say("Cannot move .matrix/vendor-current back to vendor/.\n");

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $arguments
     */
    private function command(array $arguments, string $scoopApps, ?string $xampp): int
    {
        try {
            $options = self::parseArguments($arguments);
        } catch (\InvalidArgumentException $exception) {
            $this->say($exception->getMessage() . "\n" . self::usage());

            return 2;
        }

        if ($options['restore']) {
            if (!$this->layoutIsPlain() || !$this->lock()) {
                return 2;
            }

            try {
                return $this->restore() ? 0 : 2;
            } finally {
                $this->unlock();
            }
        }

        $requirement = $this->requirement();

        if ($requirement === null) {
            $this->say("composer.json has no readable require.php constraint.\n");

            return 2;
        }

        [$constraint, $floor] = $requirement;
        $discovered = self::discover($scoopApps, $xampp, $this->runner, $this->repo);

        if ($options['list']) {
            $this->say($this->listing($discovered, $constraint));

            return 0;
        }

        $selected = [];
        $unsupported = [];
        $missing = [];

        foreach ($options['only'] ?? self::VERSIONS as $minor) {
            $php = $discovered['found'][$minor] ?? null;

            if ($php !== null && self::allows($constraint, $php['version'])) {
                $selected[] = $minor;
            } elseif ($php !== null) {
                $unsupported[] = $minor . ' (' . $php['version'] . ')';
            } elseif (self::allows($constraint, $minor . '.0') || self::allows($constraint, $minor . '.999')) {
                $missing[] = $minor;
            }
        }

        if ($missing !== []) {
            $this->say('PHP ' . \implode(', ', $missing) . ' not found; install it with scoop'
                . " (see CONTRIBUTING.md).\n");

            return 2;
        }

        $skipped = $unsupported === []
            ? ''
            : 'unsupported (composer.json requires php ' . $constraint . '), skipped: '
                . \implode(', ', $unsupported) . "\n";
        $this->say($skipped);

        if ($selected === []) {
            $this->say('No supported version selected (composer.json requires php ' . $constraint . ").\n");

            return 2;
        }

        try {
            $kit = $options['noKit'] ? null : ($options['kit'] ?? $this->kitFromEnvironment());
        } catch (\InvalidArgumentException $exception) {
            $this->say($exception->getMessage() . "\n");

            return 2;
        }

        if (!$this->layoutIsPlain()) {
            return 2;
        }

        if (self::kind($this->repo . '/.matrix/vendor-current') !== 'none') {
            $this->say("A previous run did not finish: .matrix/vendor-current exists. Run with --restore first.\n");

            return 2;
        }

        if (!$this->lock()) {
            return 2;
        }

        $rows = [];
        $moved = false;
        $stopped = false;
        $restored = true;

        try {
            if (!$options['skipInstall']) {
                $this->moveVendorAside();
                $moved = true;
            }

            $withLowest = !$options['noLowest'] && !$options['skipInstall'];

            foreach (self::cells($selected, $floor, $withLowest) as $cell) {
                $php = $discovered['found'][$cell['minor']];
                $row = $this->cell($php, $cell['lowest'], $options['skipInstall'], $kit);
                $rows[] = $row;

                // Each cell's findings as soon as it ends: a run killed later keeps them.
                foreach ($row['findings'] as $finding) {
                    $this->say('   finding: ' . $finding . "\n");
                }
            }
        } catch (\Throwable $throwable) {
            $this->say('Stopped: ' . $this->oneLine($throwable->getMessage()) . "\n");
            $stopped = true;
        } finally {
            if ($moved) {
                $restored = $this->restore();
            }

            $this->unlock();
        }

        if (!$restored) {
            $this->say("vendor/ was not restored: fix the cause above, then run --restore.\n");
        }

        if ($stopped) {
            return 2;
        }

        $this->say("\n" . self::render($rows) . ($skipped === '' ? '' : "\n" . $skipped));

        return $restored ? self::exitCode($rows) : 2;
    }

    /**
     * The runner every real run uses: `proc_open` with an argument array (no
     * shell), stdout and stderr appended to one file under
     * `$tmpDir` (files, not pipes, so a child never blocks on a full pipe on
     * Windows), and a deadline after which the child is terminated.
     *
     * @return callable(list<string>, string, array<string, string>, int): RunResult
     */
    public static function processRunner(string $tmpDir): callable
    {
        return static function (array $argv, string $cwd, array $env, int $timeout) use ($tmpDir): array {
            if (!\is_dir($tmpDir) && !@\mkdir($tmpDir, 0o755, true)) {
                throw new \RuntimeException('Cannot create .matrix/tmp.');
            }

            $file = @\tempnam($tmpDir, 'run');

            if ($file === false) {
                throw new \RuntimeException('Cannot create an output file under .matrix/tmp.');
            }

            // One handle for both streams: two `file` descriptors on the same path each start at offset 0
            // on Windows and overwrite each other (the PHPUnit banner was lost under stderr notices).
            $spec = [0 => ['pipe', 'r'], 1 => ['file', $file, 'ab'], 2 => ['redirect', 1]];
            $pipes = [];
            $started = \microtime(true);

            try {
                // The array form: the arguments go to the OS as they are, no shell (T-03/T-08). PHPStan's 7.3
                // stubs only know the string form, as for src/Compiler/Cli/Process.php.
                // @phpstan-ignore-next-line
                $handle = @\proc_open($argv, $spec, $pipes, $cwd, $env);

                if (!\is_resource($handle)) {
                    throw new \RuntimeException('Cannot start ' . \basename($argv[0] ?? '?') . '.');
                }

                if (isset($pipes[0]) && \is_resource($pipes[0])) {
                    \fclose($pipes[0]);
                }

                $exit = -1;
                $timedOut = true;

                while (\microtime(true) - $started < $timeout) {
                    $status = \proc_get_status($handle);

                    if ($status['running'] !== true) {
                        $exit = (int) $status['exitcode'];
                        $timedOut = false;

                        break;
                    }

                    \usleep(20000);
                }

                if ($timedOut) {
                    @\proc_terminate($handle);
                }

                \proc_close($handle);
                $output = (string) @\file_get_contents($file, false, null, 0, 16777216);

                if ($timedOut) {
                    $output .= \sprintf("\n[stopped after %d seconds]\n", $timeout);
                    $exit = -2;
                }

                return ['exit' => $exit, 'output' => $output, 'seconds' => \microtime(true) - $started];
            } finally {
                @\unlink($file);
            }
        };
    }

    /**
     * One cell: a version with its highest or its lowest dependencies.
     *
     * @param Php $php
     *
     * @return Row
     */
    private function cell(array $php, bool $lowest, bool $skipInstall, ?string $kit): array
    {
        $minor = $php['minor'];
        $name = 'php' . \str_replace('.', '', $minor) . ($lowest ? '-lowest' : '');
        $dir = $this->repo . '/.matrix/' . $name;
        $stan = $minor === self::STAN_VERSION && !$lowest;
        $row = [
            'minor' => $minor,
            'version' => $php['version'],
            'resolved' => '-',
            'lowest' => $lowest,
            'lint' => '-',
            'tests' => self::testSummary('', 1, 0.0),
            'fixture' => '-',
            'kit' => $kit === null ? 'skipped' : '-',
            'stan' => $stan ? '-' : '',
            'pass' => true,
            'findings' => [],
        ];

        $this->say(\sprintf("== PHP %s%s (%s)\n", $php['version'], $lowest ? ' lowest' : '', $php['path']));
        self::ensureDirectory($dir);
        $this->write($dir . '/php.ini', self::ini($php['path']));
        $env = $this->childEnv($dir);
        $parked = $dir . '/vendor';

        try {
            if (!$skipInstall) {
                if (\is_dir($parked) && !@\rename($parked, $this->repo . '/vendor')) {
                    throw new \RuntimeException('Cannot move .matrix/' . $name . '/vendor into place.');
                }

                if (!$this->resolve($php, $dir, $env, $row)) {
                    $row['pass'] = false;

                    return $row;
                }
            }

            $this->lint($php, $env, $row);
            $this->tests($php, $env, $row);
            $row['fixture'] = $this->compare($php, $env, $this->repo . '/' . self::FIXTURE, 'fixture', $row);

            if ($kit !== null) {
                $row['kit'] = $this->compare($php, $env, $kit, 'kit', $row);
            }

            if ($stan) {
                $row['stan'] = $this->stan($php, $env, $row);
            }
        } finally {
            if (!$skipInstall && \is_dir($this->repo . '/vendor') && !\is_dir($parked)) {
                @\rename($this->repo . '/vendor', $parked);
            }
        }

        return $row;
    }

    /**
     * Write the manifest copy and resolve it, once, with Composer's advisory
     * filter on; lowest for a lowest cell.
     *
     * @param Php $php
     * @param array<string, string> $env
     * @param Row $row
     */
    private function resolve(array $php, string $dir, array $env, array &$row): bool
    {
        $manifest = \json_decode((string) @\file_get_contents($this->repo . '/composer.json'));

        if (!$manifest instanceof \stdClass) {
            throw new \RuntimeException('composer.json is not an object.');
        }

        $json = (string) \json_encode(self::rewriteManifest($manifest), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $this->write($dir . '/composer.json', $json . "\n");

        $argv = [
            $php['path'],
            self::COMPOSER,
            'update',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
            '--no-plugins',
            '--no-scripts',
        ];

        if ($row['lowest']) {
            $argv[] = '--prefer-lowest';
            $argv[] = '--prefer-stable';
        }

        $composerEnv = ['COMPOSER' => 'composer.json', 'COMPOSER_VENDOR_DIR' => $this->repo . '/vendor'];
        $result = $this->run($argv, $dir, $composerEnv + $env);
        $this->say(\sprintf(
            "   resolve%s: exit %d, %.0f s\n",
            $row['lowest'] ? ' (lowest)' : '',
            $result['exit'],
            $result['seconds']
        ));

        if ($result['exit'] !== 0) {
            $row['resolved'] = 'failed';
            $row['findings'][] = "composer update failed:\n" . $this->excerpt($result['output'], true);

            return false;
        }

        $row['resolved'] = 'ok';

        return true;
    }

    /**
     * @param Php $php
     * @param array<string, string> $env
     * @param Row $row
     */
    private function lint(array $php, array $env, array &$row): void
    {
        $files = $this->lintFiles();
        $errors = [];

        foreach ($files as $file) {
            $result = $this->run([$php['path'], '-l', $file], $this->repo, $env);

            if ($result['exit'] !== 0) {
                $errors[] = $this->scrub(\trim($result['output']));
            }
        }

        $row['lint'] = $errors === [] ? 'ok (' . \count($files) . ')' : \count($errors) . ' errors';

        if ($errors !== []) {
            $row['pass'] = false;
            $row['findings'][] = "php -l:\n" . \implode("\n", \array_slice($errors, 0, self::EXCERPT_LINES));
        }

        $this->say('   lint: ' . $row['lint'] . "\n");
    }

    /**
     * @param Php $php
     * @param array<string, string> $env
     * @param Row $row
     */
    private function tests(array $php, array $env, array &$row): void
    {
        $argv = [$php['path'], 'vendor/bin/phpunit', '--testsuite', self::SUITES];
        $result = $this->run($argv, $this->repo, $env, self::TEST_TIMEOUT);
        $row['tests'] = self::testSummary($result['output'], $result['exit'], $result['seconds']);
        $version = self::phpunitVersion($result['output']);

        if ($version !== '') {
            $row['resolved'] = $version;
        }

        if ($row['tests']['status'] !== 'ok') {
            $row['pass'] = false;
            $label = $row['tests']['summary'] !== '' ? $row['tests']['summary'] : 'exit ' . $result['exit'];
            $row['findings'][] = 'tests (' . $label . "):\n" . $this->excerpt($result['output'], false);
        }

        $shown = $row['tests']['summary'] !== '' ? $row['tests']['summary'] : $row['tests']['status'];
        $this->say(\sprintf("   tests: %s (%.0f s)\n", $shown, $result['seconds']));
    }

    /**
     * @param Php $php
     * @param array<string, string> $env
     * @param Row $row
     */
    private function compare(array $php, array $env, string $project, string $label, array &$row): string
    {
        $result = $this->run([$php['path'], 'tools/compare-drivers.php', $project], $this->repo, $env);
        $verdict = 'identical';

        if ($result['exit'] !== 0) {
            $verdict = $result['exit'] === 1 ? 'differs' : 'error ' . $result['exit'];
            $row['pass'] = false;
            $row['findings'][] = 'compare-drivers on the ' . $label . ' (' . $verdict . "):\n"
                . $this->excerpt($result['output'], true);
        }

        $this->say('   ' . $label . ': ' . $verdict . "\n");

        return $verdict;
    }

    /**
     * @param Php $php
     * @param array<string, string> $env
     * @param Row $row
     */
    private function stan(array $php, array $env, array &$row): string
    {
        $failed = [];

        foreach ([[], ['-c', 'phpstan.tests.neon.dist']] as $config) {
            $argv = \array_merge(
                [$php['path'], 'vendor/bin/phpstan', 'analyse', '--memory-limit=2G', '--no-progress'],
                $config
            );
            $result = $this->run($argv, $this->repo, $env);

            if ($result['exit'] !== 0) {
                $failed[] = ($config === [] ? 'src+tools' : 'tests') . ":\n" . $this->excerpt($result['output'], true);
            }
        }

        if ($failed !== []) {
            $row['pass'] = false;

            foreach ($failed as $finding) {
                $row['findings'][] = 'phpstan ' . $finding;
            }
        }

        $verdict = $failed === [] ? 'ok' : \count($failed) . ' failed';
        $this->say('   phpstan: ' . $verdict . "\n");

        return $verdict;
    }

    /**
     * Every PHP file under `src/` and `tools/` (not the fixer's own install or
     * the binary cache) and every file under `bin/`, repository-relative.
     *
     * @return list<string>
     */
    private function lintFiles(): array
    {
        $files = [];

        foreach (['src', 'tools', 'bin'] as $root) {
            $dir = $this->repo . '/' . $root;

            if (!\is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }

                $relative = \substr(\str_replace('\\', '/', $file->getPathname()), \strlen($this->repo) + 1);

                $excluded = \strncmp($relative, 'tools/php-cs-fixer/', 19) === 0
                    || \strncmp($relative, 'tools/.cache/', 13) === 0;

                if ($excluded) {
                    continue;
                }

                if ($root === 'bin' || \substr($relative, -4) === '.php') {
                    $files[] = $relative;
                }
            }
        }

        \sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(string $dir): array
    {
        $env = [];

        foreach ($this->baseEnv as $name => $value) {
            if (!\in_array(\strtoupper((string) $name), self::OWNED_VARIABLES, true)) {
                $env[(string) $name] = $value;
            }
        }

        $env['PHPRC'] = $dir;
        $env['PHP_INI_SCAN_DIR'] = '';
        $env['COMPOSER_NO_INTERACTION'] = '1';

        return $env;
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     *
     * @return RunResult
     */
    private function run(array $argv, string $cwd, array $env, int $timeout = self::TIMEOUT): array
    {
        return ($this->runner)($argv, $cwd, $env, $timeout);
    }

    /**
     * Take `.matrix/lock` (created exclusively, the pid inside); false, with the
     * holder named, when another run or a crash left it.
     */
    private function lock(): bool
    {
        self::ensureDirectory($this->repo . '/.matrix');
        $path = $this->repo . '/.matrix/lock';
        $handle = @\fopen($path, 'x');

        if ($handle === false) {
            $content = (string) @\file_get_contents($path, false, null, 0, 32);
            $pid = \substr(\trim($content), 0, \strspn(\trim($content), '0123456789'));
            $this->say(\sprintf(
                "Another matrix run holds .matrix/lock (pid %s). If none is running, delete .matrix/lock,\n"
                    . "then run php tools/php-matrix.php --restore.\n",
                $pid === '' ? 'unknown' : $pid
            ));

            return false;
        }

        \fwrite($handle, \sprintf("%d\n", (int) \getmypid()));
        \fclose($handle);

        return true;
    }

    private function unlock(): void
    {
        @\unlink($this->repo . '/.matrix/lock');
    }

    /**
     * True when `.matrix/` and `vendor/` are each a plain directory or absent;
     * otherwise says which is not.
     */
    private function layoutIsPlain(): bool
    {
        foreach (['.matrix/', 'vendor/'] as $name) {
            if (self::kind($this->repo . '/' . \rtrim($name, '/')) === 'other') {
                $this->say($name . " is not a plain directory (a link, a junction or a file); nothing was moved.\n");

                return false;
            }
        }

        return true;
    }

    private function moveVendorAside(): void
    {
        self::ensureDirectory($this->repo . '/.matrix');
        $vendor = $this->repo . '/vendor';

        if (self::kind($vendor) === 'dir' && !@\rename($vendor, $this->repo . '/.matrix/vendor-current')) {
            throw new \RuntimeException(
                'Cannot move vendor/ to .matrix/vendor-current (a process may hold a file in it).'
            );
        }
    }

    /**
     * @param array{found: array<string, Php>, xampp: Php|null, refused: list<string>} $discovered
     */
    private function listing(array $discovered, string $constraint): string
    {
        $text = '';

        foreach (self::VERSIONS as $minor) {
            $php = $discovered['found'][$minor] ?? null;

            if ($php === null) {
                $text .= \sprintf("%-4s missing\n", $minor);

                continue;
            }

            $note = self::allows($constraint, $php['version']) ? '' : ' (unsupported, skipped)';
            $text .= \sprintf("%-4s %-8s %-6s %s%s\n", $minor, $php['version'], $php['source'], $php['path'], $note);
        }

        $xampp = $discovered['xampp'];

        if ($xampp !== null) {
            $used = ($discovered['found'][$xampp['minor']]['source'] ?? '') === 'xampp';
            $state = $used ? 'used' : 'skipped: scoop has ' . $xampp['minor'];
            $text .= \sprintf("xampp %s %s (%s)\n", $xampp['version'], $xampp['path'], $state);
        }

        foreach ($discovered['refused'] as $refused) {
            $text .= 'refused: ' . $refused . "\n";
        }

        return $text;
    }

    /**
     * The root manifest's `require.php` and its floor, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function requirement(): ?array
    {
        $manifest = \json_decode((string) @\file_get_contents($this->repo . '/composer.json'));

        if (!$manifest instanceof \stdClass || !isset($manifest->require) || !$manifest->require instanceof \stdClass) {
            return null;
        }

        $constraint = $manifest->require->php ?? null;

        if (!\is_string($constraint)) {
            return null;
        }

        $floor = self::floorOf($constraint);

        return $floor === null ? null : [$constraint, $floor];
    }

    /**
     * `8.2 (8.2.35)`, or `8.2 (8.2.35) lowest`.
     *
     * @param Row $row
     */
    private static function label(array $row): string
    {
        return $row['minor'] . ' (' . $row['version'] . ')' . ($row['lowest'] ? ' lowest' : '');
    }

    /**
     * @throws \InvalidArgumentException When the variable names no usable directory.
     */
    private function kitFromEnvironment(): ?string
    {
        $kit = '';

        foreach ($this->baseEnv as $name => $value) {
            if (\strtoupper((string) $name) === self::KIT_ENV) {
                $kit = $value;
            }
        }

        if ($kit === '') {
            return null;
        }

        try {
            return self::directory($kit);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(
                self::KIT_ENV . ' must name an existing directory, without ".." segments.'
            );
        }
    }

    /**
     * The last lines of a step's output (the first failure's lines for a test
     * run), with the repository and home paths shortened.
     */
    private function excerpt(string $output, bool $tail): string
    {
        $lines = \explode("\n", \str_replace("\r\n", "\n", self::withoutAnsi($output)));

        if (!$tail) {
            foreach ($lines as $index => $line) {
                if (\strncmp($line, 'There w', 7) === 0 && \strpos($line, ':') !== false) {
                    return $this->scrub(\implode("\n", \array_slice($lines, $index, self::EXCERPT_LINES)));
                }
            }
        }

        $lines = \array_values(\array_filter($lines, static function (string $line): bool {
            return \trim($line) !== '';
        }));

        return $this->scrub(\implode("\n", \array_slice($lines, -self::EXCERPT_LINES)));
    }

    /**
     * The repository root as `.` and the home directory as `~` (T-06).
     */
    private function scrub(string $text): string
    {
        $text = \str_ireplace(self::forms($this->repo), '.', $text);
        $home = '';

        foreach ($this->baseEnv as $name => $value) {
            if (\strtoupper((string) $name) === 'USERPROFILE') {
                $home = $value;
            }
        }

        if ($home !== '') {
            $text = \str_ireplace(self::forms($home), '~', $text);
        }

        return $text;
    }

    /**
     * A path as Windows, PHP and Git Bash print it: `D:\a`, `D:/a`, `/d/a`.
     *
     * @return list<string>
     */
    private static function forms(string $path): array
    {
        $forward = \rtrim(\str_replace('\\', '/', $path), '/');
        $forms = [$forward, \str_replace('/', '\\', $forward)];

        if (\strlen($forward) > 2 && $forward[1] === ':') {
            $forms[] = '/' . \strtolower($forward[0]) . \substr($forward, 2);
        }

        return $forms;
    }

    /**
     * A thrown message as one scrubbed line.
     */
    private function oneLine(string $message): string
    {
        $lines = \explode("\n", \str_replace("\r", "\n", $message));

        return \substr($this->scrub(\trim($lines[0])), 0, 300);
    }

    private function say(string $text): void
    {
        ($this->out)($text);
    }

    private function write(string $path, string $contents): void
    {
        if (@\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Cannot write ' . \basename($path) . ' under .matrix.');
        }
    }

    /**
     * The parts of one `||` alternative (`>= 8.2` joined to `>=8.2`, commas as spaces).
     *
     * @return list<string>
     */
    private static function tokens(string $alternative): array
    {
        $operators = [
            '>= ' => '>=',
            '<= ' => '<=',
            '!= ' => '!=',
            '> ' => '>',
            '< ' => '<',
            '^ ' => '^',
            '~ ' => '~',
            '= ' => '=',
        ];
        $alternative = \strtr(\str_replace(',', ' ', \trim($alternative)), $operators);

        return \array_values(\array_filter(\explode(' ', $alternative), static function (string $token): bool {
            return $token !== '';
        }));
    }

    /**
     * Whether one part allows a version; null when the part is unreadable.
     */
    private static function tokenAllows(string $token, string $version): ?bool
    {
        if ($token === '*') {
            return true;
        }

        $operator = '';

        foreach (['>=', '<=', '!=', '>', '<', '=', '^', '~'] as $candidate) {
            if (\strncmp($token, $candidate, \strlen($candidate)) === 0) {
                $operator = $candidate;

                break;
            }
        }

        $bound = self::bound((string) \substr($token, \strlen($operator)));

        if ($bound === null) {
            return null;
        }

        $given = $bound['parts'];
        $low = \implode('.', self::padded($given));

        if ($bound['wildcard']) {
            if ($operator !== '' && $operator !== '=') {
                return null;
            }

            return \version_compare($version, $low, '>=')
                && \version_compare($version, self::bumped($given, \count($given) - 1), '<');
        }

        switch ($operator) {
            case '>=':
                return \version_compare($version, $low, '>=');
            case '>':
                return \version_compare($version, $low, '>');
            case '<=':
                return \version_compare($version, $low, '<=');
            case '<':
                return \version_compare($version, $low, '<');
            case '!=':
                return \version_compare($version, $low, '!=');
            case '^':
                $index = \count($given) - 1;

                foreach ($given as $position => $part) {
                    if ($part !== 0) {
                        $index = $position;

                        break;
                    }
                }

                return \version_compare($version, $low, '>=')
                    && \version_compare($version, self::bumped($given, $index), '<');
            case '~':
                $index = \count($given) === 1 ? 0 : \count($given) - 2;

                return \version_compare($version, $low, '>=')
                    && \version_compare($version, self::bumped($given, $index), '<');
            default:
                return \version_compare($version, $low, '==');
        }
    }

    /**
     * `8.2.35` → parts [8, 2, 35]; `8.2.*` → [8, 2] with a wildcard; `v8.2` is
     * read as `8.2`; null for anything else (`dev-main`, `8.2@dev`, `8..2`).
     *
     * @return array{parts: list<int>, wildcard: bool}|null
     */
    private static function bound(string $text): ?array
    {
        $text = \ltrim($text, 'v');
        $digits = \strspn($text, '0123456789.');
        $head = (string) \substr($text, 0, $digits);
        $tail = (string) \substr($text, $digits);
        $wildcard = $tail === '*' || $tail === '.*';

        if (!$wildcard && $tail !== '') {
            return null;
        }

        $head = \rtrim($head, '.');

        if ($head === '') {
            return null;
        }

        $parts = [];

        foreach (\array_slice(\explode('.', $head), 0, 3) as $part) {
            if ($part === '') {
                return null;
            }

            $parts[] = (int) $part;
        }

        return ['parts' => $parts, 'wildcard' => $wildcard];
    }

    /**
     * @param list<int> $parts
     *
     * @return list<int>
     */
    private static function padded(array $parts): array
    {
        return \array_slice(\array_merge($parts, [0, 0, 0]), 0, 3);
    }

    /**
     * The parts up to $index with that one raised by one, the rest zero: the
     * first version past a `^`, `~` or wildcard range (or past a strict bound).
     *
     * @param list<int> $parts
     */
    private static function bumped(array $parts, int $index): string
    {
        $out = [];

        foreach (self::padded($parts) as $position => $part) {
            $out[] = $position < $index ? $part : ($position === $index ? $part + 1 : 0);
        }

        return \implode('.', $out);
    }

    /**
     * 'none'; 'dir' for a plain directory; 'other' for a file, a link or a
     * junction (a junction stats as neither a directory nor a link: `filetype`
     * says "unknown").
     */
    private static function kind(string $path): string
    {
        if (\is_link($path)) {
            return 'other';
        }

        $type = @\filetype($path);

        if ($type === false) {
            return 'none';
        }

        return $type === 'dir' ? 'dir' : 'other';
    }

    /**
     * @param callable(list<string>, string, array<string, string>, int): RunResult $runner
     */
    private static function versionOf(string $path, callable $runner, string $cwd): ?string
    {
        $result = $runner([$path, '-n', '-r', 'echo PHP_VERSION;'], $cwd, [], 30);
        $version = \trim($result['output']);

        $plain = $version !== '' && \strspn($version, '0123456789.-RCalphbetdv') === \strlen($version);

        return $result['exit'] === 0 && $plain ? $version : null;
    }

    private static function minorOf(string $version): string
    {
        $parts = \explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /**
     * @param string|list<string> $paths
     *
     * @return string|list<string>
     */
    private static function prefixed($paths)
    {
        if (\is_string($paths)) {
            return '../../' . $paths;
        }

        $out = [];

        foreach ($paths as $path) {
            $out[] = '../../' . $path;
        }

        return $out;
    }

    /**
     * `7.3,8.5` → `['7.3', '8.5']`, each a covered minor.
     *
     * @return list<string>
     */
    private static function minors(string $value): array
    {
        $minors = [];

        foreach (\explode(',', $value) as $minor) {
            if (!\in_array($minor, self::VERSIONS, true)) {
                throw new \InvalidArgumentException(
                    \sprintf('--only takes minors from %s.', \implode(', ', self::VERSIONS))
                );
            }

            if (!\in_array($minor, $minors, true)) {
                $minors[] = $minor;
            }
        }

        return $minors;
    }

    /**
     * An existing directory with no traversal segment.
     */
    private static function directory(string $value): string
    {
        $normal = \str_replace('\\', '/', $value);

        $unsafe = $value === '' || \in_array('..', \explode('/', $normal), true) || \strpos($value, '://') !== false;

        if ($unsafe || !\is_dir($value)) {
            throw new \InvalidArgumentException('--kit takes an existing directory, without ".." segments.');
        }

        return \rtrim($normal, '/');
    }

    private static function assertClean(string $argument): void
    {
        for ($i = 0, $length = \strlen($argument); $i < $length; ++$i) {
            $byte = \ord($argument[$i]);

            if ($byte < 0x20 || $byte === 0x7F) {
                throw new \InvalidArgumentException('Arguments may not contain control bytes.');
            }
        }
    }

    private static function withoutAnsi(string $text): string
    {
        $out = '';
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            if ($text[$i] === "\x1b" && $i + 1 < $length && $text[$i + 1] === '[') {
                $i += 2;

                while ($i < $length && \strpos('0123456789;', $text[$i]) !== false) {
                    ++$i;
                }

                continue;
            }

            $out .= $text[$i];
        }

        return $out;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!\is_dir($dir) && !@\mkdir($dir, 0o755, true)) {
            throw new \RuntimeException('Cannot create ' . \basename($dir) . ' under .matrix.');
        }
    }

    /**
     * Remove $dir and what it holds, only inside $within. A link, a junction or
     * a file — at the root or below — is removed as itself, never followed and
     * never emptied.
     */
    private static function removeTree(string $dir, string $within): void
    {
        $dir = \rtrim(\str_replace('\\', '/', $dir), '/');
        $prefix = \rtrim(\str_replace('\\', '/', $within), '/') . '/';

        if (\strncmp($dir, $prefix, \strlen($prefix)) !== 0) {
            return;
        }

        $kind = self::kind($dir);

        if ($kind === 'none') {
            return;
        }

        if ($kind === 'other') {
            self::removeEntry($dir);

            return;
        }

        $names = @\scandir($dir);

        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (self::kind($path) === 'dir') {
                self::removeTree($path, $within);
            } else {
                self::removeEntry($path);
            }
        }

        @\rmdir($dir);
    }

    /**
     * A file or a link goes with `unlink`; a directory link or a junction on
     * Windows with `rmdir`, which removes the link and leaves its target.
     */
    private static function removeEntry(string $path): void
    {
        if (!@\unlink($path)) {
            @\rmdir($path);
        }
    }
}
