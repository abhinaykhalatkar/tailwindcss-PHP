<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CssComparator;

/**
 * The semantic comparison (design doc 16 §Comparison steps 4–5; decision ENG-A9).
 *
 * Tiny hand-written expected/actual pairs, so that each outcome is pinned on its
 * own and a failure reads as one sentence. The real goldens are exercised in
 * {@see CssComparatorGoldenTest}. Three groups: every status reproduced; the
 * formatting differences that must *not* matter; and — as important — the
 * differences normalisation must never erase (review R-0001, acceptance 4b).
 */
final class CssComparatorTest extends TestCase
{
    // ---------------------------------------------------------------- statuses

    /** @test */
    public function identicalStylesheetsPassEveryCandidate(): void
    {
        $css = self::utilities('.a{color:red}.b{color:blue}');

        $result = self::compare($css, $css, ['a', 'b']);

        self::assertSame(['a' => 'PASS', 'b' => 'PASS'], $result->statuses());
        self::assertSame(1.0, $result->passRate());
        self::assertTrue($result->isIdentical());
        self::assertSame([], $result->failures());
    }

    /** @test */
    public function aRuleTheEngineDidNotProduceIsMissing(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red}.b{color:blue}'),
            self::utilities('.a{color:red}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'FAIL_MISSING'], $result->statuses());
        self::assertSame(0.5, $result->passRate());
        self::assertSame('b', $result->failures()[0]['candidate']);
        self::assertSame('.b{color:blue}', \substr($result->failures()[0]['expected'], -14));
        self::assertSame('', $result->failures()[0]['actual']);
    }

    /** @test */
    public function aRuleTheCliDidNotProduceIsExtra(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red}'),
            self::utilities('.a{color:red}.b{color:blue}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'FAIL_EXTRA'], $result->statuses());
        self::assertSame('', $result->failures()[0]['expected']);
        self::assertStringContainsString('.b{color:blue}', $result->failures()[0]['actual']);
    }

    /** @test */
    public function aDifferentValueIsFailValue(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red}'),
            self::utilities('.a{color:blue}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
        self::assertStringContainsString('color:red', $result->failures()[0]['expected']);
        self::assertStringContainsString('color:blue', $result->failures()[0]['actual']);
    }

    /** @test */
    public function aDifferentSetOfDeclarationsIsFailValue(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red;margin:0}'),
            self::utilities('.a{color:red}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function declarationOrderInsideARuleMatters(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red;margin:0}'),
            self::utilities('.a{margin:0;color:red}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function aDifferentWrapperIsFailValue(): void
    {
        $result = self::compare(
            self::utilities('@media (width>=48rem){.a{color:red}}'),
            self::utilities('@media (width>=40rem){.a{color:red}}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function aMissingWrapperIsFailValue(): void
    {
        $result = self::compare(
            self::utilities('@media (hover:hover){.a{color:red}}'),
            self::utilities('.a{color:red}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function candidatesNeitherSideProducedPass(): void
    {
        $result = self::compare(
            self::utilities('.a{color:red}'),
            self::utilities('.a{color:red}'),
            ['a', 'p-', 'bg-red-9999']
        );

        self::assertSame(['a' => 'PASS', 'p-' => 'PASS', 'bg-red-9999' => 'PASS'], $result->statuses());
    }

    /** @test */
    public function aCandidateIsReportedOnceInTheOrderGiven(): void
    {
        $css = self::utilities('.a{color:red}.b{color:blue}');

        $result = self::compare($css, $css, ['b', 'a', 'b', 'a']);

        self::assertSame(['b', 'a'], \array_keys($result->statuses()));
    }

    // ------------------------------------------------------------------- order

    /** @test */
    public function swappedRulesReportTheOneThatShouldComeSecond(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}'),
            self::utilities('.b{x:2}.a{x:1}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'FAIL_ORDER'], $result->statuses());
    }

    /** @test */
    public function aRuleMovedToTheEndIsTheOnlyOneReported(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}.c{x:3}.d{x:4}.e{x:5}'),
            self::utilities('.a{x:1}.c{x:3}.d{x:4}.e{x:5}.b{x:2}'),
            ['a', 'b', 'c', 'd', 'e']
        );

        self::assertSame(
            ['a' => 'PASS', 'b' => 'FAIL_ORDER', 'c' => 'PASS', 'd' => 'PASS', 'e' => 'PASS'],
            $result->statuses()
        );
    }

    /** @test */
    public function aRuleMovedToTheFrontIsTheOnlyOneReported(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}.c{x:3}.d{x:4}.e{x:5}'),
            self::utilities('.e{x:5}.a{x:1}.b{x:2}.c{x:3}.d{x:4}'),
            ['a', 'b', 'c', 'd', 'e']
        );

        self::assertSame(1, $result->count(ComparisonResult::FAIL_ORDER));
        self::assertSame('FAIL_ORDER', $result->statuses()['e']);
    }

    /** @test */
    public function aReversedRunReportsAllButOne(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}.c{x:3}'),
            self::utilities('.c{x:3}.b{x:2}.a{x:1}'),
            ['a', 'b', 'c']
        );

        self::assertSame(2, $result->count(ComparisonResult::FAIL_ORDER));
        self::assertSame(1, $result->count(ComparisonResult::PASS));
    }

    /** @test */
    public function theRulesOfOneCandidateOutOfOrderAreFailOrder(): void
    {
        $result = self::compare(
            self::utilities('.x::before{a:1}.x:hover{b:2}'),
            self::utilities('.x:hover{b:2}.x::before{a:1}'),
            ['x']
        );

        self::assertSame(['x' => 'FAIL_ORDER'], $result->statuses());
    }

    /** @test */
    public function aValueFailureDoesNotDisturbTheOrderOfTheOthers(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}.c{x:3}'),
            self::utilities('.a{x:9}.b{x:2}.c{x:3}'),
            ['a', 'b', 'c']
        );

