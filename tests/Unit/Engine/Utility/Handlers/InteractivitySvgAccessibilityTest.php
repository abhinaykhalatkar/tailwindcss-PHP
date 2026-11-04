<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Handlers;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities\InteractivitySvgAccessibility as Rows;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\InteractivitySvgAccessibility;
use TailwindPhp\Engine\Utility\Registry;

/**
 * The interactivity, SVG and accessibility family (TASK-0040) needs no handler:
 * every root is a data row. This test pins that (the class owns no handler
 * name and no row names one), and runs the real parser, registry and compiler
 * over the forms with something to get wrong: the paint roots with their type
 * hints and opacity, the snap and touch compositions and their registrations,
 * the refusals and the hostile values. The shapes are the pinned 4.1.18 CLI's.
 */
final class InteractivitySvgAccessibilityTest extends TestCase
{
    /** @test */
    public function theFamilyImplementsTheContractAndOwnsNoHandler(): void
    {
        $family = new InteractivitySvgAccessibility();
        $theme = self::theme();
        $candidate = new Candidate('x', [], false, false, Candidate::FUNCTIONAL, 'fill', new NamedValue('none'));
        $definition = Definition::fromRow('fill', ['props' => ['fill'], 'named' => ['none' => 'none']]);

        self::assertInstanceOf(FamilyHandlerInterface::class, $family);
        self::assertSame([], InteractivitySvgAccessibility::names());
        self::assertNull($family->run('gridCols', $definition, $candidate, $theme), 'it answers no name');
    }

    /** @test */
    public function noRowOfTheFamilyNamesAHandler(): void
    {
        foreach (Rows::rows() as $root => $row) {
            self::assertArrayNotHasKey('handler', $row, (string) $root);

            foreach ($row['variants'] ?? [] as $variant) {
                self::assertArrayNotHasKey('handler', $variant, (string) $root);
            }
        }

        self::assertCount(49, Rows::rows());
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
        return [
            'cursor' => ['cursor-grab', 'cursor:grab'],
            'important cursor' => ['cursor-grab!', 'cursor:grab!important'],
            'snap type' => ['snap-y', 'scroll-snap-type:y var(--tw-scroll-snap-strictness)'],
            'important snap type' => [
                'snap-y!',
                'scroll-snap-type:y var(--tw-scroll-snap-strictness)!important',
            ],
            'touch pan' => [
                'touch-pan-x',
                '--tw-pan-x:pan-x;touch-action:var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)',
            ],
            'important touch pan reaches both declarations' => [
                'touch-pan-up!',
                '--tw-pan-y:pan-up!important;'
                . 'touch-action:var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)!important',
            ],
            'select' => ['select-none', '-webkit-user-select:none;user-select:none'],
            'a paint colour' => ['stroke-white', 'stroke:var(--color-white)'],
            'a paint colour by hint' => ['fill-(color:--c)', 'fill:var(--c)'],
            'a paint url' => ['stroke-[url(x)]', 'stroke:url(x)'],
            'a bracketed variable is a fill' => ['fill-[var(--x)]', 'fill:var(--x)'],
            'a bracketed variable is a stroke paint, with an opacity' => [
                'stroke-[var(--x)]/50',
                'stroke:var(--x);@supports (color: color-mix(in lab, red, red)){'
                . 'stroke:color-mix(in oklab, var(--x) 50%, transparent)}',
            ],
            'a length in brackets is the stroke width' => ['stroke-[2px]', 'stroke-width:2px'],
            'a paint with an opacity' => [
                'stroke-white/50',
                'stroke:color-mix(in srgb, #fff 50%, transparent);@supports (color: color-mix(in lab, red, red)){'
                . 'stroke:color-mix(in oklab, var(--color-white) 50%, transparent)}',
            ],
            'a stroke width' => ['stroke-3', 'stroke-width:3'],
            'a stroke width by hint' => ['stroke-[number:3]', 'stroke-width:3'],
        ];
    }

