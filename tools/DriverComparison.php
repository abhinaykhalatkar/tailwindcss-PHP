<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolver;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Config\BundleConfig;
use TailwindPhp\Config\Config;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Config\ConfigFile;
use TailwindPhp\Engine\Import\FilesystemImportResolver;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Pipeline\DriverResolverInput;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\CandidateSet;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CssComparator;

/**
 * `tools/compare-drivers.php` (TASK-0054, the last M2 acceptance bullet): one project compiled once with the
 * PHP engine and once with the official CLI, the two stylesheets compared with the conformance comparator, the CLI
 * as the reference (it plays the part of the golden), the engine as the actual.
 *
 * Both drivers get exactly the same input: the entry stylesheet read through the project's configuration, the same
 * candidate list found by the library's own scanner (without the optional pre-passes: this compares the drivers,
 * not the scanner) and imports resolved by the library's confined resolver. The CLI driver is the library's own
 * `StandaloneCliCompiler` (an argument array, no shell), chosen by the library's own `DriverResolver` with
 * `driver: cli`, so the checksum of the binary is verified before it runs (T-04).
 *
 * Reading and writing (T-01): the project root comes from the command line and must be an existing directory;
 * `--entry` is a relative path that must name the input of a configured bundle, so nothing outside the
 * configuration is read; every project file goes through the library's resolver and scanner. Nothing is written
 * into the project: the CLI driver's scratch directory lives in a private directory of the system temporary
 * directory, removed on the way out. The output carries candidates and declarations, never a path (T-06); the
 * project root as typed is not echoed at all.
 *
 * Exit codes: 0 identical, 1 any difference or a driver that reported errors, 2 usage or environment (a missing
 * binary included).
 */
final class DriverComparison
{
    public const EXIT_IDENTICAL = 0;

    public const EXIT_DIFFERENT = 1;

    public const EXIT_USAGE = 2;

    /**
     * Failures printed before the list is cut.
     */
    private const MAX_FAILURES = 50;

    /**
     * How much of a value is quoted back (T-06).
     */
    private const MAX_ECHO = 64;

    private const USAGE = 'Usage: php tools/compare-drivers.php <project-root>'
        . " [--entry=<relative css file>] [--minify]\n";

    private function __construct()
    {
    }

    // ----------------------------------------------------------- arguments

    public static function usage(): string
    {
        return self::USAGE;
    }

    /**
     * @param list<string> $arguments The command line after the script name.
     *
     * @return array{root: string, entry: string|null, minify: bool} The root as an absolute, real path.
     *
     * @throws \InvalidArgumentException With a plain message, quoting at most 64 bytes of what was typed.
     */
    public static function parseArguments(array $arguments): array
    {
        $root = null;
        $entry = null;
        $minify = false;

        foreach ($arguments as $argument) {
            $argument = (string) $argument;

            if ($argument === '--minify') {
                $minify = true;

                continue;
            }

            if (\strncmp($argument, '--entry=', 8) === 0) {
                $entry = self::entry((string) \substr($argument, 8));

                continue;
            }

            if (\strncmp($argument, '--', 2) === 0) {
                throw new \InvalidArgumentException(\sprintf('Unknown option "%s".', self::echo($argument)));
            }

            if ($root !== null) {
                throw new \InvalidArgumentException('Only one project root may be given.');
            }

            $root = $argument;
        }

        if ($root === null) {
            throw new \InvalidArgumentException('The project root is missing.');
        }

        if (!\is_dir($root)) {
            throw new \InvalidArgumentException(\sprintf(
                \is_file($root) ? '"%s" is a file, not a directory.' : 'No such directory: "%s".',
                self::echo($root)
            ));
        }

        $real = \realpath($root);

        if ($real === false) {
            throw new \InvalidArgumentException(\sprintf('Cannot resolve "%s".', self::echo($root)));
        }

        return ['root' => \rtrim(\str_replace('\\', '/', $real), '/'), 'entry' => $entry, 'minify' => $minify];
    }

    /**
     * A relative path with forward slashes, no `..`, no drive letter, no control byte.
     *
     * @throws \InvalidArgumentException
     */
    private static function entry(string $value): string
    {
        $value = \str_replace('\\', '/', $value);

        if ($value === '') {
            throw new \InvalidArgumentException('The entry is empty.');
        }

        $bad = \strpbrk($value, "\0\r\n\t") !== false
            || $value[0] === '/'
            || (\strlen($value) >= 2 && $value[1] === ':' && \ctype_alpha($value[0]));

        if (!$bad) {
            foreach (\explode('/', $value) as $segment) {
                if ($segment === '..') {
                    $bad = true;

                    break;
                }
            }
        }

        if ($bad) {
            throw new \InvalidArgumentException(\sprintf(
                'The entry "%s" must be a relative path inside the project.',
                self::echo($value)
            ));
        }

        return $value;
    }

