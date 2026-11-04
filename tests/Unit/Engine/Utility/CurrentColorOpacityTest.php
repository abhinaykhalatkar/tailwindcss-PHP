<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;

/**
 * `<colour root>-current/<n>` in every family (TASK-0042): the 4.1.18 CLI writes
 * `currentcolor` itself as the fallback line (a mix of `currentcolor` could not
 * be computed ahead of time) and the oklab mix under the support query. Every
 * colour root with an opacity was probed against the pinned binary; the shadow
 * roots, which compose their colour with `--tw-shadow-alpha`, were the one that
 * wrote a srgb mix instead.
 */
final class CurrentColorOpacityTest extends TestCase
{
    private const SUPPORTS = '@supports (color: color-mix(in lab, red, red))';

    /**
     * @test
     *
     * @dataProvider plainRoots
     */
    public function aColourRootKeepsCurrentcolorAsItsFallback(string $candidate, string $property): void
    {
        self::assertSame(
            $property . ':currentcolor;' . self::SUPPORTS . '{' . $property
            . ':color-mix(in oklab, currentcolor 50%, transparent)}',
            self::css($candidate),
            $candidate
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function plainRoots(): array
    {
        return [
            'background' => ['bg-current/50', 'background-color'],
            'text' => ['text-current/50', 'color'],
            'border' => ['border-current/50', 'border-color'],
            'fill' => ['fill-current/50', 'fill'],
            'stroke' => ['stroke-current/50', 'stroke'],
            'ring' => ['ring-current/50', '--tw-ring-color'],
            'decoration' => ['decoration-current/50', 'text-decoration-color'],
            'outline' => ['outline-current/50', 'outline-color'],
            'an arbitrary colour' => ['bg-[currentcolor]/50', 'background-color'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider shadowRoots
     */
    public function theShadowRootsDoToo(string $candidate, string $property, string $alpha): void
    {
        self::assertSame(
            $property . ':currentcolor;' . self::SUPPORTS . '{' . $property
            . ':color-mix(in oklab, color-mix(in oklab, currentcolor 50%, transparent) var(' . $alpha
            . '), transparent)}',
            self::css($candidate),
            $candidate
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function shadowRoots(): array
    {
        return [
            'shadow' => ['shadow-current/50', '--tw-shadow-color', '--tw-shadow-alpha'],
            'inset shadow' => ['inset-shadow-current/50', '--tw-inset-shadow-color', '--tw-inset-shadow-alpha'],
            'an arbitrary colour' => ['shadow-[currentcolor]/50', '--tw-shadow-color', '--tw-shadow-alpha'],
        ];
    }

    /**
     * The gradient stops and the divider keep it too (their rules go on with the stop composition, so the
     * start of the output is compared).
     *
     * @test
     *
     * @dataProvider composedRoots
     *
     * @param non-empty-string $start
     */
    public function theComposedRootsDoToo(string $candidate, string $start): void
    {
        self::assertStringStartsWith($start, (string) self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function composedRoots(): array
    {
        $supports = ';' . self::SUPPORTS . '{';

        return [
            'from' => [
                'from-current/50',
                '--tw-gradient-from:currentcolor' . $supports
                . '--tw-gradient-from:color-mix(in oklab, currentcolor 50%, transparent)};',
            ],
            'via' => [
                'via-current/50',
                '--tw-gradient-via:currentcolor' . $supports
                . '--tw-gradient-via:color-mix(in oklab, currentcolor 50%, transparent)};',
            ],
            'to' => [
                'to-current/50',
                '--tw-gradient-to:currentcolor' . $supports
                . '--tw-gradient-to:color-mix(in oklab, currentcolor 50%, transparent)};',
            ],
        ];
    }

    /**
     * The spelling is kept as written (`currentColor`), the rule is the same.
     *
     * @test
     */
    public function theSpellingIsKeptAndTheRuleIsCaseBlind(): void
    {
        self::assertSame(
            'color:currentColor;' . self::SUPPORTS . '{color:color-mix(in oklab, currentColor 50%, transparent)}',
            self::css('text-[currentColor]/50')
        );
        self::assertSame(
            '--tw-shadow-color:currentColor;' . self::SUPPORTS
            . '{--tw-shadow-color:color-mix(in oklab, color-mix(in oklab, currentColor 50%, transparent) '
            . 'var(--tw-shadow-alpha), transparent)}',
            self::css('shadow-[color:currentColor]/50')
        );
    }

    /**
     * A variable colour has no value to mix ahead of time either; a plain
     * theme colour and `transparent` still get the srgb mix.
     *
     * @test
     */
    public function aVariableColourKeepsItsFallbackButAThemeColourIsMixed(): void
    {
        self::assertSame(
            '--tw-shadow-color:var(--c);' . self::SUPPORTS
            . '{--tw-shadow-color:color-mix(in oklab, color-mix(in oklab, var(--c) 50%, transparent) '
            . 'var(--tw-shadow-alpha), transparent)}',
            self::css('shadow-(color:--c)/50')
        );
        self::assertSame(
            '--tw-shadow-color:color-mix(in srgb, transparent 50%, transparent);' . self::SUPPORTS
            . '{--tw-shadow-color:color-mix(in oklab, color-mix(in oklab, transparent 50%, transparent) '
            . 'var(--tw-shadow-alpha), transparent)}',
            self::css('shadow-transparent/50')
        );
        self::assertStringStartsWith(
            '--tw-shadow-color:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);',
            (string) self::css('shadow-red-500/50')
        );
    }

    /**
     * A theme colour that is itself `currentcolor` (any case) or one `var()` falls back to its own reference,
     * whatever the alpha is; the support query mixes the reference. A colour that merely reads a variable
     * (`rgb(var(--x) 0 0)`) is mixed ahead of time like a literal one (probed against the pinned CLI).
     *
     * @test
     *
     * @dataProvider themeColours
     */
    public function aThemeColourThatIsUnmixableFallsBackToItsReference(string $candidate, string $expected): void
    {
        self::assertSame($expected, self::css($candidate, true), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function themeColours(): array
    {
        $mix = static function (string $property, string $fallback, string $mixed): string {
            return $property . ':' . $fallback . ';' . self::SUPPORTS . '{' . $property . ':' . $mixed . '}';
        };
        $shadow = static function (string $fallback, string $inner): string {
            return '--tw-shadow-color:' . $fallback . ';' . self::SUPPORTS
                . '{--tw-shadow-color:color-mix(in oklab, ' . $inner . ' var(--tw-shadow-alpha), transparent)}';
        };
        $cur50 = 'color-mix(in oklab, var(--color-cur) 50%, transparent)';
        $brand50 = 'color-mix(in oklab, var(--color-brand) 50%, transparent)';
        $curVar = 'color-mix(in oklab, var(--color-cur) var(--a), transparent)';
        $mid50 = 'color-mix(in oklab, var(--color-mid) 50%, transparent)';
        $midVar = 'color-mix(in oklab, var(--color-mid) var(--a), transparent)';
        $midMix = 'color-mix(in srgb, rgb(var(--x) 0 0) 50%, transparent)';

        return [
            'background, currentcolor' => ['bg-cur/50', $mix('background-color', 'var(--color-cur)', $cur50)],
            'background, other spelling' => [
                'bg-cap/50',
                $mix('background-color', 'var(--color-cap)', 'color-mix(in oklab, var(--color-cap) 50%, transparent)'),
            ],
            'background, one variable' => ['bg-brand/50', $mix('background-color', 'var(--color-brand)', $brand50)],
            'text, one variable' => ['text-brand/50', $mix('color', 'var(--color-brand)', $brand50)],
            'border, currentcolor' => ['border-cur/50', $mix('border-color', 'var(--color-cur)', $cur50)],
            'a variable alpha changes nothing' => [
                'bg-cur/(--a)',
                $mix('background-color', 'var(--color-cur)', $curVar),
            ],
            'shadow, currentcolor' => ['shadow-cur/50', $shadow('var(--color-cur)', $cur50)],
            'shadow, one variable' => ['shadow-brand/50', $shadow('var(--color-brand)', $brand50)],
            'shadow, variable alpha' => ['shadow-cur/(--a)', $shadow('var(--color-cur)', $curVar)],
            'background, a colour that only reads a variable is mixed' => [
                'bg-mid/50',
                $mix('background-color', $midMix, $mid50),
            ],
            'background, that colour with a variable alpha is plain' => [
                'bg-mid/(--a)',
                $mix('background-color', 'rgb(var(--x) 0 0)', $midVar),
            ],
            'shadow, a colour that only reads a variable is mixed' => ['shadow-mid/50', $shadow($midMix, $mid50)],
            'shadow, that colour with a variable alpha is plain' => [
                'shadow-mid/(--a)',
                $shadow('rgb(var(--x) 0 0)', $midVar),
            ],
        ];
    }

    /**
     * The one rule every opacity path shares.
     *
     * @test
     *
     * @dataProvider unmixable
     */
    public function theUnmixableRuleIsOneFunction(string $color, bool $wholeValue, bool $expected): void
    {
        self::assertSame($expected, Compiler::isUnmixable($color, $wholeValue), $color);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function unmixable(): array
    {
        return [
            'currentcolor' => ['currentcolor', false, true],
            'currentcolor whole' => ['currentcolor', true, true],
            'any case' => ['CurrentColor', true, true],
            'a variable' => ['var(--x)', false, true],
            'a variable whole' => ['var(--x)', true, true],
            'a function reading a variable' => ['rgb(var(--x) 0 0)', false, true],
            'that function as a whole value' => ['rgb(var(--x) 0 0)', true, false],
            'a literal' => ['#123456', false, false],
            'a literal whole' => ['#123456', true, false],
            'an empty text' => ['', true, false],
        ];
    }

    private static function css(string $candidate, bool $withThemeColours = false): ?string
    {
        $registry = Registry::default();
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());

        if ($withThemeColours) {
            $theme->merge([
                '--color-cur' => 'currentcolor',
                '--color-cap' => 'CurrentColor',
                '--color-brand' => 'var(--x)',
                '--color-mid' => 'rgb(var(--x) 0 0)',
            ], new ThemeFlags(false, false, false, false));
        }

        $compiled = (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            $theme
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
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            }
        }

        return \implode(';', $parts);
    }
}
