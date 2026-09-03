<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Diagnostic;

/**
 * Ordered collection of {@see Diagnostic} objects gathered during one compile
 * (design doc 15 §Diagnostics).
 *
 * Unlike the diagnostics it holds this is a collector, not a value object: the
 * compiler appends to it as it walks the stylesheet, then the pipeline asks
 * whether anything fatal happened. Insertion order is preserved so the overlay
 * can show the first error.
 */
final class DiagnosticBag implements \Countable
{
    /**
     * @var list<Diagnostic>
     */
    private $diagnostics = [];

    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(array $diagnostics = [])
    {
        foreach ($diagnostics as $diagnostic) {
            $this->add($diagnostic);
        }
    }

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return list<Diagnostic>
     */
    public function errors(): array
    {
        return $this->filterByLevel(Diagnostic::ERROR);
    }

    /**
     * @return list<Diagnostic>
     */
    public function warnings(): array
    {
        return $this->filterByLevel(Diagnostic::WARNING);
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->diagnostics === [];
    }

    public function count(): int
    {
        return \count($this->diagnostics);
    }

    /**
     * Log-safe projection of every diagnostic, for exception context arrays.
     *
     * @return list<array<string, scalar|null>>
     */
    public function toArray(): array
    {
        $rows = [];

        foreach ($this->diagnostics as $diagnostic) {
            $rows[] = $diagnostic->toArray();
        }

        return $rows;
    }

    /**
     * @return list<Diagnostic>
     */
    private function filterByLevel(string $level): array
    {
        $matched = [];

        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->level() === $level) {
                $matched[] = $diagnostic;
            }
        }

        return $matched;
    }
}
