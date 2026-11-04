<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

/**
 * How one run of the directive {@see Processor} is bounded and configured
 * (design doc 07 §Complexity and limits; Q16 for `preflight`; threat T-05).
 *
 * A small immutable value: the engine compiler (TASK-0034) builds one per
 * compile, passing `strlen()` of the entry CSS as `entryBytes` — the byte
 * budget counts the entry too, so the caller must say how large it is.
 */
final class ProcessorOptions
{
    /**
     * Total bytes of the entry stylesheet plus every import (doc 07: 4 MB).
     */
    public const DEFAULT_MAX_TOTAL_CSS = 4194304;

    /**
     * Longest chain of relative imports (doc 07: 16).
     */
    public const DEFAULT_MAX_IMPORT_DEPTH = 16;

    /**
     * Import operations per compile: every `@import` the processor tries to
     * resolve counts, refused ones included.
     */
    public const DEFAULT_MAX_IMPORTS = 1000;

    /**
     * Distinct `@source inline()` candidates per sheet, included and excluded
     * together (the order of the scanner's candidate limit).
     */
    public const DEFAULT_MAX_INLINE_CANDIDATES = 20000;

    /**
     * @var int
     */
    public $maxTotalCss;

    /**
     * @var int
     */
    public $maxImportDepth;

    /**
     * @var int
     */
    public $maxImports;

    /**
     * @var int
     */
    public $maxInlineCandidates;

    /**
     * @var bool False strips the preflight bundle (`I_PREFLIGHT_DISABLED`).
     */
    public $preflight;

    /**
     * @var string Project-relative path of the entry stylesheet; relative
     *             imports resolve against its directory.
     */
    public $entryPath;

    /**
     * @var int Bytes of the entry stylesheet, counted against `maxTotalCss`.
     */
    public $entryBytes;

    /**
     * @param int $entryBytes `strlen()` of the entry CSS (required: the byte budget must count it).
     *
     * @throws \InvalidArgumentException When a limit is negative, or the total byte budget is zero (the
     *                                   other limits may be zero: no import, no depth, no inline candidate).
     */
    public function __construct(
        string $entryPath,
        int $entryBytes,
        bool $preflight = true,
        int $maxTotalCss = self::DEFAULT_MAX_TOTAL_CSS,
        int $maxImportDepth = self::DEFAULT_MAX_IMPORT_DEPTH,
        int $maxImports = self::DEFAULT_MAX_IMPORTS,
        int $maxInlineCandidates = self::DEFAULT_MAX_INLINE_CANDIDATES
    ) {
        if ($entryBytes < 0 || $maxTotalCss < 1 || $maxImportDepth < 0 || $maxImports < 0 || $maxInlineCandidates < 0) {
            throw new \InvalidArgumentException('Processor limits must not be negative.');
        }

        $this->entryPath = $entryPath;
        $this->entryBytes = $entryBytes;
        $this->preflight = $preflight;
        $this->maxTotalCss = $maxTotalCss;
        $this->maxImportDepth = $maxImportDepth;
        $this->maxImports = $maxImports;
        $this->maxInlineCandidates = $maxInlineCandidates;
    }
}
