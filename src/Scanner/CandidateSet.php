<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

use TailwindPhp\Support\Hash;

/**
 * The union of every source file's candidates, plus `safelist` and
 * `inline_sources`, minus `blocklist` (design doc 05 §Layer 2, design doc 09
 * §Per-file cache, ADR-005).
 *
 * Immutable: the union, cap and truncation flag are all fixed at
 * construction. `hash()` is the identity Layer 2 compares against the stored
 * `candidates_hash` to decide TOUCH vs. COMPILE.
 */
final class CandidateSet
{
    /**
     * @var list<string>
     */
    private $all;

    /**
     * @var bool
     */
    private $truncated;

    /**
     * @param array<string, list<string>> $perFileCandidates Source-file
     *                                                       relative path => its candidates.
     * @param list<string> $safelist design doc 11 `safelist`, already
     *                               syntax-checked by Config (CSF-A1).
     * @param list<string> $blocklist design doc 11 `blocklist`.
     * @param list<string> $inlineSources `@source inline(...)` results
     *                                    (wiring lands in M2, CCP-A12).
     * @param int $maxCandidates design doc 11 `limits.max_candidates`.
     */
    public function __construct(
        array $perFileCandidates,
        array $safelist,
        array $blocklist,
        array $inlineSources,
        int $maxCandidates
    ) {
        $union = [];

        foreach ($perFileCandidates as $candidates) {
            foreach ($candidates as $candidate) {
                $union[$candidate] = true;
            }
        }

        foreach ($safelist as $candidate) {
            $union[$candidate] = true;
        }

        foreach ($inlineSources as $candidate) {
            $union[$candidate] = true;
        }

        foreach ($blocklist as $candidate) {
            unset($union[$candidate]);
        }

        $sorted = \array_keys($union);
        \sort($sorted, \SORT_STRING);

        // Cap: keep the first N in sorted order, drop the rest (CSF-A10). The
        // caller is responsible for logging one W_CANDIDATE_LIMIT diagnostic
        // when self::wasTruncated() is true. A negative cap is clamped to
        // zero (review round 1 item 8): array_slice() with a negative length
        // drops elements from the *end* instead, which would keep candidates
        // rather than reject all of them.
        $cap = \max(0, $maxCandidates);
        $this->truncated = \count($sorted) > $cap;
        $this->all = $this->truncated ? \array_slice($sorted, 0, $cap) : $sorted;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->all;
    }

    public function count(): int
    {
        return \count($this->all);
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Bare lowercase hex (design decision CSF-A3); the manifest writer adds
     * the `sha256:` prefix.
     */
    public function hash(): string
    {
        return Hash::sha256Lines($this->all);
    }
}
