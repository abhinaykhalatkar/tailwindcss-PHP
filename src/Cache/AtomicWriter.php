<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use TailwindPhp\Exception\CacheWriteException;
use TailwindPhp\Support\Path;

/**
 * Writes a cache artefact so that a reader never sees a partial one
 * (design doc 05, FR-CACHE-2, threats T-13/T-14/T-17).
 *
 * Write to a temp name, then `rename` over the target. `rename` is atomic within
 * a filesystem, so a concurrent request either sees the old file or the new one
 * and never a half-written stylesheet — which matters because the reader is a
 * web server streaming bytes to a browser, not a process that can retry.
 *
 * Three details carry most of the safety:
 *
 * - **`fopen($tmp, 'xb')`** fails when the path already exists rather than
 *   truncating it. A symlink planted at the predicted temp name therefore makes
 *   the write fail instead of redirecting it somewhere we do not own (T-14).
 * - **`chmod` before `rename`**, so the file is never visible under its final
 *   name with the wrong mode (T-17).
 * - **No `fsync`.** Durability across a power cut is not the property we need;
 *   a lost cache rebuilds itself. Paying for it on every request is not worth it.
 * - **No copy fallback.** A failed rename fails; see {@see self::renameWithRetry()}.
 *
 * The native `fopen`/`fwrite`/`rename`/`chmod` calls are deliberate: the
 * `Support\Filesystem` facade has no write API, because writes belong here where
 * the atomic protocol lives.
 */
final class AtomicWriter
{
    /**
     * Windows can fail a `rename` because a virus scanner or an indexer briefly
     * holds the target; retrying clears it (design doc 17).
     */
    private const RENAME_RETRIES = 5;
    private const RENAME_SLEEP_US = 20000;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @param string $projectRoot Used only to keep paths in exception messages
     *                            project-relative (ADR-014, threat T-06).
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * @throws CacheWriteException When the artefact could not be written or
     *                             moved into place.
     */
    public function write(string $target, string $bytes, int $mode = 0644): void
    {
        $temp = $this->temporaryNameFor($target);

        // 'x' — create exclusively. See the class docblock: this is the guard
        // against a pre-planted symlink at a predictable temp path (T-14).
        $handle = @\fopen($temp, 'xb');

        if ($handle === false) {
            throw new CacheWriteException('create', $this->relative($temp));
        }

        $written = 0;
        $total = \strlen($bytes);

        while ($written < $total) {
            // Suppressed and immediately checked: a full disk raises a warning
            // *and* returns a short count, and the count is what we act on
            // (design doc 18).
            $result = @\fwrite($handle, \substr($bytes, $written));

            if ($result === false || $result === 0) {
                \fclose($handle);
                $this->discard($temp);

                throw new CacheWriteException('write', $this->relative($target));
            }

            $written += $result;
        }

        \fclose($handle);

        // Before the rename, so the file is never visible under its final name
        // with the wrong mode (T-17). Checked rather than assumed: an unchecked
        // failure here leaves the artefact at 0666 & ~umask, which is exactly
        // the exposure the mode exists to prevent.
        if (!@\chmod($temp, $mode)) {
            $this->discard($temp);

            throw new CacheWriteException('chmod', $this->relative($target));
        }

        if (!$this->renameWithRetry($temp, $target)) {
            $this->discard($temp);

            throw new CacheWriteException('rename', $this->relative($target));
        }
    }

    /**
     * Remove a temp file we own and are abandoning.
     *
     * Suppressed and not checked, deliberately: the file is ours, half-written
     * and already being reported as a failure — a second problem about failing
     * to clean it up would bury the first.
     */
    private function discard(string $temp): void
    {
        @\unlink($temp);
    }

    /**
     * Temp name in the target's own directory — a rename across filesystems is
     * not atomic, so the two must be neighbours. The prefix comes from the
     * target's basename (decision CCP-A5) so a stale temp file is traceable.
     */
    private function temporaryNameFor(string $target): string
    {
        return \dirname($target)
            . '/' . \basename($target)
            . '.tmp.' . \getmypid()
            . '.' . \bin2hex(\random_bytes(4));
    }

    /**
     * There is deliberately **no copy fallback** (decision CCP-A26).
     *
     * A copy writes into the final name, which is the one thing this class
     * exists to prevent: on Windows a reader holding `<b>.css` open makes every
     * rename fail, so the fallback fired exactly when a reader was watching, and
     * an observer saw the artefact at size 0 mid-copy. A failing copy is worse
     * still — it leaves a truncated file under the final name and the temp is
     * then unlinked, destroying the only intact copy.
     *
     * Failing the rename is the safe outcome: the previous artefact survives
     * intact and the caller decides what that means (see {@see self::write()}'s
     * callers — a fingerprinted artefact or the manifest is fatal, a stable
     * convenience copy is not).
     */
    private function renameWithRetry(string $temp, string $target): bool
    {
        for ($attempt = 0; $attempt < self::RENAME_RETRIES; ++$attempt) {
            // Suppressed and immediately checked: a losing race is expected and
            // the return value is the answer, not the warning (design doc 18).
            if (@\rename($temp, $target)) {
                return true;
            }

            \usleep(self::RENAME_SLEEP_US);
        }

        return false;
    }

    /**
     * Paths in exception messages are project-relative (ADR-014): an absolute
     * one would leak the server layout into a log (threat T-06).
     */
    private function relative(string $absolute): string
    {
        try {
            return Path::relative($this->projectRoot, $absolute);
        } catch (\TailwindPhp\Exception\PathException $e) {
            return \basename($absolute);
        }
    }
}
