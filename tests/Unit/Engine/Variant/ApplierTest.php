<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Variant;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Variant;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Engine\Variant\Definition;
use TailwindPhp\Engine\Variant\Registry;
use TailwindPhp\Engine\Variant\Wrapped;

/**
 * Wrapping a utility's declarations in its variants, right to left (design doc 07
 * §Phase 3c §Application). Each row of expectations is a shape the pinned 4.1.18
 * CLI writes in `golden/variants/`, in the nested form it writes it in.
 */
final class ApplierTest extends TestCase
{
    private const CLASS_SELECTOR = '.c';

    // ------------------------------------------------------------- the basics

    /** @test */
    public function noVariantsLeavesTheClassRuleAlone(): void
    {
        self::same('.c{display:flex}', self::nested([]));
    }

    /** @test */
    public function aSelectorVariantNestsAnAmpersandRule(): void
    {
        self::same('.c{&:focus{display:flex}}', self::nested([self::v('focus')]));
        self::same('.c{&:first-child{display:flex}}', self::nested([self::v('first')]));
    }

    /** @test */
    public function aSelectorAndMediaVariantPutsTheMediaInsideTheSelector(): void
    {
        self::same(
            '.c{&:hover{@media (hover: hover){display:flex}}}',
            self::nested([self::v('hover')])
        );
    }

    /**
     * A custom `@starting-style` variant wraps at the same nesting position as a media query
     * (probed against the pinned CLI: `hover:starting:opacity-0` and `starting:hover:opacity-0` differ only
     * in which one is outside).
     *
     * @test
     */
    public function aStartingStyleVariantIsAnAtRuleWithNoQuery(): void
    {
        $applier = self::withStarting();
        self::same('.c{@starting-style{display:flex}}', self::wrappedText($applier, [self::v('starting')]));
        self::same(
            '.c{&:hover{@media (hover: hover){@starting-style{display:flex}}}}',
            self::wrappedText($applier, [self::v('hover'), self::v('starting')])
        );
        self::same(
            '.c{@starting-style{&:hover{@media (hover: hover){display:flex}}}}',
            self::wrappedText($applier, [self::v('starting'), self::v('hover')])
        );
        self::same(
            '.c{@media (width >= 48rem){@starting-style{display:flex}}}',
            self::wrappedText($applier, [self::v('md'), self::v('starting')])
        );
        self::same(
            '.c{@media (prefers-color-scheme: dark){@starting-style{display:flex}}}',
            self::wrappedText($applier, [self::v('dark'), self::v('starting')])
        );
    }

    /** @test */
    public function aMediaOnlyVariantIsAnAtRule(): void
    {
        self::same(
            '.c{@media (prefers-color-scheme: dark){display:flex}}',
            self::nested([self::v('dark')])
        );
        self::same('.c{@media print{display:flex}}', self::nested([self::v('print')]));
    }

    /** @test */
    public function variantsApplyRightToLeftSoTheLeftmostIsOutermost(): void
    {
        self::same(
            '.c{@media (width >= 48rem){&:hover{@media (hover: hover){display:flex}}}}',
            self::nested([self::v('md'), self::v('hover')])
        );
        self::same(
            '.c{&:is(:where(.group):hover *){@media (hover: hover){@media (width >= 48rem){display:flex}}}}',
            self::nested([self::group('hover'), self::v('md')])
        );
    }

    /** @test */
    public function aRangeStacksTwoMediaQueries(): void
    {
        self::same(
            '.c{@media (width >= 40rem){@media (width < 48rem){&:hover{@media (hover: hover){display:flex}}}}}',
            self::nested([self::v('sm'), self::v('max-md'), self::v('hover')])
        );
    }

    /** @test */
    public function theNestedRuleFlattensToTheFlatSelectorAndMedia(): void
    {
        self::same(
            '@media (hover: hover){.c:hover{display:flex}}',
            self::flat([self::v('hover')])
        );
        self::same(
            '@media (width >= 48rem){@media (hover: hover){.c:hover{display:flex}}}',
            self::flat([self::v('md'), self::v('hover')])
        );
    }

    // ------------------------------------------------------------ breakpoints

