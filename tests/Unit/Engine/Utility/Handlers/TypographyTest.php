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
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\Typography;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The typography handlers (TASK-0037): `fontSize` for `text-<size>` and
 * `lineClamp`. Each case runs the real parser, registry and compiler, so the
 * handler is exercised exactly as the engine calls it; the shapes are the
 * pinned 4.1.18 CLI's.
 */
final class TypographyTest extends TestCase
{
    /** @test */
    public function theFamilyImplementsTheContractAndOwnsTwoHandlers(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new Typography(new ValueResolver()));
        self::assertSame(['fontSize', 'lineClamp'], Typography::names());

        foreach (Typography::names() as $name) {
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
        $clamp = 'overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:';

        return [
            'a theme size has its line height companion' => [
                'text-sm',
                'font-size:var(--text-sm);line-height:var(--tw-leading, var(--text-sm--line-height))',
            ],
            'the largest size' => [
                'text-9xl',
                'font-size:var(--text-9xl);line-height:var(--tw-leading, var(--text-9xl--line-height))',
            ],
            'a spacing line height replaces the companion' => [
                'text-xs/6',
                'font-size:var(--text-xs);line-height:calc(var(--spacing) * 6)',
            ],
            'a theme leading' => ['text-lg/loose', 'font-size:var(--text-lg);line-height:var(--leading-loose)'],
            'none is one' => ['text-lg/none', 'font-size:var(--text-lg);line-height:1'],
            'an arbitrary line height' => ['text-lg/[1.5]', 'font-size:var(--text-lg);line-height:1.5'],
            'a variable line height' => ['text-lg/(--lh)', 'font-size:var(--text-lg);line-height:var(--lh)'],
            'an arbitrary size' => ['text-[14px]', 'font-size:14px'],
            'an arbitrary size with a line height' => [
                'text-[14px]/7',
                'font-size:14px;line-height:calc(var(--spacing) * 7)',
            ],
            'a size by hint' => ['text-[length:14px]/tight', 'font-size:14px;line-height:var(--leading-tight)'],
            'a variable size by hint' => ['text-(length:--s)', 'font-size:var(--s)'],
            'important' => [
                'text-sm!',
                'font-size:var(--text-sm)!important;'
                . 'line-height:var(--tw-leading, var(--text-sm--line-height))!important',
            ],
            'a clamp' => ['line-clamp-3', $clamp . '3'],
            'a zero clamp' => ['line-clamp-0', $clamp . '0'],
            'an arbitrary clamp' => ['line-clamp-[7]', $clamp . '7'],
            'a variable clamp' => ['line-clamp-(--n)', $clamp . 'var(--n)'],
            'no clamp' => [
                'line-clamp-none',
                'overflow:visible;display:block;-webkit-box-orient:horizontal;-webkit-line-clamp:unset',
            ],
            'an important clamp' => [
                'line-clamp-2!',
                'overflow:hidden!important;display:-webkit-box!important;-webkit-box-orient:vertical!important;'
                . '-webkit-line-clamp:2!important',
            ],
        ];
    }