    private static function echo(string $text): string
    {
        $text = \str_replace(["\0", "\r", "\n", "\t"], ' ', $text);

        return \strlen($text) > self::MAX_ECHO ? \substr($text, 0, self::MAX_ECHO) . '...' : $text;
    }

    // ---------------------------------------------------------- comparison

    /**
     * The CLI's stylesheet is the reference (expected), the engine's the actual.
     *
     * @param list<string> $candidates
     */
    public static function compare(string $phpCss, string $cliCss, array $candidates): ComparisonResult
    {
        return (new CssComparator())->compare($cliCss, $phpCss, $candidates);
    }

    public static function exitCode(ComparisonResult $result): int
    {
        return $result->isIdentical() ? self::EXIT_IDENTICAL : self::EXIT_DIFFERENT;
    }

    /**
     * The summary and every failure with its class, the candidate and the first differing declaration.
     */
    public static function render(ComparisonResult $result, string $php, string $cli, int $candidates): string
    {
        $lines = [
            \sprintf(
                'Driver comparison: %s against %s, %d candidate%s',
                $php,
                $cli,
                $candidates,
                $candidates === 1 ? '' : 's'
            ),
            $result->summary(),
        ];

        foreach ($result->diagnostics() as $diagnostic) {
            $lines[] = ComparisonResult::ERROR_PARSE . ' ' . self::echoLong($diagnostic);
        }

        $shown = 0;
        $failures = $result->failures();

        foreach ($failures as $failure) {
            if (++$shown > self::MAX_FAILURES) {
                $lines[] = \sprintf('... and %d more', \count($failures) - self::MAX_FAILURES);

                break;
            }

            $lines[] = $failure['status'] . ' ' . self::echo($failure['candidate']);
            $lines[] = '  cli  ' . self::echoLong($failure['expected']);
            $lines[] = '  php  ' . self::echoLong($failure['actual']);
        }

        if ($result->isIdentical()) {
            $lines[] = 'Identical.';
        }

        return \implode("\n", $lines) . "\n";
    }

    private static function echoLong(string $text): string
    {
        $text = \str_replace(["\0", "\r", "\n"], ' ', $text);

        return \strlen($text) > 300 ? \substr($text, 0, 300) . '...' : $text;
    }

    // ----------------------------------------------------------- execution

    /**
     * Run the whole comparison. The two compilers are injectable so a test needs no binary; by default the PHP
     * engine and the library's resolved CLI driver are used.
     *
     * @param list<string> $arguments
     * @param callable(string): void $out Standard output.
     * @param callable(string): void $err Standard error.
     */
    public static function execute(
        array $arguments,
        callable $out,
        callable $err,
        ?CompilerInterface $php = null,
        ?CompilerInterface $cli = null
    ): int {
        try {
            $options = self::parseArguments($arguments);
        } catch (\InvalidArgumentException $e) {
            $err($e->getMessage() . "\n" . self::USAGE);

            return self::EXIT_USAGE;
        }

        $scratch = self::scratchDirectory();

        if ($scratch === null) {
            $err("Cannot create a scratch directory in the system temporary directory.\n");

            return self::EXIT_USAGE;
        }

        try {
            return self::run($options, $scratch, $out, $err, $php, $cli);
        } finally {
            self::removeDirectory($scratch);
        }
    }

