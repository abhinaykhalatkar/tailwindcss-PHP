<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Path;

/**
 * Loads a `config/tailwind.php` that returns an array.
 *
 * **This is the only place in `src/` that executes a file** (decision HBC-A9),
 * which is why the static rule R2 allowlists exactly this path and nothing else:
 * a second `require` site anywhere near the cache would turn a written artefact
 * into executable code (threat T-08). The guards below are the price of that
 * allowlist entry — the path must be an existing `.php` file, confined to the
 * project root, and **not** inside the cache directory, so a compromised or
 * merely misconfigured cache can never be loaded as configuration.
 *
 * Everything it can reject, it rejects before the `require`.
 */
final class ConfigFile
{
    private function __construct()
    {
    }

    /**
     * @param string $absPath Absolute path of the config file.
     * @param string|null $projectRoot Confinement root; detected when null.
     * @param string|null $cacheDir Cache directory to refuse, when known.
     *                              **Must be absolute**: the guard below
     *                              compares path prefixes, so a relative value
     *                              matches nothing and silently disables the
     *                              T-08 check instead of applying it. Pass null
     *                              when the cache directory is not known yet —
     *                              that is an honest "cannot check", whereas a
     *                              relative path only looks like one.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException When the path is unusable, $cacheDir is not
     *                                absolute, or the file does not return an array.
     */
    public static function load(string $absPath, ?string $projectRoot = null, ?string $cacheDir = null): array
    {
        $root = $projectRoot ?? ProjectRoot::detect();

        if ($cacheDir !== null) {
            try {
                // Confining a path to itself is the cheapest way to ask "is this
                // absolute and well formed?" using the one path implementation
                // we have, rather than a second copy of the rule.
                Path::confine($cacheDir, $cacheDir);
            } catch (PathException $e) {
                throw new ConfigurationException(
                    ['The cache directory passed to the configuration loader must be an absolute path.'],
                    $e
                );
            }
        }

        try {
            $resolved = Path::resolve($root, $absPath, [$root], true);
        } catch (PathException $e) {
            throw new ConfigurationException(
                \array_merge(['The configuration file could not be resolved.'], $e->problems()),
                $e
            );
        }

        if (!\is_file($resolved)) {
            throw new ConfigurationException(['The configuration path is not a file.']);
        }

        if (\substr($resolved, -4) !== '.php') {
            throw new ConfigurationException(['A configuration file must be a .php file.']);
        }

        if ($cacheDir !== null && self::isInside($resolved, $cacheDir)) {
            // A cache artefact must never be executable configuration (T-08).
            throw new ConfigurationException([
                'A configuration file may not live inside the cache directory.',
            ]);
        }

        /** @var mixed $returned */
        $returned = require $resolved;

        if (!\is_array($returned)) {
            throw new ConfigurationException([
                'A configuration file must return an array.',
            ]);
        }

        /** @var array<string, mixed> $returned */
        return $returned;
    }

    private static function isInside(string $path, string $directory): bool
    {
        $normalizedDir = \rtrim(Path::normalize($directory), '/');

        if ($normalizedDir === '') {
            return false;
        }

        $normalizedPath = Path::normalize($path);
        $prefix = $normalizedDir . '/';

        if (\DIRECTORY_SEPARATOR === '\\') {
            return \strcasecmp(\substr($normalizedPath, 0, \strlen($prefix)), $prefix) === 0;
        }

        return \strncmp($normalizedPath, $prefix, \strlen($prefix)) === 0;
    }
}
