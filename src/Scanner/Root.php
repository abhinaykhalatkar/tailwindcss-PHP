<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

/**
 * A directory {@see FileFinder} watches: the entry CSS's directory and each
 * `watch[]` entry (kind {@see self::INPUT}), or a `sources[]` entry (kind
 * {@see self::SOURCE}) (design doc 09 §Roots, design doc 17).
 *
 * Immutable and side-effect free: `$abs` and `$rel` are expected to already
 * be resolved and confined by `Support\Path` and normalised by `Config`
 * before this object is built (design decision CSF-A5) — Scanner does not
 * depend on `Config` (layering, design doc 17), so it trusts the values it is
 * given rather than re-deriving them.
 */
final class Root
{
    /**
     * The entry CSS's directory or a `watch[]` entry. A change here always
     * recompiles (Layer 1 §Comparison).
     */
    public const INPUT = 'i';

    /**
     * A `sources[]` entry. A change here goes through Layer 2 (candidate
     * re-extraction) before deciding whether to recompile.
     */
    public const SOURCE = 's';

    /**
     * @var string
     */
    private $abs;

    /**
     * @var string
     */
    private $rel;

    /**
     * @var string
     */
    private $kind;

    /**
     * @var list<string>
     */
    private $extensions;

    /**
     * @param string $abs Absolute path, forward slashes, no trailing separator.
     * @param string $rel Project-relative path, forward slashes, no leading separator.
     * @param string $kind One of self::INPUT or self::SOURCE.
     * @param array<int, string> $extensions Lowercase, no leading dot (already
     *                                       normalised by Config, design doc
     *                                       11 §8); any integer keys are
     *                                       discarded (reindexed to a list)
     *                                       rather than required.
     *
     * @throws \InvalidArgumentException When $kind is neither self::INPUT nor self::SOURCE.
     */
    public function __construct(string $abs, string $rel, string $kind, array $extensions)
    {
        if ($kind !== self::INPUT && $kind !== self::SOURCE) {
            throw new \InvalidArgumentException(\sprintf('Unknown root kind "%s".', $kind));
        }

        $this->abs = $abs;
        $this->rel = $rel;
        $this->kind = $kind;
        $this->extensions = \array_values($extensions);
    }

    public function abs(): string
    {
        return $this->abs;
    }

    public function rel(): string
    {
        return $this->rel;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function hasExtension(string $extension): bool
    {
        return \in_array($extension, $this->extensions, true);
    }
}
