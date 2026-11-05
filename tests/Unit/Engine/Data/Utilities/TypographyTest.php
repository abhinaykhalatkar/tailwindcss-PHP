<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\Typography;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The Typography rows agree with the hand-written inventory (the denominator
 * of the 98 % gate, ENG-A12): the same 46 roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 *
 * A root with several sub-definitions (`font`, `text`, `decoration`) is read
 * as one: its namespaces and modifiers are the union of the sub-definitions',
 * in whatever order the CLI's resolution needs.
 */
final class TypographyTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysTypographyRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['typography'];

        self::assertCount(46, $roots);
        self::assertSame($roots, \array_keys(Typography::rows()), 'same roots, same (catalogue) order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, Typography::rows()[$root]);
        $functional = [];

        foreach ($definition->definitions() as $sub) {
            if ($sub->staticDeclarations() === null && $sub->isFunctional()) {
                $functional[] = $sub;
            }
        }

        self::assertSame($entry['kind'] === 'functional', $definition->isFunctional(), 'kind');

        $theme = [];
        $bare = null;
        $named = false;
        $fraction = false;
        $negative = false;
        $arbitrary = false;
        $modifiers = [];

        foreach ($functional as $sub) {
            foreach ($sub->theme() as $namespace) {
                $theme[] = '--' . $namespace . '-*';
            }

            $bare = $bare ?? $sub->bare();
            $named = $named || $sub->named() !== [];
            $fraction = $fraction || $sub->fraction();
            $negative = $negative || $sub->negative();
            $arbitrary = $arbitrary || $sub->acceptsArbitrary();

            if ($sub->modifier() !== null) {
                $modifiers[] = $sub->modifier();
            }
        }

        // A functional root with literals and no numeric bare kind is keyword-only; one with neither
        // takes theme values or arbitrary ones only.
        if ($bare === null && $named) {
            $bare = 'keyword';
        }

        $expected = $entry['theme'];
        \sort($expected);
        \sort($theme);

        self::assertSame($expected, $theme, 'theme');
        self::assertSame($entry['fraction'], $fraction, 'fraction');
        self::assertSame($entry['negative'], $negative, 'negative');
        self::assertSame($entry['arbitrary'], $arbitrary, 'arbitrary');
        $listed = $entry['modifier'] === null ? [] : [$entry['modifier']];

        // A root may list its colour twice (the typed colour first, the catch-all colour last).
        self::assertSame($listed, \array_values(\array_unique(\array_intersect($modifiers, $listed))), 'modifier');

        // `text` takes a colour opacity too: its listed modifier is the font-size one, so the opacity one
        // may sit next to it; no other root has two.
        self::assertLessThanOrEqual($root === 'text' ? 2 : 1, \count(\array_unique($modifiers)));

        // Whole numbers of pixels (`decoration-2`, `underline-offset-4`) are what the inventory calls an
        // `integer`: the CLI writes the number with its unit, which is the row's `pixel` bare kind.
        self::assertSame($entry['bare'], $bare === 'pixel' ? 'integer' : $bare, 'bare');

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

        foreach (\array_keys(Typography::rows()) as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }

    /**
     * Values the generated corpus does not sample, checked one by one against
     * the pinned CLI (TASK-0037): which meaning a type hint or an inferred
     * type picks, the keyword sets, the weights and families, what each
     * root refuses.
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
            'weight from a variable' => ['font-(--w)', '--tw-font-weight:var(--w);font-weight:var(--w)'],
            'weight from a number' => ['font-[500]', '--tw-font-weight:500;font-weight:500'],
            'weight by hint' => ['font-[weight:500]', '--tw-font-weight:500;font-weight:500'],
            'weight by number hint' => ['font-[number:500]', '--tw-font-weight:500;font-weight:500'],
            'weight from the theme' => [
                'font-extrabold',
                '--tw-font-weight:var(--font-weight-extrabold);font-weight:var(--font-weight-extrabold)',
            ],
            'family from a name' => ['font-[Inter]', 'font-family:Inter'],
            'family from a generic name' => ['font-[ui-sans-serif]', 'font-family:ui-sans-serif'],
            'family by hint' => ['font-(family-name:--f)', 'font-family:var(--f)'],
            'family from the theme' => ['font-serif', 'font-family:var(--font-serif)'],
            'a length is a weight value' => ['font-[10px]', '--tw-font-weight:10px;font-weight:10px'],
            'no modifier on a font' => ['font-sans/50', null],
            'colour from a variable' => ['text-(--c)', 'color:var(--c)'],
            'colour from a keyword' => ['text-[red]', 'color:red'],
            'colour by hint' => ['text-[color:red]', 'color:red'],
            'size from a length' => ['text-[3rem]', 'font-size:3rem'],
            'size by hint' => ['text-(length:--s)', 'font-size:var(--s)'],
            'size and arbitrary line height' => ['text-[12px]/[2]', 'font-size:12px;line-height:2'],
            'size and spacing line height' => ['text-[14px]/7', 'font-size:14px;line-height:calc(var(--spacing) * 7)'],
            'theme size' => [
                'text-base',
                'font-size:var(--text-base);line-height:var(--tw-leading, var(--text-base--line-height))',
            ],
            'theme size and leading' => ['text-sm/tight', 'font-size:var(--text-sm);line-height:var(--leading-tight)'],
            'theme size and none' => ['text-sm/none', 'font-size:var(--text-sm);line-height:1'],
            'theme size and a decimal' => [
                'text-sm/1.5',
                'font-size:var(--text-sm);line-height:calc(var(--spacing) * 1.5)',
            ],
            'theme size and a variable' => ['text-base/(--lh)', 'font-size:var(--text-base);line-height:var(--lh)'],
            'theme size and an arbitrary line height' => ['text-sm/[2]', 'font-size:var(--text-sm);line-height:2'],
            'an off-scale line height' => ['text-sm/3.3', null],
            'an unknown line height' => ['text-sm/foo', null],
            'a keyword takes no modifier' => ['text-left/50', null],
            'alignment' => ['text-justify', 'text-align:justify'],
            'wrapping' => ['text-balance', 'text-wrap:balance'],
            'a colour with an opacity' => [
                'text-red-500/50',
                'color:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);@supports',
            ],
            'text shadows are not sizes' => ['text-shadow-sm', null],
            'tracking' => [
                'tracking-tight',
                '--tw-tracking:var(--tracking-tight);letter-spacing:var(--tracking-tight)',
            ],
            'negative tracking' => [
                '-tracking-tight',
                '--tw-tracking:calc(var(--tracking-tight) * -1);letter-spacing:calc(var(--tracking-tight) * -1)',
            ],
            'tracking has no bare number' => ['tracking-1', null],
            'leading from the scale' => [
                'leading-3.5',
                '--tw-leading:calc(var(--spacing) * 3.5);line-height:calc(var(--spacing) * 3.5)',
            ],
            'leading none' => ['leading-none', '--tw-leading:1;line-height:1'],
            'leading px' => ['leading-px', '--tw-leading:1px;line-height:1px'],
            'leading off scale' => ['leading-1.3', null],
            'leading has no fraction' => ['leading-1/2', null],
            'no negative leading' => ['-leading-3', null],
            'line clamp' => [
                'line-clamp-3',
                'overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3',
            ],
            'line clamp zero' => [
                'line-clamp-0',
                'overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:0',
            ],
            'line clamp none' => [
                'line-clamp-none',
                'overflow:visible;display:block;-webkit-box-orient:horizontal;-webkit-line-clamp:unset',
            ],
            'line clamp variable' => [
                'line-clamp-(--n)',
                'overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--n)',
            ],
            'no fraction line clamp' => ['line-clamp-1/2', null],
            'no decimal line clamp' => ['line-clamp-1.5', null],
            'no negative line clamp' => ['-line-clamp-3', null],
            'list style' => ['list-disc', 'list-style-type:disc'],
            'list style arbitrary' => ['list-[square]', 'list-style-type:square'],
            'list has no square' => ['list-square', null],
            'list image' => ['list-image-none', 'list-style-image:none'],
            'list image unknown keyword' => ['list-image-foo', null],
            'decoration keyword' => ['decoration-from-font', 'text-decoration-thickness:from-font'],
            'decoration style' => ['decoration-wavy', 'text-decoration-style:wavy'],
            'decoration colour' => ['decoration-red-500', 'text-decoration-color:var(--color-red-500)'],
            'decoration thickness length' => ['decoration-[3px]', 'text-decoration-thickness:3px'],
            'decoration thickness in pixels' => ['decoration-2', 'text-decoration-thickness:2px'],
            'decoration thickness zero' => ['decoration-0', 'text-decoration-thickness:0px'],
            'decoration thickness is whole pixels' => ['decoration-1.5', null],
            'no negative decoration thickness' => ['-decoration-2', null],
            'decoration takes no modifier' => ['decoration-2/50', null],
            'underline offset in pixels' => ['underline-offset-4', 'text-underline-offset:4px'],
            'underline offset zero' => ['underline-offset-0', 'text-underline-offset:0px'],
            'negative underline offset' => ['-underline-offset-2', 'text-underline-offset:calc(2px * -1)'],
            'underline offset is whole pixels' => ['underline-offset-1.5', null],
            'decoration thickness by hint' => ['decoration-(length:--t)', 'text-decoration-thickness:var(--t)'],
            'decoration colour variable' => ['decoration-(--c)', 'text-decoration-color:var(--c)'],
            'underline offset keyword' => ['underline-offset-auto', 'text-underline-offset:auto'],
            'underline offset arbitrary' => ['underline-offset-[3px]', 'text-underline-offset:3px'],
            'indent' => ['indent-px', 'text-indent:1px'],
            'negative indent' => ['-indent-4', 'text-indent:calc(var(--spacing) * -4)'],
            'negative indent px' => ['-indent-px', 'text-indent:-1px'],
            'indent has no auto' => ['indent-auto', null],
            'align arbitrary' => ['align-[10px]', 'vertical-align:10px'],
            'whitespace refuses arbitrary values' => ['whitespace-[x]', null],
            'hyphens write both properties' => ['hyphens-auto', '-webkit-hyphens:auto;hyphens:auto'],
            'tracking arbitrary' => ['tracking-[1px]', '--tw-tracking:1px;letter-spacing:1px'],
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
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name;
            }
        }

        return \implode(';', $parts);
    }
}
