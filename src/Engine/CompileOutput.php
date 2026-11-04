<?php

declare(strict_types=1);

namespace TailwindPhp\Engine;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * What {@see Compiler::compile()} returns.
 *
 * - `css`: the stylesheet, pretty or compact as the request asked;
 * - `diagnostics`: every problem found, compile-wide;
 * - `imports`: the resolved identities the resolver answered with
 *   (`ResolvedImport::relPath()`, project-relative by the resolver contract,
 *   never an absolute path — T-06), each once, in first-resolved order, for
 *   the freshness snapshot;
 * - `sources`: the `@source` specs, never opened here (T-01);
 * - `inlineCandidates`: the `@source inline()` candidates (they are compiled
 *   too; the caller may record them);
 * - `stats`: counts, for build reports (no time: the engine is pure).
 *
 * @phpstan-import-type SourceSpec from \TailwindPhp\Engine\Directive\ProcessedSheet
 */
final class CompileOutput
{
    /**
     * @var string
     */
    public $css;

    /**
     * @var DiagnosticBag
     */
    public $diagnostics;

    /**
     * @var list<string>
     */
    public $imports;

    /**
     * @var list<SourceSpec>
     */
    public $sources;

    /**
     * @var list<string>
     */
    public $inlineCandidates;

    /**
     * @var array{candidates: int, utilities: int, applies: int}
     */
    public $stats;

    /**
     * @param list<string> $imports
     * @param list<SourceSpec> $sources
     * @param list<string> $inlineCandidates
     * @param array{candidates: int, utilities: int, applies: int} $stats
     */
    public function __construct(
        string $css,
        DiagnosticBag $diagnostics,
        array $imports,
        array $sources,
        array $inlineCandidates,
        array $stats
    ) {
        $this->css = $css;
        $this->diagnostics = $diagnostics;
        $this->imports = $imports;
        $this->sources = $sources;
        $this->inlineCandidates = $inlineCandidates;
        $this->stats = $stats;
    }
}
