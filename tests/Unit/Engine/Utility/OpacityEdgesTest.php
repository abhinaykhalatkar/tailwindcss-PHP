<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;

/**
 * The opacity edges of TASK-0052, each row the pinned 4.1.18 CLI's own rule for the candidate (rendered by the same
 * `render()`; the rows were generated from the binary, not from the engine): an arbitrary shadow with an opacity on a
 * colour that reads a variable or is `currentcolor` (three levels, each only when it differs), the gradient and divide
 * fallback of an unmixable theme colour, an arbitrary reference to a theme colour (its value is inlined into the
 * fallback and the variable is used), and the colour reading of an untyped arbitrary value for `text` and
 * `decoration`.
 */
final class OpacityEdgesTest extends TestCase
{
    /**
     * Theme colours added on top of the default theme: `currentcolor` (two spellings), one variable, a colour that
     * only reads a variable and a literal.
     */
    private const COLORS = [
        '--color-cur' => 'currentcolor',
        '--color-cur2' => 'CurrentColor',
        '--color-brand' => 'var(--x)',
        '--color-mid' => 'rgb(var(--x) 0 0)',
        '--color-lit' => '#123456',
    ];

    /**
     * @test
     *
     * @dataProvider shadows
     */
    public function anArbitraryShadowWithAnOpacityIsWrittenAsTheCliDoes(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, self::css($candidate, false), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function shadows(): array
    {
        return [
            'shadow-[1px_1px_var(--c)]/50' => [
                'shadow-[1px_1px_var(--c)]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[0_0_1px_var(--c,red)]/50' => [
                'shadow-[0_0_1px_var(--c,red)]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:0 0 1px var(--tw-shadow-color, var(--c,red));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:0 0 1px var(--tw-shadow-color, '
                . 'oklab(from var(--c,red) l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'inset-shadow-[1px_1px_var(--c)]/50' => [
                'inset-shadow-[1px_1px_var(--c)]/50',
                '--tw-inset-shadow-alpha:50%;'
                . '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, '
                . 'oklab(from var(--c) l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red,2px_2px_var(--c)]/50' => [
                'shadow-[1px_1px_red,2px_2px_var(--c)]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, red), '
                . '2px 2px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 50%)), 2px 2px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_var(--c),2px_2px_red]/50' => [
                'shadow-[1px_1px_var(--c),2px_2px_red]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, var(--c)), '
                . '2px 2px var(--tw-shadow-color, red);'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / 50%)), 2px 2px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red,2px_2px_var(--c)]/(--a)' => [
                'shadow-[1px_1px_red,2px_2px_var(--c)]/(--a)',
                '--tw-shadow-alpha:var(--a);--tw-shadow:1px 1px var(--tw-shadow-color, red), '
                . '2px 2px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / var(--a))), 2px 2px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / var(--a)))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_var(--c)]/(--a)' => [
                'shadow-[1px_1px_var(--c)]/(--a)',
                '--tw-shadow-alpha:var(--a);--tw-shadow:1px 1px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / var(--a)))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_var(--c)]/100' => [
                'shadow-[1px_1px_var(--c)]/100',
                '--tw-shadow-alpha:100%;--tw-shadow:1px 1px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from var(--c) l a b / 100%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_currentcolor]/50' => [
                'shadow-[1px_1px_currentcolor]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, color-mix(in oklab, currentcolor 50%, '
                . 'transparent))};box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_currentColor]/50' => [
                'shadow-[1px_1px_currentColor]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, currentColor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, color-mix(in oklab, currentColor 50%, '
                . 'transparent))};box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_currentcolor]/(--a)' => [
                'shadow-[1px_1px_currentcolor]/(--a)',
                '--tw-shadow-alpha:var(--a);--tw-shadow:1px 1px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, color-mix(in oklab, '
                . 'currentcolor var(--a), transparent))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_currentcolor]/100' => [
                'shadow-[1px_1px_currentcolor]/100',
                '--tw-shadow-alpha:100%;--tw-shadow:1px 1px var(--tw-shadow-color, currentcolor);'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'inset-shadow-[1px_1px_currentcolor]/50' => [
                'inset-shadow-[1px_1px_currentcolor]/50',
                '--tw-inset-shadow-alpha:50%;'
                . '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, color-mix(in oklab, '
                . 'currentcolor 50%, transparent))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px]/50' => [
                'shadow-[1px_1px]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, color-mix(in oklab, currentcolor 50%, '
                . 'transparent))};box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red,2px_2px_currentcolor]/50' => [
                'shadow-[1px_1px_red,2px_2px_currentcolor]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 50%)), 2px 2px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, oklab(from red l a b / 50%)), '
                . '2px 2px var(--tw-shadow-color, color-mix(in oklab, currentcolor 50%, transparent))};'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_currentcolor,2px_2px_var(--c)]/50' => [
                'shadow-[1px_1px_currentcolor,2px_2px_var(--c)]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, currentcolor), '
                . '2px 2px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'currentcolor), 2px 2px var(--tw-shadow-color, oklab(from var(--c) l a b / 50%));'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, color-mix(in oklab, currentcolor 50%, '
                . 'transparent)), 2px 2px var(--tw-shadow-color, oklab(from var(--c) l a b / 50%))}};'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red,2px_2px_currentcolor]/(--a)' => [
                'shadow-[1px_1px_red,2px_2px_currentcolor]/(--a)',
                '--tw-shadow-alpha:var(--a);--tw-shadow:1px 1px var(--tw-shadow-color, red), '
                . '2px 2px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / var(--a))), 2px 2px var(--tw-shadow-color, currentcolor);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, oklab(from red l a b / var(--a))), '
                . '2px 2px var(--tw-shadow-color, color-mix(in oklab, currentcolor var(--a), '
                . 'transparent))}};box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red,2px_2px_currentcolor,3px_3px_var(--c)]/50' => [
                'shadow-[1px_1px_red,2px_2px_currentcolor,3px_3px_var(--c)]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, red), '
                . '2px 2px var(--tw-shadow-color, currentcolor), 3px 3px var(--tw-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 50%)), 2px 2px var(--tw-shadow-color, currentcolor), '
                . '3px 3px var(--tw-shadow-color, oklab(from var(--c) l a b / 50%));'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow:1px 1px var(--tw-shadow-color, oklab(from red l a b / 50%)), '
                . '2px 2px var(--tw-shadow-color, color-mix(in oklab, currentcolor 50%, transparent)), '
                . '3px 3px var(--tw-shadow-color, oklab(from var(--c) l a b / 50%))}};'
                . 'box-shadow:var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
                . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red]/50' => [
                'shadow-[1px_1px_red]/50',
                '--tw-shadow-alpha:50%;--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 50%));box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red]/(--a)' => [
                'shadow-[1px_1px_red]/(--a)',
                '--tw-shadow-alpha:var(--a);--tw-shadow:1px 1px var(--tw-shadow-color, red);'
                . '@supports (color: lab(from red l a b)){--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / var(--a)))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'shadow-[1px_1px_red]/100' => [
                'shadow-[1px_1px_red]/100',
                '--tw-shadow-alpha:100%;--tw-shadow:1px 1px var(--tw-shadow-color, '
                . 'oklab(from red l a b / 100%));box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'inset-shadow-[1px_1px_red,2px_2px_blue]/50' => [
                'inset-shadow-[1px_1px_red,2px_2px_blue]/50',
                '--tw-inset-shadow-alpha:50%;'
                . '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, '
                . 'oklab(from red l a b / 50%)),inset  2px 2px var(--tw-inset-shadow-color, '
                . 'oklab(from blue l a b / 50%));box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
            'inset-shadow-[1px_1px_red,2px_2px_var(--c)]/50' => [
                'inset-shadow-[1px_1px_red,2px_2px_var(--c)]/50',
                '--tw-inset-shadow-alpha:50%;'
                . '--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, '
                . 'red),inset  2px 2px var(--tw-inset-shadow-color, var(--c));'
                . '@supports (color: lab(from red l a b)){--tw-inset-shadow:inset 1px 1px var(--tw-inset-shadow-color, '
                . 'oklab(from red l a b / 50%)),inset  2px 2px var(--tw-inset-shadow-color, '
                . 'oklab(from var(--c) l a b / 50%))};box-shadow:var(--tw-inset-shadow), '
                . 'var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), '
                . 'var(--tw-shadow)',
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider themeColours
     */
    public function aThemeColourWithAnOpacityIsWrittenAsTheCliDoes(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, self::css($candidate, true), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function themeColours(): array
    {
        return [
            'from-cur/50' => [
                'from-cur/50',
                '--tw-gradient-from:var(--color-cur);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-from:color-mix(in oklab, var(--color-cur) 50%, transparent)};'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops, var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'from-cur2/50' => [
                'from-cur2/50',
                '--tw-gradient-from:var(--color-cur2);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-from:color-mix(in oklab, var(--color-cur2) 50%, transparent)};'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops, var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'from-brand/50' => [
                'from-brand/50',
                '--tw-gradient-from:var(--color-brand);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-from:color-mix(in oklab, var(--color-brand) 50%, transparent)};'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops, var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'from-mid/50' => [
                'from-mid/50',
                '--tw-gradient-from:color-mix(in srgb, rgb(var(--x) 0 0) 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){--tw-gradient-from:color-mix(in oklab, '
                . 'var(--color-mid) 50%, transparent)};--tw-gradient-stops:var(--tw-gradient-via-stops, '
                . 'var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'via-cur/50' => [
                'via-cur/50',
                '--tw-gradient-via:var(--color-cur);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-via:color-mix(in oklab, var(--color-cur) 50%, transparent)};'
                . '--tw-gradient-via-stops:var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-via) var(--tw-gradient-via-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position);'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops)',
            ],
            'to-cur/(--a)' => [
                'to-cur/(--a)',
                '--tw-gradient-to:var(--color-cur);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-to:color-mix(in oklab, var(--color-cur) var(--a), transparent)};'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops, var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'to-mid/(--a)' => [
                'to-mid/(--a)',
                '--tw-gradient-to:rgb(var(--x) 0 0);@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-gradient-to:color-mix(in oklab, var(--color-mid) var(--a), transparent)};'
                . '--tw-gradient-stops:var(--tw-gradient-via-stops, var(--tw-gradient-position), '
                . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
                . 'var(--tw-gradient-to) var(--tw-gradient-to-position))',
            ],
            'divide-brand/50' => [
                'divide-brand/50',
                ':where(& > :not(:last-child)){border-color:var(--color-brand);'
                . '@supports (color: color-mix(in lab, red, red)){border-color:color-mix(in oklab, '
                . 'var(--color-brand) 50%, transparent)}}',
            ],
            'divide-cur/50' => [
                'divide-cur/50',
                ':where(& > :not(:last-child)){border-color:var(--color-cur);'
                . '@supports (color: color-mix(in lab, red, red)){border-color:color-mix(in oklab, '
                . 'var(--color-cur) 50%, transparent)}}',
            ],
            'divide-mid/50' => [
                'divide-mid/50',
                ':where(& > :not(:last-child)){border-color:color-mix(in srgb, rgb(var(--x) 0 0) 50%, '
                . 'transparent);@supports (color: color-mix(in lab, red, '
                . 'red)){border-color:color-mix(in oklab, var(--color-mid) 50%, transparent)}}',
            ],
            'bg-[var(--color-lit)]/50' => [
                'bg-[var(--color-lit)]/50',
                'background-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){background-color:color-mix(in oklab, '
                . 'var(--color-lit) 50%, transparent)}',
            ],
            'bg-[var(--color-lit,red)]/50' => [
                'bg-[var(--color-lit,red)]/50',
                'background-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){background-color:color-mix(in oklab, '
                . 'var(--color-lit,red) 50%, transparent)}',
            ],
            'bg-[var(--color-lit)]/(--a)' => [
                'bg-[var(--color-lit)]/(--a)',
                'background-color:#123456;@supports (color: color-mix(in lab, red, '
                . 'red)){background-color:color-mix(in oklab, var(--color-lit) var(--a), transparent)}',
            ],
            'bg-(--color-lit)/50' => [
                'bg-(--color-lit)/50',
                'background-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){background-color:color-mix(in oklab, '
                . 'var(--color-lit) 50%, transparent)}',
            ],
            'bg-(color:--color-lit)/50' => [
                'bg-(color:--color-lit)/50',
                'background-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){background-color:color-mix(in oklab, '
                . 'var(--color-lit) 50%, transparent)}',
            ],
            'text-(--color-lit)/50' => [
                'text-(--color-lit)/50',
                'color:color-mix(in srgb, #123456 50%, transparent);@supports (color: color-mix(in lab, '
                . 'red, red)){color:color-mix(in oklab, var(--color-lit) 50%, transparent)}',
            ],
            'border-[var(--color-lit)]/50' => [
                'border-[var(--color-lit)]/50',
                'border-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){border-color:color-mix(in oklab, '
                . 'var(--color-lit) 50%, transparent)}',
            ],
            'bg-[var(--color-cur)]/50' => [
                'bg-[var(--color-cur)]/50',
                'background-color:var(--color-cur);@supports (color: color-mix(in lab, red, '
                . 'red)){background-color:color-mix(in oklab, var(--color-cur) 50%, transparent)}',
            ],
            'bg-[var(--x)]/50' => [
                'bg-[var(--x)]/50',
                'background-color:var(--x);@supports (color: color-mix(in lab, red, '
                . 'red)){background-color:color-mix(in oklab, var(--x) 50%, transparent)}',
            ],
            'bg-[var(--color-red-500)]/50' => [
                'bg-[var(--color-red-500)]/50',
                'background-color:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){background-color:color-mix(in oklab, '
                . 'var(--color-red-500) 50%, transparent)}',
            ],
            'text-[var(--color-red-500)]/50' => [
                'text-[var(--color-red-500)]/50',
                'color:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){color:color-mix(in oklab, '
                . 'var(--color-red-500) 50%, transparent)}',
            ],
            'shadow-[color:var(--color-lit)]' => [
                'shadow-[color:var(--color-lit)]',
                '--tw-shadow-color:#123456;@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-shadow-color:color-mix(in oklab, var(--color-lit) var(--tw-shadow-alpha), '
                . 'transparent)}',
            ],
            'shadow-[color:var(--color-lit)]/50' => [
                'shadow-[color:var(--color-lit)]/50',
                '--tw-shadow-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){--tw-shadow-color:color-mix(in oklab, '
                . 'color-mix(in oklab, var(--color-lit) 50%, transparent) var(--tw-shadow-alpha), '
                . 'transparent)}',
            ],
            'shadow-[color:var(--color-red-500)]/50' => [
                'shadow-[color:var(--color-red-500)]/50',
                '--tw-shadow-color:color-mix(in srgb, oklch(63.7% 0.237 25.331) 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, red)){--tw-shadow-color:color-mix(in oklab, '
                . 'color-mix(in oklab, var(--color-red-500) 50%, transparent) var(--tw-shadow-alpha), '
                . 'transparent)}',
            ],
            'inset-shadow-[color:var(--color-lit)]/50' => [
                'inset-shadow-[color:var(--color-lit)]/50',
                '--tw-inset-shadow-color:color-mix(in srgb, #123456 50%, transparent);'
                . '@supports (color: color-mix(in lab, red, '
                . 'red)){--tw-inset-shadow-color:color-mix(in oklab, color-mix(in oklab, '
                . 'var(--color-lit) 50%, transparent) var(--tw-inset-shadow-alpha), transparent)}',
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider untypedValues
     */
    public function anUntypedArbitraryValueIsAColourForTextAndDecoration(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, self::css($candidate, false), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function untypedValues(): array
    {
        return [
            'text-[var(--x)]' => [
                'text-[var(--x)]',
                'color:var(--x)',
            ],
            'text-[foo]' => [
                'text-[foo]',
                'color:foo',
            ],
            'text-[var(--x)]/50' => [
                'text-[var(--x)]/50',
                'color:var(--x);@supports (color: color-mix(in lab, red, red)){color:color-mix(in oklab, '
                . 'var(--x) 50%, transparent)}',
            ],
            'text-[var(--x)]/[1.5]' => [
                'text-[var(--x)]/[1.5]',
                'color:var(--x);@supports (color: color-mix(in lab, red, red)){color:color-mix(in oklab, '
                . 'var(--x) 150%, transparent)}',
            ],
            'text-(--x)' => [
                'text-(--x)',
                'color:var(--x)',
            ],
            'text-[12px]' => [
                'text-[12px]',
                'font-size:12px',
            ],
            'text-[1px]/50' => [
                'text-[1px]/50',
                'font-size:1px;line-height:calc(var(--spacing) * 50)',
            ],
            'text-[red]/50' => [
                'text-[red]/50',
                'color:color-mix(in oklab, red 50%, transparent)',
            ],
            'text-(length:--x)' => [
                'text-(length:--x)',
                'font-size:var(--x)',
            ],
            'text-[color:var(--x)]' => [
                'text-[color:var(--x)]',
                'color:var(--x)',
            ],
            'decoration-[foo]' => [
                'decoration-[foo]',
                'text-decoration-color:foo',
            ],
            'decoration-[var(--x)]' => [
                'decoration-[var(--x)]',
                'text-decoration-color:var(--x)',
            ],
            'decoration-[auto]' => [
                'decoration-[auto]',
                'text-decoration-color:auto',
            ],
            'decoration-[auto]/50' => [
                'decoration-[auto]/50',
                'text-decoration-color:color-mix(in oklab, auto 50%, transparent)',
            ],
            'decoration-[1px]' => [
                'decoration-[1px]',
                'text-decoration-thickness:1px',
            ],
            'decoration-[red]/50' => [
                'decoration-[red]/50',
                'text-decoration-color:color-mix(in oklab, red 50%, transparent)',
            ],
            'decoration-(--x)' => [
                'decoration-(--x)',
                'text-decoration-color:var(--x)',
            ],
            'decoration-(length:--x)' => [
                'decoration-(length:--x)',
                'text-decoration-thickness:var(--x)',
            ],
        ];
    }

    /**
     * The values the CLI does not read as a colour, because an earlier kind claims them: a length or a keyword of
     * the thickness takes no opacity, so with one it is nothing (not a colour mixed with `1px`).
     *
     * @test
     *
     * @dataProvider claimed
     */
    public function aValueAnEarlierKindClaimsIsNotAColourWithAnOpacity(string $candidate): void
    {
        self::assertNull(self::css($candidate, false), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function claimed(): array
    {
        return [
            'a length' => ['decoration-[1px]/50'],
            'a keyword' => ['decoration-auto/50'],
            'the font keyword' => ['decoration-from-font/50'],
            'a font size' => ['text-[12px]/foo'],
        ];
    }

    /**
     * The CLI emits every theme variable the generated CSS refers to, whatever wrote the reference.
     *
     * @test
     *
     * @dataProvider references
     *
     * @param list<string> $used
     */
    public function everyThemeVariableTheOutputReadsIsUsed(string $candidate, array $used): void
    {
        $compiled = self::compiled($candidate, true);

        self::assertNotNull($compiled, $candidate);
        $known = \array_values(\array_intersect($compiled->usedVars, \array_keys(self::all())));

        self::assertSame($used, $known, $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function references(): array
    {
        return [
            'an arbitrary value' => ['w-[var(--spacing)]', ['--spacing']],
            'a variable' => ['w-(--spacing)', ['--spacing']],
            'an arbitrary property' => ['[width:var(--spacing)]', ['--spacing']],
            'inside a function' => ['p-[calc(var(--spacing)*2)]', ['--spacing']],
            'with a fallback' => ['w-[var(--spacing,1px)]', ['--spacing']],
            'a colour' => ['bg-[var(--color-lit)]', ['--color-lit']],
            'a colour with an opacity' => ['bg-[var(--color-lit)]/50', ['--color-lit']],
            'a colour in a shadow' => ['shadow-[color:var(--color-lit)]', ['--color-lit']],
            'a variable colour' => ['bg-(--color-brand)/50', ['--color-brand']],
            'a name the theme does not define' => ['w-[var(--nope)]', []],
            'a property of the engine' => ['w-[var(--tw-x)]', []],
        ];
    }

    /**
     * `themeVariableOf` takes one plain reference to a defined theme variable, with or without a fallback, and
     * nothing that only starts or ends like one.
     *
     * @test
     *
     * @dataProvider variableReferences
     *
     * @param array{0: string, 1: string}|null $expected
     */
    public function onlyOnePlainReferenceToAThemeVariableIsOne(string $color, ?array $expected): void
    {
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $theme->merge(self::COLORS, new ThemeFlags(false, false, false, false));

        self::assertSame($expected, Compiler::themeVariableOf($color, $theme), $color);
    }

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: string}|null}>
     */
    public static function variableReferences(): array
    {
        return [
            'a reference' => ['var(--color-lit)', ['--color-lit', '#123456']],
            'a reference with a fallback' => ['var(--color-lit,red)', ['--color-lit', '#123456']],
            'a fallback with a function' => ['var(--color-lit, calc(1 + (2)))', ['--color-lit', '#123456']],
            'a name the theme does not define' => ['var(--nope)', null],
            'two references' => ['var(--color-lit,1) var(--color-lit)', null],
            'a reference and more' => ['var(--color-lit) x', null],
            'a function around one' => ['rgb(var(--color-lit))', null],
            'a literal' => ['#123456', null],
            'an unclosed reference' => ['var(--color-lit', null],
            'no name' => ['var(--)', null],
            'a name followed by text' => ['var(--color-lit x)', null],
            'the one that comes first of two' => ['var(--color-lit) var(--color-lit)', null],
            'an empty text' => ['', null],
        ];
    }

    /**
     * T-15: a reference that carries more than a name never reaches the output, with an opacity or without.
     *
     * @test
     *
     * @dataProvider hostile
     */
    public function aHostileReferenceIsRefused(string $candidate): void
    {
        self::assertNull(self::css($candidate, true), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostile(): array
    {
        return [
            'a semicolon in a shadow colour' => ['shadow-[1px_1px_var(--c;color:red)]/50'],
            'a brace in a shadow colour' => ['shadow-[1px_1px_var(--c)}]/50'],
            'a comment in a shadow colour' => ['shadow-[1px_1px_var(--c/*x*/)]/50'],
            'a script url in a shadow colour' => ['shadow-[1px_1px_var(url(javascript:alert(1)))]/50'],
            'a semicolon in a theme reference' => ['bg-[var(--color-lit;x)]/50'],
            'a comment in a theme reference' => ['bg-[var(--color-lit/*x*/)]/50'],
            'a script url in a text colour' => ['text-[url(javascript:alert(1))]/50'],
            'a brace in a decoration colour' => ['decoration-[red}]/50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function all(): array
    {
        return self::COLORS + ['--spacing' => '', '--color-red-500' => ''];
    }

    private static function compiled(string $candidate, bool $withColors): ?\TailwindPhp\Engine\Utility\CompiledUtility
    {
        $registry = Registry::default();
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());

        if ($withColors) {
            $theme->merge(self::COLORS, new ThemeFlags(false, false, false, false));
        }

        return (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            $theme
        );
    }

    private static function css(string $candidate, bool $withColors): ?string
    {
        $compiled = self::compiled($candidate, $withColors);

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
                $parts[] = $node->property . ':' . $node->value . ($node->important ? '!important' : '');
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::render($node->nodes) . '}';
            }
        }

        return \implode(';', $parts);
    }
}
