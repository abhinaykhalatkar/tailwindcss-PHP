<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

use TailwindPhp\Support\Path;

/**
 * Works out where the consuming project lives (design doc 11 §Resolution step 4).
 *
 * Three strategies in order, because none of them works everywhere:
 *  1. Composer's own runtime metadata — exact, but only from Composer ≥ 2.1 and
 *     only when the package was installed rather than checked out;
 *  2. walking up from this file past `vendor/` — works for a normal install and
 *     for a path repository;
 *  3. the working directory — a last resort that is right for the common case
 *     of a front controller run from the project root.
 *
 * The answer is always `realpath`ed so that everything downstream compares
 * canonical paths (ADR-014).
 */
final class ProjectRoot
{
    private function __construct()
    {
    }

    public static function detect(): string
    {
        $candidate = self::fromComposer();

        if ($candidate === null) {
            $candidate = self::byWalkingUp();
        }

        if ($candidate === null) {
            $cwd = \getcwd();
            $candidate = $cwd === false ? __DIR__ : $cwd;
        }

        $real = \realpath($candidate);

        return Path::normalize($real === false ? $candidate : $real);
    }

    /**
     * `\Composer\InstalledVersions` only exists when the consumer installed us
     * with Composer 2, and its `install_path` key only arrived in 2.1 — so both
     * the class and that key are checked rather than assumed. (The method
     * itself has existed since 2.0, so it needs no guard.)
     */
    private static function fromComposer(): ?string
    {
        $class = 'Composer\\InstalledVersions';

        if (!\class_exists($class)) {
            return null;
        }

        /** @var array<string, mixed> $root */
        $root = \call_user_func([$class, 'getRootPackage']);

        if (!isset($root['install_path']) || !\is_string($root['install_path'])) {
            return null;
        }

        return \is_dir($root['install_path']) ? $root['install_path'] : null;
    }

    /**
     * Climb until a directory holds a `composer.json` and is not itself inside a
     * `vendor/` tree.
     */
    private static function byWalkingUp(): ?string
    {
        $directory = __DIR__;

        for ($depth = 0; $depth < 10; ++$depth) {
            $parent = \dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;

            if (\basename($directory) === 'vendor') {
                continue;
            }

            if (
                \is_file($directory . '/composer.json')
                && \basename(\dirname($directory)) !== 'vendor'
            ) {
                return $directory;
            }
        }

        return null;
    }
}
