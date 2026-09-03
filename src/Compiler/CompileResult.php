<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * Everything one call to {@see CompilerInterface::compile()} produced (design
 * doc 06 §The contract).
 *
 * This is the whole return channel of a compile, and it carries more than the
 * stylesheet because the manifest is written from it: `imports` becomes the
 * watched import graph, `inlineSources` the candidates recovered from
 * `@source inline(...)` that the scanner could not have seen, `driver` and
 * `driverVersion` the environment fingerprint, `durationMs` the `build_ms`
 * entry (design doc 05 §Manifest).
 *
 * A result is returned even when the compile went badly: input-level problems
 * are diagnostics, not exceptions, so callers check {@see self::hasErrors()}
 * rather than catching. The bag is passed through by reference on purpose —
 * the pipeline keeps appending to it after the compiler is done.
 *
 * Immutable, and every path in `imports` is project-relative (ADR-014); the
 * driver that builds this object is responsible for that, because an absolute
 * path here would travel straight into the manifest (threat T-06).
 */
final class CompileResult
{
    /**
     * @var string
     */
    private $css;

    /**
     * @var DiagnosticBag
     */
    private $diagnostics;

    /**
     * @var string
     */
    private $driver;

    /**
     * @var string
     */
    private $driverVersion;

    /**
     * @var float
     */
    private $durationMs;

    /**
     * @var list<string>
     */
    private $imports;

    /**
     * @var list<string>
     */
    private $inlineSources;

    /**
     * @param string $css The compiled stylesheet.
     * @param DiagnosticBag $diagnostics Problems found with the input.
     * @param string $driver The driver that produced this, matching {@see CompilerInterface::name()}.
     * @param string $driverVersion Matching {@see CompilerInterface::version()}.
     * @param float $durationMs Wall-clock time of the compile.
     * @param array<int, string> $imports Resolved `@import` graph, project-relative;
     *                                    any integer keys are discarded
     *                                    (reindexed to a list) rather than required.
     * @param array<int, string> $inlineSources Candidates declared with
     *                                          `@source inline(...)`; likewise reindexed.
     */
    public function __construct(
        string $css,
        DiagnosticBag $diagnostics,
        string $driver,
        string $driverVersion,
        float $durationMs,
        array $imports,
        array $inlineSources
    ) {
        $this->css = $css;
        $this->diagnostics = $diagnostics;
        $this->driver = $driver;
        $this->driverVersion = $driverVersion;
        $this->durationMs = $durationMs;
        $this->imports = \array_values($imports);
        $this->inlineSources = \array_values($inlineSources);
    }

    public function css(): string
    {
        return $this->css;
    }

    public function diagnostics(): DiagnosticBag
    {
        return $this->diagnostics;
    }

    /**
     * Whether the compile failed in a way that must not be cached or served.
     *
     * Delegated to the bag rather than snapshotted, so a diagnostic added after
     * this object was built is still seen.
     */
    public function hasErrors(): bool
    {
        return $this->diagnostics->hasErrors();
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function driverVersion(): string
    {
        return $this->driverVersion;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    /**
     * The resolved `@import` graph, project-relative (ADR-014). Each entry
     * becomes a watched input in the manifest.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        return $this->imports;
    }

    /**
     * Candidates the stylesheet declared with `@source inline(...)`. They are
     * folded back into the next scan's candidate union (design doc 09
     * §Candidate union), which is why they have to survive the compile.
     *
     * @return list<string>
     */
    public function inlineSources(): array
    {
        return $this->inlineSources;
    }
}
