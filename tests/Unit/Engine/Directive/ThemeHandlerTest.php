<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Directive\ThemeHandler;
use TailwindPhp\Engine\Theme\ThemeValue;

/**
 * `@theme` blocks (criterion 4) and the value hygiene the TASK-0032 review
 * asked for: a value that could break out of a declaration never reaches the
 * registry; unusable breakpoints are reported where the theme is read.
 */
final class ThemeHandlerTest extends TestCase
{
    /** @test */
    public function aThemeBlockIsMergedWithItsFlags(): void
    {
        $run = new ProcessorHarness(
            '@theme static { --color-brand: #ff5a1f; } @theme reference { --color-hidden: #654321; }'
        );

        self::assertSame('#ff5a1f', self::variable($run, '--color-brand')->value());
        self::assertTrue(self::variable($run, '--color-hidden')->isReference());
        self::assertSame(['--color-brand' => '#ff5a1f'], $run->theme->emittable());
        self::assertSame([], $run->codes());
        self::assertSame('', $run->authored(), '@theme is consumed');
    }

    /** @test */
    public function keyframesInsideThemeAreRecorded(): void
    {
        $run = new ProcessorHarness(
            '@theme { --animate-wiggle: wiggle 1s infinite; @keyframes wiggle { 0% { opacity: 0 } } }'
        );

        self::assertNotNull($run->theme->keyframes('wiggle'));
    }

    /** @test */
    public function laterBlocksWinAndTheDefaultThemeNeverOverridesThem(): void
    {
        $run = new ProcessorHarness('@theme { --color-red-500: #f00; } @import "tailwindcss";');

        self::assertSame('#f00', self::variable($run, '--color-red-500')->value());
    }

    /**
     * @test
     *
     * @dataProvider brokenValues
     */
    public function aValueThatCouldEndTheDeclarationIsSkipped(string $value): void
    {
        self::assertFalse(ThemeHandler::isUsable($value), $value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brokenValues(): array
    {
        return [
            'semicolon' => ['1px; --spacing: 1px'],
            'close brace' => ['1px } .evil { color: red'],
            'open brace' => ['a { b'],
            'unbalanced parenthesis' => ['1px) or (x'],
            'unclosed parenthesis' => ['calc(1px'],
            'unclosed bracket' => ['[x'],
            'unterminated string' => ['"abc'],
            'trailing backslash' => ['abc\\'],
            'nul byte' => ["a\0b"],
            // Round 2: the byte after a backslash is checked like any other,
            // with the same scanner as candidate values and emitted CSS.
            'escaped start-of-heading' => ["a\\\x01"],
            'escaped escape' => ["a\\\x1B"],
            'escaped delete' => ["a\\\x7F"],
            'escape decoding to a control' => ['a\\1 b'],
            'brace in a string (refused everywhere)' => ['"{"'],
            'script url' => ['url(javascript:alert(1))'],
            'escaped function name' => ['\\75rl(javascript:x)'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider goodValues
     */
    public function ordinaryValuesAreUsable(string $value): void
    {
        self::assertTrue(ThemeHandler::isUsable($value), $value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function goodValues(): array
    {
        return [
            'colour' => ['oklch(63.7% 0.237 25.331)'],
            'font stack' => ["ui-sans-serif, system-ui, 'Apple Color Emoji'"],
            'semicolon in a string' => ['"a;b"'],
            'calc' => ['calc(var(--spacing) * 4)'],
            'escaped semicolon' => ['a\\;b'],
            'empty' => [''],
        ];
    }

    /** @test */
    public function aRecoveredValueNeverReachesTheRegistry(): void
    {
        // The TASK-0032 verifier's input: after the stray `)` the CSS parser
        // keeps everything up to the end of the input as this one value
        // (`1px) or (x;\n  --spacing: 1px;\n}\n.after{x:y}`).
        $run = new ProcessorHarness("@theme {\n  --breakpoint-evil: 1px) or (x;\n  --spacing: 1px;\n}\n.after{x:y}");

        self::assertNull($run->theme->get('--breakpoint-evil'));
        self::assertContains(Diagnostic::W_INVALID_THEME_VALUE, $run->codes());
        self::assertSame([], $run->theme->allVars(), 'nothing of the recovered value reaches the registry');
        self::assertSame([], $run->sheet->breakpoints);
    }

    /** @test */
    public function aBackslashBeforeALineBreakIsRefusedNotFolded(): void
    {
        // Folding would turn `\` + line feed into `\ ` (an escaped space).
        $run = new ProcessorHarness("@theme { --x: a\\\n b; --y: c; }");

        self::assertNull($run->theme->get('--x'));
        self::assertSame([Diagnostic::W_INVALID_THEME_VALUE], $run->codes());
        self::assertSame('c', self::variable($run, '--y')->value());
    }

    /** @test */
    public function aValueOverTheLimitIsRefused(): void
    {
        $long = \str_repeat('a', ThemeHandler::MAX_VALUE + 1);

        self::assertFalse(ThemeHandler::isUsable($long));
        self::assertTrue(ThemeHandler::isUsable(\substr($long, 1)));
    }

    /** @test */
    public function everyDefaultThemeValueIsUsable(): void
    {
        foreach (\TailwindPhp\Engine\Data\DefaultTheme::vars() as $name => $value) {
            self::assertTrue(ThemeHandler::isUsable($value), (string) $name);
        }
    }

    /** @test */
    public function aValueWrittenAcrossLinesIsFoldedNotRefused(): void
    {
        $run = new ProcessorHarness("@theme {\n  --shadow-card:\n    0 1px 3px red,\n    0 2px 6px blue;\n}");

        self::assertSame('0 1px 3px red, 0 2px 6px blue', self::variable($run, '--shadow-card')->value());
        self::assertSame([], $run->codes());
    }

    /** @test */
    public function usableBreakpointsAreReportedAndUnusableOnesWarned(): void
    {
        $run = new ProcessorHarness(
            '@import "tailwindcss"; @theme { --breakpoint-3xl: 120rem; --breakpoint-bad: url(javascript:x); }'
        );

        self::assertSame(
            ['sm' => '40rem', 'md' => '48rem', 'lg' => '64rem', 'xl' => '80rem', '2xl' => '96rem', '3xl' => '120rem'],
            $run->sheet->breakpoints
        );
        self::assertSame([Diagnostic::W_INVALID_THEME_VALUE], $run->codes());
        self::assertStringContainsString('--breakpoint-bad', $run->messages()[0]);
    }

    /** @test */
    public function aThemeStatementWithoutABlockIsReported(): void
    {
        $run = new ProcessorHarness('@theme;');

        self::assertSame([Diagnostic::E_THEME_INVALID], $run->codes());
    }

    private static function variable(ProcessorHarness $run, string $name): ThemeValue
    {
        $value = $run->theme->get($name);

        self::assertNotNull($value, $name);

        return $value;
    }
}
