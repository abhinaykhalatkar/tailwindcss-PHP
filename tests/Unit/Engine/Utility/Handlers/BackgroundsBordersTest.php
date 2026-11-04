<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Handlers;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\BackgroundsBorders as Rows;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\BackgroundsBorders;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The BackgroundsBorders handlers (TASK-0038 criterion 4), run end to end as the
 * engine runs them: the real candidate parser, the default registry, the compiler and
 * the default theme. Each shape is the 4.1.18 CLI's, from
 * `golden/utilities/backgrounds-borders.css`. A body is rendered compactly as
 * `prop:value; …` with nested blocks as `selector{…}` / `@name params{…}`.
 */
final class BackgroundsBordersTest extends TestCase
{
    private const STOPS = 'var(--tw-gradient-via-stops, var(--tw-gradient-position), '
        . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
        . 'var(--tw-gradient-to) var(--tw-gradient-to-position))';

    private const RING = 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
        . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)';

    /** @test */
    public function theFamilyOwnsItsSevenHandlers(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new BackgroundsBorders(new ValueResolver()));
        self::assertSame(
            ['gradient', 'border', 'divide', 'divideReverse', 'outline', 'outlineHidden', 'ring'],
            BackgroundsBorders::names()
        );

        foreach (BackgroundsBorders::names() as $name) {
            self::assertArrayHasKey($name, Handlers::TAKES_VALUE);
        }

        $unimplemented = (new Handlers(new ValueResolver()))->unimplemented();

        foreach (BackgroundsBorders::names() as $name) {
            self::assertNotContains($name, $unimplemented);
        }
    }

    /**
     * @test
     *
     * @dataProvider shapes
     */
    public function eachFormHasTheCliShape(string $candidate, string $expected): void
    {
        self::assertSame($expected, self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        $direction = '--tw-gradient-position:to right';

        return [
            // gradient
            'linear direction' => ['bg-linear-to-r', $direction
                . '; @supports (background-image: linear-gradient(in lab, red, red)){'
                . $direction . ' in oklab}; background-image:linear-gradient(var(--tw-gradient-stops))'],
            'legacy direction' => ['bg-gradient-to-tl', '--tw-gradient-position:to top left in oklab; '
                . 'background-image:linear-gradient(var(--tw-gradient-stops))'],
            'from colour' => ['from-red-500', '--tw-gradient-from:var(--color-red-500); '
                . '--tw-gradient-stops:' . self::STOPS],
            'to arbitrary colour' => ['to-[#bada55]', '--tw-gradient-to:#bada55; --tw-gradient-stops:' . self::STOPS],
            'via variable' => ['via-(--my-var)', '--tw-gradient-via:var(--my-var); --tw-gradient-via-stops:'
                . 'var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) '
                . 'var(--tw-gradient-to-position); --tw-gradient-stops:var(--tw-gradient-via-stops)'],
            'from with opacity' => ['from-red-500/50',
                '--tw-gradient-from:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent); '
                . '@supports (color: color-mix(in lab, red, red)){--tw-gradient-from:color-mix(in oklab, '
                . 'var(--color-red-500) 50%, transparent)}; --tw-gradient-stops:' . self::STOPS],
            'full opacity leaves the colour' => ['to-red-500/100', '--tw-gradient-to:var(--color-red-500); '
                . '--tw-gradient-stops:' . self::STOPS],
            'stop position' => ['from-10%', '--tw-gradient-from-position:10%'],
            'arbitrary stop position' => ['to-[30%]', '--tw-gradient-to-position:30%'],
            // border
            'border alone' => ['border', 'border-style:var(--tw-border-style); border-width:1px'],
            'border side width' => ['border-x-2', 'border-inline-style:var(--tw-border-style); '
                . 'border-inline-width:2px'],
            'border zero' => ['border-t-0', 'border-top-style:var(--tw-border-style); border-top-width:0px'],
            'border arbitrary width' => ['border-s-[3px]',
                'border-inline-start-style:var(--tw-border-style); border-inline-start-width:3px'],
            'border style' => ['border-dashed', '--tw-border-style:dashed; border-style:dashed'],
            'border hidden' => ['border-hidden', '--tw-border-style:hidden; border-style:hidden'],
            'border number is a width' => ['border-[3]', 'border-style:var(--tw-border-style); border-width:3'],
            'border colour' => ['border-b-red-500', 'border-bottom-color:var(--color-red-500)'],
            'border variable is a colour' => ['border-(--my-var)', 'border-color:var(--my-var)'],
            'border typed variable is a width' => ['border-(length:--w)',
                'border-style:var(--tw-border-style); border-width:var(--w)'],
            // divide
            'divide-x alone' => ['divide-x', ':where(& > :not(:last-child)){--tw-divide-x-reverse:0; '
                . 'border-inline-style:var(--tw-border-style); border-inline-start-width:calc(1px * '
                . 'var(--tw-divide-x-reverse)); border-inline-end-width:calc(1px * '
                . 'calc(1 - var(--tw-divide-x-reverse)))}'],
            'divide-y width' => ['divide-y-2', ':where(& > :not(:last-child)){--tw-divide-y-reverse:0; '
                . 'border-bottom-style:var(--tw-border-style); border-top-style:var(--tw-border-style); '
                . 'border-top-width:calc(2px * var(--tw-divide-y-reverse)); '
                . 'border-bottom-width:calc(2px * calc(1 - var(--tw-divide-y-reverse)))}'],
            'divide colour' => ['divide-red-500', ':where(& > :not(:last-child)){border-color:var(--color-red-500)}'],
            'divide colour with opacity' => ['divide-red-500/50', ':where(& > :not(:last-child)){border-color:'
                . 'color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent); @supports (color: color-mix(in '
                . 'lab, red, red)){border-color:color-mix(in oklab, var(--color-red-500) 50%, transparent)}}'],
            'divide style' => ['divide-none', ':where(& > :not(:last-child)){--tw-border-style:none; '
                . 'border-style:none}'],
            'divide reverse' => ['divide-y-reverse', ':where(& > :not(:last-child)){--tw-divide-y-reverse:1}'],
            // outline
            'outline alone' => ['outline', 'outline-style:var(--tw-outline-style); outline-width:1px'],
            'outline width' => ['outline-[3px]', 'outline-style:var(--tw-outline-style); outline-width:3px'],
            'outline style' => ['outline-dotted', '--tw-outline-style:dotted; outline-style:dotted'],
            'outline colour' => ['outline-white', 'outline-color:var(--color-white)'],
            'outline none' => ['outline-none', '--tw-outline-style:none; outline-style:none'],
            'outline hidden' => ['outline-hidden', '--tw-outline-style:none; outline-style:none; '
                . '@media (forced-colors: active){outline:2px solid transparent; outline-offset:2px}'],
            'outline offset' => ['outline-offset-2', 'outline-offset:2px'],
            'negative outline offset' => ['-outline-offset-2', 'outline-offset:calc(2px * -1)'],
            'negative arbitrary outline offset' => ['-outline-offset-[3px]', 'outline-offset:calc(3px * -1)'],
            // ring
            'ring alone' => ['ring', '--tw-ring-shadow:var(--tw-ring-inset,) 0 0 0 calc(1px + '
                . 'var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor); ' . self::RING],
            'ring width' => ['ring-4', '--tw-ring-shadow:var(--tw-ring-inset,) 0 0 0 calc(4px + '
                . 'var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor); ' . self::RING],
            'ring colour' => ['ring-blue-500', '--tw-ring-color:var(--color-blue-500)'],
            'ring inset' => ['ring-inset', '--tw-ring-inset:inset'],
            'ring offset width' => ['ring-offset-2', '--tw-ring-offset-width:2px; --tw-ring-offset-shadow:'
                . 'var(--tw-ring-inset,) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color)'],
            'ring offset colour' => ['ring-offset-[#bada55]', '--tw-ring-offset-color:#bada55'],
            // data rows of the family
            'rounded side' => ['rounded-s-lg', 'border-start-start-radius:var(--radius-lg); '
                . 'border-end-start-radius:var(--radius-lg)'],
            'rounded full' => ['rounded-full', 'border-radius:calc(infinity * 1px)'],
            'bg keyword' => ['bg-repeat-round', 'background-repeat:round'],
            'bg image' => ['bg-[url(/img.png)]', 'background-image:url(/img.png)'],
            'bg position' => ['bg-[50%_25%]', 'background-position:50% 25%'],
            'bg size by hint' => ['bg-[length:10px]', 'background-size:10px'],
            'bg variable is a colour' => ['bg-(--my-var)', 'background-color:var(--my-var)'],
            // any other arbitrary value is the colour (the CLI's `default:`)
            'border untyped value' => ['border-x-[foo]', 'border-inline-color:foo'],
            'untyped value with opacity' => ['border-[foo]/50',
                'border-color:color-mix(in oklab, foo 50%, transparent)'],
            'ring untyped value' => ['ring-[thin]', '--tw-ring-color:thin'],
            'outline number is a width' => ['outline-[3]',
                'outline-style:var(--tw-outline-style); outline-width:3'],
            'stop untyped value' => ['from-[foo]', '--tw-gradient-from:foo; --tw-gradient-stops:' . self::STOPS],
            'variable in brackets with opacity' => ['border-[var(--x)]/50', 'border-color:var(--x); '
                . '@supports (color: color-mix(in lab, red, red)){border-color:color-mix(in oklab, var(--x) 50%, '
                . 'transparent)}'],
            'hinted angle is a colour' => ['border-[angle:3deg]', 'border-color:3deg'],
            'divide arbitrary width-like colour' => ['divide-[3px]/50', ':where(& > :not(:last-child)){'
                . 'border-color:color-mix(in oklab, 3px 50%, transparent)}'],
            'divide untyped value' => ['divide-[foo]', ':where(& > :not(:last-child)){border-color:foo}'],
            'divide width takes any value' => ['divide-x-[thin]', ':where(& > :not(:last-child)){'
                . '--tw-divide-x-reverse:0; border-inline-style:var(--tw-border-style); '
                . 'border-inline-start-width:calc(thin * var(--tw-divide-x-reverse)); '
                . 'border-inline-end-width:calc(thin * calc(1 - var(--tw-divide-x-reverse)))}'],
        ];
    }

    /**
     * A modifier where none is taken, a negative where none is allowed, a
     * value of the wrong kind, and output-breaking text: no CSS.
     *
     * @test
     *
     * @dataProvider refused
     */
    public function aCandidateTheHandlersDoNotTakeGivesNoCss(string $candidate): void
    {
        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refused(): array
    {
        return [
            // modifiers where none is taken
            'direction with a modifier' => ['bg-linear-to-r/50'],
            'width with a modifier' => ['border-2/50'],
            'divide width with a modifier' => ['divide-x-2/50'],
            'stop position with a modifier' => ['from-10%/50'],
            'reverse with a modifier' => ['divide-x-reverse/50'],
            'hidden with a modifier' => ['outline-hidden/50'],
            'ring width with a modifier' => ['ring-2/50'],
            // negatives where none is allowed
            'negative border' => ['-border-2'],
            'negative ring' => ['-ring-2'],
            'negative divide' => ['-divide-x'],
            'negative stop' => ['-from-10%'],
            'negative hidden' => ['-outline-hidden'],
            'negative direction' => ['-bg-linear-to-r'],
            // wrong kinds of value
            'unknown direction' => ['bg-linear-to-x'],
            'fractional width' => ['border-1.5'],
            'non-canonical width' => ['ring-01'],
            'fractional stop' => ['from-1.5%'],
            'stop without percent' => ['via-10'],
            'style on a side' => ['border-x-solid'],
            'hidden is not an outline style' => ['outline-hidden-2'],
            'opacity on a stop position' => ['to-[30%]/50'],
            'opacity on an arbitrary width' => ['border-[3px]/50'],
            'opacity on a typed width' => ['border-(length:--w)/50'],
            'opacity on a ring width' => ['ring-[3px]/50'],
            'opacity on a ring offset' => ['ring-offset-[3px]/50'],
            'opacity on an outline width' => ['outline-[50%]/50'],
            'opacity on a typed stop' => ['from-(length:--x)/50'],
            'reverse takes no value' => ['divide-x-reverse-2'],
            'no hidden divide' => ['divide-hidden'],
            'ring offset alone' => ['ring-offset'],
            'divide alone' => ['divide'],
            // output-breaking text, refused by the candidate parser (T-15)
            'semicolon' => ['border-[3px;color:red]'],
            'closing brace' => ['ring-[1px}body{x:y]'],
            'comment' => ['outline-[1px/*x*/]'],
            'script url' => ['bg-[url(javascript:alert(1))]'],
            'script url in a stop' => ['from-[url(javascript:x)]'],
            'brace in a divide colour' => ['divide-[red}]'],
        ];
    }

    /**
     * The compiler never hands a handler a modifier its row does not take, a
     * value for a static root or a negative the row refuses; the handlers
     * refuse them anyway when called directly (defence in depth).
     *
     * @test
     */
    public function theHandlersRefuseWhatTheCompilerNeverPassesThem(): void
    {
        $handlers = new Handlers(new ValueResolver());
        $theme = self::theme();
        $modifier = new Modifier(Modifier::NAMED, '50');
        $two = new NamedValue('2');
        $solid = new NamedValue('solid');
        $functional = Candidate::FUNCTIONAL;
        $static = Candidate::STATIC;
        // label => [handler, root, sub-definition index, negative, kind, value, modifier]
        $cases = [
            'width with a modifier' => ['border', 'border', 2, false, $functional, $two, $modifier],
            'negative width' => ['ring', 'ring', 1, true, $functional, $two, null],
            'style with a modifier' => ['border', 'border', 1, false, $functional, $solid, $modifier],
            'negative style' => ['outline', 'outline', 1, true, $functional, $solid, null],
            'reverse with a value' => ['divideReverse', 'divide-x-reverse', 0, false, $functional, $two, null],
            'negative reverse' => ['divideReverse', 'divide-x-reverse', 0, true, $static, null, null],
            'hidden with a modifier' => ['outlineHidden', 'outline-hidden', 0, false, $static, null, $modifier],
        ];

        foreach ($cases as $label => [$handler, $root, $sub, $negative, $kind, $value, $mod]) {
            $candidate = new Candidate($label, [], false, $negative, $kind, $root, $value, $mod);
            $definition = Definition::fromRow($root, Rows::rows()[$root])->definitions()[$sub];

            self::assertSame($handler, $definition->handler(), $label);
            self::assertNull($handlers->run($handler, $definition, $candidate, $theme), $label);
        }
    }

    /** @test */
    public function usedVariablesAreReportedWithoutBeingMarked(): void
    {
        $theme = self::theme();
        $cases = [
            'from-red-500/50' => ['--color-red-500'],
            'divide-black' => ['--color-black'],
            'border-x-zinc-500' => ['--color-zinc-500'],
            'ring-2' => [],
        ];

        foreach ($cases as $candidate => $used) {
            $compiled = self::compile($candidate, $theme);

            self::assertNotNull($compiled, $candidate);
            self::assertSame($used, $compiled->usedVars, $candidate);
        }

        self::assertSame([], $theme->usedVars(), 'the compiler never marks variables used');
    }

    /**
     * The `@property` names (criterion 2): the CLI's group for gradient stops
     * and rings, the style variable a width reads, and every `--tw-*` the body
     * declares (added by the compiler).
     *
     * @test
     */
    public function propertiesNameWhatTheUtilityRelieson(): void
    {
        $theme = self::theme();

        $gradient = Rows::GRADIENT_PROPERTIES;
        $ring = Rows::RING_PROPERTIES;
        \sort($gradient);
        \sort($ring);

        self::assertSame($gradient, self::properties('from-10%', $theme));
        self::assertSame($gradient, self::properties('via-red-500', $theme));
        self::assertSame($ring, self::properties('ring-2', $theme));
        self::assertSame($ring, self::properties('ring-inset', $theme));
        self::assertSame(['--tw-border-style'], self::properties('border-2', $theme));
        self::assertSame(['--tw-border-style', '--tw-divide-x-reverse'], self::properties('divide-x', $theme));
        self::assertSame(['--tw-divide-y-reverse'], self::properties('divide-y-reverse', $theme));
        self::assertSame(['--tw-outline-style'], self::properties('outline', $theme));
        self::assertSame([], self::properties('rounded', $theme));
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }

    private static function compile(string $raw, ThemeRegistry $theme): ?CompiledUtility
    {
        $registry = Registry::default();

        return (new Compiler($registry))->compileReadings($raw, (new Parser($registry))->parse($raw), $theme);
    }

    /**
     * @return list<string>
     */
    private static function properties(string $raw, ThemeRegistry $theme): array
    {
        $compiled = self::compile($raw, $theme);

        self::assertNotNull($compiled, $raw);
        $properties = $compiled->properties;
        \sort($properties);

        return $properties;
    }

    private static function css(string $raw): ?string
    {
        $compiled = self::compile($raw, self::theme());

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

        return \implode('; ', $parts);
    }
}