    /**
     * @param array{root: string, entry: string|null, minify: bool} $options
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    private static function run(
        array $options,
        string $scratch,
        callable $out,
        callable $err,
        ?CompilerInterface $php,
        ?CompilerInterface $cli
    ): int {
        try {
            $config = self::config($options['root'], $scratch);
        } catch (ConfigurationException $e) {
            $err('The project configuration is not valid: ' . self::echoLong($e->getMessage()) . "\n");

            return self::EXIT_USAGE;
        } catch (\Throwable $e) {
            $err('The project configuration could not be loaded: ' . self::echo(\get_class($e)) . "\n");

            return self::EXIT_USAGE;
        }

        $bundle = self::bundle($config, $options['entry']);

        if ($bundle === null) {
            $known = [];

            foreach ($config->bundles() as $candidate) {
                $known[] = $candidate->inputRel();
            }

            $err(\sprintf(
                "No configured bundle has that entry. The entries are: %s\n",
                \implode(', ', $known)
            ));

            return self::EXIT_USAGE;
        }

        try {
            $cli = $cli ?? self::cliCompiler($config, $scratch);
        } catch (\RuntimeException $e) {
            $err($e->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        $php = $php ?? new PhpEngineCompiler();
        $limits = $config->limits();
        $fs = new Filesystem();

        $entryText = $fs->read($bundle->input(), (int) $limits['max_total_css'] + 1);

        if (\strlen($entryText) > (int) $limits['max_total_css']) {
            $err("The entry stylesheet exceeds limits.max_total_css.\n");

            return self::EXIT_USAGE;
        }

        $candidates = self::candidates($config, $bundle, $fs);
        $allowed = \array_values(\array_diff($config->allowedRoots(), [$scratch, self::pinnedDirectory()]));
        $imports = new FilesystemImportResolver($config->projectRoot(), $allowed, $fs, (int) $limits['max_file_size']);
        // Plain output unless `--minify` is given: the CLI's minifier rewrites values (`.25rem`, `min-width`) in ways
        // the comparator does not canonicalise, so the bundle's own `minify` setting is not followed here.
        $compile = new CompileOptions($options['minify'], false, false, $bundle->inputRel());

        $phpResult = $php->compile($entryText, $imports, $candidates, $compile);
        $cliResult = $cli->compile($entryText, $imports, $candidates, $compile);

        $failed = false;

        foreach ([$phpResult, $cliResult] as $result) {
            if ($result->hasErrors()) {
                $failed = true;
                $err(self::errors($result));
            }
        }

        if ($failed) {
            return self::EXIT_DIFFERENT;
        }

        $result = self::compare($phpResult->css(), $cliResult->css(), $candidates);
        $out(self::render(
            $result,
            $phpResult->driver() . ' (' . $phpResult->driverVersion() . ')',
            // The verifier never spawns the binary, so the version is empty until a build has recorded it.
            $cliResult->driver() . ($cliResult->driverVersion() === '' ? '' : ' ' . $cliResult->driverVersion()),
            \count($candidates)
        ));

        return self::exitCode($result);
    }

    private static function errors(CompileResult $result): string
    {
        $text = $result->driver() . " reported errors:\n";

        foreach ($result->diagnostics()->errors() as $diagnostic) {
            $text .= '  ' . $diagnostic->code() . ' ' . self::echoLong($diagnostic->message()) . "\n";
        }

        return $text;
    }

    /**
     * The project's configuration through the library's own loading, with the driver forced to `cli` (it is what
     * resolves the binary) and the cache directory moved into the scratch directory (the project is never written).
     * $binary names the repository's pinned binary when the project has none of its own.
     *
     * @throws ConfigurationException
     */
    private static function config(string $root, string $scratch, ?string $binary = null): Config
    {
        $values = [];

        foreach (['tailwind.php', 'config/tailwind.php'] as $candidate) {
            if (\is_file($root . '/' . $candidate)) {
                $values = ConfigFile::load($root . '/' . $candidate, $root, $root . '/cache/tailwind');

                break;
            }
        }

        $allowed = isset($values['allowed_roots']) && \is_array($values['allowed_roots'])
            ? $values['allowed_roots']
            : [];

        $values['project_root'] = $root;
        $values['driver'] = 'cli';
        $values['cache_dir'] = $scratch;
        $allowed[] = $scratch;

        if ($binary !== null) {
            // The repository's own binary: that one file named, its directory added to the roots the library may
            // read from (the import resolver never gets that directory, see run()).
            $cli = isset($values['cli']) && \is_array($values['cli']) ? $values['cli'] : [];
            $cli['binary'] = $binary;
            $values['cli'] = $cli;
            $allowed[] = self::pinnedDirectory();
        }

        $values['allowed_roots'] = $allowed;

        return ConfigBuilder::fromArray($values);
    }

    /**
     * The bundle whose input is $entry, or the first bundle when none is asked for.
     */
    private static function bundle(Config $config, ?string $entry): ?BundleConfig
    {
        $bundles = $config->bundles();

        if ($entry === null) {
            return $bundles === [] ? null : \reset($bundles);
        }

        foreach ($bundles as $bundle) {
            if ($bundle->inputRel() === $entry) {
                return $bundle;
            }
        }

        return null;
    }

    /**
     * The directory of the repository's pinned binary (`tools/.cache`), as a normalised absolute path.
     */
    private static function pinnedDirectory(): string
    {
        return \rtrim(\str_replace('\\', '/', \dirname(__DIR__)), '/') . '/tools/.cache';
    }

