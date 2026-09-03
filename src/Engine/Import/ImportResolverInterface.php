<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Import;

/**
 * Resolves an `@import` specifier to CSS the compiler can inline (CCP-A8).
 *
 * The specifier comes out of a stylesheet, which is a file the project owns but
 * not necessarily one a reviewer has read — a copied snippet can carry
 * `@import "../../../../etc/passwd"`. So a resolver is a *confinement*
 * boundary, and the interface is written so the safe answer is also the easy
 * one: anything it cannot vouch for comes back as `null`, not as an exception
 * and never as content from outside the permitted roots.
 *
 * **Depth and cycles are the caller's job.** A resolver answers one specifier
 * at a time and holds no state about the walk, so it cannot see that
 * `a.css → b.css → a.css` has closed a loop or that the chain is now forty
 * deep. The compiler drives the recursion and enforces `limits.max_import_depth`
 * (CCP-A8).
 */
interface ImportResolverInterface
{
    /**
     * @param string $spec The specifier exactly as written in the
     *                     stylesheet, without quotes — `"./x.css"`,
     *                     `"x"`, `"tailwindcss"`.
     * @param string $fromRelPath Project-relative path of the file doing the
     *                            importing; relative specifiers resolve against
     *                            its directory.
     *
     * @return ResolvedImport|null Null when the specifier is a bare package
     *                             name the driver handles itself, or names
     *                             something that does not exist or lies outside
     *                             the permitted roots. Callers must treat null
     *                             as "leave the `@import` alone", never as an
     *                             error.
     */
    public function resolve(string $spec, string $fromRelPath): ?ResolvedImport;
}
