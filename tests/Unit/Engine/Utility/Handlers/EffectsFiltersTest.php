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
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\EffectsFilters;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The effects handler (TASK-0039): `shadow`, for `shadow` and `inset-shadow`.
 * Each case runs the real parser, registry and compiler, so the handler is
 * exercised exactly as the engine calls it; the shapes are the pinned 4.1.18
 * CLI's.
 */
final class EffectsFiltersTest extends TestCase
{
    private const BOX = 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
        . 'var(--tw-ring-shadow), var(--tw-shadow)';

    private const SUPPORTS = '@supports (color: color-mix(in lab, red, red))';

    /** @test */
    public function theFamilyImplementsTheContractAndOwnsTheShadowHandler(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new EffectsFilters(new ValueResolver()));
        self::assertSame(['shadow'], EffectsFilters::names());
        self::assertArrayHasKey('shadow', Handlers::TAKES_VALUE);
        self::assertNotContains('shadow', (new Handlers(new ValueResolver()))->unimplemented());
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
        $white = self::SUPPORTS
            . '{--tw-shadow-color:color-mix(in oklab, var(--color-white) var(--tw-shadow-alpha), transparent)}';

        return [
            'the default shadow' => [
                'shadow',
                '--tw-shadow:0 1px 3px 0 var(--tw-shadow-color, rgb(0 0 0 / 0.1)), '
                . '0 1px 2px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1));' . self::BOX,
            ],
            'a theme shadow is inlined' => [
                'shadow-2xs',
                '--tw-shadow:0 1px var(--tw-shadow-color, rgb(0 0 0 / 0.05));' . self::BOX,
            ],
            'none' => ['shadow-none', '--tw-shadow:0 0 #0000;' . self::BOX],
            'an arbitrary shadow' => [
                'shadow-[0_0_10px_red]',
                '--tw-shadow:0 0 10px var(--tw-shadow-color, red);' . self::BOX,
            ],
            'a variable is a whole shadow' => ['shadow-(--s)', '--tw-shadow:var(--s);' . self::BOX],
            'important' => [
                'shadow-none!',
                '--tw-shadow:0 0 #0000!important;' . self::BOX . '!important',
            ],
            'a theme colour' => [
                'shadow-white',
                '--tw-shadow-color:#fff;' . $white,
            ],
            'a colour keyword' => [
                'shadow-current',
                '--tw-shadow-color:currentcolor;' . self::SUPPORTS
                . '{--tw-shadow-color:color-mix(in oklab, currentcolor var(--tw-shadow-alpha), transparent)}',
            ],
            'a colour with an opacity' => [
                'shadow-white/50',
                '--tw-shadow-color:color-mix(in srgb, #fff 50%, transparent);' . self::SUPPORTS
                . '{--tw-shadow-color:color-mix(in oklab, color-mix(in oklab, var(--color-white) 50%, transparent) '
                . 'var(--tw-shadow-alpha), transparent)}',
            ],
            'a variable colour by hint' => [
                'shadow-(color:--c)',
                '--tw-shadow-color:var(--c);' . self::SUPPORTS
                . '{--tw-shadow-color:color-mix(in oklab, var(--c) var(--tw-shadow-alpha), transparent)}',
            ],
            'the inset default colour slot' => [
                'inset-shadow-xs',
                '--tw-inset-shadow:inset 0 1px 1px var(--tw-inset-shadow-color, rgb(0 0 0 / 0.05));' . self::BOX,
            ],
            'inset none is not prefixed' => ['inset-shadow-none', '--tw-inset-shadow:0 0 #0000;' . self::BOX],
            'an inset colour' => [
                'inset-shadow-white',
                '--tw-inset-shadow-color:#fff;' . self::SUPPORTS
                . '{--tw-inset-shadow-color:color-mix(in oklab, var(--color-white) var(--tw-inset-shadow-alpha), '
                . 'transparent)}',
            ],
            'an inset colour keyword' => ['inset-shadow-inherit', '--tw-inset-shadow-color:inherit'],
        ];
    }

    /**
     * What the CLI refuses, the handler refuses: a negative, a fraction, an
     * unknown name, a bare `inset-shadow`, an opacity where none fits, a
     * modifier that is not an opacity.
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
            'a negative shadow' => ['-shadow-sm'],
            'a negative colour' => ['-shadow-red-500'],
            'a fraction' => ['shadow-1/2'],
            'an unknown name' => ['shadow-nope'],
            'a bare inset shadow' => ['inset-shadow'],
            'none with an opacity' => ['shadow-none/50'],
            'inherit with an opacity' => ['shadow-inherit/50'],
            'an unknown opacity' => ['shadow-red-500/foo'],
            'a colour of the other root' => ['inset-shadow-md'],
        ];
    }

    /**
     * T-15: text that could end a declaration or a rule, open a comment or
     * smuggle a script URL is refused by the parser before the handler sees
     * it, in the value and in the opacity modifier.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachTheHandler(string $candidate): void
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

        foreach (['shadow', 'inset-shadow'] as $root) {
            $cases[$root . ' semicolon'] = [$root . '-[0_0_1px;color:red]'];
            $cases[$root . ' close brace'] = [$root . '-[0_0_1px}a{b:c]'];
            $cases[$root . ' comment'] = [$root . '-[0_0_1px/*x*/]'];
            $cases[$root . ' script url'] = [$root . '-[url(javascript:alert(1))]'];
            $cases[$root . ' colour semicolon'] = [$root . '-[color:red;x:y]'];
            $cases[$root . ' modifier semicolon'] = [$root . '-red-500/[1;color:red]'];
            $cases[$root . ' modifier comment'] = [$root . '-sm/[1/*x*/]'];
        }

        return $cases;
    }

    /**
     * Candidates built around the parser (the value and modifier classes have
     * public constructors) are stopped by the handler or the compiler's
     * emission guard: a negative shadow, a fraction, a hostile theme colour
     * reference, an arbitrary modifier that would end the declaration.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $negative = new Candidate('x', [], false, true, Candidate::FUNCTIONAL, 'shadow', new NamedValue('sm'));
        $fraction = new Candidate('y', [], false, false, Candidate::FUNCTIONAL, 'shadow', new NamedValue('1/2', true));
        $hostile = new Candidate(
            'z',
            [],
            false,
            false,
            Candidate::FUNCTIONAL,
            'shadow',
            new NamedValue('white'),
            new Modifier(Modifier::ARBITRARY, '1;color:red')
        );

        self::assertNull($compiler->compile($negative, $theme));
        self::assertNull($compiler->compile($fraction, $theme));
        self::assertNull($compiler->compile($hostile, $theme), 'the emission guard stops what a handler passes on');
    }

    /**
     * A theme shadow is inlined and marks nothing; a theme colour reports
     * `--color-*` (the fallback uses its value, the mix its variable); a
     * literal or arbitrary colour reads none. Nothing is marked used.
     *
     * @test
     */
    public function usedVarsAreReportedNotMarked(): void
    {
        $theme = self::theme();

        self::assertSame([], self::used('shadow-md', $theme));
        self::assertSame([], self::used('shadow', $theme));
        self::assertSame([], self::used('inset-shadow-sm', $theme));
        self::assertSame(['--color-red-500'], self::used('shadow-red-500', $theme));
        self::assertSame(['--color-red-500'], self::used('shadow-red-500/50', $theme));
        self::assertSame([], self::used('shadow-current', $theme));
        self::assertSame([], self::used('shadow-[color:red]', $theme));
        self::assertSame([], self::used('shadow-sm/50', $theme));
        self::assertSame([], $theme->usedVars(), 'nothing was marked');
    }

    /**
     * An inline theme colour is written as its value, not as a variable.
     *
     * @test
     */
    public function anInlineThemeColourIsWrittenAsItsValue(): void
    {
        $theme = self::theme();
        $theme->merge(['--color-brand' => '#123456'], new ThemeFlags(true));

        self::assertSame(
            '--tw-shadow-color:#123456;' . self::SUPPORTS
            . '{--tw-shadow-color:color-mix(in oklab, #123456 var(--tw-shadow-alpha), transparent)}',
            self::css('shadow-brand', $theme)
        );
        self::assertSame([], self::used('shadow-brand', $theme));
    }

    /**
     * The CLI registers the whole box-shadow composition whichever shadow
     * utility is used, in this order; the handler returns it.
     *
     * @test
     */
    public function everyShadowRegistersTheWholeComposition(): void
    {
        $expected = [
            '--tw-shadow',
            '--tw-shadow-color',
            '--tw-shadow-alpha',
            '--tw-inset-shadow',
            '--tw-inset-shadow-color',
            '--tw-inset-shadow-alpha',
            '--tw-ring-color',
            '--tw-ring-shadow',
            '--tw-inset-ring-color',
            '--tw-inset-ring-shadow',
            '--tw-ring-inset',
            '--tw-ring-offset-width',
            '--tw-ring-offset-color',
            '--tw-ring-offset-shadow',
        ];

        $candidates = ['shadow', 'shadow-md', 'shadow-none', 'shadow-red-500', 'shadow-[color:red]', 'inset-shadow-sm'];

        foreach ($candidates as $c) {
            $compiled = self::compiled($c);

            self::assertNotNull($compiled, $c);
            self::assertSame($expected, $compiled->properties, $c);
        }

        $opacity = self::compiled('opacity-50');

        self::assertNotNull($opacity);
        self::assertSame([], $opacity->properties);
    }

    /**
     * @return list<string>
     */
    private static function used(string $candidate, ThemeRegistry $theme): array
    {
        $compiled = self::compiled($candidate, $theme);

        return $compiled === null ? ['(refused)'] : $compiled->usedVars;
    }

    private static function css(string $candidate, ?ThemeRegistry $theme = null): ?string
    {
        $compiled = self::compiled($candidate, $theme);

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
