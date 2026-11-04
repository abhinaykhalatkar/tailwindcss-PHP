<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Support;

/**
 * What {@see FamilyGoldenRunner} measured for one utility family: how many corpus
 * candidates were compared, how many matched the golden, which did not, and which
 * the known-differences list excused.
 */
final class FamilyGoldenResult
{
    /**
     * @var int
     */
    private $total;

    /**
     * @var int
     */
    private $matched;

    /**
     * @var list<string>
     */
    private $mismatches;

    /**
     * @var list<string>
     */
    private $skipped;

    /**
     * @param list<string> $mismatches One line each: status, candidate, corpus entry, expected and actual rules.
     * @param list<string> $skipped The allow-listed candidates that really do mismatch.
     */
    public function __construct(int $total, int $matched, array $mismatches, array $skipped)
    {
        $this->total = $total;
        $this->matched = $matched;
        $this->mismatches = $mismatches;
        $this->skipped = $skipped;
    }

    /**
     * The candidates compared: those of the family that are not allow-listed.
     */
    public function total(): int
    {
        return $this->total;
    }

    public function matched(): int
    {
        return $this->matched;
    }

    /**
     * @return list<string>
     */
    public function mismatches(): array
    {
        return $this->mismatches;
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * The share of compared candidates that matched; 1.0 when none were compared.
     */
    public function rate(): float
    {
        return $this->total === 0 ? 1.0 : $this->matched / $this->total;
    }

    /**
     * A message for an assertion: the numbers, then every mismatch.
     */
    public function report(): string
    {
        return \sprintf(
            '%d of %d candidates match (%.2f %%), %d skipped by the allow-list%s',
            $this->matched,
            $this->total,
            $this->rate() * 100,
            \count($this->skipped),
            $this->mismatches === [] ? '.' : ":\n" . \implode("\n", $this->mismatches)
        );
    }
}
