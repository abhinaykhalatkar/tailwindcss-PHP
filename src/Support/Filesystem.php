<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The real filesystem — the production implementation of
 * {@see FilesystemInterface}.
 *
 * Stateless and constructor-free: it is a thin translation of the interface
 * into syscalls, so that the interesting behaviour lives in the callers and can
 * be tested against a double.
 *
 * **No confinement happens here.** Paths arrive already resolved and confined by
 * {@see Path}; repeating the check would suggest this class is a safe place to
 * pass arbitrary input, which design doc 12 T-01/T-02 explicitly rely on it not
 * being.
 */
final class Filesystem implements FilesystemInterface
{
    /**
     * Read size for the chunked reader; also the cap on a single `fread`.
     */
    private const CHUNK_BYTES = 65536;

    public function exists(string $path): bool
    {
        return \file_exists($path);
    }

    public function isFile(string $path): bool
    {
        return \is_file($path);
    }

    public function isDir(string $path): bool
    {
        return \is_dir($path);
    }

    /**
     * @return array{mtime: int, size: int}|null
     */
    public function stat(string $path): ?array
    {
        // One syscall for both fields: filemtime() + filesize() would be two,
        // and this runs once per watched file on every request (design doc 13).
        // Guarded by file_exists() rather than silenced, so a genuine failure
        // still surfaces as a warning instead of being swallowed.
        if (!\file_exists($path)) {
            return null;
        }

        $stat = \stat($path);

        if ($stat === false) {
            return null;
        }

        return [
            'mtime' => (int) $stat['mtime'],
            'size' => (int) $stat['size'],
        ];
    }

    /**
     * @throws \RuntimeException
     */
    public function read(string $path, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        // Checked before opening rather than silenced afterwards: `@` is only
        // permitted around rename/unlink/flock/mkdir (design doc 18), and a
        // missing file is the ordinary case here — a cold cache, a deleted
        // source — not something worth a PHP warning on the way to the throw.
        if (!\is_file($path)) {
            throw new \RuntimeException(\sprintf('Cannot open file for reading: %s', $path));
        }

        $handle = \fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot open file for reading: %s', $path));
        }

        try {
            $contents = '';

            while (!\feof($handle)) {
                $remaining = $maxBytes - \strlen($contents);

                if ($remaining < 1) {
                    break;
                }

                $wanted = $remaining < self::CHUNK_BYTES ? $remaining : self::CHUNK_BYTES;
                $chunk = \fread($handle, $wanted);

                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;
            }
        } finally {
            \fclose($handle);
        }

        return $contents;
    }

    /**
     * @throws \RuntimeException
     */
    public function mkdir(string $dir, int $mode = 0755): void
    {
        if (\is_dir($dir)) {
            return;
        }

        // Suppression is permitted here (design doc 18 §Design rules): another
        // process may create the directory between the check above and this
        // call, which makes mkdir() emit a warning and return false even though
        // the outcome is exactly what we wanted. The is_dir() re-check below is
        // the real test of success, so the warning carries no information.
        if (!@\mkdir($dir, $mode, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory: %s', $dir));
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function touch(string $path, ?int $mtime = null): void
    {
        // Same reasoning as read(): the parent missing is an ordinary caller
        // error, so it is reported directly rather than via a PHP warning that
        // we are not allowed to suppress here.
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            throw new \RuntimeException(
                \sprintf('Cannot touch file, its directory does not exist: %s', $path)
            );
        }

        $touched = $mtime === null
            ? \touch($path)
            : \touch($path, $mtime);

        if (!$touched) {
            throw new \RuntimeException(\sprintf('Cannot touch file: %s', $path));
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function unlink(string $path): bool
    {
        if (!\file_exists($path)) {
            return false;
        }

        // Suppression is permitted here (design doc 18 §Design rules): a
        // concurrent prune may remove the file between the check above and this
        // call, and the file_exists() re-check below tells us which happened.
        if (@\unlink($path)) {
            return true;
        }

        if (!\file_exists($path)) {
            // Someone else removed it first; the caller's intent is satisfied.
            return false;
        }

        throw new \RuntimeException(\sprintf('Cannot remove file: %s', $path));
    }

    public function clearStatCache(string $path): void
    {
        \clearstatcache(true, $path);
    }
}
