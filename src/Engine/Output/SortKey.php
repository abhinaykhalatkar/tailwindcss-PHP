<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Output;

use TailwindPhp\Engine\Candidate\Variant;

/**
 * Where a generated utility sorts in the utilities layer (design doc 07
 * §Sorting), as the 4.1.18 CLI orders it:
 *
 * 1. **Variants.** v4 compares each candidate's variants as a bit set over
 *    every variant *instance* used, so a utility without variants comes first,
 *    and two sets are compared from their highest instance down; the first set
 *    to run out is the lower one. An instance is a variant's order plus its
 *    detail — the arbitrary text, the value, the modifier — and instances of
 *    one order sort by detail, none first (`[& p]` < `[&.active]` <
 *    `[&:hover]`; `group-hover` < `group-hover/name`). A repeated instance
 *    counts once.
 * 2. **Properties.** The utility's v4 property sort (`CompiledUtility::sortKey`,
 *    ascending `Data\PropertyOrder` indexes, or its row `order`): compared
 *    index by index, the lower index first; when one list is the start of the
 *    other, the **longer** list first (v4 reads a missing index as infinity),
 *    so a utility with no known property sorts last.
 * 3. **Declaration count**, more first.
 * 4. **The raw candidate**, with runs of digits compared as numbers (v4's
 *    `compare()`: `z-2` before `z-10`).
 *
 * Arbitrary breakpoints (`min-[50rem]`, `max-[50rem]`) share their family's
 * place with the named breakpoints and the CLI interleaves them by value:
 * {@see self::of()} moves such a variant between the named breakpoints it
 * falls between when it has the breakpoints' unit (a different unit keeps the
 * registry's place, before the named ones); one equal to a named breakpoint
 * takes that breakpoint's place, so the two interleave by utility.
 *
 * Immutable; {@see self::compare()} is a total order, so one `usort` sorts a
 * layer deterministically.
 */
final class SortKey
{
    /**
     * The registry's named breakpoint orders (`Variant\Registry`): `min-*` from
     * 146 000, `max-*` from 138 000, spaced by the step below.
     */
    private const MIN_NAMED_BASE = 146000;

    private const MAX_NAMED_BASE = 138000;

    /**
     * @var list<array{0: float, 1: string}> Variant instances (order, detail), highest first, each once.
     */
    private $variants;

    /**
     * @var list<int>
     */
    private $properties;

    /**
     * @var int
     */
    private $declarations;

    /**
     * @var string
     */
    private $raw;

    /**
     * @param list<int|float|array{0: int|float, 1: string}> $variants Orders, or (order, detail) instances.
     * @param list<int> $properties Ascending.
     */
    public function __construct(array $variants, array $properties, int $declarations, string $raw)
    {
        $instances = [];

        foreach ($variants as $variant) {
            $instance = \is_array($variant) ? [(float) $variant[0], $variant[1]] : [(float) $variant, ''];
            $instances[$instance[0] . "\0" . $instance[1]] = $instance;
        }

        $instances = \array_values($instances);
        \usort($instances, static function (array $x, array $y): int {
            return self::instance($y, $x);
        });

        $this->variants = $instances;
        $this->properties = $properties;
        $this->declarations = $declarations;
        $this->raw = $raw;
    }

    /**
     * The key of a utility: its variants as written (`Candidate::$variants`)
     * with the orders the applier gave them (`Wrapped::variantOrders()`, same
     * order), its property sort, declaration count and raw text.
     *
     * @param list<Variant> $variants
     * @param list<int> $orders
     * @param list<int> $properties
     * @param array<string, string> $breakpoints The theme's breakpoints, `name => value`.
     */
    public static function of(
        array $variants,
        array $orders,
        array $properties,
        int $declarations,
        string $raw,
        array $breakpoints
    ): self {
        $effective = [];

        foreach ($orders as $index => $order) {
            $variant = $variants[$index] ?? null;
            if ($variant === null) {
                $effective[] = [$order, ''];

                continue;
            }

            $placed = self::breakpointOrder($variant, $order, $breakpoints);
            // A breakpoint placed by its value ties with the named one it equals.
            $effective[] = [$placed, $placed === (float) $order ? self::detail($variant) : ''];
        }

        return new self($effective, $properties, $declarations, $raw);
    }

    /**
     * Negative when $a sorts before $b.
     */
    public static function compare(self $a, self $b): int
    {
        $count = \min(\count($a->variants), \count($b->variants));

        for ($i = 0; $i < $count; ++$i) {
            $order = self::instance($a->variants[$i], $b->variants[$i]);

            if ($order !== 0) {
                return $order;
            }
        }

        if (\count($a->variants) !== \count($b->variants)) {
            return \count($a->variants) <=> \count($b->variants);
        }

        $count = \min(\count($a->properties), \count($b->properties));

        for ($i = 0; $i < $count; ++$i) {
            if ($a->properties[$i] !== $b->properties[$i]) {
                return $a->properties[$i] <=> $b->properties[$i];
            }
        }

        if (\count($a->properties) !== \count($b->properties)) {
            return \count($b->properties) <=> \count($a->properties);
        }

        if ($a->declarations !== $b->declarations) {
            return $b->declarations <=> $a->declarations;
        }

        return self::naturalCompare($a->raw, $b->raw);
    }

