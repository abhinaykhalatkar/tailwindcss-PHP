<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

/**
 * A per-extension noise reduction step run before {@see CandidateExtractor}
 * (design doc 09 §Language-specific pre-splits).
 *
 * Not for correctness — the extractor already ignores anything that does not
 * look like a candidate — but for recall: comment bodies and stripped code can
 * otherwise contribute stray runs that survive the rejection rules by
 * accident. Selecting which pre-pass (if any) applies to a given file is the
 * caller's job (`scanner.prepass`, design decision CSF-A9); this interface
 * only describes the transform itself.
 */
interface PrePassInterface
{
    /**
     * @return string Text of the same or shorter length, safe to feed to
     *                {@see ExtractorInterface::extract()}.
     */
    public function apply(string $text): string;
}
