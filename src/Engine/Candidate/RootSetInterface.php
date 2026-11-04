<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate;

/**
 * The utility roots the candidate parser may split a utility on (ENG-A3).
 *
 * Decouples candidate parsing from the utility registry: `Utility\Registry`
 * implements this with its prefix trie (design doc 13 #16), tests use
 * `tests/Support/StaticRootSet`. The parser never needs to know more than
 * which roots fit.
 */
interface RootSetInterface
{
    /**
     * Roots that fit $utility, longest first:
     *
     * - $utility itself, when it is a known root (a static utility, or a
     *   functional one used without a value, such as `border` or `shadow`);
     * - then every functional root R for which $utility starts with `R-`.
     *
     * The parser turns an exact match into a `static` candidate and every
     * other match into a `functional` one whose value is the text after `R-`.
     *
     * @return list<string>
     */
    public function matchRoots(string $utility): array;
}
