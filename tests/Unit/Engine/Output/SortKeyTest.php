<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Output;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Variant;
use TailwindPhp\Engine\Output\SortKey;

/**
 * The utilities layer's order (TASK-0034 criterion 3), one rule per row, each
 * taken from what the 4.1.18 CLI printed (`golden/variants/*`, `golden/directives/*`
 * and the probes in the report).
 */
final class SortKeyTest extends TestCase
{
    private const BREAKPOINTS = ['sm' => '40rem', 'md' => '48rem', 'lg' => '64rem', 'xl' => '80rem', '2xl' => '96rem'];

    /** @test */
    public function aUtilityWithoutVariantsComesFirst(): void
    {
        self::assertLessThan(0, SortKey::compare(self::key([], [200]), self::key([112000], [10])));
    }

    /**
     * v4 compares variant sets from the highest order down; the set that runs
     * out first is the lower one (`md:x` < `md:hover:x`).
     *
     * @test
     */
    public function variantSetsCompareFromTheHighestOrderDown(): void
    {
        $md = self::key([146010], [10]);
        $hover = self::key([112000], [10]);
        $mdHover = self::key([146010, 112000], [10], 'a');
        $hoverMd = self::key([112000, 146010], [10], 'b');

        self::assertLessThan(0, SortKey::compare($hover, $md));
        self::assertLessThan(0, SortKey::compare($md, $mdHover));
        // The written order does not matter: the same set ties on variants.
        self::assertLessThan(0, SortKey::compare($mdHover, $hoverMd), 'tie broken by raw text');
    }

    /** @test */
    public function aRepeatedVariantCountsOnce(): void
    {
        self::assertSame(0, SortKey::compare(self::key([112000, 112000], [10], 'a'), self::key([112000], [10], 'a')));
    }

    /**
     * @test
     */
    public function propertiesCompareIndexByIndexAndTheLongerListWins(): void
    {
        self::assertLessThan(0, SortKey::compare(self::key([], [5, 90]), self::key([], [6])));
        // `[5, 7]` before `[5]`: v4 reads the missing index as infinity.
        self::assertLessThan(0, SortKey::compare(self::key([], [5, 7]), self::key([], [5])));
        // No known property at all sorts last (`divide-x-reverse`).
        self::assertLessThan(0, SortKey::compare(self::key([], [999]), self::key([], [])));
    }

    /**
     * Equal keys: more declarations first (`from-red-500` before `from-10%`),
     * then the raw text.
     *
     * @test
     */
    public function tiesGoToMoreDeclarationsThenToTheRawText(): void
    {
        self::assertLessThan(0, SortKey::compare(
            new SortKey([], [185], 2, 'from-red-500'),
            new SortKey([], [185], 1, 'from-10%')
        ));
        self::assertLessThan(0, SortKey::compare(
            new SortKey([], [171], 1, 'bg-blue-500'),
            new SortKey([], [171], 1, 'bg-red-500/50')
        ));
    }

    /**
     * The raw tie-break reads digit runs as numbers, as v4's `compare()`
     * (`golden/utilities/layout.css`: `z-2` before `z-10`, `inset-2/12`
     * before `inset-11/12`).
     *
     * @test
     */
    public function theRawTieBreakComparesNumbersByValue(): void
    {
        self::assertLessThan(0, SortKey::naturalCompare('z-2', 'z-10'));
        self::assertLessThan(0, SortKey::naturalCompare('inset-2/12', 'inset-11/12'));
        self::assertLessThan(0, SortKey::naturalCompare('flex-1', 'flex-1/2'), 'a prefix first');
        self::assertLessThan(0, SortKey::naturalCompare('p-01', 'p-1'), 'equal value: as text');
        self::assertLessThan(0, SortKey::naturalCompare('p-9', 'p-a'));
        self::assertSame(0, SortKey::naturalCompare('top-4', 'top-4'));
        self::assertGreaterThan(0, SortKey::naturalCompare('z-' . \str_repeat('9', 30), 'z-' . \str_repeat('9', 29)));
    }

    /**
     * Instances of one variant order sort by their detail, none first
     * (`golden/variants/arbitrary.css`, `group-peer.css`).
     *
     * @test
     */
    public function instancesOfOneOrderSortByTheirDetail(): void
    {
        $descendant = SortKey::of([self::arbitrary('& p')], [171000], [10], 1, '[&_p]:flex', []);
        $active = SortKey::of([self::arbitrary('&.active')], [171000], [10], 1, '[&.active]:flex', []);
        $hover = SortKey::of([self::arbitrary('&:hover')], [171000], [10], 1, '[&:hover]:flex', []);
        $group = SortKey::of([self::variant('group', 'hover')], [4112], [10], 1, 'group-hover:p-4', []);
        $named = SortKey::of([self::variant('group', 'hover', 'name')], [4112], [5], 1, 'group-hover/name:bg', []);

        self::assertLessThan(0, SortKey::compare($descendant, $active));
        self::assertLessThan(0, SortKey::compare($active, $hover));
        self::assertLessThan(0, SortKey::compare($group, $named), 'unnamed first, whatever the properties');
    }

