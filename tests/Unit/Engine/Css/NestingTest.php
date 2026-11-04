<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\SelectorList;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The nesting flattener (design doc 07 §Nesting flattener): nested CSS in,
 * browser-compatible flat CSS out — `&` resolved, selector lists expanded or
 * wrapped in `:is()`, conditional at-rules hoisted above their rule.
 *
 * @covers-requirement FR-COMP-2
 */
final class NestingTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider cases
     *
     * @param list<array<int, mixed>> $expected
     */
    public function nestedCssFlattens(string $css, array $expected): void
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertSame($expected, AstDump::of($flat));
        self::assertSame([], $bag->toArray());
        self::assertFlat($flat->nodes, false);
    }

    /**
     * @return array<string, array{0: string, 1: list<array<int, mixed>>}>
     */
    public static function cases(): array
    {
        $red = ['decl', 'color', 'red', false];
        $blue = ['decl', 'color', 'blue', false];

        return [
            'ampersand pseudo-class' => [
                '.p-4 { &:hover { color: red } }',
                [['rule', '.p-4:hover', [$red]]],
            ],
            'implicit descendant' => ['.a { .b { color: red } }', [['rule', '.a .b', [$red]]]],
            'relative child combinator' => ['.a { > .b { color: red } }', [['rule', '.a > .b', [$red]]]],
            'ampersand after another selector' => ['.a { .dark & { color: red } }', [['rule', '.dark .a', [$red]]]],
            'compound ampersand' => ['.a { &.b { color: red } }', [['rule', '.a.b', [$red]]]],
            'deep chain' => [
                '.a { .b { .c { color: red } } }',
                [['rule', '.a .b .c', [$red]]],
            ],
            'declarations around nested rules keep their order' => [
                '.a { color: red; &:hover { color: blue } margin: 0 }',
                [
                    ['rule', '.a', [$red]],
                    ['rule', '.a:hover', [$blue]],
                    ['rule', '.a', [['decl', 'margin', '0', false]]],
                ],
            ],
            'rule with only nested rules emits no empty parent' => [
                '.a { &:hover { color: red } }',
                [['rule', '.a:hover', [$red]]],
            ],
            'simple parent list expands per selector' => [
                '.a, .b { &:hover { color: red } }',
                [['rule', '.a:hover, .b:hover', [$red]]],
            ],
            'child list times simple parent list' => [
                '.a, .b { &:hover, &:focus { color: red } }',
                [['rule', '.a:hover, .b:hover, .a:focus, .b:focus', [$red]]],
            ],
            'complex parent list uses :is()' => [
                '.a .x, .b { &:hover { color: red } }',
                [['rule', ':is(.a .x, .b):hover', [$red]]],
            ],
            'pseudo-element parent list uses :is()' => [
                '.a::before, .b { &:hover { color: red } }',
                [['rule', ':is(.a::before, .b):hover', [$red]]],
            ],
            'two ampersands with a parent list use :is()' => [
                '.a, .b { & + & { color: red } }',
                [['rule', ':is(.a, .b) + :is(.a, .b)', [$red]]],
            ],
            'single complex parent substitutes directly' => [
                '.a .b { &:hover { color: red } }',
                [['rule', '.a .b:hover', [$red]]],
            ],
            'ampersand inside a string is not nesting' => [
                '.a { [data-x="&"] & { color: red } }',
                [['rule', '[data-x="&"] .a', [$red]]],
            ],
            'escaped ampersand in the parent survives' => [
                '.a\\&b { &:hover { color: red } }',
                [['rule', '.a\\&b:hover', [$red]]],
            ],
            'media inside a rule is hoisted' => [
                '.p-4 { @media (width >= 40rem) { color: red } }',
                [['at', 'media', '(width >= 40rem)', [['rule', '.p-4', [$red]]]]],
            ],
            'hoisting keeps sibling order' => [
                '.a { color: red; @media (x) { color: blue } margin: 0 }',
                [
                    ['rule', '.a', [$red]],
                    ['at', 'media', '(x)', [['rule', '.a', [$blue]]]],
                    ['rule', '.a', [['decl', 'margin', '0', false]]],
                ],
            ],
            'supports is hoisted' => [
                '.a { @supports (display: grid) { color: red } }',
                [['at', 'supports', '(display: grid)', [['rule', '.a', [$red]]]]],
            ],
            'container is hoisted' => [
                '.a { @container (width > 10rem) { color: red } }',
                [['at', 'container', '(width > 10rem)', [['rule', '.a', [$red]]]]],
            ],
            'starting-style is hoisted' => [
                '.a { @starting-style { color: red } }',
                [['at', 'starting-style', '', [['rule', '.a', [$red]]]]],
            ],
            'nested at-rules stay nested' => [
                '.a { @media A { @media B { color: red } } }',
                [['at', 'media', 'A', [['at', 'media', 'B', [['rule', '.a', [$red]]]]]]],
            ],
            'rule inside a hoisted at-rule' => [
                '.a { @media x { &:hover { color: red } } }',
                [['at', 'media', 'x', [['rule', '.a:hover', [$red]]]]],
            ],
            'variant-shaped nesting' => [
                '.hover\\:bg-red:hover { @media (hover: hover) { color: red } }',
                [['at', 'media', '(hover: hover)', [['rule', '.hover\\:bg-red:hover', [$red]]]]],
            ],
            'top-level at-rule with nested rules' => [
                '@media x { .a { &:hover { color: red } } }',
                [['at', 'media', 'x', [['rule', '.a:hover', [$red]]]]],
            ],
            'layer contents flatten' => [
                '@layer utilities { .a { color: red; &:focus { color: blue } } }',
                [['at', 'layer', 'utilities', [['rule', '.a', [$red]], ['rule', '.a:focus', [$blue]]]]],
            ],
            'top-level ampersand is :scope' => ['& .a { color: red }', [['rule', ':scope .a', [$red]]]],
            'keyframes are untouched' => [
                '@keyframes spin { from { rotate: 0deg } to { rotate: 360deg } }',
                [['at', 'keyframes', 'spin', [
                    ['rule', 'from', [['decl', 'rotate', '0deg', false]]],
                    ['rule', 'to', [['decl', 'rotate', '360deg', false]]],
                ]]],
            ],
            'non-conditional at-rule inside a rule is moved out' => [
                '.a { color: red; @keyframes k { to { color: blue } } }',
                [
                    ['rule', '.a', [$red]],
                    ['at', 'keyframes', 'k', [['rule', 'to', [$blue]]]],
                ],
            ],
            'font-face declarations stay put' => [
                '@font-face { font-family: X }',
                [['at', 'font-face', '', [['decl', 'font-family', 'X', false]]]],
            ],
            'block-less at-rules stay in their rule' => [
                '.a { @apply flex; color: red }',
                [['rule', '.a', [['at', 'apply', 'flex', null], $red]]],
            ],
            'top-level statements and declarations pass through' => [
                '@layer a, b; --x: 1;',
                [['at', 'layer', 'a, b', null], ['decl', '--x', '1', false]],
            ],
            'empty rule is dropped' => ['.a {} .b { color: red }', [['rule', '.b', [$red]]]],
            'banner is kept' => ['/*! x */ .a { color: red }', [['comment', '! x '], ['rule', '.a', [$red]]]],
        ];
    }

    /** @test */
    public function flatteningIsIdempotent(): void
    {
        $css = '.a, .b { color: red; &:hover { @media (x) { color: blue; .c & { margin: 0 } } } }';
        $once = Nesting::flatten(Parser::parse($css, new DiagnosticBag()));

        self::assertSame(AstDump::of($once), AstDump::of(Nesting::flatten($once)));
    }

    /** @test */
    public function theInputTreeIsNotModified(): void
    {
        $sheet = Parser::parse('.a { &:hover { color: red } }', new DiagnosticBag());
        $before = AstDump::of($sheet);

        Nesting::flatten($sheet);

        self::assertSame($before, AstDump::of($sheet));
    }

    /** @test */
    public function aTreeDeeperThanThirtyTwoLevelsIsCutWithADiagnostic(): void
    {
        $node = new Rule('.leaf', [new Declaration('color', 'red')]);

        for ($i = 0; $i < 40; ++$i) {
            $node = new Rule('.l' . $i, [new Declaration('x', (string) $i), $node]);
        }

        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(new StyleSheet([$node]), $bag);

        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
        self::assertStringContainsString('nesting too deep', $bag->all()[0]->message());
        self::assertCount(32, $flat->nodes, 'the 32 outermost rules survive, the rest is dropped');
        self::assertFlat($flat->nodes, false);
    }

    /** @test */
    public function deepAtRuleNestingIsCutToo(): void
    {
        $node = new AtRule('media', 'x', [new Declaration('color', 'red')]);

        for ($i = 0; $i < 40; ++$i) {
            $node = new AtRule('media', 'x' . $i, [$node]);
        }

        $bag = new DiagnosticBag();
        Nesting::flatten(new StyleSheet([$node]), $bag);

        self::assertCount(1, $bag);
    }

    /**
     * No `&` anywhere and no rule inside a rule.
     *
     * @param list<Node> $nodes
     */
    private static function assertFlat(array $nodes, bool $insideRule): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                self::assertFalse($insideRule, 'rule nested in a rule');
                self::assertFalse(SelectorList::containsAmpersand($node->selector), $node->selector);
                self::assertFlat($node->nodes, true);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                self::assertFalse($insideRule, 'block at-rule nested in a rule');
                self::assertFlat($node->nodes, false);
            }
        }
    }
}
