<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

/**
 * The outcome of comparing an engine's stylesheet with a golden
 * (design doc 16 §Comparison steps 4–5, §Thresholds).
 *
 * One status per candidate under test:
 *
 * - `PASS`          the golden and the engine agree on every rule the candidate
 *                   produced, in the same order (or both produced none);
 * - `FAIL_MISSING`  the golden has rules for it and the engine produced none;
 * - `FAIL_EXTRA`    the engine produced rules the golden does not have for it;
 * - `FAIL_VALUE`    both have rules, and what they say differs;
 * - `FAIL_ORDER`    the rules agree but come in a different order — the
 *                   candidate that is out of place is the one reported (the
 *                   fewest candidates that would have to move);
 * - `ERROR_PARSE`   either stylesheet did not parse cleanly. **Nothing passes
 *                   then**, however similar the two texts look: a comparison of
 *                   input that could not be read proves nothing.
 *
 * Alongside the candidates, a few regions are compared that no candidate owns
 * (the theme variables, `@property` and `@keyframes` rules, the other layers and
 * authored CSS). A difference there is a *failure* with a pseudo-candidate name
 * in parentheses — `(theme) --color-red-500`, `(property) --tw-shadow`,
 * `(layer base) #3`, `(authored) #0` — so a future KNOWN-DIFFERENCES writer sees
 * every disagreement; it does not change {@see self::passRate()}, which is the
 * share of the *candidates* that pass (the 98 % of design doc 08).
 *
 * Immutable, and free of PHPUnit, so it can be promoted into `src/` with the
 * comparator (design doc 21).
 */
final class ComparisonResult
{
    public const PASS = 'PASS';
    public const FAIL_MISSING = 'FAIL_MISSING';
    public const FAIL_VALUE = 'FAIL_VALUE';
    public const FAIL_ORDER = 'FAIL_ORDER';
    public const FAIL_EXTRA = 'FAIL_EXTRA';
    public const ERROR_PARSE = 'ERROR_PARSE';

    /**
     * Every status, in the order a summary lists them.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::PASS,
        self::FAIL_MISSING,
        self::FAIL_VALUE,
        self::FAIL_ORDER,
        self::FAIL_EXTRA,
        self::ERROR_PARSE,
    ];

    /**
     * @var array<string, string>
     */
    private $statuses;

    /**
     * @var list<array{candidate: string, status: string, expected: string, actual: string}>
     */
    private $failures;

    /**
     * @var list<string>
     */
    private $diagnostics;

    /**
     * @param array<string, string> $statuses Candidate => status.
     * @param list<array{candidate: string, status: string, expected: string, actual: string}> $failures
     * @param list<string> $diagnostics Why parsing failed, when it did.
     */
    public function __construct(array $statuses, array $failures, array $diagnostics = [])
    {
        $this->statuses = $statuses;
        $this->failures = $failures;
        $this->diagnostics = $diagnostics;
    }

    /**
     * The result for input that did not parse: every candidate is `ERROR_PARSE`.
     *
     * @param list<string> $candidates
     * @param list<string> $diagnostics
     */
    public static function parseFailure(array $candidates, array $diagnostics): self
    {
        $statuses = [];

        foreach ($candidates as $candidate) {
            $statuses[$candidate] = self::ERROR_PARSE;
        }

        return new self($statuses, [], $diagnostics);
    }

    /**
     * The share of candidates that pass, from 0.0 to 1.0. With no candidates it
     * is 1.0 when nothing failed and 0.0 when the input did not parse.
     */
    public function passRate(): float
    {
        if ($this->diagnostics !== []) {
            return 0.0;
        }

        if ($this->statuses === []) {
            return 1.0;
        }

        return (float) $this->count(self::PASS) / (float) \count($this->statuses);
    }

    /**
     * Candidate => status, in the order the candidates were given.
     *
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /**
     * Every disagreement: the candidates that failed and the regions that
     * differ. `expected` and `actual` are the canonical, compact CSS of what
     * each side has there, empty when a side has nothing.
     *
     * @return list<array{candidate: string, status: string, expected: string, actual: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Why parsing failed, one entry per diagnostic, each naming its side.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function hasParseErrors(): bool
    {
        return $this->diagnostics !== [];
    }

    /**
     * Whether the two stylesheets agree completely: every candidate passes, no
     * region differs, and both parsed cleanly.
     */
    public function isIdentical(): bool
    {
        return $this->diagnostics === []
            && $this->failures === []
            && $this->count(self::PASS) === \count($this->statuses);
    }

    /**
     * How many candidates have a status.
     */
    public function count(string $status): int
    {
        $count = 0;

        foreach ($this->statuses as $candidate) {
            if ($candidate === $status) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * One line per status that occurs, then the pass rate.
     */
    public function summary(): string
    {
        $lines = [];

        foreach (self::STATUSES as $status) {
            $count = $this->count($status);

            if ($count > 0) {
                $lines[] = \sprintf('%s %d', $status, $count);
            }
        }

        $lines[] = \sprintf(
            'pass rate %d/%d (%.2f %%)',
            $this->count(self::PASS),
            \count($this->statuses),
            $this->passRate() * 100.0
        );

        return \implode("\n", $lines);
    }

    /**
     * Everything, as plain data, for the future KNOWN-DIFFERENCES writer.
     *
     * @return array{
     *     passRate: float,
     *     counts: array<string, int>,
     *     statuses: array<string, string>,
     *     failures: list<array{candidate: string, status: string, expected: string, actual: string}>,
     *     diagnostics: list<string>
     * }
     */
    public function toArray(): array
    {
        $counts = [];

        foreach (self::STATUSES as $status) {
            $counts[$status] = $this->count($status);
        }

        return [
            'passRate' => $this->passRate(),
            'counts' => $counts,
            'statuses' => $this->statuses,
            'failures' => $this->failures,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
