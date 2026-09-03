<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The narrow filesystem surface this library actually uses
 * (design doc 17 §Support, design doc 18 §Design rules: interfaces for
 * anything with I/O or time).
 *
 * It exists so the pipeline can be tested against a recording or failing
 * double instead of a real disk — the lifecycle matrix counts reads and stats
 * (design doc 16 §Lifecycle matrix) — and so the handful of syscalls on the hot
 * path are in one auditable place.
 *
 * **This layer performs no confinement and no normalisation.** Every path
 * reaching it has already been through {@see Path::resolve()} or
 * {@see Path::confine()}; re-checking here would imply the caller may pass
 * anything, which is exactly the assumption design doc 12 T-01/T-02 forbid.
 * Writes are deliberately absent: they belong to `Cache\AtomicWriter`, which
 * needs temp-file-plus-rename semantics rather than a plain write (T-13).
 */
interface FilesystemInterface
{
    /**
     * Whether anything exists at $path — file, directory or otherwise.
     */
    public function exists(string $path): bool;

    public function isFile(string $path): bool;

    public function isDir(string $path): bool;

    /**
     * Modification time and size in one syscall.
     *
     * These two fields are what freshness comparison is built from: mtime alone
     * misses a same-second edit and misses an `rsync -a`/`tar` restore that
     * carries an older mtime, so the snapshot records both (design doc 05
     * §Layer 1).
     *
     * Returns null when the path does not exist. Whether that is an error is
     * the caller's decision — a missing watched file means "rebuild", a missing
     * cache artefact means "cold" — so it is reported rather than thrown.
     *
     * @return array{mtime: int, size: int}|null
     */
    public function stat(string $path): ?array;

    /**
     * Read at most $maxBytes bytes from the start of $path.
     *
     * The budget is a hard stop that bounds the work one request can do
     * (design doc 12 T-05): a file longer than $maxBytes comes back **silently
     * truncated**. Callers that need to distinguish "short file" from "clipped
     * file" compare `strlen()` of the result against `stat()['size']`.
     *
     * @throws \RuntimeException When the file cannot be opened or read.
     */
    public function read(string $path, int $maxBytes): string;

    /**
     * Create $dir and any missing parents, idempotently.
     *
     * Succeeds when the directory already exists, including when a concurrent
     * process created it between the check and the call.
     *
     * @throws \RuntimeException When the directory does not exist afterwards.
     */
    public function mkdir(string $dir, int $mode = 0755): void;

    /**
     * Create $path if missing and set its modification time.
     *
     * @param int|null $mtime Unix seconds; null means "now".
     *
     * @throws \RuntimeException When the file cannot be created or stamped.
     */
    public function touch(string $path, ?int $mtime = null): void;

    /**
     * Remove $path.
     *
     * @return bool True when this call removed it, false when there was nothing
     *              to remove — pruning an entry another process already deleted
     *              is normal, not an error (design doc 12 T-13).
     *
     * @throws \RuntimeException When $path exists but cannot be removed.
     */
    public function unlink(string $path): bool;

    /**
     * Drop PHP's cached stat data for $path.
     *
     * Needed because one request can stat a file, write it, and stat it again;
     * without this the second answer is the first one (design doc 05 §Layer 1).
     */
    public function clearStatCache(string $path): void;
}