    /** @test */
    public function namedBreakpointsAreMediaQueriesFromTheTheme(): void
    {
        self::same('.c{@media (width >= 48rem){display:flex}}', self::nested([self::v('md')]));
        self::same('.c{@media (width < 48rem){display:flex}}', self::nested([self::v('max-md')]));
        self::same('.c{@media (width >= 64rem){display:flex}}', self::nested([self::v('min-lg')]));
    }

    /** @test */
    public function aBreakpointSplitIntoRootAndValueIsTheSameVariant(): void
    {
        self::same(
            self::nested([self::v('max-md')]),
            self::nested([new Variant('max', 'md', null, 'max-md')])
        );
    }

    /** @test */
    public function arbitraryMinAndMaxFillTheTemplate(): void
    {
        self::same(
            '.c{@media (width < 600px){display:flex}}',
            self::nested([new Variant('max', null, '600px', 'max-[600px]')])
        );
        self::same(
            '.c{@media (width >= 40rem){display:flex}}',
            self::nested([new Variant('min', null, '40rem', 'min-[40rem]')])
        );
        self::same(
            '.c{@media (width >= 400px){@media (width < 800px){display:flex}}}',
            self::nested([
                new Variant('min', null, '400px', 'min-[400px]'),
                new Variant('max', null, '800px', 'max-[800px]'),
            ])
        );
    }

    // --------------------------------------------------------------- compound

    /** @test */
    public function aGroupVariantUsesTheInnerSelectorOnAWhereAncestor(): void
    {
        self::same(
            '.c{&:is(:where(.group):hover *){@media (hover: hover){display:flex}}}',
            self::nested([self::group('hover')])
        );
        self::same(
            '.c{&:is(:where(.group):focus-visible *){display:flex}}',
            self::nested([self::group('focus-visible')])
        );
    }

    /** @test */
    public function aPeerVariantUsesTheSiblingCombinator(): void
    {
        self::same(
            '.c{&:is(:where(.peer):checked ~ *){display:flex}}',
            self::nested([new Variant('peer', 'checked', null, 'peer-checked')])
        );
    }

    /** @test */
    public function aNamedGroupUsesTheEscapedNameOnTheAncestor(): void
    {
        $named = new Variant('group', 'hover', null, 'group-hover/name', new Modifier(Modifier::NAMED, 'name'));

        self::same(
            '.c{&:is(:where(.group\/name):hover *){@media (hover: hover){display:flex}}}',
            self::nested([$named])
        );
    }

    /** @test */
    public function aNamedPeerAndAWholeNameAreHandled(): void
    {
        $peer = new Variant('peer', 'checked', null, 'peer-checked/field', new Modifier(Modifier::NAMED, 'field'));
        $whole = new Variant('group-open', null, null, 'group-open/x', new Modifier(Modifier::NAMED, 'x'));

        self::same(
            '.c{&:is(:where(.peer\/field):checked ~ *){display:flex}}',
            self::nested([$peer])
        );
        self::same(
            '.c{&:is(:where(.group\/x):is([open], :popover-open, :open) *){display:flex}}',
            self::nested([$whole])
        );
    }

    /** @test */
    public function aGroupNameWithPunctuationIsEscapedSoItCannotLeaveTheClassSelector(): void
    {
        $named = new Variant('group', 'hover', null, 'group-hover/a.b', new Modifier(Modifier::NAMED, 'a.b)}x'));

        $nested = self::nested([$named]);

        self::assertStringContainsString(':where(.group\/a\.b\)\}x)', $nested);
        self::assertSame(1, \substr_count($nested, '{&:is('));
    }

    // --------------------------------------------------------------- injected

    /** @test */
    public function beforeInjectsTheContentDeclarationAndRegistersItsProperty(): void
    {
        $wrapped = self::wrapped([self::v('before')]);

        self::same(
            '.c{&::before{content:var(--tw-content);display:flex}}',
            self::text($wrapped)
        );
        self::assertSame(['--tw-content'], $wrapped->properties());
    }

    /** @test */
    public function theInjectedDeclarationComesBeforeAnInnerVariantRule(): void
    {
        self::same(
            '.c{&::before{content:var(--tw-content);&:hover{@media (hover: hover){display:flex}}}}',
            self::nested([self::v('before'), self::v('hover')])
        );
    }

