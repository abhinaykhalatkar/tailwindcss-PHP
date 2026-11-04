<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

/**
 * The per-family table of the conformance gate (design doc 16 §Thresholds and
 * reporting, TASK-0047).
 *
 * Every measured candidate is recorded once with its comparator status and
 * whether the allow-list names it. A row (a family, `variants`, `directives`)
 * counts:
 *
 * - `passed`: the comparator said PASS and the allow-list does not name it;
 * - `known`: named by the allow-list, whatever the comparator said (a golden
 *   rule that did not parse leaves both sides empty, which is a PASS that is
 *   still a confirmed difference);
 * - `failed`: not a pass and not named — what makes the gate fail.
 *
 * The rate is **strict**: a known difference is not a pass, so the number the
 * threshold is judged by can only be flattered by passing candidates.
 *
 * Plain text out, nothing written anywhere (T-01): the test prints it once at
 * the end of the run.
 */
final class ConformanceReport
{
    /**
     * @var array<string, array{total: int, passed: int, known: int, failed: int, statuses: array<string, int>}>
     */
    private $rows = [];

    /**
     * @param string $row The family or group the candidate belongs to.
     * @param string $status One of the {@see ComparisonResult} statuses.
     * @param bool $known Whether the allow-list names the candidate.
     *
     * @throws \InvalidArgumentException When $status is not a comparator status.
     */
    public function record(string $row, string $status, bool $known = false): void
    {
        if (!\in_array($status, ComparisonResult::STATUSES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a comparator status.', \substr($status, 0, 40)));
        }

        if (!isset($this->rows[$row])) {
            $this->rows[$row] = ['total' => 0, 'passed' => 0, 'known' => 0, 'failed' => 0, 'statuses' => []];
        }

        ++$this->rows[$row]['total'];

        if ($known) {
            ++$this->rows[$row]['known'];
        } elseif ($status === ComparisonResult::PASS) {
            ++$this->rows[$row]['passed'];
        } else {
            ++$this->rows[$row]['failed'];
        }

        if ($status !== ComparisonResult::PASS) {
            $this->rows[$row]['statuses'][$status] = ($this->rows[$row]['statuses'][$status] ?? 0) + 1;
            \ksort($this->rows[$row]['statuses'], \SORT_STRING);
        }
    }

    /**
     * The rows in the order they were first seen.
     *
     * @return array<string, array{total: int, passed: int, known: int, failed: int, statuses: array<string, int>}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Candidates recorded, in the named rows (all of them by default).
     *
     * @param list<string>|null $rows
     */
    public function total(?array $rows = null): int
    {
        return $this->sum($rows)['total'];
    }

    /**
     * The strict pass rate over the named rows (all by default), from 0.0 to 1.0;
     * 1.0 when nothing was recorded.
     *
     * @param list<string>|null $rows
     */
    public function rate(?array $rows = null): float
    {
        $sum = $this->sum($rows);

        return $sum['total'] === 0 ? 1.0 : $sum['passed'] / $sum['total'];
    }

    /**
     * The table: a title, a header, one line per row and a total.
     */
    public function render(string $title): string
    {
        $lines = [];
        $width = \strlen('family');

        foreach (\array_keys($this->rows) as $row) {
            $width = \max($width, \strlen((string) $row));
        }

        $lines[] = $title;
        $lines[] = self::format('family', $width, 'candidates', 'passed', 'known', 'failed', 'rate', 'failures');

        foreach ($this->rows as $row => $counts) {
            $lines[] = self::format(
                (string) $row,
                $width,
                (string) $counts['total'],
                (string) $counts['passed'],
                (string) $counts['known'],
                (string) $counts['failed'],
                self::percent($counts['passed'], $counts['total']),
                self::breakdown($counts['statuses'])
            );
        }

        $sum = $this->sum(null);
        $lines[] = self::format(
            'total',
            $width,
            (string) $sum['total'],
            (string) $sum['passed'],
            (string) $sum['known'],
            (string) $sum['failed'],
            self::percent($sum['passed'], $sum['total']),
            ''
        );

        return \implode("\n", $lines) . "\n";
    }

    /**
     * The row of a utility candidate: the inventory family of its longest root
     * (variants, a leading or trailing `!` and a leading `-` ignored), the
     * arbitrary-property family for `[property:value]`, and `(no root)` for a
     * candidate that names none (`zzz`, `p-`): those are the rejection lines,
     * measured like any other.
     */
    public static function rowOf(Inventory $inventory, string $candidate): string
    {
        $depth = 0;
        $start = 0;

        for ($i = 0, $length = \strlen($candidate); $i < $length; ++$i) {
            $byte = $candidate[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif ($byte === ']' || $byte === ')') {
                --$depth;
            } elseif ($byte === ':' && $depth === 0) {
                $start = $i + 1;
            }
        }

        $utility = \ltrim(\trim((string) \substr($candidate, $start), '!'), '-');

        if ($utility !== '' && $utility[0] === '[') {
            return $inventory->family(Inventory::ARBITRARY_PROPERTY);
        }

        $best = null;

        foreach ($inventory->roots() as $root) {
            if ($root === Inventory::ARBITRARY_PROPERTY) {
                continue;
            }

            $matches = $utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0;

            if ($matches && ($best === null || \strlen($root) > \strlen($best))) {
                $best = $root;
            }
        }

        return $best === null ? '(no root)' : $inventory->family($best);
    }

    /**
     * @param list<string>|null $rows
     *
     * @return array{total: int, passed: int, known: int, failed: int}
     */
    private function sum(?array $rows): array
    {
        $sum = ['total' => 0, 'passed' => 0, 'known' => 0, 'failed' => 0];

        foreach ($this->rows as $name => $counts) {
            if ($rows !== null && !\in_array((string) $name, $rows, true)) {
                continue;
            }

            $sum['total'] += $counts['total'];
            $sum['passed'] += $counts['passed'];
            $sum['known'] += $counts['known'];
            $sum['failed'] += $counts['failed'];
        }

        return $sum;
    }

    private static function percent(int $passed, int $total): string
    {
        return \sprintf('%.1f %%', $total === 0 ? 100.0 : $passed * 100 / $total);
    }

    /**
     * @param array<string, int> $statuses
     */
    private static function breakdown(array $statuses): string
    {
        $parts = [];

        foreach ($statuses as $status => $count) {
            $parts[] = $status . ' ' . $count;
        }

        return \implode(', ', $parts);
    }

    private static function format(
        string $name,
        int $width,
        string $total,
        string $passed,
        string $known,
        string $failed,
        string $rate,
        string $failures
    ): string {
        return \rtrim(
            \str_pad($name, $width)
            . '  ' . \str_pad($total, 10, ' ', \STR_PAD_LEFT)
            . '  ' . \str_pad($passed, 6, ' ', \STR_PAD_LEFT)
            . '  ' . \str_pad($known, 5, ' ', \STR_PAD_LEFT)
            . '  ' . \str_pad($failed, 6, ' ', \STR_PAD_LEFT)
            . '  ' . \str_pad($rate, 7, ' ', \STR_PAD_LEFT)
            . '  ' . $failures
        );
    }
}
