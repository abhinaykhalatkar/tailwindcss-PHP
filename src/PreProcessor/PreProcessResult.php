<?php

declare(strict_types=1);

namespace TailwindPhp\PreProcessor;

/**
 * What {@see PreProcessorInterface::process()} produced: the CSS to hand to the
 * compiler, and the files the pre-processor read to produce it (design doc 06
 * §Optional pre-processor stage).
 *
 * The import list is the reason this type exists. A SCSS entry that pulls in
 * `_variables.scss` depends on it just as surely as on itself, so the pipeline
 * records those paths as inputs in the manifest and watches them; without them
 * a partial could be edited and the cache would happily serve stale CSS
 * (design doc 05 §Comparison).
 *
 * Immutable. Every path is project-relative (ADR-014) — the pre-processor
 * normalises them, since this object's contents reach the manifest verbatim.
 */
final class PreProcessResult
{
    /**
     * @var string
     */
    private $css;

    /**
     * @var list<string>
     */
    private $imports;

    /**
     * @param string $css The transformed entry stylesheet.
     * @param array<int, string> $imports Project-relative paths of every file the
     *                                    pre-processor read besides the entry; any
     *                                    integer keys are discarded (reindexed to a
     *                                    list) rather than required.
     */
    public function __construct(string $css, array $imports)
    {
        $this->css = $css;
        $this->imports = \array_values($imports);
    }

    public function css(): string
    {
        return $this->css;
    }

    /**
     * @return list<string>
     */
    public function imports(): array
    {
        return $this->imports;
    }
}