    /**
     * The library's own CLI driver for $config, through the library's own resolver: the binary the project has
     * (`cli.binary`, or the one `install-cli` put under `vendor/bin`) and, failing that, the repository's pinned
     * one under `tools/.cache`. Either way the library verifies its checksum before running it (T-04).
     *
     * @throws \RuntimeException When no binary is usable or it does not match a known release.
     */
    private static function cliCompiler(Config $config, string $scratch): CompilerInterface
    {
        try {
            return self::resolveCli($config);
        } catch (\RuntimeException $e) {
            $name = \DIRECTORY_SEPARATOR === '\\' ? 'tailwindcss.exe' : 'tailwindcss';
            $pinned = self::pinnedDirectory() . '/' . $name;

            if (!\is_file($pinned)) {
                throw $e;
            }
        }

        return self::resolveCli(self::config($config->projectRoot(), $scratch, $pinned));
    }

    /**
     * @throws \RuntimeException
     */
    private static function resolveCli(Config $config): CompilerInterface
    {
        $fs = new Filesystem();
        $root = $config->projectRoot();
        $cacheDir = $config->cacheDir();
        $timeout = (float) $config->cli()['timeout'];

        $resolver = new DriverResolver(
            static function (): Environment {
                return new Environment();
            },
            static function () use ($fs, $root): BinaryVerifier {
                return new BinaryVerifier(Checksums::load(), $fs, $root);
            },
            new PhpEngineCompiler(),
            static function (
                string $binaryPath,
                string $version
            ) use (
                $fs,
                $root,
                $cacheDir,
                $timeout
            ): CompilerInterface {
                return new StandaloneCliCompiler(
                    $binaryPath,
                    $version,
                    $cacheDir,
                    $timeout,
                    new Process(),
                    $fs,
                    $root,
                    new AtomicWriter($root),
                    new Environment()
                );
            }
        );

        try {
            $resolution = $resolver->resolve(DriverResolverInput::from($config), null);
        } catch (DriverUnavailableException $e) {
            throw new \RuntimeException(
                'The official CLI is not available (' . self::echoLong($e->getMessage())
                . '). Install it with `vendor/bin/tailwind-php install-cli`.',
                0,
                $e
            );
        }

        $compiler = $resolution->compiler();

        if ($compiler->name() !== 'cli') {
            throw new \RuntimeException(
                'The official CLI is not available. Install it with `vendor/bin/tailwind-php install-cli`.'
            );
        }

        return $compiler;
    }

    /**
     * The candidate union of the bundle, as the pipeline computes it (safelist and blocklist applied, the
     * candidate cap respected), without the optional pre-passes.
     *
     * @return list<string>
     */
    private static function candidates(Config $config, BundleConfig $bundle, Filesystem $fs): array
    {
        $limits = $config->limits();
        $roots = [];

        foreach ($bundle->inputRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], Root::INPUT, $root['extensions']);
        }

        foreach ($bundle->sourceRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], Root::SOURCE, $root['extensions']);
        }

        $finder = new FileFinder($config->cacheDir(), $bundle->ignore(), (int) $limits['max_files']);
        $snapshot = $finder->snapshot($roots);
        $scan = new ScanCache(
            $fs,
            new CandidateExtractor(),
            static function (string $rel) {
                return null;
            },
            [],
            (int) $limits['max_file_size'],
            $config->projectRoot()
        );

        $perFile = [];

        foreach ($snapshot->files() as $rel => $entry) {
            if ($entry['k'] === 's') {
                $perFile[(string) $rel] = $scan->extract((string) $rel, $entry['k'], $entry['m'], $entry['s']);
            }
        }

        $max = (int) $limits['max_candidates'];
        $set = new CandidateSet($perFile, $bundle->safelist(), $bundle->blocklist(), [], $max);

        return $set->all();
    }

    // -------------------------------------------------------- scratch space

    /**
     * A private (0700) directory in the system temporary directory, or null.
     */
    private static function scratchDirectory(): ?string
    {
        $base = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/');

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $path = $base . '/tailwind-php-compare-' . \bin2hex(\random_bytes(8));

            if (@\mkdir($path, 0700)) {
                $real = \realpath($path);

                return $real === false ? $path : \rtrim(\str_replace('\\', '/', $real), '/');
            }
        }

        return null;
    }

    /**
     * Remove the scratch directory and what the CLI driver may have left in it. Only entries of the directory
     * itself are followed; a link is removed, never entered.
     */
    private static function removeDirectory(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }

        $entries = \scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (\is_dir($child) && !\is_link($child)) {
                self::removeDirectory($child);
            } else {
                @\unlink($child);
            }
        }

        @\rmdir($path);
    }
}
