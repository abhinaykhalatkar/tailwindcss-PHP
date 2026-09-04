<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner\PrePass;

use TailwindPhp\Scanner\PrePassInterface;
use TailwindPhp\Support\Regex;

/**
 * Strips `<?php ... ?>` and `<?= ... ?>` blocks (design doc 09
 * §Language-specific pre-splits, design decision CSF-A9 — the `<?= ?>`
 * shorthand is not in the original doc 09 text and was added there).
 *
 * Opt-in only (`scanner.prepass` must list `php`): many projects build class
 * strings inside PHP (`'class' => 'flex'` in a Blade component's arguments,
 * say), so stripping PHP code by default would lose real candidates. Applying
 * this pre-pass is the caller's decision, not this class's.
 *
 * An unterminated tag (no `?>` before end of file — real PHP treats that as
 * running to EOF) is left unstripped rather than swallowed, for the same
 * "lose as little as possible" reason as {@see TwigPrePass}.
 */
final class PhpPrePass implements PrePassInterface
{
    /**
     * Case-insensitive (`<?PHP` is valid PHP), non-greedy, dot-matches-newline
     * — one bounded scan per tag, no nested quantifiers (design doc 12 T-12).
     */
    private const TAG_PATTERN = '/<\?php.*?\?>|<\?=.*?\?>/is';

    public function apply(string $text): string
    {
        return Regex::replace(self::TAG_PATTERN, '', $text);
    }
}
