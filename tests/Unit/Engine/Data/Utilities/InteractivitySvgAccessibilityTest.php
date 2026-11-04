<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\InteractivitySvgAccessibility;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The InteractivitySvgAccessibility rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same 49 roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class InteractivitySvgAccessibilityTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysInteractivitySvgAccessibilityRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['interactivity-svg-accessibility'];

        self::assertCount(49, $roots);
        self::assertSame($roots, \array_keys(InteractivitySvgAccessibility::rows()), 'same roots, same order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, InteractivitySvgAccessibility::rows()[$root]);
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

        foreach (\array_keys(InteractivitySvgAccessibility::rows()) as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }

    /**
     * Values the generated corpus does not sample, checked one by one against
     * the pinned CLI (TASK-0039).
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
            'cursor keyword' => ['cursor-zoom-in', 'cursor:zoom-in'],
            'cursor arbitrary' => ['cursor-[url(x.png),_auto]', 'cursor:url(x.png), auto'],
            'cursor variable' => ['cursor-(--c)', 'cursor:var(--c)'],
            'cursor unknown keyword' => ['cursor-foo', null],
            'pointer events refuse arbitrary values' => ['pointer-events-[x]', null],
            'pointer events unknown keyword' => ['pointer-events-all', null],
            'select writes both properties' => ['select-all', '-webkit-user-select:all;user-select:all'],
            'select unknown keyword' => ['select-contain', null],
            'appearance unknown keyword' => ['appearance-menulist', null],
            'resize alone' => ['resize', 'resize:both'],
            'resize x' => ['resize-x', 'resize:horizontal'],
            'resize y' => ['resize-y', 'resize:vertical'],
            'resize none' => ['resize-none', 'resize:none'],
            'resize spelled out is refused' => ['resize-horizontal', null],
            'scroll margin' => ['scroll-ms-4', 'scroll-margin-inline-start:calc(var(--spacing) * 4)'],
            'negative scroll margin' => ['-scroll-ms-4', 'scroll-margin-inline-start:calc(var(--spacing) * -4)'],
            'negative scroll margin px' => ['-scroll-mx-px', 'scroll-margin-inline:-1px'],
            'scroll margin arbitrary' => ['scroll-mt-[3px]', 'scroll-margin-top:3px'],
            'scroll padding px' => ['scroll-p-px', 'scroll-padding:1px'],
            'scroll padding is never negative' => ['-scroll-p-4', null],
            'scroll margin has no auto' => ['scroll-m-auto', null],
            'scroll behaviour' => ['scroll-smooth', 'scroll-behavior:smooth'],
            'snap type composes the strictness' => [
                'snap-x',
                'scroll-snap-type:x var(--tw-scroll-snap-strictness)',
            ],
            'snap both' => ['snap-both', 'scroll-snap-type:both var(--tw-scroll-snap-strictness)'],
            'snap none' => ['snap-none', 'scroll-snap-type:none'],
            'snap strictness' => ['snap-mandatory', '--tw-scroll-snap-strictness:mandatory'],
            'snap alignment' => ['snap-center', 'scroll-snap-align:center'],
            'snap alignment none' => ['snap-align-none', 'scroll-snap-align:none'],
            'snap stop' => ['snap-always', 'scroll-snap-stop:always'],
            'snap takes no value' => ['snap-foo', null],
            'touch action' => ['touch-manipulation', 'touch-action:manipulation'],
            'touch pan composes the three variables' => [
                'touch-pan-left',
                '--tw-pan-x:pan-left;touch-action:var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)',
            ],
            'touch pan on the other axis' => [
                'touch-pan-down',
                '--tw-pan-y:pan-down;touch-action:var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)',
            ],
            'touch pinch zoom' => [
                'touch-pinch-zoom',
                '--tw-pinch-zoom:pinch-zoom;touch-action:var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)',
            ],
            'touch takes no value' => ['touch-pan-foo', null],
            'fill colour' => ['fill-red-500', 'fill:var(--color-red-500)'],
            'fill keyword' => ['fill-current', 'fill:currentcolor'],
            'fill none' => ['fill-none', 'fill:none'],
            'fill variable' => ['fill-(--c)', 'fill:var(--c)'],
            'fill arbitrary colour' => ['fill-[red]', 'fill:red'],
            'fill url' => ['fill-[url(#a)]', 'fill:url(#a)'],
            'fill takes a variable in brackets' => ['fill-[var(--x)]', 'fill:var(--x)'],
            'fill takes any keyword' => ['fill-[context-fill]', 'fill:context-fill'],
            'fill takes inherit in brackets' => ['fill-[inherit]', 'fill:inherit'],
            'fill takes a calc' => ['fill-[calc(1px)]', 'fill:calc(1px)'],
            'fill takes a length by hint' => ['fill-[length:1px]', 'fill:1px'],
            'fill takes a variable by length hint' => ['fill-(length:--x)', 'fill:var(--x)'],
            'a bracketed variable takes an opacity' => [
                'fill-[var(--x)]/50',
                'fill:var(--x);@supports (color: color-mix(in lab, red, red)){'
                . 'fill:color-mix(in oklab, var(--x) 50%, transparent)}',
            ],
            'stroke takes a variable in brackets' => ['stroke-[var(--x)]', 'stroke:var(--x)'],
            'stroke takes any keyword' => ['stroke-[context-stroke]', 'stroke:context-stroke'],
            'stroke takes inherit in brackets' => ['stroke-[inherit]', 'stroke:inherit'],
            'stroke reads a calc as the width' => ['stroke-[calc(1px)]', 'stroke-width:calc(1px)'],
            'stroke reads a length as the width' => ['stroke-[2px]', 'stroke-width:2px'],
            'a bracketed stroke variable takes an opacity' => [
                'stroke-[var(--x)]/50',
                'stroke:var(--x);@supports (color: color-mix(in lab, red, red)){'
                . 'stroke:color-mix(in oklab, var(--x) 50%, transparent)}',
            ],
            'fill with an opacity' => [
                'fill-red-500/50',
                'fill:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){'
                . 'fill:color-mix(in oklab, var(--color-red-500) 50%, transparent)}',
            ],
            'none takes no opacity' => ['fill-none/50', null],
            'stroke colour' => ['stroke-red-500', 'stroke:var(--color-red-500)'],
            'stroke variable is the colour' => ['stroke-(--c)', 'stroke:var(--c)'],
            'stroke none' => ['stroke-none', 'stroke:none'],
            'stroke width' => ['stroke-2', 'stroke-width:2'],
            'stroke width zero' => ['stroke-0', 'stroke-width:0'],
            'stroke width from a number' => ['stroke-[3]', 'stroke-width:3'],
            'stroke width from a length' => ['stroke-[2px]', 'stroke-width:2px'],
            'stroke width by hint' => ['stroke-(length:--w)', 'stroke-width:var(--w)'],
            'stroke width from a percentage' => ['stroke-[percentage:3%]', 'stroke-width:3%'],
            'stroke colour by keyword' => ['stroke-[red]', 'stroke:red'],
            'stroke width is whole numbers' => ['stroke-1.5', null],
            'stroke is never negative' => ['-stroke-2', null],
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

        return $compiled === null ? null : self::render($compiled->nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value;
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::render($node->nodes) . '}';
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            }
        }

        return \implode(';', $parts);
    }
}
