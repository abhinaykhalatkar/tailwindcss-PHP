<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\FlexboxGrid;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The FlexboxGrid rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class FlexboxGridTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysFlexboxGridRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['flexbox-grid'];

        self::assertCount(38, $roots);
        self::assertSame($roots, \array_keys(FlexboxGrid::rows()), 'same roots, same (catalogue) order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, FlexboxGrid::rows()[$root]);
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

        foreach (\array_keys(FlexboxGrid::rows()) as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }

    /**
     * Values the generated corpus does not sample, checked one by one against
     * the pinned CLI (TASK-0036): the 4.1 `-safe` and `-last` alignment forms,
     * the keywords each alignment root has or lacks, the `order` literals
     * that never negate, and the growth factors.
     *
     * @test
     *
     * @dataProvider cliValues
     */
    public function valuesTheCorpusDoesNotSampleAgreeWithTheCli(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function cliValues(): array
    {
        return [
            'justify center-safe' => ['justify-center-safe', 'justify-content:safe center'],
            'justify end-safe' => ['justify-end-safe', 'justify-content:safe flex-end'],
            'justify normal' => ['justify-normal', 'justify-content:normal'],
            'justify-items end-safe' => ['justify-items-end-safe', 'justify-items:safe end'],
            'justify-items has no baseline' => ['justify-items-baseline', null],
            'justify-self auto' => ['justify-self-auto', 'justify-self:auto'],
            'items baseline-last' => ['items-baseline-last', 'align-items:last baseline'],
            'items center-safe' => ['items-center-safe', 'align-items:safe center'],
            'self baseline-last' => ['self-baseline-last', 'align-self:last baseline'],
            'self auto' => ['self-auto', 'align-self:auto'],
            'content normal' => ['content-normal', 'align-content:normal'],
            'content start' => ['content-start', 'align-content:flex-start'],
            'content end-safe' => ['content-end-safe', 'align-content:safe flex-end'],
            'place-content end-safe' => ['place-content-end-safe', 'place-content:safe end'],
            'place-items center-safe' => ['place-items-center-safe', 'place-items:safe center'],
            'place-self auto' => ['place-self-auto', 'place-self:auto'],
            'place-self has no baseline' => ['place-self-baseline', null],
            'order first' => ['order-first', 'order:-9999'],
            'order last' => ['order-last', 'order:9999'],
            'order none' => ['order-none', 'order:0'],
            'no negative order keyword' => ['-order-first', null],
            'no negative order none' => ['-order-none', null],
            'negative order number' => ['-order-3', 'order:calc(3 * -1)'],
            'order zero negated' => ['-order-0', 'order:calc(0 * -1)'],
            'no decimal order' => ['order-4.5', null],
            'grow alone' => ['grow', 'flex-grow:1'],
            'grow zero' => ['grow-0', 'flex-grow:0'],
            'grow arbitrary' => ['grow-[2]', 'flex-grow:2'],
            'shrink alone' => ['shrink', 'flex-shrink:1'],
            'no fraction grow' => ['grow-1/2', null],
            'basis theme' => ['basis-sm', 'flex-basis:var(--container-sm)'],
            'basis fraction' => ['basis-1/2', 'flex-basis:calc(1/2 * 100%)'],
            'basis px' => ['basis-px', 'flex-basis:1px'],
            'no negative basis' => ['-basis-1', null],
            'col negative' => ['-col-start-2', 'grid-column-start:calc(2 * -1)'],
            'col zero' => ['col-0', 'grid-column:0'],
            'col full is not a root value' => ['col-full', null],
            'row end auto' => ['row-end-auto', 'grid-row-end:auto'],
            'grid-flow dense' => ['grid-flow-dense', 'grid-auto-flow:dense'],
            'grid-flow row-dense' => ['grid-flow-row-dense', 'grid-auto-flow:row dense'],
            'grid-flow col-dense' => ['grid-flow-col-dense', 'grid-auto-flow:column dense'],
            'auto-cols fr' => ['auto-cols-fr', 'grid-auto-columns:minmax(0, 1fr)'],
            'auto-rows min' => ['auto-rows-min', 'grid-auto-rows:min-content'],
            'gap scale' => ['gap-1.5', 'gap:calc(var(--spacing) * 1.5)'],
            'gap off scale' => ['gap-0.3', null],
            'gap no fraction' => ['gap-1/2', null],
            'gap-x px' => ['gap-x-px', 'column-gap:1px'],
            'no negative gap' => ['-gap-1', null],
            'keyword roots refuse arbitrary values' => ['items-[x]', null],
        ];
    }

    private static function css(string $candidate): ?string
    {
        $registry = Registry::default();
        $compiled = (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes())
        );

        if ($compiled === null) {
            return null;
        }

        $parts = [];

        foreach ($compiled->nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value;
            }
        }

        return \implode(';', $parts);
    }
}