    /**
     * What the CLI refuses, the handlers refuse: unknown sizes, decimals and
     * fractions, line heights the scale does not have, a negative, a text
     * shadow, a modifier on a clamp.
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
            'an unknown size' => ['text-nope'],
            'a fraction size' => ['text-1/2'],
            'a decimal line height' => ['text-sm/3.3'],
            'a percentage line height' => ['text-sm/100%'],
            'an unknown line height' => ['text-sm/foo'],
            'a negative size' => ['-text-sm'],
            'a text shadow is not a size' => ['text-shadow-sm'],
            'a bare clamp' => ['line-clamp'],
            'a decimal clamp' => ['line-clamp-1.5'],
            'a fraction clamp' => ['line-clamp-1/2'],
            'a negative clamp' => ['-line-clamp-3'],
            'a clamp with a modifier' => ['line-clamp-3/50'],
            'a clamp with an unknown keyword' => ['line-clamp-auto'],
        ];
    }

    /**
     * T-15: text that could end a declaration or a rule, open a comment or
     * smuggle a script URL is refused by the parser before any handler sees
     * it, in the value and in the line-height modifier.
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
        return [
            'size semicolon' => ['text-[1px;color:red]'],
            'size close brace' => ['text-[1px}a{b:c]'],
            'size comment' => ['text-[1px/*x*/]'],
            'size script url' => ['text-[url(javascript:alert(1))]'],
            'clamp semicolon' => ['line-clamp-[1;x:y]'],
            'clamp close brace' => ['line-clamp-[1}a{b:c]'],
            'clamp comment' => ['line-clamp-[1/*x*/]'],
            'clamp script url' => ['line-clamp-[url(javascript:alert(1))]'],
            'modifier semicolon' => ['text-sm/[1;color:red]'],
            'modifier close brace' => ['text-sm/[1}a{b:c]'],
            'modifier comment' => ['text-sm/[1/*x*/]'],
        ];
    }

    /**
     * A candidate built around the parser (the value and modifier classes have
     * public constructors) is still stopped by the compiler's emission guard
     * or by the handler: a static candidate, a negative one, a size that is
     * not a theme key, a modifier that is neither a theme leading nor a
     * number.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $static = new Candidate('text', [], false, false, Candidate::STATIC, 'text', null);
        $clamp = new Candidate('line-clamp', [], false, false, Candidate::STATIC, 'line-clamp', null);
        $bogus = new Candidate('x', [], false, false, Candidate::FUNCTIONAL, 'text', new NamedValue('bogus'));
        $odd = new Candidate(
            'y',
            [],
            false,
            false,
            Candidate::FUNCTIONAL,
            'text',
            new NamedValue('sm'),
            new Modifier(Modifier::NAMED, 'x;y')
        );
        $hostile = new Candidate(
            'z',
            [],
            false,
            false,
            Candidate::FUNCTIONAL,
            'text',
            new NamedValue('sm'),
            new Modifier(Modifier::ARBITRARY, '1;color:red')
        );

        self::assertNull($compiler->compile($static, $theme));
        self::assertNull($compiler->compile($clamp, $theme));
        self::assertNull($compiler->compile($bogus, $theme));
        self::assertNull($compiler->compile($odd, $theme));
        self::assertNull($compiler->compile($hostile, $theme), 'the emission guard stops what a handler passes on');
    }

    /**
     * Handlers report the theme variables they read and never mark them: the
     * size and its companion, the leading or the spacing, and none for a
     * literal or an arbitrary value.
     *
     * @test
     */
    public function usedVarsAreReportedNotMarked(): void
    {
        $theme = self::theme();

        self::assertSame(['--text-sm', '--text-sm--line-height'], self::used('text-sm', $theme));
        self::assertSame(['--text-sm', '--spacing'], self::used('text-sm/7', $theme));
        self::assertSame(['--text-sm', '--leading-tight'], self::used('text-sm/tight', $theme));
        self::assertSame(['--text-sm'], self::used('text-sm/none', $theme));
        self::assertSame(['--text-sm'], self::used('text-sm/[2]', $theme));
        self::assertSame([], self::used('text-[14px]', $theme));
        self::assertSame(['--spacing'], self::used('text-[14px]/7', $theme));
        self::assertSame([], self::used('line-clamp-3', $theme));
        self::assertSame([], $theme->usedVars(), 'nothing was marked');
    }

    /**
     * A theme that defines the other two companions gets them too, each
     * behind its `--tw-*` property, after the line height.
     *
     * @test
     */
    public function letterSpacingAndWeightCompanionsFollowTheLineHeight(): void
    {
        $theme = self::theme();
        $theme->merge([
            '--text-fancy' => '2rem',
            '--text-fancy--line-height' => '1.1',
            '--text-fancy--letter-spacing' => '0.02em',
            '--text-fancy--font-weight' => '600',
        ], new ThemeFlags());

        self::assertSame(
            'font-size:var(--text-fancy);line-height:var(--tw-leading, var(--text-fancy--line-height));'
            . 'letter-spacing:var(--tw-tracking, var(--text-fancy--letter-spacing));'
            . 'font-weight:var(--tw-font-weight, var(--text-fancy--font-weight))',
            self::css('text-fancy', $theme)
        );
        self::assertSame(
            'font-size:var(--text-fancy);line-height:calc(var(--spacing) * 6);'
            . 'letter-spacing:var(--tw-tracking, var(--text-fancy--letter-spacing));'
            . 'font-weight:var(--tw-font-weight, var(--text-fancy--font-weight))',
            self::css('text-fancy/6', $theme),
            'a modifier replaces the line height only'
        );
        self::assertSame(
            ['--text-fancy', '--text-fancy--line-height', '--text-fancy--letter-spacing', '--text-fancy--font-weight'],
            self::used('text-fancy', $theme)
        );
    }

    /**
     * An inline theme size is written as its value, not as a variable.
     *
     * @test
     */
    public function anInlineThemeSizeIsWrittenAsItsValue(): void
    {
        $theme = self::theme();
        $theme->merge(['--text-tiny' => '0.5rem', '--text-tiny--line-height' => '1'], new ThemeFlags(true));

        self::assertSame('font-size:0.5rem;line-height:var(--tw-leading, 1)', self::css('text-tiny', $theme));
        self::assertSame([], self::used('text-tiny', $theme));
    }

    /**
     * The font-size utilities read `--tw-leading`, `--tw-tracking` and
     * `--tw-font-weight` as a fallback without declaring them, so they report
     * nothing to register; the numeric statics register all five of theirs;
     * `leading`, `tracking` and `font` register their own.
     *
     * @test
     */
    public function theCustomPropertiesToRegisterAreReported(): void
    {
        $expected = [
            'text-sm' => [],
            'line-clamp-3' => [],
            'leading-tight' => ['--tw-leading'],
            'tracking-wide' => ['--tw-tracking'],
            'font-bold' => ['--tw-font-weight'],
            'font-[500]' => ['--tw-font-weight'],
            'font-sans' => [],
            'ordinal' => [
                '--tw-ordinal',
                '--tw-slashed-zero',
                '--tw-numeric-figure',
                '--tw-numeric-spacing',
                '--tw-numeric-fraction',
            ],
            'normal-nums' => [],
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
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::render($node->nodes) . '}';
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
