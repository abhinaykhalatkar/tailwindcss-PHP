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
    public function aStartingStyleVariantIsRegistered(): void
    {
        $run = new ProcessorHarness('@custom-variant starting (@starting-style);');
        $definition = $run->variants->get('starting');

        self::assertSame([], $run->codes());
        self::assertNotNull($definition);
        self::assertTrue($definition->startingStyle());
        self::assertSame([], $definition->selectors());
        self::assertNull($definition->media());
        self::assertSame('', $run->authored());
    }

    /**
     * `@starting-style` takes no query, the other at-rules need one.
     *
     * @test
     */
    public function aStartingStyleWithAQueryIsRefused(): void
    {
        $run = new ProcessorHarness('@custom-variant x (@starting-style (width > 1px));');

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertSame([], $run->sheet->customVariants);
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

    /**
     * T-15 (TASK-0053 item 4): a custom-variant selector cannot carry markup.
     *
     * @test
     *
     * @dataProvider markup
     */
    public function aCustomVariantCarryingMarkupIsRefused(string $css): void
    {
        $run = new ProcessorHarness($css);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertSame([], $run->sheet->customVariants);
        self::assertNull($run->variants->get('v'));

        foreach ($run->messages() as $message) {
            self::assertStringNotContainsString('<', $message);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function markup(): array
    {
        return [
            'a closing style tag' => ['@custom-variant v (&</style><script>alert(1)</script>);'],
            'an opening tag' => ['@custom-variant v (&<b);'],
            'in a quoted value' => ['@custom-variant v (&[data-x="</style>"]);'],
            'in the second selector' => ['@custom-variant v (&:hover, &</style>);'],
            'a greater-than that is no combinator' => ['@custom-variant v (&>/style);'],
            'a trailing greater-than' => ['@custom-variant v (&:is(.a >));'],
            'an at-rule closing the style element' => ['@custom-variant v (@media (width</style));'],
            'a selector closing it with no greater-than' => ['@custom-variant v (&:is(.a</style));'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider combinators
     *
     * @param list<string> $selectors
     */
    public function aCombinatorOrRangeSyntaxIsKept(string $css, array $selectors, ?string $media): void
    {
        $run = new ProcessorHarness($css);
        $definition = self::variant($run, 'v');

        self::assertSame([], $run->codes());
        self::assertSame($selectors, $definition->selectors());
        self::assertSame($media, $definition->media());
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string|null}>
     */
    public static function combinators(): array
    {
        return [
            'a child combinator' => ['@custom-variant v (& > p);', ['& > p'], null],
            'a relative selector' => ['@custom-variant v (&:has(> img));', ['&:has(> img)'], null],
            'a quoted greater-than' => ['@custom-variant v (&[data-x="a>b"]);', ['&[data-x="a>b"]'], null],
            'media range syntax' => ['@custom-variant v (@media (width > 40rem));', [], '(width > 40rem)'],
        ];
    }

    private static function variant(ProcessorHarness $run, string $name): Definition
    {
        $definition = $run->variants->get($name);

        self::assertNotNull($definition, $name);

        return $definition;
    }
}
