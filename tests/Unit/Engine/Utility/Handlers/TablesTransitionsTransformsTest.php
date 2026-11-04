<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Handlers;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
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
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\TablesTransitionsTransforms;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The tables, transitions and transforms handlers (TASK-0039): `transform`
 * for the axis pairs (`translate`, `scale`, `rotate`, `border-spacing`) and
 * `transition`. Each case runs the real parser, registry and compiler, so the
 * handler is exercised exactly as the engine calls it; the shapes are the
 * pinned 4.1.18 CLI's.
 */
final class TablesTransitionsTransformsTest extends TestCase
{
    private const TIMING = 'transition-timing-function:var(--tw-ease, var(--default-transition-timing-function))';

    private const DURATION = 'transition-duration:var(--tw-duration, var(--default-transition-duration))';

    /** @test */
    public function theFamilyImplementsTheContractAndOwnsTwoHandlers(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new TablesTransitionsTransforms(new ValueResolver()));
        self::assertSame(['transform', 'transition'], TablesTransitionsTransforms::names());

        foreach (TablesTransitionsTransforms::names() as $name) {
            self::assertArrayHasKey($name, Handlers::TAKES_VALUE);
            self::assertNotContains($name, (new Handlers(new ValueResolver()))->unimplemented());
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
        return [
            'a named transition' => [
                'transition-opacity',
                'transition-property:opacity;' . self::TIMING . ';' . self::DURATION,
            ],
            'a transition of the transform properties' => [
                'transition-transform',
                'transition-property:transform, translate, scale, rotate;' . self::TIMING . ';' . self::DURATION,
            ],
            'an arbitrary transition' => [
                'transition-[opacity,transform]',
                'transition-property:opacity,transform;' . self::TIMING . ';' . self::DURATION,
            ],
            'a variable transition' => [
                'transition-(--p)',
                'transition-property:var(--p);' . self::TIMING . ';' . self::DURATION,
            ],
            'none is the property alone' => ['transition-none', 'transition-property:none'],
            'important transition' => [
                'transition-all!',
                'transition-property:all!important;' . self::TIMING . '!important;' . self::DURATION . '!important',
            ],
            'translate both axes' => [
                'translate-1/2',
                '--tw-translate-x:calc(1/2 * 100%);--tw-translate-y:calc(1/2 * 100%);'
                . 'translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate one axis' => [
                'translate-y-px',
                '--tw-translate-y:1px;translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate variable' => [
                'translate-x-(--t)',
                '--tw-translate-x:var(--t);translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate arbitrary' => [
                'translate-[10px]',
                '--tw-translate-x:10px;--tw-translate-y:10px;translate:var(--tw-translate-x) var(--tw-translate-y)',
            ],
            'translate important reaches every declaration' => [
                'translate-x-4!',
                '--tw-translate-x:calc(var(--spacing) * 4)!important;'
                . 'translate:var(--tw-translate-x) var(--tw-translate-y)!important',
            ],
            'scale one axis' => ['scale-x-50', '--tw-scale-x:50%;scale:var(--tw-scale-x) var(--tw-scale-y)'],
            'scale none' => ['scale-none', 'scale:none'],
            'scale arbitrary' => ['scale-[0.5]', 'scale:0.5'],
            'scale variable' => ['scale-(--s)', 'scale:var(--s)'],
            'rotate whole degrees' => ['rotate-45', 'rotate:45deg'],
            'rotate arbitrary' => ['rotate-[1turn]', 'rotate:1turn'],
            'rotate variable' => ['rotate-(--r)', 'rotate:var(--r)'],
            'rotate none' => ['rotate-none', 'rotate:none'],
            'negative rotate' => ['-rotate-45', 'rotate:calc(45deg * -1)'],
            'border spacing both' => [
                'border-spacing-px',
                '--tw-border-spacing-x:1px;--tw-border-spacing-y:1px;'
                . 'border-spacing:var(--tw-border-spacing-x) var(--tw-border-spacing-y)',
            ],
            'border spacing axis' => [
                'border-spacing-x-[10px]',
                '--tw-border-spacing-x:10px;border-spacing:var(--tw-border-spacing-x) var(--tw-border-spacing-y)',
            ],
        ];
    }

    /**
     * What the CLI refuses, the handlers refuse: decimals where only whole
     * numbers fit, a negative where none is allowed, a keyword the axis form
     * lacks, an unknown name.
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
            'a decimal scale' => ['scale-1.5'],
            'a decimal axis scale' => ['scale-y-0.5'],
            'a decimal rotation' => ['rotate-1.5'],
            'a fraction scale' => ['scale-1/2'],
            'none on an axis' => ['scale-x-none'],
            'none on a translate axis' => ['translate-y-none'],
            'a negative none' => ['-scale-none'],
            'a negative border spacing' => ['-border-spacing-4'],
            'a fraction border spacing' => ['border-spacing-1/2'],
            'an off-scale translate' => ['translate-0.3'],
            'an unknown transition name' => ['transition-foo'],
            'a negative transition' => ['-transition-all'],
            'a fraction transition' => ['transition-1/2'],
        ];
    }

    /**
     * T-15: text that could end a declaration or a rule, open a comment or
     * smuggle a script URL is refused by the parser before any handler sees it.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachAHandler(string $candidate): void
    {
        $registry = Registry::default();

        foreach ((new CandidateParser($registry))->parse($candidate) as $reading) {
            self::assertNotInstanceOf(ArbitraryValue::class, $reading->value, $candidate);
        }

        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCandidates(): array
    {
        $cases = [];

        foreach (['translate', 'translate-x', 'scale', 'scale-y', 'rotate', 'border-spacing', 'transition'] as $root) {
            $cases[$root . ' semicolon'] = [$root . '-[1px;color:red]'];
            $cases[$root . ' close brace'] = [$root . '-[1px}a{b:c]'];
            $cases[$root . ' comment'] = [$root . '-[1px/*x*/]'];
            $cases[$root . ' script url'] = [$root . '-[url(javascript:alert(1))]'];
        }

        return $cases;
    }

