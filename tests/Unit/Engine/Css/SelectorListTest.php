<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\SelectorList;

/**
 * Selector-string helpers (design doc 07 §Phase 1): top-level comma split,
 * `&` detection and substitution, and the "complex parent" test the nesting
 * flattener uses — all string-, bracket- and escape-aware.
 */
final class SelectorListTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider splits
     *
     * @param list<string> $expected
     */
    public function splitHonoursStringsBracketsParensAndEscapes(string $selector, array $expected): void
    {
        self::assertSame($expected, SelectorList::split($selector));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function splits(): array
    {
        return [
            'single' => ['.a', ['.a']],
            'empty' => ['', []],
            'two' => ['.a, .b', ['.a', '.b']],
            'trimmed parts' => [" .a ,\n .b ", ['.a', '.b']],
            'empty parts dropped' => ['a,,b,', ['a', 'b']],
            'comma in a double-quoted attribute' => ['[x="a,b"], c', ['[x="a,b"]', 'c']],
            'comma in a single-quoted attribute' => ["[x='a,b'],c", ["[x='a,b']", 'c']],
            'escaped quote inside a string' => ['[x="a\\",b"],c', ['[x="a\\",b"]', 'c']],
            'comma inside :is()' => [':is(a, b), c', [':is(a, b)', 'c']],
            'comma inside nested parens' => [':not(:is(a, b)), c', [':not(:is(a, b))', 'c']],
            'comma inside brackets' => ['[a,b], c', ['[a,b]', 'c']],
            'escaped comma' => ['.a\\,b, c', ['.a\\,b', 'c']],
            'escaped trailing space survives the trim' => ['.a\\ , b', ['.a\\ ', 'b']],
            'hex escape terminator survives the trim' => ['.\\31  , b', ['.\\31 ', 'b']],
            'plain trailing space is trimmed' => ['.a\\\\ , b', ['.a\\\\', 'b']],
        ];
    }

    /**
     * @test
     *
     * @dataProvider ampersands
     */
    public function ampersandsAreFoundOnlyOutsideStringsBracketsAndEscapes(string $selector, int $count): void
    {
        self::assertSame($count, SelectorList::countAmpersands($selector));
        self::assertSame($count > 0, SelectorList::containsAmpersand($selector));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function ampersands(): array
    {
        return [
            'leading' => ['&:hover', 1],
            'none' => ['.a .b', 0],
            'two' => ['& + &', 2],
            'inside :is()' => [':is(&) .x', 1],
            'inside a string' => ['[data-x="&"]', 0],
            'inside brackets' => ['[x=&]', 0],
            'escaped' => ['.a\\&b', 0],
            'after an escaped backslash' => ['.a\\\\&', 1],
            'string then real one' => ['[x="&"] &', 1],
        ];
    }

    /**
     * @test
     *
     * @dataProvider replacements
     */
    public function replaceAmpersandSubstitutesOnlyRealAmpersands(string $selector, string $with, string $want): void
    {
        self::assertSame($want, SelectorList::replaceAmpersand($selector, $with));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function replacements(): array
    {
        return [
            'pseudo-class' => ['&:hover', '.p-4', '.p-4:hover'],
            'trailing' => ['.dark &', '.a', '.dark .a'],
            'twice' => ['& + &', '.a', '.a + .a'],
            'string left alone' => ['[x="&"] &', '.a', '[x="&"] .a'],
            'escape left alone' => ['.b\\& &', '.a', '.b\\& .a'],
            'none' => ['.x', '.a', '.x'],
            'replacement containing an ampersand is not re-scanned' => ['&', '.a\\&b', '.a\\&b'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider complexity
     */
    public function complexMeansACombinatorOrAPseudoElementAtTheTopLevel(string $selector, bool $complex): void
    {
        self::assertSame($complex, SelectorList::isComplex($selector));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function complexity(): array
    {
        return [
            'class' => ['.a', false],
            'compound' => ['a.b#c[d]:hover', false],
            'descendant' => ['.a .b', true],
            'child' => ['.a>.b', true],
            'next sibling' => ['.a+.b', true],
            'subsequent sibling' => ['.a ~ .b', true],
            'pseudo-element' => ['.a::before', true],
            'legacy pseudo-element' => ['.a:after', true],
            'legacy pseudo-element any case' => ['.a:First-Line', true],
            'pseudo-class sharing a prefix' => ['.a:before-x', false],
            'whitespace inside :is()' => [':is(.a .b)', false],
            'plus inside :nth-child()' => [':nth-child(2n+1)', false],
            'whitespace inside an attribute string' => ['[x="a b"]', false],
            'escaped space' => ['.a\\ b', false],
            'hex escape terminator' => ['.\\32 xl', false],
            'surrounding whitespace only' => ['  .a  ', false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider minified
     */
    public function minifyDropsOptionalWhitespace(string $selector, string $expected): void
    {
        self::assertSame($expected, SelectorList::minify($selector));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function minified(): array
    {
        return [
            'list' => ['.a ,  .b', '.a,.b'],
            'combinators' => ['.a > .b + .c ~ .d', '.a>.b+.c~.d'],
            'descendant collapsed' => [".a \n\t .b", '.a .b'],
            'inside :is()' => [':is( .a , .b )', ':is(.a,.b)'],
            'an+b keeps its inner spaces' => [':nth-child(2n + 1)', ':nth-child(2n + 1)'],
            'strings untouched' => ['[x="a  ,  b"]', '[x="a  ,  b"]'],
            'hex escape terminator kept' => ['.\\32 xl .b', '.\\32 xl .b'],
            'escaped space kept' => ['.a\\  .b', '.a\\  .b'],
        ];
    }
}