    /** @test */
    public function theContentPropertyIsRegisteredOnce(): void
    {
        $wrapped = self::wrapped([self::v('before'), self::v('after')]);

        self::assertSame(['--tw-content'], $wrapped->properties());
    }

    /** @test */
    public function aVariantWithoutInjectionRegistersNoProperty(): void
    {
        self::assertSame([], self::wrapped([self::v('hover'), self::v('md')])->properties());
    }

    // ---------------------------------------------------------- many selectors

    /** @test */
    public function aSelectorListMakesOneNestedRuleEachWithItsOwnDeclarations(): void
    {
        self::same(
            '.c{& *::marker{display:flex}&::marker{display:flex}'
            . '& *::-webkit-details-marker{display:flex}&::-webkit-details-marker{display:flex}}',
            self::nested([self::v('marker')])
        );
    }

    /** @test */
    public function theCopiesOfASelectorListAreIndependentNodes(): void
    {
        $wrapped = self::wrapped([self::v('selection')]);
        $rules = self::rule($wrapped->nodes()[0])->nodes;

        self::assertCount(2, $rules);

        $first = self::declaration(self::rule($rules[0])->nodes[0]);
        $second = self::declaration(self::rule($rules[1])->nodes[0]);

        self::assertNotSame($first, $second);

        $first->value = 'changed';

        self::assertSame('flex', $second->value);
    }

    /** @test */
    public function theFlatFormOfAPseudoElementListHasOneRuleEach(): void
    {
        self::same(
            '.c *::selection{display:flex}.c::selection{display:flex}',
            self::flat([self::v('selection')])
        );
    }

    /** @test */
    public function childSelectorsWrapInIs(): void
    {
        self::same('.c{:is(& > *){display:flex}}', self::nested([self::v('*')]));
        self::same('.c{:is(& *){display:flex}}', self::nested([self::v('**')]));
        self::same(':is(.c > *){display:flex}', self::flat([self::v('*')]));
    }

    // -------------------------------------------------------------- arbitrary

