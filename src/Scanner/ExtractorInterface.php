<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

/**
 * Turns raw source bytes into class-name candidates (design doc 09).
 *
 * An interface rather than a single concrete class so {@see ScanCache} and its
 * tests can depend on the contract instead of {@see CandidateExtractor}
 * directly (design doc 18 §Design rules: interfaces for anything with I/O or
 * time — extraction is pure, but the seam is still useful for test doubles).
 */
interface ExtractorInterface
{
    /**
     * @return list<string> Sorted, unique, each 1-256 bytes.
     */
    public function extract(string $bytes): array;
}
