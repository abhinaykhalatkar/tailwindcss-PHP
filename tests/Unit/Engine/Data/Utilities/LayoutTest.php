<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\Utilities\Layout;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The Layout rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same 60 roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class LayoutTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysLayoutRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['layout'];

        self::assertCount(60, $roots);
        self::assertSame($roots, \array_keys(Layout::rows()), 'same roots, same (catalogue) order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, Layout::rows()[$root]);
        $functional = [];

        foreach ($definition->definitions() as $sub) {
            if ($sub->staticDeclarations() === null && $sub->isFunctional()) {
                $functional[] = $sub;
            }
        }

        self::assertSame($entry['kind'] === 'functional', $definition->isFunctional(), 'kind');

        $theme = [];
        $bare = null;
        $fraction = false;
        $negative = false;
        $arbitrary = false;
        $modifier = null;

        foreach ($functional as $sub) {
            foreach ($sub->theme() as $namespace) {
                $theme[] = '--' . $namespace . '-*';
            }

            $bare = $bare ?? $sub->bare();
            $fraction = $fraction || $sub->fraction();
            $negative = $negative || $sub->negative();
            $arbitrary = $arbitrary || $sub->acceptsArbitrary();
            $modifier = $modifier ?? $sub->modifier();
        }

        // A functional root without a numeric bare kind is keyword-only.
        if ($bare === null && $functional !== []) {
            $bare = 'keyword';
        }

        self::assertSame($entry['theme'], $theme, 'theme');
        self::assertSame($entry['bare'], $bare, 'bare');
        self::assertSame($entry['fraction'], $fraction, 'fraction');
        self::assertSame($entry['negative'], $negative, 'negative');
        self::assertSame($entry['arbitrary'], $arbitrary, 'arbitrary');
        self::assertSame($entry['modifier'], $modifier, 'modifier');

        // A root the inventory counts as valid alone has a static form.
        $alone = \in_array('static', $entry['forms'], true);
        $hasStatic = !$definition->isFunctional();

        foreach ($definition->definitions() as $sub) {
            $hasStatic = $hasStatic || $sub->staticDeclarations() !== null || $sub->defaultValue() !== null;
        }

        self::assertSame($alone, $hasStatic, 'static form');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roots(): array
    {
        $cases = [];

        foreach (\array_keys(Layout::rows()) as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }
}
