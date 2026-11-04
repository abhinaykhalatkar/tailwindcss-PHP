<?php

declare(strict_types=1);

namespace TailwindPhp\Engine;

use TailwindPhp\Engine\Import\ImportResolverInterface;

/**
 * One compile for {@see Compiler}: the entry stylesheet, how its imports are
 * read, the candidates, and the output settings and limits.
 *
 * The engine reads nothing itself (T-01/T-08): every import goes through the
 * injected resolver, which confines paths. Candidates are plain strings; the
 * candidate parser decides what they are (T-15).
 */
final class CompileRequest
{
    /**
     * The most candidates one compile takes. The output is also capped at the
     * parser's 100 000 nodes (about 49 000 one-declaration utilities). Measured
     * for 100 000 `w-N` candidates (30 000 compiled): 1.4–1.7 s of CPU (up to
     * 2.9 s wall) and 77–90 MB on the development hosts, about 26 MB per
     * 10 000 candidates; long variant stacks cost more per candidate (30 000
     * `group-hover/…:peer-focus/…:[&_…]:flex` ≈ 9 s of CPU there). The budgets
     * are `EngineBench`'s (TASK-0041). The scanner may collect more
     * (`limits.max_candidates`).
     */
    public const DEFAULT_MAX_CANDIDATES = 30000;

    /**
     * The entry path when the caller has none: a name at the project root.
     */
    public const DEFAULT_ENTRY_PATH = 'input.css';

    /**
     * @var string
     */
    public $entryCss;

    /**
     * @var string Project-relative path of the entry, for cycle detection and diagnostics.
     */
    public $entryPath;

    /**
     * @var ImportResolverInterface
     */
    public $imports;

    /**
     * @var list<string>
     */
    public $candidates;

    /**
     * @var bool
     */
    public $minify;

    /**
     * @var bool False strips the preflight (design doc 07 Q16, `I_PREFLIGHT_DISABLED`).
     */
    public $preflight;

    /**
     * @var int
     */
    public $maxCandidates;

    /**
     * @param list<string> $candidates
     *
     * @throws \InvalidArgumentException When $maxCandidates is below 1.
     */
    public function __construct(
        string $entryCss,
        ImportResolverInterface $imports,
        array $candidates,
        bool $minify = false,
        bool $preflight = true,
        int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
        string $entryPath = self::DEFAULT_ENTRY_PATH
    ) {
        if ($maxCandidates < 1) {
            throw new \InvalidArgumentException('The candidate limit must be at least 1.');
        }

        $this->entryCss = $entryCss;
        $this->imports = $imports;
        $this->candidates = $candidates;
        $this->minify = $minify;
        $this->preflight = $preflight;
        $this->maxCandidates = $maxCandidates;
        $this->entryPath = $entryPath;
    }
}