    /**
     * What the CLI refuses, the rows refuse: a negative, a fraction, an unknown
     * keyword, an opacity on a non-colour, a decimal width, arbitrary values on
     * the keyword roots.
     *
     * @test
     *
     * @dataProvider refusals
     */
    public function theCliRefusalsAreRefused(string $candidate): void
    {
        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusals(): array
    {
        return [
            'a negative cursor' => ['-cursor-grab'],
            'an unknown cursor' => ['cursor-nope'],
            'a bare snap' => ['snap'],
            'a value on a static touch root' => ['touch-pan-x-1'],
            'a negative touch' => ['-touch-pan-x'],
            'a modifier on a static root' => ['snap-x/50'],
            'an opacity on none' => ['fill-none/50'],
            'a decimal width' => ['stroke-1.5'],
            'a negative stroke' => ['-stroke-2'],
            'a negative fill' => ['-fill-red-500'],
            'an arbitrary select' => ['select-[x]'],
            'an arbitrary resize' => ['resize-[x]'],
            'an arbitrary pointer events' => ['pointer-events-[x]'],
            'a fraction scroll margin' => ['scroll-m-1/2'],
        ];
    }

    /**
     * T-15: text that could end a declaration or a rule, open a comment or
     * smuggle a script URL is refused by the parser before any row sees it, for
     * every root that takes an arbitrary value.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachADeclaration(string $candidate): void
    {
        $registry = Registry::default();

        foreach ((new CandidateParser($registry))->parse($candidate) as $reading) {
            self::assertNotInstanceOf(ArbitraryValue::class, $reading->value, $candidate);

            if ($reading->modifier !== null && $reading->modifier->kind === Modifier::ARBITRARY) {
                self::fail($candidate . ' kept a hostile arbitrary modifier');
            }
        }

        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCandidates(): array
    {
        $cases = [];

        foreach (['cursor', 'scroll-mt', 'scroll-p', 'fill', 'stroke'] as $root) {
            $cases[$root . ' semicolon'] = [$root . '-[1px;color:red]'];
            $cases[$root . ' close brace'] = [$root . '-[1px}a{b:c]'];
            $cases[$root . ' comment'] = [$root . '-[1px/*x*/]'];
            $cases[$root . ' script url'] = [$root . '-[url(javascript:alert(1))]'];
        }

        $cases['fill modifier semicolon'] = ['fill-red-500/[1;color:red]'];
        $cases['stroke modifier comment'] = ['stroke-red-500/[1/*x*/]'];

        return $cases;
    }

    /**
     * Candidates built around the parser (the value and modifier classes have
     * public constructors) are stopped by the rows or the compiler's emission
     * guard: a hostile opacity, a static candidate for a value root, a
     * negative paint.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $hostile = new Candidate(
            'x',
            [],
            false,
            false,
            Candidate::FUNCTIONAL,
            'fill',
            new NamedValue('white'),
            new Modifier(Modifier::ARBITRARY, '1;color:red')
        );
        $static = new Candidate('y', [], false, false, Candidate::STATIC, 'stroke', null);
        $negative = new Candidate('z', [], false, true, Candidate::FUNCTIONAL, 'fill', new NamedValue('white'));

        self::assertNull($compiler->compile($hostile, $theme), 'the emission guard stops a hostile opacity');
        self::assertNull($compiler->compile($static, $theme));
        self::assertNull($compiler->compile($negative, $theme));
    }

    /**
     * Rows report the theme variables they read and never mark them: a theme
     * colour reports `--color-*`, the spacing scale `--spacing`, a keyword
     * nothing.
     *
     * @test
     */
    public function usedVarsAreReportedNotMarked(): void
    {
        $theme = self::theme();

        self::assertSame(['--color-red-500'], self::used('fill-red-500', $theme));
        self::assertSame(['--color-red-500'], self::used('stroke-red-500/50', $theme));
        self::assertSame(['--spacing'], self::used('scroll-mt-4', $theme));
        self::assertSame([], self::used('scroll-mt-px', $theme));
        self::assertSame([], self::used('fill-current', $theme));
        self::assertSame([], self::used('stroke-[red]', $theme));
        self::assertSame([], self::used('cursor-pointer', $theme));
        self::assertSame([], $theme->usedVars(), 'nothing was marked');
    }

    /**
     * The CLI registers the pan variables for every pan utility, and the
     * strictness for `snap-x|y|both` and the two strictness utilities; nothing
     * else in the family registers anything.
     *
     * @test
     */
    public function theRegistrationsAreExactlyTheCliOnes(): void
    {
        $pan = ['--tw-pan-x', '--tw-pan-y', '--tw-pinch-zoom'];
        $strictness = ['--tw-scroll-snap-strictness'];
        $expected = [
            'touch-pan-x' => $pan,
            'touch-pan-left' => $pan,
            'touch-pan-right' => $pan,
            'touch-pan-y' => $pan,
            'touch-pan-up' => $pan,
            'touch-pan-down' => $pan,
            'touch-pinch-zoom' => $pan,
            'touch-auto' => [],
            'touch-none' => [],
            'touch-manipulation' => [],
            'snap-x' => $strictness,
            'snap-y' => $strictness,
            'snap-both' => $strictness,
            'snap-mandatory' => $strictness,
            'snap-proximity' => $strictness,
            'snap-none' => [],
            'snap-start' => [],
            'snap-align-none' => [],
            'snap-always' => [],
            'resize' => [],
            'cursor-pointer' => [],
            'scroll-m-4' => [],
            'fill-red-500' => [],
            'stroke-2' => [],
            'select-none' => [],
        ];

        foreach ($expected as $candidate => $properties) {
            $compiled = self::compiled((string) $candidate);

            self::assertNotNull($compiled, (string) $candidate);
            self::assertSame($properties, $compiled->properties, (string) $candidate);
        }
    }

    /**
     * @return list<string>
     */
    private static function used(string $candidate, ThemeRegistry $theme): array
    {
        $compiled = self::compiled($candidate, $theme);

        return $compiled === null ? ['(refused)'] : $compiled->usedVars;
    }

    private static function css(string $candidate): ?string
    {
        $compiled = self::compiled($candidate);

        return $compiled === null ? null : self::render($compiled->nodes);
    }

    private static function compiled(string $candidate, ?ThemeRegistry $theme = null): ?CompiledUtility
    {
        $registry = Registry::default();

        return (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            $theme ?? self::theme()
        );
    }

    /**
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value . ($node->important ? '!important' : '');
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            }
        }

        return \implode(';', $parts);
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }
}
