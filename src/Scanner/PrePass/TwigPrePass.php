<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner\PrePass;

use TailwindPhp\Scanner\PrePassInterface;
use TailwindPhp\Support\Regex;

/**
 * Strips Twig `{# ... #}` comments (design doc 09 §Language-specific
 * pre-splits).
 *
 * `{% ... %}` tag bodies are deliberately left alone: a `{% set %}` can build
 * a class string worth extracting, so only the delimiters themselves act as
 * boundaries for {@see \TailwindPhp\Scanner\CandidateExtractor} — nothing
 * here needs to strip them.
 *
 * An unterminated `{#` (no matching `#}` before end of file) is left as-is
 * rather than swallowed to the end of the file: a stray `{#` in a file this
 * pre-pass does not fully understand should lose as little of the file as
 * possible, matching the "bounded, not perfect" posture of the whole scanner
 * (design doc 09 §Safety).
 */
final class TwigPrePass implements PrePassInterface
{
    /**
     * Non-nested, non-greedy and dot-matches-newline: a single bounded scan
     * per comment with no backtracking blow-up risk (design doc 12 T-12).
     */
    private const COMMENT_PATTERN = '/\{#.*?#\}/s';

    public function apply(string $text): string
    {
        return Regex::replace(self::COMMENT_PATTERN, '', $text);
    }
}
