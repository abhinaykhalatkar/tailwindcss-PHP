<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Support\Regex;

/**
 * Brace expansion for `@source inline(...)` arguments (design doc 09
 * §Per-file cache, design decision CSF-A11).
 *
 * `{hover:,}bg-red-{500,600}` → 4 results; `{1..3}` → `1`, `2`, `3`; braces
 * nest. Simplified relative to a shell's brace expansion in one place: a
 * group with no comma and no `..` range (`{solo}`) still expands to its one
 * alternative with the braces removed, rather than being left as a literal
 * `{solo}` — there is no committed spec for that edge case here, and treating
 * every `{...}` uniformly as a group keeps the algorithm a single recursive
 * rule instead of two.
 *
 * Two bounded passes (design decision CSF-A11, corrected in review round 1):
 * {@see self::countExpansions()} walks the same recursive structure as
 * {@see self::generate()} but only ever does integer arithmetic — a numeric
 * range's size is computed from its endpoints, never by building the range —
 * so a pathological pattern (`{1..3000000}`, 20 000 levels of nesting) is
 * rejected before a single string is allocated. `generate()` only ever runs
 * once the count pass has proven the result is small, so it never needs its
 * own bound. The same recursion-depth counter closes two different blow-up
 * shapes at once: true nesting (`{{{…}}}`) and a long sequential chain of
 * single-alternative groups (`{a}{a}{a}…`) — both cost one call stack frame
 * per level regardless of which shape produced it.
 *
 * M1 implements and unit-tests this class only; wiring it into the pipeline
 * (`inline_sources`, extra scan roots from `@source`) is FR-SCAN-7, which
 * lands in M2 (design decision CCP-A12).
 */
final class BraceExpander
{
    /**
     * Beyond this many results the directive is rejected outright rather than
     * silently truncated (design decision CSF-A11): a project author who
     * wrote a pattern this large almost certainly made a mistake, and
     * truncating would hide it.
     */
    private const MAX_EXPANSIONS = 1000;

    /**
     * Recursion-depth bound (review round 1): protects the PHP call stack
     * from both true brace nesting and a long chain of sequential groups,
     * either of which recurses once per level regardless of how few results
     * each level itself produces.
     */
    private const MAX_NESTING_DEPTH = 16;

    private function __construct()
    {
    }

    /**
     * @return list<string>
     *
     * @throws ScanLimitException When expansion would exceed self::MAX_EXPANSIONS
     *                            results or self::MAX_NESTING_DEPTH levels.
     */
    public static function expand(string $pattern): array
    {
        // Validates bounds in O(pattern length) time and no more than a
        // handful of small substrings of memory, regardless of how large the
        // *would-be* expansion is — see the class docblock.
        self::countExpansions($pattern, 0);

        return self::generate($pattern, 0);
    }

