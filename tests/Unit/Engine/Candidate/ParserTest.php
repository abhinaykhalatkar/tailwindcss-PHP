<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * The candidate grammar of design doc 07 §Phase 3, line by line, against the
 * roots of {@see StaticRootSet::forTests()}.
 *
 * @covers-requirement FR-COMP-2
 */
final class ParserTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider grammar
     *
     * @param list<string> $expected
     */
    public function candidatesParseToTheirInterpretations(string $raw, array $expected): void
    {
        $parser = new Parser(StaticRootSet::forTests());

        self::assertSame($expected, CandidateDump::lines($parser->parse($raw)));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function grammar(): array
    {
        return [
            // static-name
            'static utility' => ['flex', ['static:flex']],
            'static utility with dashes' => ['text-center', ['static:text-center', 'fn:text=center']],
            'functional root used without a value' => ['border', ['static:border']],
            'functional root exact, plus a shorter root' => ['border-t', ['static:border-t', 'fn:border=t']],

            // functional-root "-" value
            'functional utility' => ['p-4', ['fn:p=4']],
            'decimal value' => ['px-2.5', ['fn:px=2.5']],
            'static and functional share a root' => ['flex-1', ['fn:flex=1']],
            'colour value' => ['text-red-500', ['fn:text=red-500']],
            'overlapping roots, longest first' => ['border-t-red-500', ['fn:border-t=red-500', 'fn:border=t-red-500']],
            'multi-dash root' => ['grid-cols-3', ['fn:grid-cols=3']],
            'keyword value' => ['w-full', ['fn:w=full']],

            // fraction vs modifier (ENG-A4)
            'fraction: modifier reading then fraction' => ['w-1/2', ['fn:w=1 /2', 'fn:w=1/2 (fraction)']],
            'colour with opacity modifier' => ['bg-red-500/50', ['fn:bg=red-500 /50', 'fn:bg=red-500/50']],
            'arbitrary modifier has one reading' => ['bg-red-500/[0.33]', ['fn:bg=red-500 /[0.33]']],
            'variable modifier has one reading' => ['bg-red-500/(--o)', ['fn:bg=red-500 /(--o)']],
            'line-height modifier' => ['text-xl/8', ['fn:text=xl /8', 'fn:text=xl/8']],
            'modifier on a static match' => ['flex/50', ['static:flex /50']],

            // functional-root "-[" arbitrary "]"
            'arbitrary length' => ['w-[32rem]', ['fn:w=[32rem]']],
            'arbitrary colour' => ['bg-[#123]', ['fn:bg=[#123]']],
            'arbitrary value with a type hint' => ['bg-[length:var(--x)]', ['fn:bg=[length:var(--x)]']],
            'underscores become spaces' => ['grid-cols-[1fr_auto]', ['fn:grid-cols=[1fr auto]']],
            'underscores kept inside url()' => ['bg-[url(/a_b.png)]', ['fn:bg=[url(/a_b.png)]']],
            'escaped underscore kept' => ["content-['a\\_b']", ["fn:content=['a_b']"]],
            'semicolon inside a string' => ["content-['a;b']", ["fn:content=['a;b']"]],
            'math operators get spaces' => ['w-[calc(100%-2rem)]', ['fn:w=[calc(100% - 2rem)]']],
            'arbitrary value with a modifier' => ['bg-[#fff]/50', ['fn:bg=[#fff] /50']],
            'arbitrary value on a multi-dash root' => ['border-t-[3px]', ['fn:border-t=[3px]']],

            // functional-root "-(" css-var ")"
            'variable value' => ['bg-(--brand)', ['fn:bg=(--brand)']],
            'variable value with a type hint' => ['bg-(color:--brand)', ['fn:bg=(color:--brand)']],
            'variable name keeps underscores' => ['bg-(--my_brand)', ['fn:bg=(--my_brand)']],

            // "[" property ":" value "]"
            'arbitrary property' => ['[color:red]', ['prop:color=[red]']],
            'arbitrary custom property' => ['[--my-var:1px_2px]', ['prop:--my-var=[1px 2px]']],
            'arbitrary vendor property' => ['[-webkit-line-clamp:3]', ['prop:-webkit-line-clamp=[3]']],
            'arbitrary property with a modifier' => ['[color:red]/50', ['prop:color=[red] /50']],
            'arbitrary property colon inside the value' => ['[background:url(a:b)]', ['prop:background=[url(a:b)]']],

            // variants
            'one variant' => ['hover:flex', ['[hover]static:flex']],
            'variants in written order' => ['md:hover:p-4', ['[md,hover]fn:p=4']],
            'arbitrary selector variant' => ['[&>*]:p-4', ['[[&>*]]fn:p=4']],
            'arbitrary selector variant with underscores' => ['[&_p]:p-4', ['[[& p]]fn:p=4']],
            'arbitrary at-rule variant' => ['[@media(width>=600px)]:p-4', ['[[@media(width>=600px)]]fn:p=4']],
            'colon inside an arbitrary variant' => ['[&:nth-child(3)]:p-4', ['[[&:nth-child(3)]]fn:p=4']],
            'named arbitrary variant' => ['data-[state=open]:p-4', ['[data[state=open]]fn:p=4']],
            'variable variant' => ['supports-(--x):p-4', ['[supports[var(--x)]]fn:p=4']],
            'variant with a modifier' => ['group-hover/item:p-4', ['[group-hover/item]fn:p=4']],
            'eight variants are allowed' => [
                'a:b:c:d:e:f:g:h:flex',
                ['[a,b,c,d,e,f,g,h]static:flex'],
            ],

            // important and negative
            'important prefix' => ['!p-4', ['!fn:p=4']],
            'important suffix' => ['p-4!', ['!fn:p=4']],
            'negative' => ['-mt-4', ['-fn:mt=4']],
            'important then negative' => ['!-mt-4', ['!-fn:mt=4']],
            'negative with important suffix and a variant' => ['hover:-mt-4!', ['[hover]!-fn:mt=4']],
            'important on an arbitrary property' => ['[color:red]!', ['!prop:color=[red]']],

            // no interpretation
            'unknown utility' => ['unknown', []],
            'unknown root with a value' => ['foo-4', []],
            'empty value' => ['bg-', []],
            'arbitrary value on an unknown root' => ['foo-[3px]', []],
            'variable value on an unknown root' => ['foo-(--x)', []],
        ];
    }

    /**
     * @test
     *
     * @dataProvider rejected
     */
    public function malformedCandidatesHaveNoInterpretation(string $raw): void
    {
        $parser = new Parser(StaticRootSet::forTests());

        self::assertSame([], $parser->parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejected(): array
    {
        return [
            'empty' => [''],
            '257 bytes' => ['w-[' . \str_repeat('1', 252) . 'px]'],
            'newline' => ["p-4\n"],
            'tab' => ["p-\t4"],
            'control byte' => ["p-4\x01"],
            'semicolon outside brackets' => ['p-4;'],
            'brace outside brackets' => ['p-{4}'],
            'closing brace outside brackets' => ['p-4}'],
            'comment opener outside brackets' => ['p-4/*'],
            'nine variants' => ['a:b:c:d:e:f:g:h:i:flex'],
            'two modifiers' => ['bg-red-500/50/50'],
            'empty modifier' => ['bg-red-500/'],
            'empty arbitrary modifier' => ['bg-red-500/[]'],
            'empty variable modifier' => ['bg-red-500/()'],
            'variable modifier without dashes' => ['bg-red-500/(o)'],
            'bracketed named modifier' => ['bg-red-500/x]'],
            'empty arbitrary value' => ['bg-[]'],
            'whitespace-only arbitrary value' => ['bg-[_]'],
            'unterminated arbitrary value' => ['bg-[#fff'],
            'arbitrary value with an unbalanced paren' => ['w-[calc(1px]'],
            'type hint without a value' => ['bg-[color:]'],
            'variable value without dashes' => ['bg-(brand)'],
            'variable value bare dashes' => ['bg-(--)'],
            'unterminated variable value' => ['bg-(--x'],
            'variable variant with a type hint' => ['supports-(length:--x):p-4'],
            'arbitrary property without a colon' => ['[color]'],
            'arbitrary property without a property' => ['[:red]'],
            'arbitrary property without a value' => ['[color:]'],
            'arbitrary property upper case' => ['[Color:red]'],
            'arbitrary property starting with a digit' => ['[1color:red]'],
            'arbitrary property unterminated' => ['[color:red'],
            'negative arbitrary property' => ['-[color:red]'],
            'arbitrary property with an unsafe value' => ['[color:red;x:y]'],
            'empty variant' => ['hover::p-4'],
            'leading empty variant' => [':p-4'],
            'empty utility' => ['p-4:'],
            'only important' => ['!'],
            'only negative' => ['-'],
            'empty arbitrary variant' => ['[]:p-4'],
            'arbitrary at-rule variant with an ampersand' => ['[@media(x)&]:p-4'],
            'arbitrary variant with a semicolon' => ['[&;x]:p-4'],
            'variant with two modifiers' => ['group/a/b:p-4'],
            'quote in a plain variant' => ["a'b:p-4"],
            'bracket in a named value' => ['p-4]'],
            'arbitrary value not at the end' => ['bg-[#fff]x'],
        ];
    }

    /** @test */
    public function theCandidateKeepsItsRawText(): void
    {
        $parser = new Parser(StaticRootSet::forTests());

        foreach ($parser->parse('md:hover:!bg-red-500/50') as $candidate) {
            self::assertSame('md:hover:!bg-red-500/50', $candidate->raw);
        }
    }

    /**
     * @test
     *
     * @dataProvider prefixed
     *
     * @param list<string> $expected
     */
    public function aPrefixMustBeTheFirstVariant(string $raw, array $expected): void
    {
        $parser = new Parser(StaticRootSet::forTests(), 'tw');

        self::assertSame($expected, CandidateDump::lines($parser->parse($raw)));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function prefixed(): array
    {
        return [
            'prefixed' => ['tw:flex', ['static:flex']],
            'prefixed with variants' => ['tw:hover:p-4', ['[hover]fn:p=4']],
            'prefix still counts towards nothing else' => ['tw:!-mt-4', ['!-fn:mt=4']],
            'unprefixed' => ['flex', []],
            'prefix after a variant' => ['hover:tw:flex', []],
            'prefix alone' => ['tw', []],
            'prefix and nothing' => ['tw:', []],
            'other prefix' => ['tx:flex', []],
        ];
    }

    /**
     * @test
     *
     * @dataProvider withVariantRoots
     */
    public function aVariantRootSetSplitsNameAndValue(string $raw, string $expected): void
    {
        $parser = new Parser(
            StaticRootSet::forTests(),
            null,
            new StaticRootSet(['hover', 'focus-visible', 'md'], ['group', 'aria', 'nth', 'min'])
        );
        $candidates = $parser->parse($raw);

        self::assertCount(1, $candidates);
        self::assertSame($expected, CandidateDump::line($candidates[0]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function withVariantRoots(): array
    {
        return [
            'static variant' => ['hover:flex', '[hover]static:flex'],
            'static variant with a dash' => ['focus-visible:flex', '[focus-visible]static:flex'],
            'compound variant' => ['group-hover:flex', '[group-hover]static:flex'],
            'functional variant' => ['aria-checked:flex', '[aria-checked]static:flex'],
            'unknown variant keeps its text' => ['foo-bar:flex', '[foo-bar]static:flex'],
        ];
    }

    /** @test */
    public function aVariantRootSetFillsNameAndValueSeparately(): void
    {
        $parser = new Parser(StaticRootSet::forTests(), null, new StaticRootSet(['hover'], ['group', 'nth']));
        $candidates = $parser->parse('group-hover/item:nth-3:hover:flex');

        self::assertCount(1, $candidates);
        $variants = $candidates[0]->variants;
        self::assertSame(
            ['group', 'hover', 'group-hover/item'],
            [$variants[0]->name, $variants[0]->value, $variants[0]->raw]
        );
        self::assertNotNull($variants[0]->modifier);
        self::assertSame('item', $variants[0]->modifier->value);
        self::assertSame(['nth', '3'], [$variants[1]->name, $variants[1]->value]);
        self::assertSame(['hover', null], [$variants[2]->name, $variants[2]->value]);
    }

    /** @test */
    public function segmentIsDepthAndStringAware(): void
    {
        self::assertSame(['a', 'b', 'c'], Parser::segment('a:b:c', ':'));
        self::assertSame(['[a:b]', 'c'], Parser::segment('[a:b]:c', ':'));
        self::assertSame(['x(a:b)', 'c'], Parser::segment('x(a:b):c', ':'));
        self::assertSame(["['a:b']"], Parser::segment("['a:b']", ':'));
        self::assertSame(['a\\:b', 'c'], Parser::segment('a\\:b:c', ':'));
        self::assertSame(['', ''], Parser::segment(':', ':'));
        self::assertSame([''], Parser::segment('', ':'));
        self::assertSame(['a', 'b c'], Parser::segment('a,b c', ','));
    }
}
