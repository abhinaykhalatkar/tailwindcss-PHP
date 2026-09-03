<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Exception\CompilationException;

/**
 * The seam between the pipeline and whichever compiler actually produces the
 * stylesheet (design doc 06 §The contract, ADR-002).
 *
 * Two implementations exist — the bundled pure-PHP engine and the official
 * standalone binary — and the pipeline must not care which one it holds: it
 * hands over the pre-processed entry text, an import resolver and the candidate
 * set the scanner already agreed on, and gets a {@see CompileResult} back. The
 * candidate set is supplied rather than discovered because the manifest records
 * a candidate hash and both drivers have to be fed identical inputs for that
 * hash to mean anything (design doc 06 §The contract).
 *
 * The split between diagnostics and exceptions is the important part of this
 * contract: anything wrong with the *input* — a CSS syntax error, an unknown
 * utility, `@plugin` on the PHP engine — is a {@see \TailwindPhp\Engine\Diagnostic\Diagnostic}
 * on the result, so one compile reports every problem at once. Only
 * infrastructure failures (the binary crashed, the process timed out) throw.
 */
interface CompilerInterface
{
    /**
     * Stable driver identifier recorded in the manifest: `php` or `cli`.
     */
    public function name(): string;

    /**
     * Version string recorded in the manifest — the CLI's own version (e.g.
     * `4.1.18`) or the engine's compatibility marker (e.g. `compat-4.1`).
     *
     * It is part of the cache's environment fingerprint, so bumping it
     * invalidates every artefact this driver produced (FR-FRESH-8).
     */
    public function version(): string;

    /**
     * @param string $entryCss Entry stylesheet text, already pre-processed.
     * @param ImportResolverInterface $imports Resolves `@import` targets relative to the entry.
     * @param list<string> $candidates Sorted, unique class-name candidates.
     * @param CompileOptions $options Output settings for this compile.
     *
     * @throws CompilationException Only for infrastructure failures. CSS syntax
     *                              problems and unsupported directives become
     *                              diagnostics on the returned result instead.
     */
    public function compile(
        string $entryCss,
        ImportResolverInterface $imports,
        array $candidates,
        CompileOptions $options
    ): CompileResult;
}