    /**
     * @throws ScanLimitException
     */
    private static function countExpansions(string $pattern, int $depth): int
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new ScanLimitException('inline_expansions_nesting', self::MAX_NESTING_DEPTH);
        }

        $openPosition = \strpos($pattern, '{');

        if ($openPosition === false) {
            return 1;
        }

        $closePosition = self::findMatchingBrace($pattern, $openPosition);

        if ($closePosition === null) {
            return 1;
        }

        $body = \substr($pattern, $openPosition + 1, $closePosition - $openPosition - 1);
        $suffix = \substr($pattern, $closePosition + 1);

        if (self::isRange($body)) {
            $groupCount = self::rangeCount($body);

            if ($groupCount > self::MAX_EXPANSIONS) {
                throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
            }
        } else {
            $groupCount = 0;

            foreach (self::splitTopLevelPositions($body) as [$from, $to]) {
                $groupCount += self::countExpansions(\substr($body, $from, $to - $from), $depth + 1);

                if ($groupCount > self::MAX_EXPANSIONS) {
                    throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
                }
            }
        }

        $total = $groupCount * self::countExpansions($suffix, $depth + 1);

        if ($total > self::MAX_EXPANSIONS) {
            throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
        }

        return $total;
    }

    /**
     * Structurally identical to {@see self::countExpansions()}; only ever
     * called after it has proven the result is bounded, so it does not
     * repeat the bound checks.
     *
     * @return list<string>
     */
    private static function generate(string $pattern, int $depth): array
    {
        $openPosition = \strpos($pattern, '{');

        if ($openPosition === false) {
            return [$pattern];
        }

        $closePosition = self::findMatchingBrace($pattern, $openPosition);

        if ($closePosition === null) {
            return [$pattern];
        }

        $prefix = \substr($pattern, 0, $openPosition);
        $body = \substr($pattern, $openPosition + 1, $closePosition - $openPosition - 1);
        $suffix = \substr($pattern, $closePosition + 1);

        $groupExpansions = [];

        if (self::isRange($body)) {
            foreach (self::expandRangeValues($body) as $value) {
                $groupExpansions[] = $value;
            }
        } else {
            foreach (self::splitTopLevelPositions($body) as [$from, $to]) {
                foreach (self::generate(\substr($body, $from, $to - $from), $depth + 1) as $expanded) {
                    $groupExpansions[] = $expanded;
                }
            }
        }

        $suffixExpansions = self::generate($suffix, $depth + 1);
        $results = [];

        foreach ($groupExpansions as $group) {
            foreach ($suffixExpansions as $tail) {
                $results[] = $prefix . $group . $tail;
            }
        }

        return $results;
    }

    private static function findMatchingBrace(string $pattern, int $openPosition): ?int
    {
        $depth = 0;
        $length = \strlen($pattern);

        for ($i = $openPosition; $i < $length; ++$i) {
            if ($pattern[$i] === '{') {
                ++$depth;
            } elseif ($pattern[$i] === '}') {
                --$depth;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function isRange(string $body): bool
    {
        return Regex::match('/^-?[0-9]+\.\.-?[0-9]+$/D', $body);
    }

    /**
     * The size of a `{a..b}` range without building it (review round 1):
     * `{1..3000000}` must be rejected in O(1) time and memory.
     */
    private static function rangeCount(string $body): int
    {
        $parts = \explode('..', $body, 2);

        // Computed as float, deliberately. `{0..9223372036854775807}` and
        // `{-9223372036854775808..0}` overflow `int` subtraction; PHP promotes
        // the result to float, which then fails this method's `int` return type
        // with a TypeError rather than the ScanLimitException the caller is
        // prepared for. `@source inline(...)` is author input, so a hostile
        // range has to produce the documented limit error, not a fatal.
        // Each endpoint must be a literal that survives a round trip through
        // `int` and sits strictly inside the integer range. Round 3 found that
        // the float span alone is not enough: within ~1 000 of 2^63 the
        // subtraction collapses to 1.0 through loss of precision, so
        // `{9223372036854775806..9223372036854775807}` looked like a two-element
        // range, and `expandRangeValues()` then counted in `int` until `++$n`
        // overflowed to float and `<=` stayed true forever — memory exhausted,
        // a fatal rather than the documented limit error.
        //
        // The strict bound (not `PHP_INT_MAX` itself) is what keeps the counter
        // below from ever reaching a value it cannot increment.
        if (!self::isSafeEndpoint($parts[0]) || !self::isSafeEndpoint($parts[1])) {
            throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
        }

        // The float span is a *coarse gate* only. It cannot be the answer: near
        // 2^63 a real span of 8 collapses to 1.0 through loss of precision, and
        // returning that silently truncated the range to a single value — which
        // it did, until this probe caught it:
        //   {9223372036854775000..9223372036854775007} -> 1 value, expected 8.
        // Its job is just to reject the astronomically large before the exact
        // subtraction below, which is only safe once the operands are close.
        $coarse = \abs(((float) $parts[1]) - ((float) $parts[0])) + 1.0;

        if ($coarse > (float) self::MAX_EXPANSIONS) {
            throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
        }

        // Exact, and safe from overflow: the gate above bounds the true
        // difference to roughly the cap plus one float ulp at this magnitude, so
        // the operands are near each other whatever their absolute size.
        $span = \abs(((int) $parts[1]) - ((int) $parts[0])) + 1;

        if ($span > self::MAX_EXPANSIONS) {
            throw new ScanLimitException('inline_expansions', self::MAX_EXPANSIONS);
        }

        return $span;
    }

    /**
     * Whether a range endpoint is an integer literal we can count with.
     *
     * `(string) (int) $s === $s` after normalising a leading `+` and redundant
     * zeros: a literal that does not round-trip either overflowed the cast
     * (`{99999999999999999999..…}` clamps silently to `PHP_INT_MAX`) or was
     * never an integer. Both are rejected rather than silently reinterpreted.
     */
    private static function isSafeEndpoint(string $literal): bool
    {
        $trimmed = $literal;

        if ($trimmed === '' || $trimmed === '-' || $trimmed === '+') {
            return false;
        }

        $negative = $trimmed[0] === '-';

        if ($negative || $trimmed[0] === '+') {
            $trimmed = \substr($trimmed, 1);
        }

        if ($trimmed === '' || \ltrim($trimmed, '0123456789') !== '') {
            return false;
        }

        // Strip leading zeros so `007` round-trips as `7`, but keep one digit.
        $digits = \ltrim($trimmed, '0');
        $digits = $digits === '' ? '0' : $digits;
        $normalised = ($negative && $digits !== '0' ? '-' : '') . $digits;

        if ((string) (int) $normalised !== $normalised) {
            return false;
        }

        $value = (int) $normalised;

        return $value > \PHP_INT_MIN + 1 && $value < \PHP_INT_MAX - 1;
    }

    /**
     * @return list<string>
     */
    private static function expandRangeValues(string $body): array
    {
        $parts = \explode('..', $body, 2);
        $from = (int) $parts[0];
        $to = (int) $parts[1];
        $values = [];

        // Counted, never compared against the running value. `$n <= $to` is
        // unfalsifiable once `++$n` overflows into a float — the loop then runs
        // until memory is exhausted, which is how a near-`PHP_INT_MAX` range
        // became a fatal instead of a limit error. `rangeCount()` has already
        // rejected anything that cannot be counted, so this bound is exact and
        // the increment can never reach the overflow edge.
        $remaining = self::rangeCount($body);
        $step = $from <= $to ? 1 : -1;
        $n = $from;

        for ($i = 0; $i < $remaining; ++$i) {
            $values[] = (string) $n;
            $n += $step;
        }

        return $values;
    }

    /**
     * Split on commas at brace depth zero only, so `{a,{b,c}}` splits into
     * `a` and `{b,c}`, not `a`, `{b`, `c}`. Returns byte offsets into $body
     * rather than copied substrings (review round 1: avoid rebuilding a
     * string once per nesting level).
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function splitTopLevelPositions(string $body): array
    {
        $positions = [];
        $depth = 0;
        $start = 0;
        $length = \strlen($body);

        for ($i = 0; $i < $length; ++$i) {
            $char = $body[$i];

            if ($char === '{') {
                ++$depth;
            } elseif ($char === '}') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $positions[] = [$start, $i];
                $start = $i + 1;
            }
        }

        $positions[] = [$start, $length];

        return $positions;
    }
}
