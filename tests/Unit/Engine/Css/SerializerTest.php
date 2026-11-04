<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The serializer (design doc 07 §Serializer): readable `pretty` output and
 * `compact` output with no optional whitespace, safe number shortening and
 * merging of adjacent identical rules (Q7, ENG-A6).
 *
 * @covers-requirement FR-COMP-2
 */
final class SerializerTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider prettyCases
     */
    public function prettyOutputIsIndentedOneDeclarationPerLine(string $css, string $expected): void
    {
        self::assertSame($expected, Serializer::pretty(self::parse($css)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function prettyCases(): array
    {
        return [
            'empty' => ['', ''],
            'one rule' => ['.a{color:red;margin:0}', ".a {\n  color: red;\n  margin: 0;\n}\n"],
            'empty rule' => ['.a{}', ".a {\n}\n"],
            'blank line between top-level rules' => [
                '.a{x:y}.b{x:z}',
                ".a {\n  x: y;\n}\n\n.b {\n  x: z;\n}\n",
            ],
            'nested blocks indent and are not separated' => [
                '@media (x){.a{x:y}.b{x:z}}',
                "@media (x) {\n  .a {\n    x: y;\n  }\n  .b {\n    x: z;\n  }\n}\n",
            ],
            'nested rule inside a rule' => [
                '.a{color:red;&:hover{color:blue}}',
                ".a {\n  color: red;\n  &:hover {\n    color: blue;\n  }\n}\n",
            ],
            'at-rule statement' => ['@import "x.css" layer(base);', "@import \"x.css\" layer(base);\n"],
            'at-rule without params' => ['@font-face{font-family:X}', "@font-face {\n  font-family: X;\n}\n"],
            'statement without params' => ['.a{@tailwind;}', ".a {\n  @tailwind;\n}\n"],
            'important' => ['.a{color:red!important}', ".a {\n  color: red !important;\n}\n"],
            'empty custom property' => ['.a{--x:;}', ".a {\n  --x: ;\n}\n"],
            'banner then content without a blank line' => [
                "/*! tailwindcss */\n@layer theme;",
                "/*! tailwindcss */\n@layer theme;\n",
            ],
            'values verbatim' => [
                '.a{font:12px/1.5 "A  B",x;width:calc(100% - 0.5px)}',
                ".a {\n  font: 12px/1.5 \"A  B\",x;\n  width: calc(100% - 0.5px);\n}\n",
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider compactCases
     */
    public function compactOutputHasNoOptionalWhitespace(string $css, string $expected): void
    {
        self::assertSame($expected, Serializer::compact(self::parse($css)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function compactCases(): array
    {
        return [
            'empty' => ['', ''],
            'one rule' => [".a {\n  color: red;\n  margin: 0;\n}\n", '.a{color:red;margin:0}'],
            'empty rule is dropped' => ['.a {}', ''],
            'empty rules between others are dropped' => ['.a{} .b{x:y} .c{}', '.b{x:y}'],
            'rule with only a comment is dropped' => ['.a { /* nothing */ }', ''],
            'empty at-rule blocks are kept' => ['@layer base {}', '@layer base{}'],
            'vendor pseudo-element is not merged' => [
                '.a::-moz-placeholder{color:red}.a::placeholder{color:red}',
                '.a::-moz-placeholder{color:red}.a::placeholder{color:red}',
            ],
            'vendor pseudo-class is not merged' => [
                '.x{a:b}.y:-ms-input-placeholder{a:b}.z{a:b}',
                '.x{a:b}.y:-ms-input-placeholder{a:b}.z{a:b}',
            ],
            'rules after a vendor rule still merge with each other' => [
                '.v::-webkit-x{a:b}.p{a:b}.q{a:b}',
                '.v::-webkit-x{a:b}.p,.q{a:b}',
            ],
            'leading zero dropped' => [
                '.a { opacity: 0.5; margin: -0.25rem +0.5px }',
                '.a{opacity:.5;margin:-.25rem +.5px}',
            ],
            'other numbers untouched' => ['.a { x: 10.5 00.5 1e-3 }', '.a{x:10.5 00.5 1e-3}'],
            'zero lengths lose their unit' => [
                '.a { margin: 0px 0rem 0.0em -0px 1px }',
                '.a{margin:0 0 0 0 1px}',
            ],
            'zero non-lengths keep their unit' => [
                '.a { x: 0% 0s 0ms 0deg 0fr }',
                '.a{x:0% 0s 0ms 0deg 0fr}',
            ],
            'unit case does not matter' => ['.a { margin: 0PX }', '.a{margin:0}'],
            'nothing is rewritten inside functions' => [
                '.a { width: calc( 0px + 0.5rem ); color: oklch(0.5 0 0 / 0.5); x: var(--y, 0px) }',
                '.a{width:calc(0px + 0.5rem);color:oklch(0.5 0 0 / 0.5);x:var(--y,0px)}',
            ],
            'flex keeps a zero basis unit' => ['.a { flex: 1 0px }', '.a{flex:1 0px}'],
            'custom properties are verbatim' => ['.a { --x:  0px  0.5 ; }', '.a{--x:0px  0.5}'],
            'colours are untouched' => ['.a { color: #FFFFFF; background: #aBc }', '.a{color:#FFFFFF;background:#aBc}'],
            'whitespace collapsed around commas' => [
                '.a { font-family: a ,  b; margin: 1px   2px }',
                '.a{font-family:a,b;margin:1px 2px}',
            ],
            'strings untouched' => ['.a { content: "a  ,  0.5px" }', '.a{content:"a  ,  0.5px"}'],
            'important' => ['.a { color: red !important; x: y }', '.a{color:red!important;x:y}'],
            'selectors minified' => ['.a > .b ,  .c ~ .d { x: y }', '.a>.b,.c~.d{x:y}'],
            'adjacent identical rules merged' => [
                '.a{color:red}.b{color:red}.c{color:blue}.d{color:red}',
                '.a,.b{color:red}.c{color:blue}.d{color:red}',
            ],
            'three in a row merged' => ['.a{x:y}.b{x:y}.c{x:y}', '.a,.b,.c{x:y}'],
            'merge inside an at-rule' => ['@media x { .a { x: y } .b { x: y } }', '@media x{.a,.b{x:y}}'],
            'no merge across an at-rule' => [
                '.a{x:y}@media x{.c{x:z}}.b{x:y}',
                '.a{x:y}@media x{.c{x:z}}.b{x:y}',
            ],
            'different important flags are not merged' => [
                '.a{x:y}.b{x:y!important}',
                '.a{x:y}.b{x:y!important}',
            ],
            'at-rule params collapse' => [
                '@media (min-width:  40rem)   and   (hover: hover) { .a { x: y } }',
                '@media (min-width:40rem) and (hover:hover){.a{x:y}}',
            ],
            'statements keep their semicolon' => ['@import "x.css" ;@layer a , b;', '@import "x.css";@layer a,b;'],
            'declaration before a nested rule' => [
                '.a { color: red; &:hover { x: y } }',
                '.a{color:red;&:hover{x:y}}',
            ],
            'nested rule before a declaration' => [
                '.a { &:hover { x: y } color: red }',
                '.a{&:hover{x:y}color:red}',
            ],
            'statement inside a block' => ['.a { @apply flex; color: red }', '.a{@apply flex;color:red}'],
            'banner kept' => ['/*! x */ .a { x: y }', '/*! x */.a{x:y}'],
        ];
    }

    /** @test */
    public function nonBannerCommentsAreDroppedInCompactAndKeptInPretty(): void
    {
        $sheet = new StyleSheet([new Comment(' note '), new Rule('.a', [new Declaration('x', 'y')])]);

        self::assertSame('.a{x:y}', Serializer::compact($sheet));
        self::assertSame("/* note */\n.a {\n  x: y;\n}\n", Serializer::pretty($sheet));
    }

    /** @test */
    public function programmaticTreesSerialize(): void
    {
        $sheet = new StyleSheet([
            new AtRule('layer', 'utilities', [
                new Rule('.p-4', [new Declaration('padding', 'calc(var(--spacing) * 4)')]),
            ]),
            new AtRule('property', '--tw-x', [
                new Declaration('syntax', '"*"'),
                new Declaration('inherits', 'false'),
            ]),
        ]);

        self::assertSame(
            "@layer utilities {\n  .p-4 {\n    padding: calc(var(--spacing) * 4);\n  }\n}\n\n"
            . "@property --tw-x {\n  syntax: \"*\";\n  inherits: false;\n}\n",
            Serializer::pretty($sheet)
        );
        self::assertSame(
            '@layer utilities{.p-4{padding:calc(var(--spacing) * 4)}}@property --tw-x{syntax:"*";inherits:false}',
            Serializer::compact($sheet)
        );
    }

    /** @test */
    public function blocksDeeperThanTheNestingCapAreDroppedFromCodeBuiltTrees(): void
    {
        $node = new Rule('.leaf', [new Declaration('x', 'leaf')]);

        for ($i = 0; $i < 40; ++$i) {
            $node = new Rule('.l' . $i, [new Declaration('x', (string) $i), $node]);
        }

        $sheet = new StyleSheet([$node]);
        $pretty = Serializer::pretty($sheet);
        $compact = Serializer::compact($sheet);

        self::assertSame(32, \substr_count($pretty, '{'));
        self::assertSame(32, \substr_count($compact, '{'));
        self::assertStringNotContainsString('.leaf', $pretty);
        self::assertStringNotContainsString('.leaf', $compact);
        self::assertStringContainsString('.l39', $pretty);
    }

    /** @test */
    public function aVeryDeepCodeBuiltTreeSerializesCheaply(): void
    {
        $node = new Rule('.leaf', [new Declaration('x', 'y')]);

        for ($i = 0; $i < 20000; ++$i) {
            $node = new Rule('.n', [$node]);
        }

        $sheet = new StyleSheet([$node]);

        self::assertLessThan(10000, \strlen(Serializer::pretty($sheet)));
        self::assertLessThan(10000, \strlen(Serializer::compact($sheet)));
    }

    /** @test */
    public function compactIsIdempotentThroughTheParser(): void
    {
        $css = '.a, .b { margin: 0.5px 0px; &:hover { x: y } } .c{x:y} @media (x) { .d { --v: { a } } }';
        $once = Serializer::compact(self::parse($css));

        self::assertSame($once, Serializer::compact(self::parse($once)));
    }

    private static function parse(string $css): StyleSheet
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);

        self::assertSame([], $bag->toArray(), 'fixture CSS must be valid');

        return $sheet;
    }
}
