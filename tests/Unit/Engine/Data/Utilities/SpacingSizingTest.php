<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\SpacingSizing;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The SpacingSizing rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class SpacingSizingTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysSpacingSizingRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['spacing-sizing'];

        self::assertCount(29, $roots);
        self::assertSame($roots, \array_keys(SpacingSizing::rows()), 'same roots, same (catalogue) order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, SpacingSizing::rows()[$root]);
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

        foreach (\array_keys(SpacingSizing::rows()) as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }

    /**
     * Values the generated corpus does not sample, checked one by one against
     * the pinned CLI (TASK-0036): which keywords each sizing root takes, the
     * viewport units per axis, the negative margins, the spacing scale.
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
            'padding scale' => ['p-0.25', 'padding:calc(var(--spacing) * 0.25)'],
            'padding off scale' => ['p-0.3', null],
            'padding px' => ['pt-px', 'padding-top:1px'],
            'padding has no auto' => ['p-auto', null],
            'padding has no fraction' => ['p-1/2', null],
            'padding is never negative' => ['-p-1', null],
            'padding logical' => ['ps-4', 'padding-inline-start:calc(var(--spacing) * 4)'],
            'margin auto' => ['m-auto', 'margin:auto'],
            'margin negative px' => ['-m-px', 'margin:-1px'],
            'margin negative scale' => ['-m-0.5', 'margin:calc(var(--spacing) * -0.5)'],
            'margin negative auto refused' => ['-mx-auto', null],
            'margin logical' => ['me-px', 'margin-inline-end:1px'],
            'width screen' => ['w-screen', 'width:100vw'],
            'width viewport units' => ['w-svh', 'width:100svh'],
            'width fraction' => ['w-3/4', 'width:calc(3/4 * 100%)'],
            'width container' => ['w-7xl', 'width:var(--container-7xl)'],
            'width has no none' => ['w-none', null],
            'width has no lh' => ['w-lh', null],
            'width has no prose' => ['w-prose', null],
            'no negative width' => ['-w-4', null],
            'min-width auto' => ['min-w-auto', 'min-width:auto'],
            'min-width has no none' => ['min-w-none', null],
            'max-width none' => ['max-w-none', 'max-width:none'],
            'max-width has no auto' => ['max-w-auto', null],
            'max-width prose' => ['max-w-prose', 'max-width:65ch'],
            // TASK-0042: a theme breakpoint behind `screen-`, for `max-w` only.
            'max-width of a breakpoint' => ['max-w-screen-md', 'max-width:var(--breakpoint-md)'],
            'max-width of the first breakpoint' => ['max-w-screen-sm', 'max-width:var(--breakpoint-sm)'],
            'max-width of the last breakpoint' => ['max-w-screen-2xl', 'max-width:var(--breakpoint-2xl)'],
            'max-width of an unknown breakpoint' => ['max-w-screen-3xl', null],
            'max-width of a breakpoint name that is no key' => ['max-w-screen-foo', null],
            'a bare screen prefix' => ['max-w-screen-', null],
            'no negative breakpoint width' => ['-max-w-screen-md', null],
            'min-width has no breakpoints' => ['min-w-screen-md', null],
            'width has no breakpoints' => ['w-screen-md', null],
            'height has no breakpoints' => ['max-h-screen-md', null],
            'a breakpoint is not an arbitrary value' => ['max-w-screen-[3px]', null],
            'max-width screen' => ['max-w-screen', 'max-width:100vw'],
            'height screen' => ['h-screen', 'height:100vh'],
            'height lh' => ['h-lh', 'height:1lh'],
            'height has no container name' => ['h-sm', null],
            'height fraction' => ['h-1/2', 'height:calc(1/2 * 100%)'],
            'min-height lh' => ['min-h-lh', 'min-height:1lh'],
            'max-height none' => ['max-h-none', 'max-height:none'],
            'max-height lh' => ['max-h-lh', 'max-height:1lh'],
            'max-height has no auto' => ['max-h-auto', null],
            'size pair' => ['size-4', 'width:calc(var(--spacing) * 4);height:calc(var(--spacing) * 4)'],
            'size viewport unit' => ['size-svw', 'width:100svw;height:100svw'],
            'size has no screen' => ['size-screen', null],
            'size has no lh' => ['size-lh', null],
            'size has no container name' => ['size-sm', null],
            'size fit' => ['size-fit', 'width:fit-content;height:fit-content'],
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
