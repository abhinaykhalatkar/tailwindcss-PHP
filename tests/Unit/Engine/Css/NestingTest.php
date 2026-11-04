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
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Css\Tokenizer;
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
            // `:is()` cannot hold a pseudo-element (ENG-A14 / review item 7).
            'pseudo-element parent list expands per selector' => [
                '.a::before, .b { &:hover { color: red } }',
                [['rule', '.a::before:hover, .b:hover', [$red]]],
            ],
            'legacy pseudo-element parent list expands per selector' => [
                '.a:after, .b .c { &:hover { color: red } }',
                [['rule', '.a:after:hover, .b .c:hover', [$red]]],
            ],
            'type selector after the ampersand moves in front' => [
                '.a { &div { color: red } }',
                [['rule', 'div.a', [$red]]],
            ],
            'universal selector after the ampersand moves in front' => [
                '.a { &*.b { color: red } }',
                [['rule', '*.a.b', [$red]]],
            ],
            'type selector after the ampersand with a complex parent' => [
                '.a .b { &div { color: red } }',
                [['rule', 'div:is(.a .b)', [$red]]],
            ],
            'type selector after a descendant ampersand' => [
                '.a { .x &span { color: red } }',
                [['rule', '.x span.a', [$red]]],
            ],
            'type selector after the ampersand with a simple parent list' => [
                '.a, .b { &p { color: red } }',
                [['rule', 'p.a, p.b', [$red]]],
            ],
            'type selector after the ampersand with a type parent' => [
                'div { &span { color: red } }',
                [['rule', 'span:is(div)', [$red]]],
            ],
            'type selector after the ampersand with a type-led compound parent' => [
                'a.x { &span { color: red } }',
                [['rule', 'span:is(a.x)', [$red]]],
            ],
            'type selector after the ampersand in a mixed parent list' => [
                'div, .b { &p { color: red } }',
                [['rule', 'p:is(div), p.b', [$red]]],
            ],
            'ampersand inside a compound is left alone' => [
                '.a { .x&.y { color: red } }',
                [['rule', '.x.a.y', [$red]]],
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
    public function ampersandChainsAreCutAtTheSelectorLimit(): void
    {
        $bag = new DiagnosticBag();
        $css = '.a{' . \str_repeat('& &{', 22) . 'c:d' . \str_repeat('}', 23);
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);
        $pretty = Serializer::pretty($flat);

        self::assertCount(1, $bag);
        self::assertStringContainsString('Selector too long after nesting', $bag->all()[0]->message());
        self::assertLessThan(64 * 1024, \strlen($pretty));
        self::assertFlat($flat->nodes, false);

        foreach ($flat->nodes as $node) {
            self::assertInstanceOf(Rule::class, $node);
            self::assertLessThanOrEqual(Nesting::MAX_SELECTOR_BYTES, \strlen($node->selector));
        }
    }

    /** @test */
    public function doublingParentListsAreCutAtTheSelectorLimit(): void
    {
        $bag = new DiagnosticBag();
        $css = \str_repeat('.a,.b{', 18) . 'c:d' . \str_repeat('}', 18);
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertCount(1, $bag);
        self::assertLessThan(64 * 1024, \strlen(Serializer::pretty($flat)));
    }

    /** @test */
    public function aNestedSelectorExactlyAtTheLimitIsKept(): void
    {
        $bag = new DiagnosticBag();
        // `.p` + `:hover` + padding up to exactly the limit after nesting.
        $tail = ':is(.' . \str_repeat('a', Nesting::MAX_SELECTOR_BYTES - 8) . ')';
        $flat = Nesting::flatten(Parser::parse('.p{&' . $tail . '{x:y}}', $bag), $bag);

        self::assertSame([], $bag->toArray());
        self::assertSame(Nesting::MAX_SELECTOR_BYTES, \strlen(AstDump::of($flat)[0][1]));

        $over = Nesting::flatten(Parser::parse('.pp{&' . $tail . '{x:y}}', $bag), $bag);

        self::assertSame([], $over->nodes);
        self::assertCount(1, $bag);
        self::assertStringContainsString('Selector too long after nesting', $bag->all()[0]->message());
    }

    /** @test */
    public function topLevelSelectorsAreNotCapped(): void
    {
        $bag = new DiagnosticBag();
        $long = '.' . \str_repeat('a', 9000);
        $list = [];

        for ($i = 0; $i < 3000; ++$i) {
            $list[] = '.i' . $i;
        }

        $flat = Nesting::flatten(Parser::parse($long . '{x:y}' . \implode(',', $list) . '{x:y}', $bag), $bag);

        self::assertSame([], $bag->toArray());
        self::assertCount(2, $flat->nodes);
        self::assertSame($long, AstDump::of($flat)[0][1]);
    }

    /** @test */
    public function manyChildrenOfALongSelectorStopAtTheOutputBudget(): void
    {
        $bag = new DiagnosticBag();
        $css = '.' . \str_repeat('a', 8000) . '{' . \str_repeat('b{c:d}', 2000) . '}';
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertCount(1, $bag);
        self::assertStringContainsString('Nesting output too large', $bag->all()[0]->message());
        self::assertLessThanOrEqual(Nesting::MAX_OUTPUT_BYTES + 16384, \strlen(Serializer::compact($flat)));
    }

    /**
     * @test
     *
     * @dataProvider pseudoElementBranches
     *
     * @param list<array<int, mixed>> $expected
     */
    public function branchesThatCanNeverMatchAreDropped(string $css, array $expected, int $diagnostics): void
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertSame($expected, AstDump::of($flat));
        self::assertCount($diagnostics, $bag);
    }

    /**
     * @return array<string, array{0: string, 1: list<array<int, mixed>>, 2: int}>
     */
    public static function pseudoElementBranches(): array
    {
        $red = ['decl', 'color', 'red', false];

        return [
            'descendant after a pseudo-element in a list' => [
                '.a::before, .b { c { color: red } }',
                [['rule', '.b c', [$red]]],
                1,
            ],
            'two ampersands after a pseudo-element in a list' => [
                '.a::before, .b { & + & { color: red } }',
                [['rule', '.b + .b', [$red]]],
                1,
            ],
            'pseudo-class after a pseudo-element is fine' => [
                '.a::before, .b { &:hover { color: red } }',
                [['rule', '.a::before:hover, .b:hover', [$red]]],
                0,
            ],
            'ampersand alone after a pseudo-element is fine' => [
                '.a::before { & { color: red } }',
                [['rule', '.a::before', [$red]]],
                0,
            ],
            'a single pseudo-element parent with a descendant child' => [
                '.a::after { b { color: red } }',
                [],
                1,
            ],
            'a second pseudo-element after a pseudo-element' => [
                '.a::before { &::after { color: red } }',
                [],
                1,
            ],

            // TASK-0046 F3: a child using `&` twice pairs every remaining
            // part with every other (`a + .b` must match too), so the parts
            // without a pseudo-element are wrapped in `:is()` together.
            'two ampersands after a pseudo-element in a longer list' => [
                '.a::before, a, .b { & + & { color: red } }',
                [['rule', ':is(a, .b) + :is(a, .b)', [$red]]],
                1,
            ],
            'descendant ampersands after a pseudo-element in a longer list' => [
                '.a::before, a, .b { & & { color: red } }',
                [['rule', ':is(a, .b) :is(a, .b)', [$red]]],
                1,
            ],
            'two ampersands with a complex part after a pseudo-element' => [
                '.a::after, .x .y, .z { & > & { color: red } }',
                [['rule', ':is(.x .y, .z) > :is(.x .y, .z)', [$red]]],
                1,
            ],
            'a typed child with two ampersands after a pseudo-element' => [
                '.a::before, a, .b { &div + & { color: red } }',
                [['rule', 'div:is(a, .b) + :is(a, .b)', [$red]]],
                1,
            ],
            // Round 2 (review note): a grandchild of a wrapped multi-`&` child
            // sees that child as one complex part, as for any single complex
            // parent (substituted as written, no `:is()` around it).
            'a grandchild of a wrapped multi-ampersand child' => [
                '.a::before, .b, .c { & + & { .d + & { color: red } } }',
                [['rule', '.d + :is(.b, .c) + :is(.b, .c)', [$red]]],
                1,
            ],
            'two ampersands when every part has a pseudo-element' => [
                '.a::before, .b::after { & + & { color: red } }',
                [],
                1,
            ],
            // One `&` keeps the per-part expansion under a mixed list (an
            // `:is()` would change specificity to that of its most specific
            // part; per part each branch keeps its own).
            'one ampersand under a mixed list with a complex part stays per part' => [
                '.a::before, .x .y, .z { &:hover { color: red } }',
                [['rule', '.a::before:hover, .x .y:hover, .z:hover', [$red]]],
                0,
            ],
        ];
    }

    /**
     * TASK-0046 F1: a rule whose subtree holds no declaration and no
     * at-rule emits nothing, so it is skipped before its selector is
     * resolved — no work, no budget, no diagnostic for it.
     *
     * @test
     */
    public function rulesThatEmitNothingAreSkippedBeforeResolving(): void
    {
        $bag = new DiagnosticBag();
        $css = '.a::before, .b { c {} d { e {} f { g {} } } } .p { &:hover {} & { & {} } }';
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertSame([], AstDump::of($flat));
        self::assertSame([], $bag->toArray(), 'nothing is resolved, so nothing is reported');

        // An at-rule, even an empty one, and a statement at-rule still count.
        $kept = Nesting::flatten(Parser::parse('.a { b { @media x {} } c { @import "x"; } }', $bag), $bag);

        // The empty `@media` node is still produced (the serializer drops it).
        self::assertCount(2, $kept->nodes);
        self::assertInstanceOf(AtRule::class, $kept->nodes[0]);
        self::assertSame('.a c{@import "x";}', Serializer::compact($kept));
        self::assertSame([], $bag->toArray());
    }

    /**
     * TASK-0046 F1: a nested rule resolved against its parent also costs
     * {@see Nesting::PART_CHARGE} bytes per part, so many short parts stop
     * the walk long before 4 MiB of them were resolved one by one. Only
     * there: a top-level rule and an emitted chunk cost their bytes (round 2).
     *
     * @test
     */
    public function shortPartsAreChargedPerPart(): void
    {
        $bag = new DiagnosticBag();
        $css = \implode(',', \array_fill(0, 1600, 'a')) . '{' . \str_repeat('&:h{x:y}', 3000) . '}';
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        // The top-level parent list pays its bytes once (1 600 one-byte parts
        // joined by `, `). Each child pays 7 998 bytes plus 1 600 × PART_CHARGE
        // when it is resolved, then its 7 998 bytes again when its
        // declarations are emitted.
        $parent = 1600 + 1599 * 2;
        $selector = 1600 * 3 + 1599 * 2;
        $perChild = $selector + 1600 * Nesting::PART_CHARGE + $selector;

        self::assertCount(\intdiv(Nesting::MAX_OUTPUT_BYTES - $parent, $perChild), $flat->nodes);
        self::assertCount(1, $bag);
        self::assertStringContainsString('Nesting output too large', $bag->all()[0]->message());
    }

    /**
     * TASK-0046 F2: the budget stop is reported even after the diagnostic
     * cap was reached by other nesting diagnostics — it explains why the
     * output ends early. The nesting diagnostics stay within 101.
     *
     * @test
     */
    public function theBudgetStopIsReportedEvenAfterTheCap(): void
    {
        $bag = new DiagnosticBag();
        $css = \str_repeat('.a::before, .b { c { x: y } }', 200)
            . '.' . \str_repeat('q', 8000) . '{' . \str_repeat('&:h{x:y}', 600) . '}';
        Nesting::flatten(Parser::parse($css, $bag), $bag);

        $messages = [];

        foreach ($bag->all() as $diagnostic) {
            $messages[] = $diagnostic->message();
        }

        self::assertLessThanOrEqual(Tokenizer::MAX_DIAGNOSTICS + 1, \count($messages));
        self::assertStringContainsString('Too many CSS nesting errors', $messages[\count($messages) - 2]);
        self::assertStringContainsString('Nesting output too large', $messages[\count($messages) - 1]);
    }

    /** @test */
    public function nestingDiagnosticsAreCapped(): void
    {
        $parents = [];

        // About 6.9 KB of parents (under the limit); every child resolves to
        // about 12.9 KB (over it), so all 500 children are dropped.
        for ($i = 0; $i < 1000; ++$i) {
            $parents[] = '.p' . $i;
        }

        $bag = new DiagnosticBag();
        $css = \implode(',', $parents) . '{' . \str_repeat('&:hover{c:d}', 500) . '}';
        $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);

        self::assertSame([], $flat->nodes);
        self::assertLessThanOrEqual(101, \count($bag));
        self::assertStringContainsString('Too many', $bag->all()[\count($bag) - 1]->message());
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