    /**
     * Candidates built around the parser (the value classes have public
     * constructors) are stopped by the handler or the compiler's emission
     * guard: a static axis root, a negative none, a fraction transition, a
     * unit-less negative.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $static = new Candidate('translate-x', [], false, false, Candidate::STATIC, 'translate-x', null);
        $none = new Candidate('x', [], false, true, Candidate::FUNCTIONAL, 'scale', new NamedValue('none'));
        $half = new NamedValue('1/2', true);
        $fraction = new Candidate('y', [], false, false, Candidate::FUNCTIONAL, 'transition', $half);
        $bogus = new Candidate('z', [], false, false, Candidate::FUNCTIONAL, 'scale-x', new NamedValue('bogus'));

        self::assertNull($compiler->compile($static, $theme));
        self::assertNull($compiler->compile($none, $theme));
        self::assertNull($compiler->compile($fraction, $theme));
        self::assertNull($compiler->compile($bogus, $theme));
    }

    /**
     * Handlers report the theme variables they read and never mark them: the
     * spacing scale for a bare number, the two defaults for a transition, none
     * for a literal.
     *
     * @test
     */
    public function usedVarsAreReportedNotMarked(): void
    {
        $theme = self::theme();

        self::assertSame(['--spacing'], self::used('translate-4', $theme));
        self::assertSame([], self::used('translate-px', $theme));
        self::assertSame([], self::used('translate-[10px]', $theme));
        self::assertSame([], self::used('scale-50', $theme));
        self::assertSame(['--spacing'], self::used('border-spacing-x-4', $theme));
        self::assertSame(
            ['--default-transition-duration', '--default-transition-timing-function'],
            self::sorted(self::used('transition', $theme))
        );
        self::assertSame(
            ['--default-transition-duration', '--default-transition-timing-function'],
            self::sorted(self::used('transition-[opacity]', $theme))
        );
        self::assertSame([], self::used('transition-none', $theme));
        self::assertSame([], self::used('transition-discrete', $theme));
        self::assertSame([], $theme->usedVars(), 'nothing was marked');
    }

    /**
     * A theme without the transition defaults reports none of them.
     *
     * @test
     */
    public function aThemeWithoutTheTransitionDefaultsReportsNone(): void
    {
        $theme = ThemeRegistry::fromDefault([], []);

        self::assertSame([], self::used('transition', $theme));
    }

    /**
     * The CLI registers the variables of the pair with `@property` whichever
     * axis is used; the transform composition registers its five; the others
     * register nothing.
     *
     * @test
     */
    public function theCustomPropertiesToRegisterAreReported(): void
    {
        $expected = [
            'translate-4' => ['--tw-translate-x', '--tw-translate-y', '--tw-translate-z'],
            'translate-x-4' => ['--tw-translate-x', '--tw-translate-y', '--tw-translate-z'],
            'scale-50' => ['--tw-scale-x', '--tw-scale-y', '--tw-scale-z'],
            'scale-y-50' => ['--tw-scale-x', '--tw-scale-y', '--tw-scale-z'],
            'scale-[0.5]' => [],
            'scale-none' => [],
            'rotate-45' => [],
            'border-spacing-4' => ['--tw-border-spacing-x', '--tw-border-spacing-y'],
            'border-spacing-x-4' => ['--tw-border-spacing-x', '--tw-border-spacing-y'],
            'transform' => ['--tw-rotate-x', '--tw-rotate-y', '--tw-rotate-z', '--tw-skew-x', '--tw-skew-y'],
            'transform-cpu' => [],
            'transform-gpu' => [],
            'transform-none' => [],
            'transition' => [],
            'duration-75' => ['--tw-duration'],
            'duration-initial' => ['--tw-duration'],
            'ease-in' => ['--tw-ease'],
            'ease-initial' => ['--tw-ease'],
            'delay-75' => [],
            'animate-spin' => [],
        ];

        foreach ($expected as $candidate => $properties) {
            $compiled = self::compiled((string) $candidate);

            self::assertNotNull($compiled, (string) $candidate);
            self::assertSame($properties, $compiled->properties, (string) $candidate);
        }
    }

    /**
     * An inline spacing scale is written as its value in the pair.
     *
     * @test
     */
    public function anInlineSpacingIsWrittenAsItsValue(): void
    {
        $theme = self::theme();
        $theme->merge(['--spacing' => '2px'], new ThemeFlags(true));

        self::assertSame(
            '--tw-translate-x:calc(2px * 3);--tw-translate-y:calc(2px * 3);'
            . 'translate:var(--tw-translate-x) var(--tw-translate-y)',
            self::css('translate-3', $theme)
        );
        self::assertSame([], self::used('translate-3', $theme));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        \sort($names);

        return $names;
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