    /**
     * v4's `compare()`: byte order, except that runs of digits compare as
     * numbers (`z-2` < `z-10`, `inset-2/12` < `inset-11/12`); equal numbers
     * written differently compare as text, and a prefix sorts first.
     */
    public static function naturalCompare(string $a, string $b): int
    {
        $lengthA = \strlen($a);
        $lengthB = \strlen($b);
        $i = 0;
        $j = 0;

        while ($i < $lengthA && $j < $lengthB) {
            $digitsA = (int) \strspn($a, '0123456789', $i);
            $digitsB = (int) \strspn($b, '0123456789', $j);

            if ($digitsA > 0 && $digitsB > 0) {
                $numberA = (string) \substr($a, $i, $digitsA);
                $numberB = (string) \substr($b, $j, $digitsB);
                $byValue = self::compareDigits($numberA, $numberB);

                if ($byValue !== 0) {
                    return $byValue;
                }

                if ($numberA !== $numberB) {
                    return \strcmp($numberA, $numberB);
                }

                $i += $digitsA;
                $j += $digitsB;

                continue;
            }

            if ($a[$i] !== $b[$j]) {
                return \ord($a[$i]) <=> \ord($b[$j]);
            }

            ++$i;
            ++$j;
        }

        return ($lengthA - $i) <=> ($lengthB - $j);
    }

    /**
     * Two digit strings by numeric value, without converting (any length).
     */
    private static function compareDigits(string $a, string $b): int
    {
        $a = \ltrim($a, '0');
        $b = \ltrim($b, '0');

        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return \strcmp($a, $b) <=> 0;
    }

    /**
     * Two variant instances: by order, then by detail (none first).
     *
     * @param array{0: float, 1: string} $a
     * @param array{0: float, 1: string} $b
     */
    private static function instance(array $a, array $b): int
    {
        return $a[0] === $b[0] ? \strcmp($a[1], $b[1]) : $a[0] <=> $b[0];
    }

    /**
     * What tells two instances of one variant order apart: the arbitrary text
     * (normalised, `& p`) or the value, then the modifier.
     */
    private static function detail(Variant $variant): string
    {
        $detail = $variant->arbitrary ?? $variant->value ?? '';

        return $variant->modifier === null ? $detail : $detail . "\0/" . $variant->modifier->value;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * An arbitrary `min-[…]` / `max-[…]` placed among the named breakpoints of
     * its unit: half a step before the first named breakpoint it does not
     * pass. Any other variant keeps $order.
     *
     * @param array<string, string> $breakpoints
     */
    private static function breakpointOrder(Variant $variant, int $order, array $breakpoints): float
    {
        $isMin = $variant->name === 'min';

        if (($variant->name !== 'min' && $variant->name !== 'max') || $variant->arbitrary === null) {
            return (float) $order;
        }

        $value = self::length($variant->arbitrary);
        $named = [];

        foreach ($breakpoints as $breakpoint) {
            $named[] = self::length($breakpoint);
        }

        $units = [];

        foreach ($named as $length) {
            if ($length !== null) {
                $units[$length[1]] = true;
            }
        }

        // The registry sorts by unit rank first; only the first unit's
        // breakpoints sit at the start of the family.
        $first = \array_key_first($units);

        if ($value === null || $first === null || $value[1] !== $first) {
            return (float) $order;
        }

        $count = \count($breakpoints);
        $step = \max(1, \min(10, \intdiv(999, \max(1, $count))));
        $before = 0;
        $sameUnit = 0;
        $equal = false;

        foreach ($named as $length) {
            if ($length === null || $length[1] !== $first) {
                continue;
            }

            ++$sameUnit;
            $equal = $equal || $length[0] === $value[0];

            if ($isMin ? $length[0] < $value[0] : $length[0] > $value[0]) {
                ++$before;
            }
        }

        // Equal to a named breakpoint: its very place (`min-[40rem]` with `sm`).
        $between = $equal ? 0 : $step / 2;

        if ($isMin) {
            return self::MIN_NAMED_BASE + $before * $step - $between;
        }

        // `max-*` counts down over every breakpoint: the first unit's sit last.
        return self::MAX_NAMED_BASE + ($count - $sameUnit + $before) * $step - $between;
    }

    /**
     * `[number, unit]` of a plain length (`48rem`, `600px`), or null.
     *
     * @return array{0: float, 1: string}|null
     */
    private static function length(string $text): ?array
    {
        $digits = (int) \strspn($text, '0123456789.');

        if ($digits === 0) {
            return null;
        }

        $unit = \strtolower((string) \substr($text, $digits));

        if ($unit !== '' && \strspn($unit, 'abcdefghijklmnopqrstuvwxyz%') !== \strlen($unit)) {
            return null;
        }

        return [(float) \substr($text, 0, $digits), $unit];
    }
}
