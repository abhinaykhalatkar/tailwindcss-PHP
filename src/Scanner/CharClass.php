<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

/**
 * Byte classification table for {@see CandidateExtractor} (design doc 09
 * §Candidate extraction; design doc 13 §Snapshot and scan #13; corrected by
 * design decisions CSF-A7, CSF-A21, CSF-A22 in review round 1).
 *
 * `,` is deliberately absent from every flag here even though it is a valid
 * candidate byte inside brackets (`bg-[url(a,b)]`): its boundary/continue
 * behaviour depends on bracket depth (CSF-A7), which a per-byte table cannot
 * express, so the extractor checks for it explicitly instead. `!` carries no
 * such exception — it is a normal start/continue byte at every depth (the
 * important-modifier prefix/suffix, e.g. `!flex`, `flex!`).
 *
 * `(` is deliberately **not** a start byte (CSF-A22b): no real Tailwind
 * utility begins with it — arbitrary CSS-variable syntax is always
 * hyphen-prefixed (`bg-(--x)`) — so a bare `(` (a JS function call, say) must
 * never itself open a candidate. It keeps the OPEN flag because it still
 * needs to be recognised as a bracket character once a run is already under
 * way.
 */
final class CharClass
{
    /**
     * A run may start on this byte (design doc 09, minus `(` per CSF-A22b:
     * `[A-Za-z0-9_@!\-\[*]`).
     */
    public const START = 1;

    /**
     * A run may continue on this byte while bracket depth is zero
     * (design doc 09: `[A-Za-z0-9_\-:/.%!@*#&]`, `,` excluded per CSF-A7).
     */
    public const CONTINUE = 2;

    /**
     * `[` or `(` — a bracket-opening byte. Whether it actually opens a new
     * depth level depends on context the table cannot express (CSF-A22c);
     * see {@see CandidateExtractor}.
     */
    public const OPEN = 4;

    /**
     * `]` or `)` — decreases bracket depth.
     */
    public const CLOSE = 8;

    /**
     * `'`, `"` or `` ` `` — while inside brackets, starts a string that runs
     * to its matching quote regardless of bracket depth.
     */
    public const QUOTE = 16;

    /**
     * Space, tab, LF, CR, FF or VT (CSF-A22a): ends a run at every depth: a
     * boundary at depth zero, but a rejection when depth is greater than
     * zero or a string is open, because Tailwind never allows whitespace
     * inside a candidate (arbitrary values use `_` instead).
     */
    public const WHITESPACE = 32;

    /**
     * One entry per byte value 0-255, each a bitmask of the flags above.
     *
     * @var list<int>
     */
    public const TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 32, 32, 32, 32, 32, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        32, 3, 16, 2, 0, 2, 2, 16, 4, 8, 3, 0, 0, 3, 2, 2,
        3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 2, 0, 0, 0, 0, 0,
        3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3,
        3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 5, 0, 8, 0, 3,
        16, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3,
        3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
    ];

    /**
     * ASCII code for `,` (design decision CSF-A7): continue byte only while
     * bracket depth is greater than zero, a boundary at depth zero.
     */
    public const COMMA = 44;

    private function __construct()
    {
    }

    /**
     * @param int<0, 255> $byte
     */
    public static function is(int $byte, int $flag): bool
    {
        $flags = self::TABLE[$byte] ?? 0;

        return ($flags & $flag) !== 0;
    }
}
