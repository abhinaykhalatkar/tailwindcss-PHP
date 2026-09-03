<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FilesystemInterface;

/**
 * A real filesystem that counts what was asked of it
 * (design doc 16 §Lifecycle matrix).
 *
 * The lifecycle matrix does not assert on CSS; it asserts on *syscalls*. "Warm
 * cache inside the check interval" is only interesting if it performs zero
 * stats, and "manifest corrupt" is only interesting if it performs exactly one
 * compile. Those numbers are the specification, so a double that answers
 * plausibly but counts nothing would be useless here — hence a decorator over
 * the genuine {@see Filesystem} rather than an in-memory fake: the behaviour
 * under test stays real, only the bookkeeping is added.
 *
 * It decorates rather than extends because {@see Filesystem} is `final`, which
 * is deliberate — the production class is a thin syscall translation and
 * subclassing it would let a test change its meaning rather than merely observe
 * it.
 *
 * The inner implementation is injectable so this can also sit on top of a
 * failing or read-only double when a test needs both the failure and the count.
 */
final class RecordingFilesystem implements FilesystemInterface
{
    /**
     * @var FilesystemInterface
     */
    private $inner;

    /**
     * @var array<string, int> Method name => number of calls. A method that has
     *                         not been called is absent rather than zero, so
     *                         calls() reads as "what happened".
     */
    private $calls = [];

    /**
     * @param FilesystemInterface|null $inner Defaults to the real filesystem;
     *                                        pass a double to record on top of
     *                                        it.
     */
    public function __construct(?FilesystemInterface $inner = null)
    {
        $this->inner = $inner === null ? new Filesystem() : $inner;
    }

    public function exists(string $path): bool
    {
        $this->record('exists');

        return $this->inner->exists($path);
    }

    public function isFile(string $path): bool
    {
        $this->record('isFile');

        return $this->inner->isFile($path);
    }

    public function isDir(string $path): bool
    {
        $this->record('isDir');

        return $this->inner->isDir($path);
    }

    /**
     * @return array{mtime: int, size: int}|null
     */
    public function stat(string $path): ?array
    {
        $this->record('stat');

        return $this->inner->stat($path);
    }

    public function read(string $path, int $maxBytes): string
    {
        $this->record('read');

        return $this->inner->read($path, $maxBytes);
    }

    public function mkdir(string $dir, int $mode = 0755): void
    {
        $this->record('mkdir');

        $this->inner->mkdir($dir, $mode);
    }

    public function touch(string $path, ?int $mtime = null): void
    {
        $this->record('touch');

        $this->inner->touch($path, $mtime);
    }

    public function unlink(string $path): bool
    {
        $this->record('unlink');

        return $this->inner->unlink($path);
    }

    public function clearStatCache(string $path): void
    {
        $this->record('clearStatCache');

        $this->inner->clearStatCache($path);
    }

    /**
     * How many files were opened — the budget half of the matrix.
     */
    public function reads(): int
    {
        return $this->countOf('read');
    }

    /**
     * How many freshness checks were made — the half that says whether the
     * check interval is doing its job.
     */
    public function stats(): int
    {
        return $this->countOf('stat');
    }

    /**
     * @return array<string, int> Method name => call count, for every method
     *                            actually called.
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Forget everything counted so far, so one test can set a project up and
     * then measure only the request under examination.
     */
    public function reset(): void
    {
        $this->calls = [];
    }

    private function record(string $method): void
    {
        if (!isset($this->calls[$method])) {
            $this->calls[$method] = 0;
        }

        ++$this->calls[$method];
    }

    private function countOf(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }
}
