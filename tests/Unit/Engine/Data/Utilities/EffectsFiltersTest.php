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
use TailwindPhp\Engine\Data\Utilities\EffectsFilters;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The EffectsFilters rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same 5 roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class EffectsFiltersTest extends TestCase
{
    private const BOX = ';box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
        . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)';

    /** @test */
    public function theRowsAreTheInventorysEffectsFiltersRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['effects-filters'];

        self::assertCount(5, $roots);
        self::assertSame($roots, \array_keys(EffectsFilters::rows()), 'same roots, same (catalogue) order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, EffectsFilters::rows()[$root]);
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

        foreach (\array_keys(EffectsFilters::rows()) as $root) {
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
            'opacity has no unit' => ['opacity-0.5', 'opacity:0.5%'],
            'opacity from a number' => ['opacity-101', 'opacity:101%'],
            'opacity takes no modifier' => ['opacity-50/50', null],
            'opacity is never negative' => ['-opacity-5', null],
            'a blend mode' => ['mix-blend-plus-lighter', 'mix-blend-mode:plus-lighter'],
            'a blend mode the background lacks' => ['bg-blend-plus-lighter', null],
            'blend modes refuse arbitrary values' => ['mix-blend-[x]', null],
            'a shadow of one token is used as written' => [
                'shadow-[length:3px]',
                '--tw-shadow:3px;box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a shadow without a colour gets currentcolor' => [
                'shadow-[0_0_3px]',
                '--tw-shadow:0 0 3px var(--tw-shadow-color, currentcolor);box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            // v4's replaceShadowColors (TASK-0042): a shadow is rewritten only when two offsets are found.
            'one offset leaves the shadow alone' => ['shadow-[1px_red]', '--tw-shadow:1px red' . self::BOX],
            'one offset leaves an inset shadow alone' => [
                'inset-shadow-[1px_red]',
                '--tw-inset-shadow:inset 1px red' . self::BOX,
            ],
            'one offset and a variable' => ['shadow-[1px_var(--x)]', '--tw-shadow:1px var(--x)' . self::BOX],
            'a variable and a colour have no offsets' => [
                'shadow-[var(--a)_red]',
                '--tw-shadow:var(--a) red' . self::BOX,
            ],
            'a plus sign is no offset' => ['shadow-[+1px_1px_red]', '--tw-shadow:+1px 1px red' . self::BOX],
            'a calc is the colour when two offsets precede it' => [
                'shadow-[0_0_calc(1px)_red]',
                '--tw-shadow:0 0 var(--tw-shadow-color, calc(1px)) red' . self::BOX,
            ],
            'calc offsets are no offsets' => [
                'shadow-[calc(1px)_calc(1px)_red]',
                '--tw-shadow:calc(1px) calc(1px) red' . self::BOX,
            ],
            'inherit is skipped like inset' => [
                'shadow-[0_0_1px_inherit]',
                '--tw-shadow:0 0 1px inherit var(--tw-shadow-color, currentcolor)' . self::BOX,
            ],
            'initial is skipped' => [
                'shadow-[0px_0px_1px_initial]',
                '--tw-shadow:0px 0px 1px initial var(--tw-shadow-color, currentcolor)' . self::BOX,
            ],
            'revert and unset are skipped' => [
                'shadow-[0_0_1px_revert]',
                '--tw-shadow:0 0 1px revert var(--tw-shadow-color, currentcolor)' . self::BOX,
            ],
            'a keyword in capitals is a colour' => [
                'shadow-[1px_1px_Inherit]',
                '--tw-shadow:1px 1px var(--tw-shadow-color, Inherit)' . self::BOX,
            ],
            'a colour before the offsets is replaced in place' => [
                'shadow-[red_0_0]',
                '--tw-shadow:var(--tw-shadow-color, red) 0 0' . self::BOX,
            ],
            'an offset is a number with anything after it' => [
                'shadow-[1._1._red]',
                '--tw-shadow:1. 1. var(--tw-shadow-color, red)' . self::BOX,
            ],
            'a decimal and a negative are offsets' => [
                'shadow-[-.5px_.5px_red]',
                '--tw-shadow:-.5px .5px var(--tw-shadow-color, red)' . self::BOX,
            ],
            'only the shadows with offsets of a list are recoloured' => [
                'shadow-[0_0_#fff,1px_red]',
                '--tw-shadow:0 0 var(--tw-shadow-color, #fff), 1px red' . self::BOX,
            ],
            'every shadow of a list is recoloured' => [
                'shadow-[0_0_10px_red,0_0_5px_blue]',
                '--tw-shadow:0 0 10px var(--tw-shadow-color, red), 0 0 5px var(--tw-shadow-color, blue);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a variable colour is recoloured' => [
                'shadow-[0_0_10px_var(--c)]',
                '--tw-shadow:0 0 10px var(--tw-shadow-color, var(--c));box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a colour keyword is a colour' => [
                'shadow-[red]',
                '--tw-shadow-color:red;@supports (color: color-mix(in lab, red, red)){'
                . '--tw-shadow-color:color-mix(in oklab, red var(--tw-shadow-alpha), transparent)}',
            ],
            'a colour hint with an opacity' => [
                'shadow-[color:red]/50',
                '--tw-shadow-color:color-mix(in srgb, red 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){'
                . '--tw-shadow-color:color-mix(in oklab, color-mix(in oklab, red 50%, transparent) '
                . 'var(--tw-shadow-alpha), transparent)}',
            ],
            'a theme colour with a variable opacity keeps the plain fallback' => [
                'shadow-red-500/(--o)',
                '--tw-shadow-color:oklch(63.7% 0.237 25.331);@supports (color: color-mix(in lab, red, red)){'
                . '--tw-shadow-color:color-mix(in oklab, '
                . 'color-mix(in oklab, var(--color-red-500) var(--o), transparent) '
                . 'var(--tw-shadow-alpha), transparent)}',
            ],
            'a full opacity leaves the colour alone' => [
                'shadow-red-500/100',
                '--tw-shadow-color:oklch(63.7% 0.237 25.331);@supports (color: color-mix(in lab, red, red)){'
                . '--tw-shadow-color:color-mix(in oklab, var(--color-red-500) var(--tw-shadow-alpha), transparent)}',
            ],
            'inherit is written alone' => ['shadow-inherit', '--tw-shadow-color:inherit'],
            'inherit takes no opacity' => ['shadow-inherit/50', null],
            'an opacity on a theme shadow becomes the alpha' => [
                'shadow-sm/50',
                '--tw-shadow-alpha:50%;'
                . '--tw-shadow:0 1px 3px 0 var(--tw-shadow-color, oklab(from rgb(0 0 0 / 0.1) l a b / 50%)), '
                . '0 1px 2px -1px var(--tw-shadow-color, oklab(from rgb(0 0 0 / 0.1) l a b / 50%));'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a variable alpha needs the relative colour support' => [
                'shadow-sm/(--o)',
                '--tw-shadow-alpha:var(--o);--tw-shadow:0 1px 3px 0 var(--tw-shadow-color, rgb(0 0 0 / 0.1)), '
                . '0 1px 2px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1));@supports (color: lab(from red l a b)){'
                . '--tw-shadow:0 1px 3px 0 var(--tw-shadow-color, oklab(from rgb(0 0 0 / 0.1) l a b / var(--o))), '
                . '0 1px 2px -1px var(--tw-shadow-color, oklab(from rgb(0 0 0 / 0.1) l a b / var(--o)))};'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'none takes no opacity' => ['shadow-none/50', null],
            'an inset shadow from the theme is not prefixed twice' => [
                'inset-shadow-sm',
                '--tw-inset-shadow:inset 0 2px 4px var(--tw-inset-shadow-color, rgb(0 0 0 / 0.05));'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'an arbitrary inset shadow is prefixed' => [
                'inset-shadow-[0_1px_red]',
                '--tw-inset-shadow:inset 0 1px var(--tw-inset-shadow-color, red);box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'every shadow of an inset list is inset' => [
                'inset-shadow-[1px_1px_red,2px_2px_blue]',
                '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, red),'
                . 'inset 2px 2px var(--tw-inset-shadow-color, blue);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'an inset list keeps the white space after the comma' => [
                'inset-shadow-[1px_1px_red,_2px_2px_blue]',
                '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, red),'
                . 'inset  2px 2px var(--tw-inset-shadow-color, blue);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a variable in an inset list is inset too' => [
                'inset-shadow-[1px_1px_red,var(--s)]',
                '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, red),inset var(--s);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'an inset keyword of the author is kept as written' => [
                'inset-shadow-[1px_1px_red,inset_2px_2px_blue]',
                '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, red),'
                . 'inset inset 2px 2px var(--tw-inset-shadow-color, blue);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
                . 'var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'a variable inset shadow is prefixed' => [
                'inset-shadow-(--s)',
                '--tw-inset-shadow:inset var(--s);box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'no negative shadow' => ['-shadow-sm', null],
            'the colour name of the other root is no shadow' => ['inset-shadow-md', null],
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
