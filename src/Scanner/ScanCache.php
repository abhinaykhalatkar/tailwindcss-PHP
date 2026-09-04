<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\FilesystemInterface;

/**
 * Per-file candidate memo, identity-keyed on `(rel, mtime, size)` (design doc
 * 09 §Per-file cache, design doc 04 §4.8, ADR-005).
 *
 * A file is re-read and re-extracted only when its identity changed since the
 * previous manifest; this is what makes a templates-only edit touch one file
 * instead of the whole source tree. The same cache applies unchanged in
 * debug mode, because the identity is content-derived rather than a
 * time-based flag (FR-FRESH-4).
 *
 * Choosing which {@see PrePassInterface} (if any) applies to a file is the
 * caller's decision (design decision CSF-A9): the selector is injected as a
 * callable rather than this class knowing about extensions or config.
 */
final class ScanCache
{
    /**
     * Only the first 512 bytes are checked for a NUL byte (design doc 09
     * §Safety) — enough to catch an accidentally-matched image or archive
     * without reading further into a large file that already passed the
     * size budget.
     */
    private const BINARY_PROBE_BYTES = 512;

    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @var ExtractorInterface
     */
    private $extractor;

    /**
     * @var callable(string): (PrePassInterface|null)
     */
    private $prepassSelector;

    /**
     * @var array<string, array{k?: string, m?: int, s?: int, c?: list<string>}>
     */
    private $previousFiles;

    /**
     * @var int
     */
    private $maxFileSize;

    /**
     * @var string
     */
    private $projectRootAbs;

    /**
     * @var DiagnosticBag
     */
    private $warnings;

    /**
     * Every file seen so far via {@see self::extract()} (review round 1 item
     * 7 — the pipeline needs this to write `manifest.files[rel].c`). `c` is
     * present only when a real candidate list is known for the current
     * identity; a size/read/binary skip records the identity without it, so
     * the next request's identity check (which requires `c`) naturally
     * re-attempts the file instead of treating the skip as a valid cache
     * entry (review round 1 item 3).
     *
     * @var array<string, array{k: string, m: int, s: int, c?: list<string>}>
     */
    private $records = [];

    /**
     * $prepassSelector: given a project-relative path, the pre-pass to apply
     * before extraction, or null for none. $previousFiles: the previous
     * manifest's `files` map (design decision: Scanner never imports
     * `Cache\Manifest`, so the caller passes its array).
     *
     * @param callable(string): (PrePassInterface|null) $prepassSelector
     * @param array<string, array{k?: string, m?: int, s?: int, c?: list<string>}> $previousFiles
     * @param int $maxFileSize design doc 11 `limits.max_file_size`.
     * @param string $projectRootAbs Absolute project root; $rel is joined to
     *                               it to read a file.
     */
    public function __construct(
        FilesystemInterface $filesystem,
        ExtractorInterface $extractor,
        callable $prepassSelector,
        array $previousFiles,
        int $maxFileSize,
        string $projectRootAbs
    ) {
        $this->filesystem = $filesystem;
        $this->extractor = $extractor;
        $this->prepassSelector = $prepassSelector;
        $this->previousFiles = $previousFiles;
        $this->maxFileSize = $maxFileSize;
        $this->projectRootAbs = \rtrim(\str_replace('\\', '/', $projectRootAbs), '/');
        $this->warnings = new DiagnosticBag();
    }

    /**
     * @param string $kind The file's root kind (`'i'`/`'s'`, design doc 09
     *                     §Roots) — recorded in {@see self::records()} but
     *                     otherwise opaque to this class (design doc 17
     *                     layering: Scanner never imports `Scanner\Root`
     *                     here either, the caller already resolved it).
     *
     * @return list<string> Empty when the file was skipped (oversized,
     *                      unreadable, or binary-looking) rather than an
     *                      error.
     */
    public function extract(string $rel, string $kind, int $mtime, int $size): array
    {
        // In-request memo (review round 2 item 6). A second extract() for the
        // same file in one request answers from what this request already
        // recorded, without touching the disk again.
        //
        // Keyed on `(rel, m, s)` and on the record actually carrying `c`: the
        // unreadable, oversized and binary records are stored *without* it
        // precisely so they are not memoised, and a stat that no longer matches
        // means the file changed mid-request and must be re-read.
        $memo = $this->records[$rel] ?? null;

        if (
            $memo !== null
            && \array_key_exists('c', $memo)
            && $memo['m'] === $mtime
            && $memo['s'] === $size
        ) {
            return $memo['c'];
        }

        $previous = $this->previousFiles[$rel] ?? null;

        if (
            $previous !== null
            && \array_key_exists('m', $previous)
            && \array_key_exists('s', $previous)
            && \array_key_exists('c', $previous)
            && $previous['m'] === $mtime
            && $previous['s'] === $size
        ) {
            $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size, 'c' => $previous['c']];

            return $previous['c'];
        }

        if ($size > $this->maxFileSize) {
            $this->warnFileTooLarge($rel);
            $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size];

            return [];
        }

        $abs = $this->projectRootAbs . '/' . $rel;

        try {
            $bytes = $this->filesystem->read($abs, $this->maxFileSize + 1);
        } catch (\RuntimeException $e) {
            // An ordinary deploy race (the file vanished or became
            // unreadable between the snapshot stat and this read) rather
            // than a defect — treated as empty for this request, and not
            // memoised as a valid cache entry, so the next request tries
            // again (review round 1 item 3).
            $this->warnings->add(new Diagnostic(
                Diagnostic::WARNING,
                Diagnostic::W_FILE_UNREADABLE,
                'File could not be read and was treated as empty for this request.',
                $rel
            ));
            $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size];

            return [];
        }

        if (\strlen($bytes) > $this->maxFileSize) {
            $this->warnFileTooLarge($rel);
            $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size];

            return [];
        }

        if (\strpos(\substr($bytes, 0, self::BINARY_PROBE_BYTES), "\0") !== false) {
            $this->warnings->add(new Diagnostic(
                Diagnostic::WARNING,
                Diagnostic::W_BINARY_SKIPPED,
                'File looks binary (a NUL byte in the first 512 bytes) and was not scanned.',
                $rel
            ));
            $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size];

            return [];
        }

        $prepass = ($this->prepassSelector)($rel);

        if ($prepass !== null) {
            $bytes = $prepass->apply($bytes);
        }

        $candidates = $this->extractor->extract($bytes);
        $this->records[$rel] = ['k' => $kind, 'm' => $mtime, 's' => $size, 'c' => $candidates];

        return $candidates;
    }

    public function warnings(): DiagnosticBag
    {
        return $this->warnings;
    }

    /**
     * Every file seen so far via {@see self::extract()}, ready to become the
     * next manifest's `files` map (`c` omitted for a skipped file — see the
     * property docblock).
     *
     * @return array<string, array{k: string, m: int, s: int, c?: list<string>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    private function warnFileTooLarge(string $rel): void
    {
        $this->warnings->add(new Diagnostic(
            Diagnostic::WARNING,
            Diagnostic::W_FILE_TOO_LARGE,
            \sprintf('File exceeds limits.max_file_size (%d bytes) and was not scanned.', $this->maxFileSize),
            $rel
        ));
    }
}
