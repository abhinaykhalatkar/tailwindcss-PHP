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
use TailwindPhp\Engine\Data\Utilities\TablesTransitionsTransforms;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The TablesTransitionsTransforms rows agree with the hand-written inventory (the denominator of
 * the 98 % gate, ENG-A12): the same 25 roots, and for each the same kind,
 * theme namespaces, bare kind, fraction, negative, arbitrary and modifier
 * flags. The inventory is never derived from the rows; a disagreement means
 * one of them is wrong.
 */
final class TablesTransitionsTransformsTest extends TestCase
{
    /** @test */
    public function theRowsAreTheInventorysTablesTransitionsTransformsRoots(): void
    {
        $inventory = new Inventory();
        $roots = $inventory->families()['tables-transitions-transforms'];

        self::assertCount(26, $roots);
        self::assertSame($roots, \array_keys(TablesTransitionsTransforms::rows()), 'same roots, same order');
    }

    /**
     * @test
     *
     * @dataProvider roots
     */
    public function eachRowsFlagsAgreeWithTheInventory(string $root): void
    {
        $entry = (new Inventory())->entry($root);
        $definition = Definition::fromRow($root, TablesTransitionsTransforms::rows()[$root]);
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

        foreach (\array_keys(TablesTransitionsTransforms::rows()) as $root) {
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
            'transition alone' => [
                'transition',
                'transition-property:color, background-color, border-color, outline-color, '
                . 'text-decoration-color, fill, '
                . 'stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to, opacity, box-shadow, transform, '
                . 'translate, scale, rotate, filter, -webkit-backdrop-filter, backdrop-filter, display, '
                . 'content-visibility, overlay, pointer-events;transition-timing-function:var(--tw-ease, '
                . 'var(--default-transition-timing-function));transition-duration:var(--tw-duration, '
                . 'var(--default-transition-duration))',
            ],
            'transition none is the property alone' => ['transition-none', 'transition-property:none'],
            'transition behaviour' => ['transition-discrete', 'transition-behavior:allow-discrete'],
            'transition behaviour normal' => ['transition-normal', 'transition-behavior:normal'],
            'transition unknown keyword' => ['transition-foo', null],
            'transition initial is refused' => ['transition-initial', null],
            'no negative transition' => ['-transition-all', null],
            'duration zero' => ['duration-0', '--tw-duration:0ms;transition-duration:0ms'],
            'duration initial is the variable alone' => ['duration-initial', '--tw-duration:initial'],
            'duration in seconds' => ['duration-[1s]', '--tw-duration:1s;transition-duration:1s'],
            'duration is whole milliseconds' => ['duration-1.5', null],
            'no negative duration' => ['-duration-75', null],
            'delay zero' => ['delay-0', 'transition-delay:0ms'],
            'delay initial is refused' => ['delay-initial', null],
            'ease from the theme' => ['ease-in', '--tw-ease:var(--ease-in);transition-timing-function:var(--ease-in)'],
            'ease linear is a literal' => ['ease-linear', '--tw-ease:linear;transition-timing-function:linear'],
            'ease initial is the variable alone' => ['ease-initial', '--tw-ease:initial'],
            'ease arbitrary' => ['ease-[x]', '--tw-ease:x;transition-timing-function:x'],
            'ease unknown keyword' => ['ease-foo', null],
            'animate from the theme' => ['animate-spin', 'animation:var(--animate-spin)'],
            'animate none' => ['animate-none', 'animation:none'],
            'animate arbitrary' => ['animate-[wiggle_1s]', 'animation:wiggle 1s'],
            'animate unknown name' => ['animate-foo', null],
            'rotate none' => ['rotate-none', 'rotate:none'],
            'rotate whole degrees only' => ['rotate-1.5', null],
            'rotate arbitrary' => ['rotate-[1turn]', 'rotate:1turn'],
            'negative rotate' => ['-rotate-45', 'rotate:calc(45deg * -1)'],
            'origin keyword' => ['origin-top-left', 'transform-origin:0 0'],
            'origin arbitrary' => ['origin-[10px_20px]', 'transform-origin:10px 20px'],
            'origin unknown keyword' => ['origin-foo', null],
            'scale all axes' => [
                'scale-150',
                '--tw-scale-x:150%;--tw-scale-y:150%;--tw-scale-z:150%;scale:var(--tw-scale-x) var(--tw-scale-y)',
            ],
            'scale one axis' => ['scale-y-50', '--tw-scale-y:50%;scale:var(--tw-scale-x) var(--tw-scale-y)'],
            'scale none' => ['scale-none', 'scale:none'],
            'scale arbitrary is the property' => ['scale-[0.5]', 'scale:0.5'],
            'scale variable is the property' => ['scale-(--s)', 'scale:var(--s)'],
            'scale axis arbitrary is the variable' => [
                'scale-x-[0.5]',
                '--tw-scale-x:0.5;scale:var(--tw-scale-x) var(--tw-scale-y)',
            ],
            'scale whole percentages only' => ['scale-1.5', null],
            'scale axis has no none' => ['scale-x-none', null],
            'negative scale' => [
                '-scale-50',
                '--tw-scale-x:calc(50% * -1);--tw-scale-y:calc(50% * -1);--tw-scale-z:calc(50% * -1);'
                . 'scale:var(--tw-scale-x) var(--tw-scale-y)',
            ],
            'translate both axes' => [
                'translate-4',
                '--tw-translate-x:calc(var(--spacing) * 4);--tw-translate-y:calc(var(--spacing) * 4);'
                . 'translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate one axis' => [
                'translate-x-full',
                '--tw-translate-x:100%;translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'negative translate full' => [
                '-translate-full',
                '--tw-translate-x:-100%;--tw-translate-y:-100%;translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate fraction' => [
                '-translate-y-1/2',
                '--tw-translate-y:calc(calc(1/2 * 100%) * -1);translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate none' => ['translate-none', 'translate:none'],
            'translate axis has no none' => ['translate-x-none', null],
            'translate off scale' => ['translate-0.3', null],
            'border spacing both' => [
                'border-spacing-4',
                '--tw-border-spacing-x:calc(var(--spacing) * 4);--tw-border-spacing-y:calc(var(--spacing) * 4);'
                . 'border-spacing:var(--tw-border-spacing-x) var(--tw-border-spacing-y)',
            ],
            'border spacing one axis' => [
                'border-spacing-y-px',
                '--tw-border-spacing-y:1px;border-spacing:var(--tw-border-spacing-x) var(--tw-border-spacing-y)',
            ],
            'border spacing is never negative' => ['-border-spacing-4', null],
            'border spacing has no fraction' => ['border-spacing-1/2', null],
            'transform composition' => [
                'transform',
                'transform:var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,) var(--tw-skew-x,) '
                . 'var(--tw-skew-y,)',
            ],
            'transform cpu is the composition and registers nothing' => [
                'transform-cpu',
                'transform:var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,) var(--tw-skew-x,) '
                . 'var(--tw-skew-y,)',
            ],
            'transform gpu' => [
                'transform-gpu',
                'transform:translateZ(0) var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,) '
                . 'var(--tw-skew-x,) var(--tw-skew-y,)',
            ],
            'transform none' => ['transform-none', 'transform:none'],
            'transform takes no value' => ['transform-foo', null],
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
