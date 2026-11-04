<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Conformance\Support\Canonicalizer;

/**
 * The canonical form (design doc 16 §Comparison step 3).
 *
 * Every expectation here is a hand-written array, **not** a round trip through
 * the comparator: the canonicaliser is the thing the comparator trusts, so it is
 * pinned on its own, rule by rule. The converter at the bottom is deliberately
 * independent of the production code. Notation:
 *
 *   ['rule', selector, [children]]
 *   ['decl', property, value]  and  ['decl', property, value, '!important']
 *   ['at', name, params, [children] | null]
 *
 * Half the rows are the other direction — what the canonicaliser must *refuse*
 * to normalise, because doing so would let two different stylesheets compare
 * equal (review R-0001, adversarial acceptance 4b).
 */
final class CanonicalizerTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider declarationRows
     *
     * @param array<int, mixed> $expected
     */
    public function declarationsAreNormalisedOneRuleAtATime(string $css, array $expected): void
    {
        self::assertSame([['rule', 'a', $expected]], $this->canonical('a{' . $css . '}'));
    }

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>}>
     */
    public static function declarationRows(): array
    {
        return [
            // ----- the property
            'property is lower-cased' => ['COLOR:red', [['decl', 'color', 'red']]],
            'a mixed-case property' => ['Background-Color:red', [['decl', 'background-color', 'red']]],
            'a custom property keeps its case' => ['--Foo:1;--foo:2', [['decl', '--Foo', '1'], ['decl', '--foo', '2']]],
            'important is kept' => ['color:red !important', [['decl', 'color', 'red', '!important']]],
            // ----- whitespace
            'a value is trimmed and its runs collapse' => [
                'margin:  1px   2px ;',
                [['decl', 'margin', '1px 2px']],
            ],
            'a newline is whitespace' => ["margin:1px\n\t2px", [['decl', 'margin', '1px 2px']]],
            // ----- numbers
            'a leading zero goes' => ['opacity:0.5', [['decl', 'opacity', '.5']]],
            'trailing zeros go' => ['opacity:0.50', [['decl', 'opacity', '.5']]],
            'a whole number written with a point' => ['order:1.0', [['decl', 'order', '1']]],
            'a longer number' => ['width:10.50px', [['decl', 'width', '10.5px']]],
            'a negative leading zero' => ['margin:-0.5rem', [['decl', 'margin', '-.5rem']]],
            'a bare fraction' => ['opacity:.50', [['decl', 'opacity', '.5']]],
            'zero point zero' => ['order:0.0', [['decl', 'order', '0']]],
            'several leading zeros' => ['opacity:00.5', [['decl', 'opacity', '.5']]],
            'an exponent is left alone' => ['x:1e3', [['decl', 'x', '1e3']]],
            'a percentage' => ['width:50.0%', [['decl', 'width', '50%']]],
            'integers are not rewritten' => ['z-index:007', [['decl', 'z-index', '007']]],
            'units are case-insensitive' => ['width:10PX', [['decl', 'width', '10px']]],
            // ----- zero lengths
            'a zero length loses its unit' => ['padding:0px', [['decl', 'padding', '0']]],
            'several zero lengths' => [
                'margin:0px 0.5rem 0rem 0em',
                [['decl', 'margin', '0 .5rem 0 0']],
            ],
            'zero percent keeps its unit' => ['width:0%', [['decl', 'width', '0%']]],
            'zero seconds keeps its unit' => ['transition-duration:0s', [['decl', 'transition-duration', '0s']]],
            'zero degrees keeps its unit' => ['rotate:0deg', [['decl', 'rotate', '0deg']]],
            'a zero inside a function keeps its unit' => [
                'translate:translate(0px, 0px)',
                [['decl', 'translate', 'translate(0px, 0px)']],
            ],
            'a zero inside calc is never made unitless' => [
                'width:calc(0px + 1rem)',
                [['decl', 'width', 'calc(0px + 1rem)']],
            ],
            // ----- colours
            'a short hex colour is lower-cased' => ['color:#FFF', [['decl', 'color', '#fff']]],
            'a long hex colour' => ['color:#AbCdEf', [['decl', 'color', '#abcdef']]],
            'eight digits' => ['color:#AABBCCDD', [['decl', 'color', '#aabbccdd']]],
            'five digits is not a colour' => ['x:#ABCDE', [['decl', 'x', '#ABCDE']]],
            'not hex digits' => ['x:#GGG', [['decl', 'x', '#GGG']]],
            // ----- functions and operators
            'calc operators get single spaces' => [
                'width:calc(1/2*100%)',
                [['decl', 'width', 'calc(1 / 2 * 100%)']],
            ],
            'calc padding is removed' => ['width:calc( 1 + 2 )', [['decl', 'width', 'calc(1 + 2)']]],
            'calc with var' => [
                'padding:calc(var(--spacing)*4)',
                [['decl', 'padding', 'calc(var(--spacing) * 4)']],
            ],
            'commas get one space after' => [
                'transition-timing-function:cubic-bezier(0.4,0,0.2,1)',
                [['decl', 'transition-timing-function', 'cubic-bezier(.4, 0, .2, 1)']],
            ],
            'a space before a comma goes' => ['font-family:a ,b', [['decl', 'font-family', 'a, b']]],
            'a slash in rgb' => ['color:rgb(0 0 0/0.1)', [['decl', 'color', 'rgb(0 0 0 / .1)']]],
            'a slash in a shorthand' => ['font:12px/1.5 serif', [['decl', 'font', '12px / 1.5 serif']]],
            'a fallback after a comma' => ['x:var(--a,0.5)', [['decl', 'x', 'var(--a, .5)']]],
            // ----- what must not be touched
            'a string is copied exactly' => [
                'content:"a  b ,  0.50 #FFF /"',
                [['decl', 'content', '"a  b ,  0.50 #FFF /"']],
            ],
            'a single-quoted string' => [
                "font-family:'Apple  Color Emoji'",
                [['decl', 'font-family', "'Apple  Color Emoji'"]],
            ],
            'an unquoted url is copied exactly' => [
                'background:url(x/0.50.png)',
                [['decl', 'background', 'url(x/0.50.png)']],
            ],
            'a quoted url is copied exactly' => [
                'background:url("a  b/0.50.png") #FFF',
                [['decl', 'background', 'url("a  b/0.50.png") #fff']],
            ],
            'identifiers keep their case and digits' => ['x:h1 H1 Foo-0.5', [['decl', 'x', 'h1 H1 Foo-0.5']]],
            'keywords are not lower-cased' => ['display:BLOCK', [['decl', 'display', 'BLOCK']]],
            'a color keyword is not lower-cased' => ['color:RED', [['decl', 'color', 'RED']]],
        ];
    }

    /**
     * Custom-property values are opaque token streams: whitespace runs collapse
     * (the pinned CLI wraps long values over several lines) and nothing else.
     *
     * @test
     *
     * @dataProvider customValueRows
     */
    public function customPropertyValuesOnlyLoseWhitespaceRuns(string $css, string $expected): void
    {
        self::assertSame([['rule', 'a', [['decl', '--x', $expected]]]], $this->canonical('a{--x:' . $css . '}'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function customValueRows(): array
    {
        return [
            'runs collapse and the ends trim' => ['  a    b  ', 'a b'],
            'a wrapped value is one line' => ["'a',\n    'b'", "'a', 'b'"],
            'numbers are not rewritten' => ['0.50 0PX .5', '0.50 0PX .5'],
            'hex is not lower-cased' => ['#FFF', '#FFF'],
            'comma spacing is not changed' => ['a,b', 'a,b'],
            'operators are not spaced' => ['calc(1/2*3)', 'calc(1/2*3)'],
            'a string is exact' => ['"a  b"', '"a  b"'],
            'an empty string value' => ['""', '""'],
            'a url is exact' => ['url(x/0.50.png)', 'url(x/0.50.png)'],
            'case is kept' => ['Foo BAR', 'Foo BAR'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider selectorRows
     */
    public function selectorsAreNormalised(string $selector, string $expected): void
    {
        self::assertSame([['rule', $expected, [['decl', 'x', '1']]]], $this->canonical($selector . '{x:1}'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function selectorRows(): array
    {
        return [
            'tight combinators get spaces' => ['.a>.b', '.a > .b'],
            'loose combinators are collapsed' => ['.a   >   .b', '.a > .b'],
            'plus' => ['.a+.b', '.a + .b'],
            'tilde' => ['.a~.b', '.a ~ .b'],
            'descendant whitespace is one space' => ['.a    .b', '.a .b'],
            'a newline is whitespace' => ["a\n\n b", 'a b'],
            'the list is sorted' => ['.z,.a,.m', '.a, .m, .z'],
            'list spaces are normalised' => ['.b ,  .a', '.a, .b'],
            'host and root sort' => [':root, :host', ':host, :root'],
            'a compound selector is never reordered' => ['.b.a:hover', '.b.a:hover'],
            'type id class attribute order stays' => ['a.b#c[x]', 'a.b#c[x]'],
            'case is not changed' => ['A.Foo', 'A.Foo'],
            'an attribute selector is copied exactly' => ['[data-x="a  >  b"]', '[data-x="a  >  b"]'],
            'spaces inside brackets stay' => ["a[ x ~= 'y' ]", "a[ x ~= 'y' ]"],
            'a hex escape keeps its terminating space' => ['.\32 xl\:flex', '.\32 xl\:flex'],
            'a real space after a hex escape stays' => ['.\32  xl', '.\32  xl'],
            'a combinator after a hex escape' => ['.\32 > .c', '.\32 > .c'],
            'a descendant after a hex escape and a digit' => ['.a  .\31 0  .b', '.a .\31 0 .b'],
            'escaped brackets and symbols' => [
                '.\[\&\>\*\]\:p-4 > *',
                '.\[\&\>\*\]\:p-4 > *',
            ],
            'nth arguments only lose whitespace runs' => ['a:nth-child(2n+1)', 'a:nth-child(2n+1)'],
            'nth arguments with spaces' => [
                'a:nth-child(  2n   +   1 )',
                'a:nth-child(2n + 1)',
            ],
            'combinators inside is()' => [':is(.a>.b)', ':is(.a > .b)'],
            'is() with spaces' => [':is(.a   >   .b)', ':is(.a > .b)'],
            'a relative selector in has()' => [':has(>img)', ':has(> img)'],
            'a list inside is()' => [':is(.a,.b)', ':is(.a, .b)'],
            'nested pseudo functions' => [
                '.group-hover\:p-4:is(:where(.group):hover *)',
                '.group-hover\:p-4:is(:where(.group):hover *)',
            ],
            'a pseudo-element' => ['a::before:hover', 'a::before:hover'],
            'the list is sorted but each part is normalised' => ['.b>.c,.a', '.a, .b > .c'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider atRuleRows
     */
    public function atRuleNamesAndParametersAreNormalised(string $css, string $name, string $params): void
    {
        self::assertSame([['at', $name, $params, [['rule', 'a', [['decl', 'x', '1']]]]]], $this->canonical($css));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function atRuleRows(): array
    {
        $body = '{a{x:1}}';

        return [
            'the name is lower-cased' => ['@MEDIA print' . $body, 'media', 'print'],
            'tight greater-or-equal' => ['@media (width>=48rem)' . $body, 'media', '(width >= 48rem)'],
            'loose greater-or-equal' => ['@media (width   >=   48rem)' . $body, 'media', '(width >= 48rem)'],
            'half-tight greater-or-equal' => ['@media (width>= 48rem)' . $body, 'media', '(width >= 48rem)'],
            'less-or-equal' => ['@media (width<=40rem)' . $body, 'media', '(width <= 40rem)'],
            'less than' => ['@media (width<40rem)' . $body, 'media', '(width < 40rem)'],
            'greater than' => ['@media (width>40rem)' . $body, 'media', '(width > 40rem)'],
            'a range' => ['@media (40rem<=width<64rem)' . $body, 'media', '(40rem <= width < 64rem)'],
            'a colon gets one space after' => ['@media (hover:hover)' . $body, 'media', '(hover: hover)'],
            'a space before a colon goes' => ['@media (min-width :40rem)' . $body, 'media', '(min-width: 40rem)'],
            'keywords are lower-cased' => [
                '@media SCREEN AND (MIN-WIDTH: 40REM)' . $body,
                'media',
                'screen and (min-width: 40rem)',
            ],
            'not and a parenthesis' => [
                '@media not (prefers-reduced-motion:reduce)' . $body,
                'media',
                'not (prefers-reduced-motion: reduce)',
            ],
            'numbers are written one way' => ['@media (min-width:40.0rem)' . $body, 'media', '(min-width: 40rem)'],
            'nested supports' => [
                '@supports (not (-webkit-appearance:-apple-pay-button))' . $body,
                'supports',
                '(not (-webkit-appearance: -apple-pay-button))',
            ],
            'a supports function' => ['@supports selector(a>b)' . $body, 'supports', 'selector(a > b)'],
            'a function inside a supports value' => [
                '@supports (color:rgb(from red r g b))' . $body,
                'supports',
                '(color: rgb(from red r g b))',
            ],
            'a custom property test is left exactly as written' => [
                '@supports (--Foo:Bar Baz)' . $body,
                'supports',
                '(--Foo: Bar Baz)',
            ],
            'a container query' => ['@container (MIN-WIDTH:400PX)' . $body, 'container', '(min-width: 400px)'],
            'a container name keeps its case' => [
                '@container Sidebar (min-width:400px)' . $body,
                'container',
                'Sidebar (min-width: 400px)',
            ],
            'a container name with a keyword' => [
                '@container Sidebar NOT (min-width:400px)' . $body,
                'container',
                'Sidebar not (min-width: 400px)',
            ],
            'keyframes names keep their case' => ['@keyframes Spin' . $body, 'keyframes', 'Spin'],
            'keyframes whitespace is collapsed' => ['@keyframes   spin  ' . $body, 'keyframes', 'spin'],
            'a layer name keeps its case' => ['@layer Base' . $body, 'layer', 'Base'],
            'a property name keeps its case' => ['@property --Foo' . $body, 'property', '--Foo'],
        ];
    }

    /** @test */
    public function aLayerStatementIsAListWithOneSpaceAfterEachComma(): void
    {
        self::assertSame(
            [['at', 'layer', 'theme, base, components, utilities', null]],
            $this->canonical('@layer theme,base ,  components   ,utilities;')
        );
    }

    /** @test */
    public function nestedCssIsFlattenedFirst(): void
    {
        self::assertSame(
            [['rule', '.a:hover', [['decl', 'color', 'red']]]],
            $this->canonical('.a{&:hover{color:red}}')
        );
    }

    /** @test */
    public function aConditionalInsideARuleIsHoistedAboveIt(): void
    {
        self::assertSame(
            [['at', 'media', '(hover: hover)', [['rule', '.x:hover', [['decl', 'color', 'red']]]]]],
            $this->canonical('.x{&:hover{@media (hover:hover){color:red}}}')
        );
    }

    /** @test */
    public function theRealCliShapeFlattensToOneRulePerBlock(): void
    {
        self::assertSame(
            [
                [
                    'at',
                    'layer',
                    'utilities',
                    [
                        ['rule', '.before\:hover\:flex::before', [['decl', 'content', 'var(--tw-content)']]],
                        [
                            'at',
                            'media',
                            '(hover: hover)',
                            [['rule', '.before\:hover\:flex::before:hover', [['decl', 'display', 'flex']]]],
                        ],
                    ],
                ],
            ],
            $this->canonical(
                "@layer utilities {\n  .before\\:hover\\:flex {\n    &::before {\n      content: var(--tw-content);\n"
                . "      &:hover {\n        @media (hover: hover) {\n          display: flex;\n        }\n      }\n"
                . "    }\n  }\n}"
            )
        );
    }

    /** @test */
    public function commentsAreDropped(): void
    {
        self::assertSame(
            [['rule', 'a', [['decl', 'color', 'red']]]],
            $this->canonical('/* one */a{/* two */color:red;/* three */}')
        );
    }

    /** @test */
    public function theInputIsNeverModified(): void
    {
        $css = '.b,.a{COLOR:red;margin:0.50rem 0px}@media (width>=48rem){.q{y:2}}';
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);
        $before = Serializer::compact($sheet);

        Canonicalizer::canonical($sheet, $bag);

        self::assertSame($before, Serializer::compact($sheet));
    }

    /** @test */
    public function canonicalisationIsIdempotent(): void
    {
        $css = '.b,.a>.c{COLOR:#FFF;margin:0.50rem 0px;--Foo:  a   b;width:calc(1/2*100%)}'
            . '@MEDIA (width>=48rem) AND (hover:hover){.q{y:2.0}}@layer a,b;';
        $once = $this->canonical($css);
        $bag = new DiagnosticBag();
        $twice = self::toArray(Canonicalizer::canonical($this->sheetFromArray($css), $bag));

        self::assertSame($once, $twice);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return list<array<int, mixed>>
     */
    private function canonical(string $css): array
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);
        $canonical = Canonicalizer::canonical($sheet, $bag);

        self::assertTrue($bag->isEmpty(), 'The test input must parse cleanly.');

        return self::toArray($canonical);
    }

    private function sheetFromArray(string $css): \TailwindPhp\Engine\Css\Node\StyleSheet
    {
        $bag = new DiagnosticBag();
        $once = Canonicalizer::canonical(Parser::parse($css, $bag), $bag);

        return Parser::parse(Serializer::compact($once), $bag);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private static function toArray(\TailwindPhp\Engine\Css\Node\StyleSheet $sheet): array
    {
        return self::nodes($sheet->nodes);
    }

    /**
     * @param array<array-key, Node> $nodes
     *
     * @return list<array<int, mixed>>
     */
    private static function nodes(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $row = ['decl', $node->property, $node->value];

                if ($node->important) {
                    $row[] = '!important';
                }

                $out[] = $row;
            } elseif ($node instanceof Rule) {
                $out[] = ['rule', $node->selector, self::nodes($node->nodes)];
            } elseif ($node instanceof AtRule) {
                $out[] = [
                    'at',
                    $node->name,
                    $node->params,
                    $node->nodes === null ? null : self::nodes($node->nodes),
                ];
            } elseif ($node instanceof Comment) {
                $out[] = ['comment', $node->text];
            }
        }

        return $out;
    }
}
