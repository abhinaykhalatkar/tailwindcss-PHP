<?php

declare(strict_types=1);

namespace TailwindPhp\Freshness;

/**
 * The result of one {@see \TailwindPhp\Scanner\FileFinder::snapshot()} call:
 * every watched file's identity, plus which configured roots could not be
 * found (design doc 05 §Layer 1).
 *
 * One algorithm compares two snapshots — {@see StalenessChecker::compare()}
 * — so this class carries no `diff()` of its own and no `rootId` field
 * (design decision CSF-A12). Always ordered by relative path: the constructor
 * `ksort()`s regardless of the order the caller built the map in, because the
 * order is part of the contract (deterministic hashing and diffing, design
 * doc 05 §Layer 1).
 */
final class FileSnapshot
{
    /**
     * @var array<string, array{k: string, m: int, s: int}>
     */
    private $files;

    /**
     * @var list<string>
     */
    private $missingRoots;

    /**
     * @param array<string, array{k: string, m: int, s: int}> $files Project-relative
     *                                                               path => identity.
     * @param array<int, string> $missingRoots Project-relative paths of configured
     *                                         roots that were not directories; any
     *                                         integer keys are discarded (reindexed
     *                                         to a list) rather than required.
     */
    public function __construct(array $files, array $missingRoots = [])
    {
        \ksort($files);

        $this->files = $files;
        $this->missingRoots = \array_values($missingRoots);
    }

    /**
     * @return array<string, array{k: string, m: int, s: int}>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function has(string $rel): bool
    {
        return isset($this->files[$rel]);
    }

    /**
     * @return array{k: string, m: int, s: int}|null
     */
    public function get(string $rel): ?array
    {
        return $this->files[$rel] ?? null;
    }

    public function count(): int
    {
        return \count($this->files);
    }

    /**
     * @return list<string>
     */
    public function missingRoots(): array
    {
        return $this->missingRoots;
    }
}