    /**
     * `min-[50rem]` falls between `md` (48rem) and `lg` (64rem); one equal to a
     * named breakpoint ties with it, so they interleave by utility
     * (`golden/variants/responsive.css`); another unit keeps the registry's
     * place before the named ones.
     *
     * @test
     */
    public function arbitraryBreakpointsInterleaveWithTheNamedOnesByValue(): void
    {
        $md = SortKey::of([self::variant('md')], [146010], [10], 1, 'md:flex', self::BREAKPOINTS);
        $lg = SortKey::of([self::variant('lg')], [146020], [10], 1, 'lg:flex', self::BREAKPOINTS);
        $sm = SortKey::of([self::variant('sm')], [146000], [20], 1, 'sm:p-4', self::BREAKPOINTS);
        $between = self::breakpoint('min', '50rem', 144000);
        $equal = self::breakpoint('min', '40rem', 144000);
        $pixels = self::breakpoint('min', '400px', 144000);

        self::assertLessThan(0, SortKey::compare($md, $between));
        self::assertLessThan(0, SortKey::compare($between, $lg));
        self::assertLessThan(0, SortKey::compare($equal, $sm), 'same place as sm: flex (10) before p-4 (20)');
        // And the other way round: a tie, not "just before sm".
        $smFlex = SortKey::of([self::variant('sm')], [146000], [10], 1, 'sm:flex', self::BREAKPOINTS);
        $equalPadding = SortKey::of(
            [self::arbitrary('40rem', 'min')],
            [144000],
            [20],
            1,
            'min-[40rem]:p-4',
            self::BREAKPOINTS
        );
        self::assertLessThan(0, SortKey::compare($smFlex, $equalPadding));
        self::assertLessThan(0, SortKey::compare($pixels, $equal));
    }

    /**
     * `max-*` counts down: `max-[50rem]` sits between `max-lg` and `max-md`.
     *
     * @test
     */
    public function arbitraryMaxBreakpointsCountDown(): void
    {
        $maxLg = SortKey::of([self::variant('max-lg')], [138020], [10], 1, 'max-lg:flex', self::BREAKPOINTS);
        $maxMd = SortKey::of([self::variant('max-md')], [138030], [10], 1, 'max-md:flex', self::BREAKPOINTS);
        $between = self::breakpoint('max', '50rem', 137000);

        self::assertLessThan(0, SortKey::compare($maxLg, $between));
        self::assertLessThan(0, SortKey::compare($between, $maxMd));
    }

    /** @test */
    public function oneUsortGivesATotalOrder(): void
    {
        $keys = [
            self::key([112000], [10], 'hover:flex'),
            self::key([], [], 'divide-x-reverse'),
            self::key([], [10], 'flex'),
            self::key([], [10, 20], 'grid'),
        ];

        \usort($keys, [SortKey::class, 'compare']);

        $raws = \array_map(static function (SortKey $key): string {
            return $key->raw();
        }, $keys);

        self::assertSame(['grid', 'flex', 'divide-x-reverse', 'hover:flex'], $raws);
    }

    /**
     * @param list<int> $variants
     * @param list<int> $properties
     */
    private static function key(array $variants, array $properties, string $raw = 'x'): SortKey
    {
        return new SortKey($variants, $properties, 1, $raw);
    }

    private static function variant(string $name, ?string $value = null, ?string $modifier = null): Variant
    {
        $raw = $name . ($value === null ? '' : '-' . $value) . ($modifier === null ? '' : '/' . $modifier);

        $modifier = $modifier === null ? null : new Modifier(Modifier::NAMED, $modifier);

        return new Variant($name, $value, null, $raw, $modifier);
    }

    /**
     * `min-[…]` / `max-[…]` on `flex` with the registry's order for it.
     */
    private static function breakpoint(string $name, string $value, int $order): SortKey
    {
        $variant = self::arbitrary($value, $name);

        return SortKey::of([$variant], [$order], [10], 1, $name . '-[' . $value . ']:flex', self::BREAKPOINTS);
    }

    private static function arbitrary(string $text, string $name = ''): Variant
    {
        return new Variant($name, null, $text, $name . '[' . $text . ']');
    }
}
