<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The CSS parser (design doc 07 §Phase 1): rules, at-rules, declarations and
 * the banner comment, nesting preserved, and browser-style error recovery.
 *
 * @covers-requirement FR-COMP-2
 */
final class ParserTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider wellFormed
     *
     * @param list<array<int, mixed>> $expected
     */
    public function wellFormedInputParsesToTheExpectedTree(string $css, array $expected): void
    {
        $bag = new DiagnosticBag();

        self::assertSame($expected, AstDump::of(Parser::parse($css, $bag)));
        self::assertSame([], $bag->toArray());
    }

    /**
     * @return array<string, array{0: string, 1: list<array<int, mixed>>}>
     */
    public static function wellFormed(): array
    {
        return [
            'empty input' => ['', []],
            'whitespace only' => [" \n\t ", []],
            'one rule' => [
                '.a { color: red; }',
                [['rule', '.a', [['decl', 'color', 'red', false]]]],
            ],
            'last declaration without a semicolon' => [
                '.a{color:red;margin:0}',
                [['rule', '.a', [['decl', 'color', 'red', false], ['decl', 'margin', '0', false]]]],
            ],
            'empty rule' => ['.a {}', [['rule', '.a', []]]],
            'selector list and combinators kept verbatim' => [
                ".a,\n.b > .c ~ d + e {x:y}",
                [['rule', ".a,\n.b > .c ~ d + e", [['decl', 'x', 'y', false]]]],
            ],
            'escaped selector kept verbatim' => [
                '.hover\\:flex:hover{display:flex}',
                [['rule', '.hover\\:flex:hover', [['decl', 'display', 'flex', false]]]],
            ],
            'trailing escaped space in a selector survives' => [
                '.a\\  {x:y}',
                [['rule', '.a\\ ', [['decl', 'x', 'y', false]]]],
            ],
            'attribute selector with braces in a string' => [
                '[data-x="{;}"] {x:y}',
                [['rule', '[data-x="{;}"]', [['decl', 'x', 'y', false]]]],
            ],
            'at-rule statement' => [
                '@import "theme.css" layer(theme);',
                [['at', 'import', '"theme.css" layer(theme)', null]],
            ],
            'layer order statement' => [
                '@layer theme, base, components, utilities;',
                [['at', 'layer', 'theme, base, components, utilities', null]],
            ],
            'at-rule with a block of rules' => [
                '@media (min-width: 40rem) { .a { color: red } }',
                [['at', 'media', '(min-width: 40rem)', [['rule', '.a', [['decl', 'color', 'red', false]]]]]],
            ],
            'at-rule without params' => [
                '@font-face { font-family: X; src: url(x.woff2) }',
                [['at', 'font-face', '', [
                    ['decl', 'font-family', 'X', false],
                    ['decl', 'src', 'url(x.woff2)', false],
                ]]],
            ],
            'empty at-rule block' => ['@layer base {}', [['at', 'layer', 'base', []]]],
            'at-rule nested in an at-rule' => [
                '@layer x { @media y { a { b: c } } }',
                [['at', 'layer', 'x', [['at', 'media', 'y', [['rule', 'a', [['decl', 'b', 'c', false]]]]]]]],
            ],
            'nested rules and declarations after them' => [
                '.a { color: red; &:hover { color: blue } .b { x: y } margin: 0 }',
                [['rule', '.a', [
                    ['decl', 'color', 'red', false],
                    ['rule', '&:hover', [['decl', 'color', 'blue', false]]],
                    ['rule', '.b', [['decl', 'x', 'y', false]]],
                    ['decl', 'margin', '0', false],
                ]]],
            ],
            'relative selector in a nested rule' => [
                '.a { > .b { x: y } }',
                [['rule', '.a', [['rule', '> .b', [['decl', 'x', 'y', false]]]]]],
            ],
            'at-rule inside a rule' => [
                '.a { @media (x) { color: red } }',
                [['rule', '.a', [['at', 'media', '(x)', [['decl', 'color', 'red', false]]]]]],
            ],
            'at-rule statement inside a rule' => [
                '.a { @apply p-4 hover:flex; color: red }',
                [['rule', '.a', [['at', 'apply', 'p-4 hover:flex', null], ['decl', 'color', 'red', false]]]],
            ],
            'at-rule statement ended by the closing brace' => [
                '.a { @apply flex }',
                [['rule', '.a', [['at', 'apply', 'flex', null]]]],
            ],
            'a pseudo-class selector is a rule, not a declaration' => [
                'a:hover{color:red}',
                [['rule', 'a:hover', [['decl', 'color', 'red', false]]]],
            ],
            'important' => ['a{color:red!important}', [['rule', 'a', [['decl', 'color', 'red', true]]]]],
            'important with spaces and any case' => [
                'a{color: red ! IMPORTANT ;}',
                [['rule', 'a', [['decl', 'color', 'red', true]]]],
            ],
            'important on a custom property' => [
                'a{--x: 1 !important}',
                [['rule', 'a', [['decl', '--x', '1', true]]]],
            ],
            'important-like text inside a value is not the flag' => [
                'a{content:"!important"}',
                [['rule', 'a', [['decl', 'content', '"!important"', false]]]],
            ],
            'custom property values are verbatim' => [
                ':root{--x: {a:b; c: d};--y:  1px  2px ;--empty:;--fn: var(--a, { b })}',
                [['rule', ':root', [
                    ['decl', '--x', '{a:b; c: d}', false],
                    ['decl', '--y', '1px  2px', false],
                    ['decl', '--empty', '', false],
                    ['decl', '--fn', 'var(--a, { b })', false],
                ]]],
            ],
            'values keep strings, escapes, urls and functions as written' => [
                'a{content:"\\"}";background:url( a.png ) no-repeat;width:calc(100% - 2px);font:12px/1.5 "A B",x}',
                [['rule', 'a', [
                    ['decl', 'content', '"\\"}"', false],
                    ['decl', 'background', 'url( a.png ) no-repeat', false],
                    ['decl', 'width', 'calc(100% - 2px)', false],
                    ['decl', 'font', '12px/1.5 "A B",x', false],
                ]]],
            ],
            'semicolons inside parentheses do not end a declaration' => [
                'a{x:f(a;b);y:z}',
                [['rule', 'a', [['decl', 'x', 'f(a;b)', false], ['decl', 'y', 'z', false]]]],
            ],
            'multi-line values are kept' => [
                "a{grid-template-areas:\n  \"a b\"\n  \"c d\";}",
                [['rule', 'a', [['decl', 'grid-template-areas', "\"a b\"\n  \"c d\"", false]]]],
            ],
            'declarations at the top level are kept' => ['color: red;', [['decl', 'color', 'red', false]]],
            'stray semicolons are ignored' => [';;a{;x:y;;};', [['rule', 'a', [['decl', 'x', 'y', false]]]]],
            'banner comment is kept' => [
                "/*! tailwindcss | MIT */\n.a{}",
                [['comment', '! tailwindcss | MIT '], ['rule', '.a', []]],
            ],
            'banner after leading whitespace is kept' => ["\n  /*!x*/ a{}", [['comment', '!x'], ['rule', 'a', []]]],
            // ENG-A14: a comment between two tokens becomes one space.
            'comment between two values keeps them apart' => [
                'a{margin:1px/**/2px}',
                [['rule', 'a', [['decl', 'margin', '1px 2px', false]]]],
            ],
            'comment inside a custom property keeps tokens apart' => [
                'a{--x: a/**/b}',
                [['rule', 'a', [['decl', '--x', 'a b', false]]]],
            ],
            'comment inside a selector keeps compounds apart' => [
                'a/**/b{x:y}',
                [['rule', 'a b', [['decl', 'x', 'y', false]]]],
            ],
            'comment before a colon' => [
                'a{color/* c */:red}',
                [['rule', 'a', [['decl', 'color', 'red', false]]]],
            ],
            'comment right after a colon' => [
                'a{color:/**/red}',
                [['rule', 'a', [['decl', 'color', 'red', false]]]],
            ],
            'comment next to whitespace adds nothing' => [
                'a{margin:1px /**/ 2px}',
                [['rule', 'a', [['decl', 'margin', '1px  2px', false]]]],
            ],
            'comment in an at-rule prelude' => [
                '@media/**/screen{}',
                [['at', 'media', 'screen', []]],
            ],
            'ordinary comments are dropped' => [
                '/* lead */ a /* in selector */ { color: /* in value */ red; /* between */ }',
                [['rule', 'a', [['decl', 'color', 'red', false]]]],
            ],
            'a banner that is not first is dropped' => ['a{} /*! late */', [['rule', 'a', []]]],
            'html comment markers at the top level are ignored' => [
                '<!-- a{x:y} -->',
                [['rule', 'a', [['decl', 'x', 'y', false]]]],
            ],
            'html comment markers inside a value are ordinary text' => [
                'a{content:x-->y}',
                [['rule', 'a', [['decl', 'content', 'x-->y', false]]]],
            ],
            'crlf input' => ["a{\r\n  x: y;\r\n}", [['rule', 'a', [['decl', 'x', 'y', false]]]]],
        ];
    }

    /** @test */
    public function positionsAreKeptOnlyInDebugMode(): void
    {
        $css = ".a {\n  color: red;\n  @media x { b {} }\n}";

        $plain = Parser::parse($css, new DiagnosticBag());
        $rule = $plain->nodes[0];
        self::assertInstanceOf(Rule::class, $rule);
        self::assertNull($rule->line);
        self::assertNull($rule->col);

        $debug = Parser::parse($css, new DiagnosticBag(), true);
        $rule = $debug->nodes[0];
        self::assertInstanceOf(Rule::class, $rule);
        self::assertSame([1, 1], [$rule->line, $rule->col]);

        $declaration = $rule->nodes[0];
        self::assertInstanceOf(Declaration::class, $declaration);
        self::assertSame([2, 3], [$declaration->line, $declaration->col]);

        $media = $rule->nodes[1];
        self::assertInstanceOf(AtRule::class, $media);
        self::assertSame([3, 3], [$media->line, $media->col]);
    }

    /**
     * @test
     *
     * @dataProvider recovery
     *
     * @param list<array<int, mixed>> $expected
     */
    public function malformedInputRecoversWithOneDiagnostic(string $css, array $expected, string $messagePart): void
    {
        $bag = new DiagnosticBag();

        self::assertSame($expected, AstDump::of(Parser::parse($css, $bag)));
        self::assertCount(1, $bag, \json_encode($bag->toArray()) ?: '');
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
        self::assertTrue($bag->all()[0]->isError());
        self::assertStringContainsString($messagePart, $bag->all()[0]->message());
        self::assertStringContainsString('line', $bag->all()[0]->message());
        self::assertStringContainsString('column', $bag->all()[0]->message());
    }

    /**
     * @return array<string, array{0: string, 1: list<array<int, mixed>>, 2: string}>
     */
    public static function recovery(): array
    {
        return [
            'unterminated string drops the declaration only' => [
                "a{content:\"oops\n;color:red}",
                [['rule', 'a', [['decl', 'color', 'red', false]]]],
                'Unterminated string',
            ],
            'malformed url drops the declaration only' => [
                'a{background:url(x y);color:red}',
                [['rule', 'a', [['decl', 'color', 'red', false]]]],
                'Malformed url',
            ],
            'bad string in a selector drops that rule only' => [
                "a[title=\"x\n] {color:red} b{c:d}",
                [['rule', 'b', [['decl', 'c', 'd', false]]]],
                'Unterminated string',
            ],
            'stray closing brace at the top level' => [
                '} a{b:c}',
                [['rule', 'a', [['decl', 'b', 'c', false]]]],
                'closing brace',
            ],
            'missing closing brace at eof' => [
                'a{b:c',
                [['rule', 'a', [['decl', 'b', 'c', false]]]],
                'not closed',
            ],
            'missing closing braces at several levels report once' => [
                'a{b{c:d',
                [['rule', 'a', [['rule', 'b', [['decl', 'c', 'd', false]]]]]],
                'not closed',
            ],
            'declaration without a colon' => [
                'a{color red;x:y}',
                [['rule', 'a', [['decl', 'x', 'y', false]]]],
                'Invalid declaration',
            ],
            'declaration without a property' => [
                'a{: red;x:y}',
                [['rule', 'a', [['decl', 'x', 'y', false]]]],
                'Invalid declaration',
            ],
            'rule without a selector' => [
                '{x:y} a{b:c}',
                [['rule', 'a', [['decl', 'b', 'c', false]]]],
                'selector',
            ],
            'unterminated comment' => [
                'a{b:c} /* open',
                [['rule', 'a', [['decl', 'b', 'c', false]]]],
                'Unterminated comment',
            ],
        ];
    }

    /** @test */
    public function aStringUnterminatedAtEofAlsoLeavesTheBlockOpen(): void
    {
        $bag = new DiagnosticBag();

        self::assertSame([['rule', 'a', []]], AstDump::of(Parser::parse('a{content:"oops', $bag)));
        self::assertCount(2, $bag);
        self::assertStringContainsString('Unterminated string', $bag->all()[0]->message());
        self::assertStringContainsString('not closed', $bag->all()[1]->message());
    }

    /** @test */
    public function aDiagnosticNamesThePositionOfTheOffendingToken(): void
    {
        $bag = new DiagnosticBag();

        Parser::parse("a {\n  b: \"x\n}", $bag);

        self::assertSame(2, $bag->all()[0]->line());
        self::assertStringContainsString('line 2, column 6', $bag->all()[0]->message());
        self::assertNull($bag->all()[0]->file());
    }

    /** @test */
    public function thirtyTwoNestedBlocksAreKept(): void
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse(\str_repeat('a{', 32) . 'x:y' . \str_repeat('}', 32), $bag);

        self::assertSame(32, AstDump::depth($sheet));
        self::assertSame([], $bag->toArray());
    }

    /** @test */
    public function theThirtyThirdNestedBlockIsDroppedWithADiagnostic(): void
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse(\str_repeat('a{', 33) . 'x:y' . \str_repeat('}', 33) . 'b{c:d}', $bag);

        self::assertSame(32, AstDump::depth($sheet));
        self::assertCount(1, $bag);
        self::assertStringContainsString('nesting too deep', $bag->all()[0]->message());

        // Parsing carries on after the dropped subtree.
        $last = $sheet->nodes[\count($sheet->nodes) - 1];
        self::assertSame(['rule', 'b', [['decl', 'c', 'd', false]]], AstDump::node($last));
    }

    /** @test */
    public function aNulByteInASelectorBecomesTheReplacementCharacter(): void
    {
        $sheet = Parser::parse(".a\0b{x:y}", new DiagnosticBag());

        self::assertSame([['rule', ".a\xEF\xBF\xBDb", [['decl', 'x', 'y', false]]]], AstDump::of($sheet));
    }

    /** @test */
    public function invalidUtf8IsCarriedThroughWithoutError(): void
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse(".a\xFF\xC3{content:\"\xFE\"}", $bag);

        self::assertSame([['rule', ".a\xFF\xC3", [['decl', 'content', "\"\xFE\"", false]]]], AstDump::of($sheet));
        self::assertSame([], $bag->toArray());
    }

    /** @test */
    public function syntaxDiagnosticsAreCapped(): void
    {
        $bag = new DiagnosticBag();

        Parser::parse(\str_repeat("a{b:\"x\n}", 500), $bag);

        self::assertLessThanOrEqual(101, \count($bag));
        self::assertStringContainsString('Too many', $bag->all()[\count($bag) - 1]->message());
    }

    /**
     * @test
     *
     * @dataProvider oddInputs
     */
    public function theParserNeverThrows(string $css): void
    {
        $sheet = Parser::parse($css, new DiagnosticBag());

        self::assertInstanceOf(StyleSheet::class, $sheet);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function oddInputs(): array
    {
        $inputs = [
            '}', '{', '(', ')', '[', ']', '"', "'", '\\', '@', '@media', '@{', '@;', '::', ';;;', '{{}}}}',
            'a{b', 'a{b:', 'a{b:c', 'url(', 'url("', '/*', '/*!', '--x:{', '--x:{{{', 'a{--x:(', '#', '!',
            "\0", "\xFF", '-->', '<!--', 'a{}}}}{', '@media{@media{', 'a{b:url(x y', '.a\\', 'a{b:"\\',
            '@import url(', '!important', 'a{!important}', ':;', 'a,b,{', 'a{&{&{&', "\\\n", '1e', '.5.',
        ];
        $rows = [];

        foreach ($inputs as $i => $input) {
            $rows['odd input ' . $i] = [$input];
        }

        return $rows;
    }
}