        self::assertSame(['a' => 'FAIL_VALUE', 'b' => 'PASS', 'c' => 'PASS'], $result->statuses());
    }

    /** @test */
    public function aMissingRuleDoesNotMakeTheFollowingOnesOutOfOrder(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}.c{x:3}'),
            self::utilities('.a{x:1}.c{x:3}'),
            ['a', 'b', 'c']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'FAIL_MISSING', 'c' => 'PASS'], $result->statuses());
    }

    /** @test */
    public function aCandidatesRulesAreFoundWhateverTheWrappersBetweenThem(): void
    {
        $css = self::utilities(
            '.a::before{content:var(--tw-content)}@media (hover:hover){.a::before:hover{display:flex}}.b{x:1}'
        );

        $result = self::compare($css, $css, ['a', 'b']);

        self::assertTrue($result->isIdentical());
    }

    // ------------------------------------------- order over the full sequence

    /** @test */
    public function aCandidateSplitAroundAnotherRuleIsOutOfOrder(): void
    {
        // Each candidate's own rules are unchanged, yet an element with both classes
        // under the media query is green in one stylesheet and blue in the other.
        $expected = self::utilities('.a{x:red}.b{x:blue}@media (hover:hover){.a{x:green}}');
        $actual = self::utilities('.a{x:red}@media (hover:hover){.a{x:green}}.b{x:blue}');

        $result = self::compare($expected, $actual, ['a', 'b']);

        self::assertSame(['a' => 'FAIL_ORDER', 'b' => 'PASS'], $result->statuses());
        self::assertFalse($result->isIdentical());
    }

    /** @test */
    public function theSameSplitInTheOtherDirectionIsOutOfOrderToo(): void
    {
        $expected = self::utilities('.a{x:red}@media (hover:hover){.a{x:green}}.b{x:blue}');
        $actual = self::utilities('.a{x:red}.b{x:blue}@media (hover:hover){.a{x:green}}');

        $result = self::compare($expected, $actual, ['a', 'b']);

        self::assertSame(['a' => 'PASS', 'b' => 'FAIL_ORDER'], $result->statuses());
    }

    /** @test */
    public function aCandidatesRulesKeptTogetherAroundAnotherCandidateAreInOrder(): void
    {
        $css = self::utilities('.a{x:1}@media (hover:hover){.a{x:2}}.b{x:3}.c{x:4}');

        self::assertTrue(self::compare($css, $css, ['a', 'b', 'c'])->isIdentical());
    }

    /** @test */
    public function anAuthoredRuleMovedAmongUtilitiesIsReported(): void
    {
        $result = self::compare(
            self::utilities('.btn{y:1}.a{x:1}.b{x:2}.c{x:3}'),
            self::utilities('.a{x:1}.b{x:2}.c{x:3}.btn{y:1}'),
            ['a', 'b', 'c']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'PASS', 'c' => 'PASS'], $result->statuses());
        self::assertSame(['(authored) #0' => 'FAIL_ORDER'], self::statusesByLabel($result));
        self::assertSame(1.0, $result->passRate());
        self::assertFalse($result->isIdentical());
    }

    /** @test */
    public function anAuthoredRuleSwappedWithACandidateIsReportedAsTheOneThatCameTooEarly(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.btn{y:1}.b{x:2}'),
            self::utilities('.btn{y:1}.a{x:1}.b{x:2}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'PASS', 'b' => 'PASS'], $result->statuses());
        self::assertSame(['(authored) #0' => 'FAIL_ORDER'], self::statusesByLabel($result));

        $reverse = self::compare(
            self::utilities('.btn{y:1}.a{x:1}.b{x:2}'),
            self::utilities('.a{x:1}.btn{y:1}.b{x:2}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'FAIL_ORDER', 'b' => 'PASS'], $reverse->statuses());
    }

    /** @test */
    public function authoredRulesInTheSameOrderAmongUtilitiesAreIdentical(): void
    {
        $css = self::utilities('.btn{y:1}.a{x:1}.card{z:1}.b{x:2}');

        self::assertTrue(self::compare($css, $css, ['a', 'b'])->isIdentical());
    }

    /** @test */
    public function aRuleDuplicatedOnOneSideOnlyIsNotHiddenByTheOrderCheck(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}.b{x:2}'),
            self::utilities('.a{x:1}.b{x:2}.a{x:1}'),
            ['a', 'b']
        );

        self::assertSame(['a' => 'FAIL_VALUE', 'b' => 'PASS'], $result->statuses());
    }

    // ------------------------------------------------------ attribution inside

    /** @test */
    public function aRuleIsTheLongestListedCandidatesNotTheFirstListedClassInIt(): void
    {
        $selector = '.flex .\[\.flex_\&\]\:p-4';

        $result = self::compare(
            self::utilities($selector . '{padding:1px}'),
            self::utilities($selector . '{padding:2px}'),
            ['flex', '[.flex_&]:p-4']
        );

        self::assertSame(['flex' => 'PASS', '[.flex_&]:p-4' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function aSelectorListCoveringTwoListedCandidatesBelongsToBoth(): void
    {
        $result = self::compare(
            self::utilities('.\!flex,.flex\!{display:flex}'),
            self::utilities('.\!flex,.flex\!{display:grid}'),
            ['!flex', 'flex!']
        );

        self::assertSame(['!flex' => 'FAIL_VALUE', 'flex!' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function aRuleOfTwoCandidatesMissingOnTheEngineSideFailsBoth(): void
    {
        $result = self::compare(
            self::utilities('.\!flex,.flex\!{display:flex}'),
            self::utilities(''),
            ['!flex', 'flex!']
        );

        self::assertSame(['!flex' => 'FAIL_MISSING', 'flex!' => 'FAIL_MISSING'], $result->statuses());
    }

    // -------------------------------------------- differences that must not matter

    /**
     * @test
     *
     * @dataProvider equivalentPairs
     */
    public function formattingDifferencesDoNotMatter(string $expected, string $actual): void
    {
        $result = self::compare(self::utilities($expected), self::utilities($actual), ['a', 'b']);

        self::assertTrue($result->isIdentical(), $result->summary());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentPairs(): array
    {
        return [
            'whitespace and semicolons' => ['.a{color:red;margin:0}', ".a {\n  color: red;\n  margin: 0;\n}"],
            'a selector list in another order' => ['.a,.b{x:1}', '.b , .a { x: 1 }'],
            'number forms' => ['.a{opacity:0.50;margin:0px}', '.a{opacity:.5;margin:0}'],
            'a leading zero and trailing zeros' => ['.a{width:10.0px}', '.a{width:10px}'],
            'a wrapped list value' => [
                ".a{font-family:'A', 'B',\n     'C'}",
                ".a{font-family:'A','B','C'}",
            ],
            'calc spacing' => ['.a{width:calc(1/2*100%)}', '.a{width:calc( 1 / 2 * 100% )}'],
            'hex case' => ['.a{color:#FFF}', '.a{color:#fff}'],
            'a media query written tight' => [
                '@media (width>=48rem){.a{x:1}}',
                '@media (width >= 48rem) { .a { x: 1 } }',
            ],
            'media keywords in another case' => [
                '@media (hover:hover) AND (width>=40rem){.a{x:1}}',
                '@media (hover: hover) and (width >= 40rem){.a{x:1}}',
            ],
            'a property in another case' => ['.a{COLOR:red}', '.a{color:red}'],
            'native nesting against the flat form' => [
                '.a{&:hover{@media (hover:hover){color:red}}}',
                '@media (hover:hover){.a:hover{color:red}}',
            ],
            'combinators' => ['.a>.b{x:1}', '.a   >   .b{x:1}'],
            'a comment' => ['.a{color:red}', '/* note */.a{/* x */color:red}'],
        ];
    }

    /** @test */
    public function aSelectorWithAnArbitraryVariantAndAHexEscapeAttributesAndMatches(): void
    {
        $candidates = ['[&>*]:p-4', '2xl:flex', 'w-1/2', '!flex'];
        $expected = self::utilities(
            '.\[\&\>\*\]\:p-4 > *{padding:1px}.\32 xl\:flex{display:flex}'
            . '.w-1\/2{width:50%}.\!flex{display:flex!important}'
        );
        $actual = self::utilities(
            '.\[\&\>\*\]\:p-4>*{padding:1px}.\32 xl\:flex{display:flex}'
            . '.w-1\/2{width:50%}.\!flex{display:flex !important}'
        );

        self::assertTrue(self::compare($expected, $actual, $candidates)->isIdentical());
    }

    /** @test */
    public function aMismatchInAnImportantRuleIsAttributedToItsCandidate(): void
    {
        $result = self::compare(
            self::utilities('.\!flex{display:flex!important}.flex{display:flex}'),
            self::utilities('.\!flex{display:flex}.flex{display:flex}'),
            ['!flex', 'flex']
        );

        self::assertSame(['!flex' => 'FAIL_VALUE', 'flex' => 'PASS'], $result->statuses());
    }

    // --------------------------- differences normalisation must never erase

    /**
     * @test
     *
     * @dataProvider differencesThatMustSurvive
     */
    public function normalisationNeverErasesARealDifference(string $expected, string $actual): void
    {
        $result = self::compare(self::utilities($expected), self::utilities($actual), ['a']);

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differencesThatMustSurvive(): array
    {
        return [
            'a custom property name in another case' => ['.a{--Foo:1}', '.a{--foo:1}'],
            'whitespace inside a string' => ['.a{content:"a  b"}', '.a{content:"a b"}'],
            'a number inside a string' => ['.a{content:"0.50"}', '.a{content:".5"}'],
            'a url that only differs in a number' => ['.a{background:url(x/0.50.png)}', '.a{background:url(x/.5.png)}'],
            'a custom property number form' => ['.a{--tw-x:0.5}', '.a{--tw-x:.5}'],
            'a custom property comma spacing' => ['.a{--tw-x:a,b}', '.a{--tw-x:a, b}'],
            'a custom property operator spacing' => ['.a{--tw-x:calc(1/2)}', '.a{--tw-x:calc(1 / 2)}'],
            'a custom property hex case' => ['.a{--tw-x:#FFF}', '.a{--tw-x:#fff}'],
            'a custom property unit' => ['.a{--tw-x:0px}', '.a{--tw-x:0}'],
            'a keyword in another case' => ['.a{display:BLOCK}', '.a{display:block}'],
            'a colour keyword in another case' => ['.a{color:RED}', '.a{color:red}'],
            'five hex digits is not a colour' => ['.a{x:#ABCDE}', '.a{x:#abcde}'],
            'a unitless zero in calc is a different value' => [
                '.a{width:calc(0px + 1rem)}',
                '.a{width:calc(0 + 1rem)}',
            ],
            'a zero time is not a bare zero' => ['.a{transition-duration:0s}', '.a{transition-duration:0}'],
            'a zero angle is not a bare zero' => ['.a{rotate:0deg}', '.a{rotate:0}'],
            'a zero percentage is not a bare zero' => ['.a{width:0%}', '.a{width:0}'],
            'a zero inside a function' => ['.a{translate:translate(0px,0px)}', '.a{translate:translate(0,0)}'],
            'a compound selector in another order' => ['.a.b{x:1}', '.b.a{x:1}'],
            'a different type selector' => ['.a div{x:1}', '.a span{x:1}'],
            'a different pseudo class' => ['.a:hover{x:1}', '.a:focus{x:1}'],
            'a different attribute value' => ['.a[x="1"]{x:1}', '.a[x="2"]{x:1}'],
            'whitespace inside an attribute string' => ['.a[x="1  2"]{x:1}', '.a[x="1 2"]{x:1}'],
            'a descendant against a child combinator' => ['.a .b{x:1}', '.a > .b{x:1}'],
            'important against not important' => ['.a{x:1!important}', '.a{x:1}'],
            'a different value order inside a list' => ['.a{margin:1px 2px}', '.a{margin:2px 1px}'],
            'a different media threshold' => [
                '@media (width>=48rem){.a{x:1}}',
                '@media (width>=48.5rem){.a{x:1}}',
            ],
            'media against supports' => [
                '@media (hover:hover){.a{x:1}}',
                '@supports (hover:hover){.a{x:1}}',
            ],
            'a supports custom property test in another case' => [
                '@supports (--Foo:Bar){.a{x:1}}',
                '@supports (--Foo:bar){.a{x:1}}',
            ],
            'a container name in another case' => [
                '@container Sidebar (width>=1px){.a{x:1}}',
                '@container sidebar (width>=1px){.a{x:1}}',
            ],
        ];
    }

    // ----------------------------------------------------------------- regions

    /** @test */
    public function themeVariablesAreComparedAsASetAndOrderDoesNotMatter(): void
    {
        $result = self::compare(
            '@layer theme{:root,:host{--a:1;--b:2}}',
            '@layer theme{:host,:root{--b:2;--a:1}}',
            []
        );

        self::assertTrue($result->isIdentical(), $result->summary());
    }

    /** @test */
    public function aWrappedThemeValueEqualsItsOneLineForm(): void
    {
        $result = self::compare(
            "@layer theme{:root,:host{--font-sans:ui-sans-serif, system-ui,\n    sans-serif}}",
            '@layer theme{:root,:host{--font-sans:ui-sans-serif, system-ui, sans-serif}}',
            []
        );

        self::assertTrue($result->isIdentical(), $result->summary());
    }

    /**
     * @test
     *
     * @dataProvider themeDifferences
     */
    public function aThemeDifferenceIsAFailureNotACandidateFailure(string $actual, string $status, string $label): void
    {
        $result = self::compare('@layer theme{:root,:host{--a:1;--b:2}}', $actual, ['x']);

        self::assertSame(['x' => 'PASS'], $result->statuses());
        self::assertSame(1.0, $result->passRate());
        self::assertFalse($result->isIdentical());
        self::assertCount(1, $result->failures());
        self::assertSame($status, $result->failures()[0]['status']);
        self::assertSame($label, $result->failures()[0]['candidate']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function themeDifferences(): array
    {
        return [
            'a missing variable' => [
                '@layer theme{:root,:host{--a:1}}',
                'FAIL_MISSING',
                '(theme) --b',
            ],
            'an extra variable' => [
                '@layer theme{:root,:host{--a:1;--b:2;--c:3}}',
                'FAIL_EXTRA',
                '(theme) --c',
            ],
            'a changed value' => [
                '@layer theme{:root,:host{--a:1;--b:3}}',
                'FAIL_VALUE',
                '(theme) --b',
            ],
        ];
    }

    /** @test */
    public function aThemeVariableSetUnderASecondSelectorIsNotOverwritten(): void
    {
        $both = '@layer theme{:root{--x:1}.dark{--x:2}}';
        $one = '@layer theme{.dark{--x:2}}';

        self::assertSame(['(theme) --x' => 'FAIL_VALUE'], self::statusesByLabel(self::compare($both, $one, [])));
        self::assertSame(['(theme) --x' => 'FAIL_VALUE'], self::statusesByLabel(self::compare($one, $both, [])));
        self::assertTrue(self::compare($both, $both, [])->isIdentical());
    }

    /** @test */
    public function aThemeVariableSetTwiceInOneSelectorKeepsBothDeclarations(): void
    {
        $result = self::compare('@layer theme{:root{--x:1}}', '@layer theme{:root{--x:1;--x:2}}', []);

        self::assertSame(['(theme) --x' => 'FAIL_VALUE'], self::statusesByLabel($result));
    }

    /** @test */
    public function aDuplicateRuleIsNotHiddenByTheKeyedRegions(): void
    {
        $property = '@property --a{syntax:"*";inherits:false}';
        $frames = '@keyframes spin{to{x:1}}';

        self::assertSame(
            ['(property) --a' => 'FAIL_VALUE'],
            self::statusesByLabel(self::compare($property, $property . $property, []))
        );
        self::assertSame(
            ['(property) --a' => 'FAIL_VALUE'],
            self::statusesByLabel(self::compare($property . $property, $property, []))
        );
        self::assertSame(
            ['(keyframes) spin' => 'FAIL_VALUE'],
            self::statusesByLabel(self::compare($frames, $frames . $frames, []))
        );
        self::assertTrue(self::compare($property . $property, $property . $property, [])->isIdentical());
    }

    /** @test */
    public function aChangedThemeSelectorFailsEveryVariableUnderIt(): void
    {
        $result = self::compare(
            '@layer theme{:root,:host{--a:1;--b:2}}',
            '@layer theme{:root{--a:1;--b:2}}',
            []
        );

        self::assertSame(
            ['(theme) --a' => 'FAIL_VALUE', '(theme) --b' => 'FAIL_VALUE'],
            self::statusesByLabel($result)
        );
    }

    /** @test */
    public function aHexEscapeIsNotItsPlainCharacterInASelector(): void
    {
        // Both selectors name the candidate `a2b`, so the rule is compared, and the
        // escaped and unescaped spellings are different selector text.
        $result = self::compare(
            self::utilities('.a\\32 b{x:1}'),
            self::utilities('.a2b{x:1}'),
            ['a2b']
        );

        self::assertSame(['a2b' => 'FAIL_VALUE'], $result->statuses());
    }

    /** @test */
    public function propertyRulesAreASetByName(): void
    {
        $expected = '@property --a{syntax:"*";inherits:false}@property --b{syntax:"<length>";inherits:false}';
        $reordered = '@property --b{syntax:"<length>";inherits:false}@property --a{syntax:"*";inherits:false}';

        self::assertTrue(self::compare($expected, $reordered, [])->isIdentical());

        $missing = self::compare($expected, '@property --a{syntax:"*";inherits:false}', []);

        self::assertSame('(property) --b', $missing->failures()[0]['candidate']);
        self::assertSame('FAIL_MISSING', $missing->failures()[0]['status']);

        $changed = self::compare(
            $expected,
            '@property --a{syntax:"*";inherits:true}@property --b{syntax:"<length>";inherits:false}',
            []
        );

        self::assertSame('(property) --a', $changed->failures()[0]['candidate']);
        self::assertSame('FAIL_VALUE', $changed->failures()[0]['status']);

        $extra = self::compare('@property --a{syntax:"*";inherits:false}', $expected, []);

        self::assertSame('FAIL_EXTRA', $extra->failures()[0]['status']);
    }

    /** @test */
    public function keyframesAreASetByNameAndTheNameKeepsItsCase(): void
    {
        $spin = '@keyframes spin{to{transform:rotate(360deg)}}';
        $ping = '@keyframes ping{75%,100%{opacity:0}}';

        self::assertTrue(self::compare($spin . $ping, $ping . $spin, [])->isIdentical());

        $renamed = self::compare('@keyframes Spin{to{x:1}}', '@keyframes spin{to{x:1}}', []);

        self::assertSame(
            ['(keyframes) Spin' => 'FAIL_MISSING', '(keyframes) spin' => 'FAIL_EXTRA'],
            self::statusesByLabel($renamed)
        );
    }

    /** @test */
    public function theLayerStatementIsComparedInOrder(): void
    {
        $result = self::compare('@layer a, b;', '@layer b, a;', []);

        self::assertSame(['(layers) #0' => 'FAIL_VALUE'], self::statusesByLabel($result));
    }

    /** @test */
    public function otherLayersAreComparedRuleByRuleInOrder(): void
    {
        $same = '@layer base{a{x:1}b{x:2}}';

        self::assertTrue(self::compare($same, $same, [])->isIdentical());

        $swapped = self::compare($same, '@layer base{b{x:2}a{x:1}}', []);

        self::assertSame(
            ['(layer base) #0' => 'FAIL_VALUE', '(layer base) #1' => 'FAIL_VALUE'],
            self::statusesByLabel($swapped)
        );

        $missing = self::compare($same, '@layer base{a{x:1}}', []);

        self::assertSame(['(layer base) #1' => 'FAIL_MISSING'], self::statusesByLabel($missing));

        $extra = self::compare('@layer base{a{x:1}}', $same, []);

        self::assertSame(['(layer base) #1' => 'FAIL_EXTRA'], self::statusesByLabel($extra));
    }

    /** @test */
    public function theFirefoxFallbackLayerIsComparedLikeAnyOtherLayer(): void
    {
        $css = '@layer properties{@supports ((-webkit-hyphens:none) and (not (margin-trim:inline))){'
            . '*,::before{--tw-x:0}}}';

        self::assertTrue(self::compare($css, $css, [])->isIdentical());
        self::assertFalse(
            self::compare($css, \str_replace('--tw-x:0', '--tw-x:1', $css), [])->isIdentical()
        );
    }

    /** @test */
    public function unlayeredCssIsComparedInOrder(): void
    {
        $result = self::compare('.a{x:1}.b{x:2}', '.b{x:2}.a{x:1}', []);

        self::assertSame(
            ['(unlayered) #0' => 'FAIL_VALUE', '(unlayered) #1' => 'FAIL_VALUE'],
            self::statusesByLabel($result)
        );
    }

    /** @test */
    public function utilitiesNoCandidateAccountsForAreAuthoredAndComparedInOrder(): void
    {
        $css = self::utilities('.btn{padding:1px}.a{x:1}.card{margin:0}');

        $same = self::compare($css, $css, ['a']);

        self::assertTrue($same->isIdentical());

        $changed = self::compare($css, self::utilities('.btn{padding:2px}.a{x:1}.card{margin:0}'), ['a']);

        self::assertSame(['(authored) #0' => 'FAIL_VALUE'], self::statusesByLabel($changed));
        self::assertSame(1.0, $changed->passRate());

        $extra = self::compare(self::utilities('.a{x:1}'), self::utilities('.a{x:1}.btn{y:1}'), ['a']);

        self::assertSame(['(authored) #0' => 'FAIL_EXTRA'], self::statusesByLabel($extra));
    }

    /** @test */
    public function aRegionDifferenceDoesNotHideACandidateFailure(): void
    {
        $result = self::compare(
            '@layer theme{:root{--a:1}}' . self::utilities('.a{x:1}'),
            '@layer theme{:root{--a:2}}' . self::utilities('.a{x:2}'),
            ['a']
        );

        self::assertSame(['a' => 'FAIL_VALUE'], $result->statuses());
        self::assertSame(['a', '(theme) --a'], \array_column($result->failures(), 'candidate'));
    }

    // ------------------------------------------------------------ parse errors

    /** @test */
    public function anUnreadableActualStylesheetIsAParseErrorNotAPass(): void
    {
        $result = self::compare(
            self::utilities('.a{x:1}'),
            '@layer utilities{.a{x:1}',
            ['a']
        );

        self::assertSame(['a' => 'ERROR_PARSE'], $result->statuses());
        self::assertSame(0.0, $result->passRate());
        self::assertTrue($result->hasParseErrors());
        self::assertStringStartsWith('actual: ', $result->diagnostics()[0]);
        self::assertFalse($result->isIdentical());
    }

    /** @test */
    public function anUnreadableGoldenIsAParseErrorToo(): void
    {
        $result = self::compare('@layer utilities{.a{x:1}', self::utilities('.a{x:1}'), ['a']);

        self::assertSame(['a' => 'ERROR_PARSE'], $result->statuses());
        self::assertStringStartsWith('expected: ', $result->diagnostics()[0]);
    }

    /** @test */
    public function twoIdenticalUnreadableStylesheetsDoNotPass(): void
    {
        $broken = '@layer utilities{.a{x:1}';

        $result = self::compare($broken, $broken, ['a', 'b']);

        self::assertSame(['a' => 'ERROR_PARSE', 'b' => 'ERROR_PARSE'], $result->statuses());
        self::assertSame(0.0, $result->passRate());
        self::assertCount(2, $result->diagnostics());
        self::assertFalse($result->isIdentical());
    }

    /** @test */
    public function aMalformedUrlIsAParseErrorOnTheSideThatHasIt(): void
    {
        // What the pinned CLI really emits for bg-[url(javascript:alert(1))].
        $result = self::compare(
            self::utilities('.a{background-image:url(javascript:alert(1))}'),
            self::utilities('.a{background-image:none}'),
            ['a']
        );

        self::assertSame(['a' => 'ERROR_PARSE'], $result->statuses());
        self::assertStringStartsWith('expected: ', $result->diagnostics()[0]);
    }

    /** @test */
    public function aDiagnosticFromTheNestingFlattenerIsAParseErrorToo(): void
    {
        // `& &` doubles the selector at every level; past the flattener's cap the
        // subtree is dropped with a diagnostic, and that must not read as a pass.
        $open = '';
        $close = '';

        for ($i = 0; $i < 14; ++$i) {
            $open .= '& &{';
            $close .= '}';
        }

        $css = '.a{' . $open . 'x:1' . $close . '}';

        $result = self::compare($css, $css, ['a']);

        self::assertSame(['a' => 'ERROR_PARSE'], $result->statuses());
        self::assertSame(0.0, $result->passRate());
    }

    // ------------------------------------------------------------- determinism

    /** @test */
    public function twoRunsGiveTheSameResult(): void
    {
        $expected = '@layer theme{:root{--a:1}}'
            . self::utilities('.a{x:1}.b{x:2}.c{x:3}.btn{y:1}')
            . '@property --p{syntax:"*";inherits:false}';
        $actual = '@layer theme{:root{--a:2;--z:1}}'
            . self::utilities('.c{x:3}.a{x:9}.d{x:4}.btn{y:2}')
            . '@property --q{syntax:"*";inherits:false}';
        $candidates = ['a', 'b', 'c', 'd'];

        $first = self::compare($expected, $actual, $candidates)->toArray();
        $second = self::compare($expected, $actual, $candidates)->toArray();

        self::assertSame($first, $second);
        self::assertNotSame([], $first['failures']);
    }

    /** @test */
    public function theComparisonIsPureAndTheComparatorHoldsNoState(): void
    {
        $comparator = new CssComparator();
        $css = self::utilities('.a{x:1}');

        $one = $comparator->compare($css, $css, ['a'])->toArray();
        $comparator->compare(self::utilities('.a{x:2}'), $css, ['a']);
        $two = $comparator->compare($css, $css, ['a'])->toArray();

        self::assertSame($one, $two);
    }

    // ---------------------------------------------------------------- helpers

    private static function utilities(string $rules): string
    {
        return '@layer utilities{' . $rules . '}';
    }

    /**
     * @param list<string> $candidates
     */
    private static function compare(string $expected, string $actual, array $candidates): ComparisonResult
    {
        return (new CssComparator())->compare($expected, $actual, $candidates);
    }

    /**
     * Failure label => status.
     *
     * @return array<string, string>
     */
    private static function statusesByLabel(ComparisonResult $result): array
    {
        $labelled = [];

        foreach ($result->failures() as $failure) {
            $labelled[$failure['candidate']] = $failure['status'];
        }

        return $labelled;
    }
}
