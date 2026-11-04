<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Variant\Definition;

/**
 * `@custom-variant` shorthand (criterion 6, T-15): a selector or at-rule
 * variant, checked before it is registered; the block form is M3.
 */
final class VariantHandlerTest extends TestCase
{
    /** @test */
    public function aSelectorVariantIsRegistered(): void
    {
        $run = new ProcessorHarness('@custom-variant theme-midnight (&:where([data-theme="midnight"] *));');
        $definition = $run->variants->get('theme-midnight');

        self::assertSame([], $run->codes());
        self::assertNotNull($definition);
        self::assertSame(['&:where([data-theme="midnight"] *)'], $definition->selectors());
        self::assertSame($definition->order(), $run->sheet->customVariants['theme-midnight']->order());
        self::assertSame('', $run->authored());
    }

    /** @test */
    public function anAtRuleVariantIsRegistered(): void
    {
        $run = new ProcessorHarness('@custom-variant any-hover (@media (any-hover: hover));');
        $definition = $run->variants->get('any-hover');

        self::assertNotNull($definition);
        self::assertSame('(any-hover: hover)', $definition->media());
    }

    /** @test */
    public function severalSelectorsAreKept(): void
    {
        $run = new ProcessorHarness('@custom-variant hocus (&:hover, &:focus);');
        $definition = $run->variants->get('hocus');

        self::assertNotNull($definition);
        self::assertSame(['&:hover', '&:focus'], $definition->selectors());
    }

    /** @test */
    public function customVariantsComeAfterEveryBuiltInAndReplaceOne(): void
    {
        $run = new ProcessorHarness('@custom-variant a (&.a); @custom-variant dark (&:where(.dark, .dark *));');

        self::assertGreaterThan(self::variant($run, 'hover')->order(), self::variant($run, 'a')->order());
        self::assertSame(['&:where(.dark, .dark *)'], self::variant($run, 'dark')->selectors());
    }

    /** @test */
    public function theBlockFormIsUnsupported(): void
    {
        $run = new ProcessorHarness('@custom-variant pointer-fine { @media (pointer: fine) { @slot; } }');

        self::assertSame([Diagnostic::E_UNSUPPORTED_DIRECTIVE], $run->codes());
        self::assertStringContainsString('M3', $run->messages()[0]);
        self::assertSame([], $run->sheet->customVariants);
    }

    /**
     * @test
     *
     * @dataProvider invalid
     */
    public function anInvalidShorthandIsRefused(string $css): void
    {
        $run = new ProcessorHarness($css);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertSame([], $run->sheet->customVariants);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalid(): array
    {
        return [
            'no parentheses' => ['@custom-variant x &:hover;'],
            'no name' => ['@custom-variant (&:hover);'],
            'selector without an ampersand' => ['@custom-variant x (.dark);'],
            'unknown at-rule' => ['@custom-variant x (@layer base);'],
            'at-rule without a query' => ['@custom-variant x (@media);'],
            'unsafe selector' => ['@custom-variant x (&:is(url(javascript:x)));'],
            'escaped function' => ['@custom-variant x (&:\\75rl(a));'],
        ];
    }

    private static function variant(ProcessorHarness $run, string $name): Definition
    {
        $definition = $run->variants->get($name);

        self::assertNotNull($definition, $name);

        return $definition;
    }
}
