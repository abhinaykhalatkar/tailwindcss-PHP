<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Theme;

/**
 * One resolved theme variable (design doc 07 §Phase 2).
 *
 * Carries what a utility needs to decide how to write it: the variable's name
 * (so `var(--name)` can be built and the variable marked used), its value (what
 * an `inline` theme substitutes), and whether it is inline or reference-only.
 * A reference variable has no emitted definition, so a utility that reads one
 * writes `var(--name, <value>)` instead of a bare `var(--name)` — the form the
 * pinned CLI produces for `@theme reference`.
 *
 * Immutable. The value is stored exactly as the stylesheet wrote it and is never
 * interpreted (threat T-15).
 */
final class ThemeValue
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $value;

    /**
     * @var bool
     */
    private $inline;

    /**
     * @var bool
     */
    private $reference;

    /**
     * @param string $name The full custom property name, e.g. `--color-red-500`.
     */
    public function __construct(string $name, string $value, bool $inline, bool $reference)
    {
        $this->name = $name;
        $this->value = $value;
        $this->inline = $inline;
        $this->reference = $reference;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function isReference(): bool
    {
        return $this->reference;
    }
}
