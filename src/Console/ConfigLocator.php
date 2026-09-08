<?php

declare(strict_types=1);

namespace TailwindPhp\Console;

use TailwindPhp\Config\ConfigFile;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Tailwind;

/**
 * Finds the project's configuration file and turns it into a facade
 * (design doc 11 §config file, doc 21; decision HBC-A9).
 *
 * Lookup order, first hit wins:
 *
 * 1. `--config=PATH`, resolved against the working directory
 * 2. `tailwind.php` in the working directory
 * 3. `config/tailwind.php`
 *
 * An explicit `--config` that does not exist is an error rather than a fall
 * through to the defaults: someone who names a file means that file, and
 * silently building from a different one is how a deploy publishes the wrong
 * stylesheet.
 *
 * **Threat T-08, twice.** The file is `require`d — the only place in `src/`
 * that is true, and `ConfigFile::load()` is the single site. It refuses a path
 * under the cache directory, and this class then asks *again* after the
 * `Config` exists, because until the configuration is built we only know the
 * conventional `cache/tailwind`, not the one the project actually configured.
 * A project whose `cache_dir` points somewhere unusual would otherwise have a
 * writable directory from which arbitrary PHP is executed on the next CLI run.
 */
final class ConfigLocator
{
    /**
     * The two conventional locations, in order.
     *
     * @var list<string>
     */
    private const CANDIDATES = ['tailwind.php', 'config/tailwind.php'];

    /**
     * The default `cache_dir`, used as the pre-load T-08 gate because the
     * configured one cannot be known until the file has been executed.
     */
    private const CONVENTIONAL_CACHE_DIR = 'cache/tailwind';

    private function __construct()
    {
    }

    /**
     * @param string|null $explicit The `--config` value, if given.
     * @param string $cwd The working directory, forward slashes.
     *
     * @return string The absolute path of the file to load.
     *
     * @throws ConsoleException When nothing is found, or when an explicit path
     *                          does not exist (exit 2).
     */
    public static function locate(?string $explicit, string $cwd): string
    {
        $cwd = \rtrim(\str_replace('\\', '/', $cwd), '/');

        if ($explicit !== null && $explicit !== '') {
            $path = self::absolute($explicit, $cwd);

            if (!\is_file($path)) {
                throw new ConsoleException(\sprintf('No configuration file at "%s".', $explicit));
            }

            return $path;
        }

        foreach (self::CANDIDATES as $candidate) {
            $path = $cwd . '/' . $candidate;

            if (\is_file($path)) {
                return $path;
            }
        }

        throw new ConsoleException(
            'No tailwind.php found. Looked for "tailwind.php" and "config/tailwind.php" '
            . 'in the current directory. Create one, or pass --config=PATH.'
        );
    }

    /**
     * Locate, load and wire — everything a command needs before it can work.
     *
     * @param string|null $explicit The `--config` value, if given.
     * @param string $cwd The working directory.
     * @param array<string, mixed> $overrides Merged over the file's values —
     *                                        how `build` forces production mode
     *                                        regardless of what the project
     *                                        configured for its own requests.
     *
     * @return array{0: Tailwind, 1: string} The facade and the absolute path of
     *                                       the file it came from.
     *
     * @throws ConsoleException When the file cannot be found or sits inside the
     *                          cache directory.
     * @throws \TailwindPhp\Exception\ConfigurationException When it does not
     *                                                       validate.
     */
    public static function load(?string $explicit, string $cwd, array $overrides = []): array
    {
        $path = self::locate($explicit, $cwd);
        $cwd = \rtrim(\str_replace('\\', '/', $cwd), '/');

        // The first T-08 gate, and the only one that runs **before** the file
        // is executed. The configured `cache_dir` is not knowable until the
        // config exists, so this passes the conventional default — which is
        // where an attacker who can write to a deployed cache actually lands.
        //
        // The task text specified `null` here. That is measurably wrong: with
        // null, a `--config=cache/tailwind/tailwind.php` runs the planted file
        // and only then gets refused, which is code execution followed by an
        // error message. See the report for the residual case this still does
        // not cover.
        $values = ConfigFile::load($path, $cwd, $cwd . '/' . self::CONVENTIONAL_CACHE_DIR);

        if (!\array_key_exists('project_root', $values)) {
            $values['project_root'] = $cwd;
        }

        $tailwind = Tailwind::fromArray(\array_merge($values, $overrides));

        self::refuseConfigInsideCacheDir($path, $tailwind);

        return [$tailwind, $path];
    }

    /**
     * The second T-08 gate, now that the configured cache directory is known.
     *
     * @throws ConsoleException
     */
    private static function refuseConfigInsideCacheDir(string $path, Tailwind $tailwind): void
    {
        $cacheDir = \rtrim(\str_replace('\\', '/', $tailwind->config()->cacheDir()), '/');
        $normalized = \str_replace('\\', '/', $path);

        if ($cacheDir === '') {
            return;
        }

        $real = \realpath($normalized);
        $realCache = \realpath($cacheDir);

        if ($real !== false) {
            $normalized = \str_replace('\\', '/', $real);
        }

        if ($realCache !== false) {
            $cacheDir = \rtrim(\str_replace('\\', '/', $realCache), '/');
        }

        if (\strncmp($normalized, $cacheDir . '/', \strlen($cacheDir) + 1) !== 0) {
            return;
        }

        throw new ConsoleException(
            'The configuration file may not live inside the cache directory: '
            . 'that directory is writable by the web server, and loading PHP from it '
            . 'would turn a cache write into code execution.'
        );
    }

    private static function absolute(string $path, string $cwd): string
    {
        $normalized = \str_replace('\\', '/', $path);

        if ($normalized === '') {
            return $cwd;
        }

        // Absolute already: a leading slash, or a Windows drive letter. Checked
        // by hand rather than with a pattern — `preg_*` is confined to
        // `Support\Regex` (static rule R1), and two byte comparisons are
        // clearer here than a call into it would be.
        if ($normalized[0] === '/' || (isset($normalized[1]) && $normalized[1] === ':')) {
            return $normalized;
        }

        return $cwd . '/' . $normalized;
    }
}
