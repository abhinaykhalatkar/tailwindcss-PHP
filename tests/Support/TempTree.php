<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

/**
 * Throwaway directory trees for integration-style tests
 * (design doc 16 §Integration test conventions).
 *
 * The removal logic is the interesting part and the reason this is shared: a
 * Windows directory junction reports `is_link() === false` and
 * `is_dir() === true`, so a naive recursive delete **follows it and empties the
 * target**. A junction whose target has since gone reports false from all of
 * `is_link()`, `is_file()`, `is_dir()` and `file_exists()` while still
 * occupying a directory entry, so a walk that only handles those three cases
 * leaves it behind and can never remove the parent. Both cases cost real
 * debugging time in M0; they are handled once, here.
 */
final class TempTree
{
    private function __construct()
    {
    }

    /**
     * Create a unique directory under the system temp directory.
     *
     * @param string $prefix Short label so a leaked directory is traceable.
     *
     * @return string The new directory, with forward slashes.
     */
    public static function make(string $prefix): string
    {
        $base = \realpath(\sys_get_temp_dir());
        $root = \str_replace('\\', '/', $base === false ? \sys_get_temp_dir() : $base);

        $dir = \rtrim($root, '/') . '/' . $prefix . '-' . \bin2hex(\random_bytes(6));

        \mkdir($dir, 0777, true);

        return $dir;
    }

    /**
     * Remove a tree, tolerating a directory that is already gone.
     */
    public static function remove(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $entries = \scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if (\is_link($full) || \is_file($full)) {
                \unlink($full);

                continue;
            }

            if (\is_dir($full)) {
                // A junction: remove the reparse point itself. Recursing would
                // delete the contents of whatever it points at.
                if (self::isReparsePoint($full)) {
                    \rmdir($full);

                    continue;
                }

                self::remove($full);

                continue;
            }

            // Matches no stat predicate at all: a junction whose target has
            // gone. It still occupies an entry, so it has to be unlinked or the
            // parent cannot be removed.
            \rmdir($full);
        }

        \rmdir($dir);
    }

    /**
     * Backup cleanup for a fatal that skips `tearDown()`
     * (design doc 16 §Integration test conventions).
     */
    public static function registerShutdownCleanup(string $dir): void
    {
        \register_shutdown_function(static function () use ($dir): void {
            self::remove($dir);
        });
    }

    /**
     * Whether a directory entry of this name exists, including a dangling
     * junction that `is_dir()` and `file_exists()` both report as absent.
     */
    public static function entryExists(string $path): bool
    {
        $parent = \dirname($path);

        if (!\is_dir($parent)) {
            return false;
        }

        $entries = \scandir($parent);

        return $entries !== false && \in_array(\basename($path), $entries, true);
    }

    /**
     * True when a directory entry redirects elsewhere — a junction or a symlink
     * to a directory. Detected by comparing the resolved path with the lexical
     * one rather than by `readlink()`, which warns on ordinary directories.
     */
    private static function isReparsePoint(string $dir): bool
    {
        $real = \realpath($dir);

        if ($real === false) {
            return true;
        }

        $normalized = \str_replace('\\', '/', $real);

        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp($normalized, $dir) !== 0
            : $normalized !== $dir;
    }
}
