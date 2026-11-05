<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A CPU-time **tripwire** for the Unit, Security and Conformance suites
 * (design doc 13 §Time assertions, TASK-0060): it catches runaway behaviour —
 * seconds becoming minutes — and never a slow runner. Budgets, with their
 * tolerance, live in `tests/Benchmark`.
 *
 * The rule, enforced here so it cannot drift row by row: a limit is at least
 * 10× what the row measures on the reference host idle, and never below
 * {@see self::FLOOR} (5 s of CPU) for whole-input rows, or below
 * {@see self::REGEX_FLOOR} (2 s) for the regex-DoS rows, whose only question
 * is "no exponential blow-up". A lower limit throws: it would be a budget.
 *
 * CPU time rather than wall time: other processes on a shared runner do not
 * count. With `TAILWINDPHP_TRIPWIRE_LOG` naming a file, every measurement is
 * appended there (`Class::method`, what, seconds) — how doc 13's numbers are
 * taken.
 */
final class Tripwire
{
    public const FLOOR = 5.0;

    public const REGEX_FLOOR = 2.0;

    public const LOG_ENV = 'TAILWINDPHP_TRIPWIRE_LOG';

    /**
     * User plus system CPU time of this process, in seconds (wall time where
     * `getrusage()` is unavailable).
     */
    public static function cpu(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \microtime(true);
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }

    /**
     * A whole-input row: $seconds of CPU stays under $limit (≥ 5 s).
     */
    public static function assertUnder(float $limit, float $seconds, string $what): void
    {
        self::check($limit, self::FLOOR, $seconds, $what);
    }

    /**
     * A regex-DoS row: $seconds of CPU stays under $limit (≥ 2 s).
     */
    public static function assertRegexUnder(float $limit, float $seconds, string $what): void
    {
        self::check($limit, self::REGEX_FLOOR, $seconds, $what);
    }

    private static function check(float $limit, float $floor, float $seconds, string $what): void
    {
        if ($limit < $floor) {
            throw new \LogicException(\sprintf(
                'A %.1f s tripwire is below the %.0f s floor: that is a budget, and budgets belong in'
                    . ' tests/Benchmark (design doc 13).',
                $limit,
                $floor
            ));
        }

        self::log($what, $seconds);

        Assert::assertLessThan($limit, $seconds, \sprintf(
            '%s took %.2f s of CPU against a %.1f s tripwire (10x this row on the reference host idle):'
                . ' a regression, not noise.',
            $what,
            $seconds,
            $limit
        ));
    }

    private static function log(string $what, float $seconds): void
    {
        $file = \getenv(self::LOG_ENV);

        if (!\is_string($file) || $file === '') {
            return;
        }

        // [0] log, [1] check, [2] assertUnder or assertRegexUnder, [3] the test row.
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $row = ($frames[3]['class'] ?? '?') . '::' . ($frames[3]['function'] ?? '?');

        \file_put_contents($file, \sprintf("%s\t%s\t%.4f\n", $row, $what, $seconds), \FILE_APPEND);
    }
}