    /**
     * @test
     *
     * @dataProvider arbitrarySelectors
     */
    public function anArbitrarySelectorIsANestedRule(string $content, string $nested, string $flat): void
    {
        $variant = new Variant('', null, $content, '[' . $content . ']');

        self::same($nested, self::nested([$variant]));
        self::same($flat, self::flat([$variant]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function arbitrarySelectors(): array
    {
        return [
            'a child' => ['&>*', '.c{&>*{display:flex}}', '.c>*{display:flex}'],
            'a descendant' => ['& p', '.c{& p{display:flex}}', '.c p{display:flex}'],
            'a class on itself' => ['&.active', '.c{&.active{display:flex}}', '.c.active{display:flex}'],
            'an ancestor' => ['.parent &', '.c{.parent &{display:flex}}', '.parent .c{display:flex}'],
            'a pseudo-class with an argument' => [
                '&:not(:first-child)',
                '.c{&:not(:first-child){display:flex}}',
                '.c:not(:first-child){display:flex}',
            ],
            'an attribute' => ['&[data-open]', '.c{&[data-open]{display:flex}}', '.c[data-open]{display:flex}'],
            'without an ampersand it is wrapped in is()' => [
                '.x',
                '.c{&:is(.x){display:flex}}',
                '.c:is(.x){display:flex}',
            ],
            'a bare ampersand is the element itself' => ['&', '.c{&{display:flex}}', '.c{display:flex}'],
            'a selector list' => ['&>a,&>b', '.c{&>a,&>b{display:flex}}', '.c>a,.c>b{display:flex}'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider arbitraryAtRules
     */
    public function anArbitraryAtRuleWrapsTheNodes(string $content, string $nested): void
    {
        $variant = new Variant('', null, $content, '[' . $content . ']');

        self::same($nested, self::nested([$variant]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function arbitraryAtRules(): array
    {
        return [
            'a media query without a space' => [
                '@media(min-width:900px)',
                '.c{@media (min-width:900px){display:flex}}',
            ],
            'a media type' => ['@media print', '.c{@media print{display:flex}}'],
            'a supports query' => ['@supports(display:grid)', '.c{@supports (display:grid){display:flex}}'],
            'a nested supports query' => [
                '@supports(not (display:grid))',
                '.c{@supports (not (display:grid)){display:flex}}',
            ],
            'a container query' => ['@container(min-width:20rem)', '.c{@container (min-width:20rem){display:flex}}'],
            'starting-style takes no query' => ['@starting-style', '.c{@starting-style{display:flex}}'],
        ];
    }

    /** @test */
    public function anArbitraryVariantStacksWithNamedOnes(): void
    {
        self::same(
            '.c{&>*{&:hover{@media (hover: hover){display:flex}}}}',
            self::nested([new Variant('', null, '&>*', '[&>*]'), self::v('hover')])
        );
        self::same(
            '.c{&:hover{@media (hover: hover){&>*{display:flex}}}}',
            self::nested([self::v('hover'), new Variant('', null, '&>*', '[&>*]')])
        );
    }

    // ----------------------------------------------------------------- custom

    /** @test */
    public function aCustomVariantFromTheStylesheetIsAppliedLikeABuiltInOne(): void
    {
        $registry = self::registry();
        $registry->addCustom('theme-dark', Definition::fromRow('theme-dark', [
            'selector' => '&:where([data-theme=dark] *)',
            'order' => $registry->nextOrder(),
        ]));
        $registry->addCustom('wide', Definition::fromRow('wide', ['media' => '(width >= 1px)', 'order' => 1]));
        $applier = new Applier($registry, new DiagnosticBag());

        $wrapped = $applier->wrap(
            self::decls(),
            [new Variant('wide', null, null, 'wide'), self::v('theme-dark')],
            '.c'
        );

        self::assertNotNull($wrapped);
        self::same(
            '.c{@media (width >= 1px){&:where([data-theme=dark] *){display:flex}}}',
            self::text($wrapped)
        );
    }

    /** @test */
    public function aRowWithSeveralAtRulesNestsThemInAFixedOrderInsideTheSelector(): void
    {
        // No T1 row has more than one; the order is pinned so a later row cannot change it silently.
        $registry = self::registry();
        $registry->addCustom('all', Definition::fromRow('all', [
            'selector' => '&:x',
            'media' => '(a)',
            'supports' => '(b)',
            'container' => '(c)',
            'order' => 1,
        ]));
        $wrapped = (new Applier($registry, new DiagnosticBag()))->wrap(
            self::decls(),
            [new Variant('all', null, null, 'all')],
            '.c'
        );

        self::assertNotNull($wrapped);
        self::same('.c{&:x{@container (c){@supports (b){@media (a){display:flex}}}}}', self::text($wrapped));
    }

    // ---------------------------------------------------------------- failure

    /** @test */
    public function anUnknownVariantIsNullWithAWarningNamingOnlyTheVariant(): void
    {
        $bag = new DiagnosticBag();
        $wrapped = self::applier($bag)->wrap(self::decls(), [self::v('hoverr')], self::CLASS_SELECTOR);

        self::assertNull($wrapped);
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::W_UNKNOWN_VARIANT, $bag->all()[0]->code());
        self::assertTrue($bag->all()[0]->isWarning());
        self::assertSame('hoverr', $bag->all()[0]->candidate());
        self::assertStringContainsString('hoverr', $bag->all()[0]->message());
    }

    /**
     * @test
     *
     * @dataProvider unknownVariants
     */
    public function theseAreNotVariants(Variant $variant): void
    {
        $bag = new DiagnosticBag();

        self::assertNull(self::applier($bag)->wrap(self::decls(), [$variant], self::CLASS_SELECTOR));
        self::assertSame(Diagnostic::W_UNKNOWN_VARIANT, $bag->all()[0]->code());
    }

    /**
     * @return array<string, array{0: Variant}>
     */
    public static function unknownVariants(): array
    {
        return [
            'a template alone' => [new Variant('group', null, null, 'group')],
            'a template with an unknown value' => [new Variant('group', 'before', null, 'group-before')],
            'a media variant under group' => [new Variant('group', 'dark', null, 'group-dark')],
            'a value on a plain variant' => [new Variant('hover', 'x', null, 'hover-x')],
            'a T2 variant' => [new Variant('has', 'checked', null, 'has-checked')],
            'a T2 functional with an arbitrary value' => [new Variant('data', null, 'x=y', 'data-[x=y]')],
            'a functional name with no arbitrary value' => [new Variant('min', null, null, 'min')],
            'an arbitrary value on a plain variant' => [new Variant('hover', null, '&>*', 'hover-[&>*]')],
            'a modifier on a plain variant' => [
                new Variant('hover', null, null, 'hover/x', new Modifier(Modifier::NAMED, 'x')),
            ],
            'an arbitrary modifier on group' => [
                new Variant('group', 'hover', null, 'group-hover/[x]', new Modifier(Modifier::ARBITRARY, 'x')),
            ],
            'a var modifier on group' => [
                new Variant('group', 'hover', null, 'group-hover/(--x)', new Modifier(Modifier::VAR, '--x')),
            ],
            'a modifier on an arbitrary breakpoint' => [
                new Variant('min', null, '40rem', 'min-[40rem]/x', new Modifier(Modifier::NAMED, 'x')),
            ],
            'group of group' => [new Variant('group', 'group-hover', null, 'group-group-hover')],
        ];
    }

    /**
     * @test
     *
     * @dataProvider unsafeArbitraries
     */
    public function anUnsafeOrMalformedArbitraryVariantIsNullWithAWarning(Variant $variant): void
    {
        $bag = new DiagnosticBag();

        self::assertNull(self::applier($bag)->wrap(self::decls(), [$variant], self::CLASS_SELECTOR));
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::W_INVALID_ARBITRARY, $bag->all()[0]->code());
    }

    /**
     * @return array<string, array{0: Variant}>
     */
    public static function unsafeArbitraries(): array
    {
        $arbitrary = static function (string $content): Variant {
            return new Variant('', null, $content, '[' . $content . ']');
        };

        return [
            'a rule that ends early' => [$arbitrary('&>*{}')],
            'a closing brace' => [$arbitrary('&}')],
            'an opening brace' => [$arbitrary('&{')],
            'a second declaration' => [$arbitrary('@media(screen);x')],
            'a statement terminator in a selector' => [$arbitrary('&;x')],
            'an unterminated string' => [$arbitrary('&[x="')],
            'an unbalanced bracket' => [$arbitrary('&[x')],
            'an unbalanced paren' => [$arbitrary('&:not(a')],
            'a comment opener' => [$arbitrary('& /* x')],
            'a control byte' => [$arbitrary("&\x01")],
            'an empty selector' => [$arbitrary('')],
            'a blank selector' => [$arbitrary('   ')],
            'a bare at sign' => [$arbitrary('@')],
            'an at-rule that is not a grouping rule' => [$arbitrary('@import url(x)')],
            'a font-face' => [$arbitrary('@font-face')],
            'keyframes' => [$arbitrary('@keyframes x')],
            'a layer' => [$arbitrary('@layer x')],
            'a media query with nothing in it' => [$arbitrary('@media')],
            'an at-rule that is also a selector' => [$arbitrary('@media print and &')],
            'an at-rule name with an escape' => [$arbitrary('@\\6dedia print')],
            'a javascript url' => [$arbitrary('&[x="javascript:alert(1)"]')],
            'an unsafe arbitrary min' => [new Variant('min', null, '1px}', 'min-[1px}]')],
            'an unbalanced arbitrary max' => [new Variant('max', null, '(1px', 'max-[(1px]')],
            'an empty arbitrary min' => [new Variant('min', null, '', 'min-[]')],
        ];
    }

    /** @test */
    public function aFailedVariantAnywhereInTheStackFailsTheWholeCandidate(): void
    {
        $bag = new DiagnosticBag();

        self::assertNull(
            self::applier($bag)->wrap(
                self::decls(),
                [self::v('md'), self::v('nope'), self::v('hover')],
                self::CLASS_SELECTOR
            )
        );
        self::assertCount(1, $bag->all());
    }

    /** @test */
    public function moreThanEightVariantsIsALimitWarning(): void
    {
        $bag = new DiagnosticBag();
        $variants = [];

        for ($i = 0; $i < 9; ++$i) {
            $variants[] = self::v('hover');
        }

        self::assertNull(self::applier($bag)->wrap(self::decls(), $variants, self::CLASS_SELECTOR));
        self::assertSame(Diagnostic::W_CANDIDATE_LIMIT, $bag->all()[0]->code());

        $eight = [];

        for ($i = 0; $i < 8; ++$i) {
            $eight[] = self::v('hover');
        }

        self::assertNotNull(self::applier(new DiagnosticBag())->wrap(self::decls(), $eight, self::CLASS_SELECTOR));
    }

    /** @test */
    public function aDiagnosticNeverEchoesAnArbitraryValueOrALongName(): void
    {
        $bag = new DiagnosticBag();
        $long = \str_repeat('x', 300);

        self::applier($bag)->wrap(
            self::decls(),
            [new Variant('data', null, 'SECRET-VALUE=1', 'data-[SECRET-VALUE=1]')],
            self::CLASS_SELECTOR
        );
        self::applier($bag)->wrap(self::decls(), [self::v($long)], self::CLASS_SELECTOR);
        self::applier($bag)->wrap(
            self::decls(),
            [new Variant('', null, '&}SECRET-SELECTOR', '[&}SECRET-SELECTOR]')],
            self::CLASS_SELECTOR
        );

        self::assertCount(3, $bag->all());

        foreach ($bag->all() as $diagnostic) {
            self::assertStringNotContainsString('SECRET', $diagnostic->message());
            self::assertStringNotContainsString('SECRET', (string) $diagnostic->candidate());
            self::assertLessThan(200, \strlen($diagnostic->message()));
            self::assertLessThan(200, \strlen((string) $diagnostic->candidate()));
        }
    }

    // ----------------------------------------------------------------- orders

    /** @test */
    public function variantOrdersFollowTheVariantsAsWritten(): void
    {
        $registry = self::registry();
        $wrapped = self::wrapped([self::v('md'), self::v('hover')]);

        self::assertSame([$registry->order('md'), $registry->order('hover')], $wrapped->variantOrders());
        self::assertSame([], self::wrapped([])->variantOrders());
    }

    /** @test */
    public function compoundAndArbitraryOrdersAreTheirOwn(): void
    {
        $registry = self::registry();
        $wrapped = self::wrapped([
            self::group('hover'),
            new Variant('', null, '&>*', '[&>*]'),
            new Variant('', null, '@media print', '[@media print]'),
            new Variant('max', null, '600px', 'max-[600px]'),
        ]);

        self::assertSame(
            [
                $registry->order('group-hover'),
                Registry::ARBITRARY_SELECTOR_ORDER,
                Registry::ARBITRARY_AT_RULE_ORDER,
                self::maxRow($registry)->order(),
            ],
            $wrapped->variantOrders()
        );
    }

    /** @test */
    public function aNamedGroupHasTheOrderOfTheUnnamedOne(): void
    {
        $named = new Variant('group', 'hover', null, 'group-hover/x', new Modifier(Modifier::NAMED, 'x'));

        self::assertSame(
            [self::registry()->order('group-hover')],
            self::wrapped([$named])->variantOrders()
        );
    }

    // ----------------------------------------------------------------- purity

    /** @test */
    public function theInputNodesAreNotModified(): void
    {
        $nodes = self::decls();
        $before = Serializer::compact(new StyleSheet([new \TailwindPhp\Engine\Css\Node\Rule('x', $nodes)]));

        self::applier(new DiagnosticBag())->wrap(
            $nodes,
            [self::v('before'), self::v('selection'), self::v('md')],
            self::CLASS_SELECTOR
        );

        self::assertSame(
            $before,
            Serializer::compact(new StyleSheet([new \TailwindPhp\Engine\Css\Node\Rule('x', $nodes)]))
        );
        self::assertCount(1, $nodes);
    }

    /** @test */
    public function theClassSelectorIsUsedVerbatimAsTheOutermostRule(): void
    {
        $wrapped = self::applier(new DiagnosticBag())->wrap(
            self::decls(),
            [self::v('hover')],
            '.hover\:flex'
        );

        self::assertNotNull($wrapped);
        self::assertCount(1, $wrapped->nodes());
        self::assertSame('.hover\:flex', self::rule($wrapped->nodes()[0])->selector);
    }

    /** @test */
    public function twoRunsGiveTheSameResult(): void
    {
        $variants = [self::v('md'), self::group('hover'), self::v('before')];

        self::same(self::nested($variants), self::nested($variants));
    }

    /** @test */
    public function itIsFinalAndHoldsNoStaticState(): void
    {
        $reflection = new ReflectionClass(Applier::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame([], $reflection->getStaticProperties());
        self::assertTrue((new ReflectionClass(Wrapped::class))->isFinal());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Compare two stylesheets as the serializer writes them, so an expectation can
     * be written readably (`(hover: hover)`, `a, b`) while the structure it names
     * (what is nested in what, in what order) is compared exactly.
     */
    /**
     * T-15 (TASK-0053 item 4): a variant selector cannot carry markup. `<` is
     * refused everywhere — inside a quoted value too, since `</style` in a CSS
     * string still ends an HTML `<style>` element — and `>` everywhere but as a
     * combinator between compound selectors or inside a quoted value.
     *
     * @test
     *
     * @dataProvider markupSelectors
     */
    public function anArbitraryVariantCarryingMarkupIsRefused(string $selector): void
    {
        $bag = new DiagnosticBag();
        $variant = new Variant('', null, $selector, '[' . $selector . ']');

        self::assertFalse(Applier::isMarkupFree($selector));
        self::assertNull(self::applier($bag)->wrap(self::decls(), [$variant], self::CLASS_SELECTOR));
        self::assertSame([Diagnostic::W_INVALID_ARBITRARY], \array_map(static function (Diagnostic $d): string {
            return $d->code();
        }, $bag->all()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function markupSelectors(): array
    {
        return [
            'a closing style tag' => ['&</style><script>x</script>'],
            'an opening tag' => ['&<b'],
            'a bare less-than' => ['& < .x'],
            'a less-than inside a quoted value' => ['&[data-x="</style>"]'],
            'an escaped less-than' => ['&\\</style'],
            'a greater-than ending the selector' => ['&>'],
            'a greater-than before a slash' => ['&>/style'],
            'a doubled combinator' => ['& >> .x'],
            'a greater-than after a combinator' => ['& + > .x'],
            'a greater-than before a comma' => ['&:is(.a >, .b)'],
            'a greater-than before a closing paren' => ['&:is(.a >)'],
            'an unterminated quote hiding a greater-than' => ['&[data-x=">'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider markupFreeSelectors
     */
    public function aCombinatorOrAQuotedGreaterThanIsKept(string $selector, string $expected): void
    {
        $variant = new Variant('', null, $selector, '[' . $selector . ']');

        self::assertTrue(Applier::isMarkupFree($selector));
        self::same($expected, self::text(self::wrapped([$variant])));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markupFreeSelectors(): array
    {
        return [
            'child of the host' => ['&>*', '.c{&>*{display:flex}}'],
            'spaced child combinator' => ['& > p', '.c{& > p{display:flex}}'],
            'a relative selector in :has()' => ['&:has(> img)', '.c{&:has(> img){display:flex}}'],
            'after a list comma' => ['&:is(.a, > .b)', '.c{&:is(.a, > .b){display:flex}}'],
            'before an attribute' => ['&>[data-x]', '.c{&>[data-x]{display:flex}}'],
            'before a pseudo-class' => ['&>:first-child', '.c{&>:first-child{display:flex}}'],
            'after an attribute' => ['[dir]>&', '.c{[dir]>&{display:flex}}'],
            'a greater-than in a quoted value' => ['&[data-x="a>b"]', '.c{&[data-x="a>b"]{display:flex}}'],
            'an escaped greater-than in a name' => ['&.a\\>b', '.c{&.a\\>b{display:flex}}'],
        ];
    }

    /**
     * The at-rule paths have no markup check of their own beyond `</`: `<`
     * and `>` are range syntax there (`(width < 40rem)`).
     *
     * @test
     */
    public function anArbitraryBreakpointCannotCloseTheStyleElement(): void
    {
        $bag = new DiagnosticBag();
        // `</style` with no `>`: everything else passes it (`ArbitraryValue::isSafe`).
        $variant = new Variant('min', null, '1px</style', 'min-[1px</style]');

        self::assertNull(self::applier($bag)->wrap(self::decls(), [$variant], self::CLASS_SELECTOR));
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::W_INVALID_ARBITRARY, $bag->all()[0]->code());
    }

    /** @test */
    public function anArbitraryAtRuleCannotCloseTheStyleElement(): void
    {
        $bag = new DiagnosticBag();
        $variant = new Variant('', null, '@media (width</style)', '[@media (width</style)]');

        self::assertNull(self::applier($bag)->wrap(self::decls(), [$variant], self::CLASS_SELECTOR));
        self::assertSame(Diagnostic::W_INVALID_ARBITRARY, $bag->all()[0]->code());
    }

    /** @test */
    public function rangeSyntaxStaysValidInAnArbitraryAtRule(): void
    {
        $variant = new Variant('', null, '@media (width > 40rem)', '[@media (width > 40rem)]');

        self::same('.c{@media (width > 40rem){display:flex}}', self::text(self::wrapped([$variant])));
    }

    private static function same(string $expected, string $actual): void
    {
        self::assertSame(self::css($expected), self::css($actual));
    }

    private static function css(string $css): string
    {
        return Serializer::compact(Parser::parse($css, new DiagnosticBag()));
    }

    private static function rule(Node $node): Rule
    {
        self::assertInstanceOf(Rule::class, $node);

        return $node;
    }

    private static function declaration(Node $node): Declaration
    {
        self::assertInstanceOf(Declaration::class, $node);

        return $node;
    }

    private static function maxRow(Registry $registry): Definition
    {
        $row = $registry->functional('max');

        self::assertNotNull($row);

        return $row;
    }

    /**
     * `@custom-variant starting (@starting-style)` (TASK-0042): the registry plus that one custom variant.
     */
    private static function withStarting(): Applier
    {
        $registry = self::registry();
        $registry->addCustom(
            'starting',
            Definition::fromRow('starting', ['starting-style' => true, 'order' => $registry->nextOrder()])
        );

        return new Applier($registry, new DiagnosticBag());
    }

    private static function v(string $name): Variant
    {
        return new Variant($name, null, null, $name);
    }

    private static function group(string $value): Variant
    {
        return new Variant('group', $value, null, 'group-' . $value);
    }

    private static function registry(): Registry
    {
        return new Registry(
            Variants::table(),
            ['sm' => '40rem', 'md' => '48rem', 'lg' => '64rem', 'xl' => '80rem', '2xl' => '96rem']
        );
    }

    private static function applier(DiagnosticBag $bag): Applier
    {
        return new Applier(self::registry(), $bag);
    }

    /**
     * @return list<Node>
     */
    private static function decls(): array
    {
        return [new Declaration('display', 'flex')];
    }

    /**
     * @param list<Variant> $variants
     */
    private static function wrapped(array $variants): Wrapped
    {
        $wrapped = self::applier(new DiagnosticBag())->wrap(self::decls(), $variants, self::CLASS_SELECTOR);

        self::assertNotNull($wrapped);

        return $wrapped;
    }

    private static function text(Wrapped $wrapped): string
    {
        return Serializer::compact(new StyleSheet($wrapped->nodes()));
    }

    /**
     * @param list<Variant> $variants
     */
    private static function nested(array $variants): string
    {
        return self::text(self::wrapped($variants));
    }

    /**
     * @param list<Variant> $variants
     */
    private static function flat(array $variants): string
    {
        $sheet = Nesting::flatten(new StyleSheet(self::wrapped($variants)->nodes()));

        return Serializer::compact($sheet);
    }

    /**
     * @param list<\TailwindPhp\Engine\Candidate\Variant> $variants
     */
    private static function wrappedText(Applier $applier, array $variants): string
    {
        $wrapped = $applier->wrap(self::decls(), $variants, self::CLASS_SELECTOR);
        self::assertNotNull($wrapped);

        return self::text($wrapped);
    }
}
