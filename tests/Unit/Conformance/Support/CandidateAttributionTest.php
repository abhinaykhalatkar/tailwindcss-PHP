<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;

/**
 * Mapping a generated rule back to the candidate that produced it
 * (design doc 16 §Comparison: attribution by the escaped class selector).
 */
final class CandidateAttributionTest extends TestCase
{
    /**
     * Selectors exactly as the pinned CLI writes them for each candidate.
     *
     * @test
     *
     * @dataProvider attributions
     *
     * @param list<string> $candidates
     */
    public function aSelectorAttributesToItsCandidate(string $selector, array $candidates, ?string $expected): void
    {
        self::assertSame($expected, (new CandidateAttribution($candidates))->attribute($selector));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string|null}>
     */
    public static function attributions(): array
    {
        return [
            'a plain utility' => ['.flex', ['flex', 'p-4'], 'flex'],
            'a variant stack' => [
                '.md\:hover\:bg-red-500:hover',
                ['md:hover:bg-red-500', 'bg-red-500'],
                'md:hover:bg-red-500',
            ],
            'an arbitrary variant with a child combinator' => [
                '.\[\&\>\*\]\:p-4 > *',
                ['[&>*]:p-4'],
                '[&>*]:p-4',
            ],
            'a fraction' => ['.w-1\/2', ['w-1/2'], 'w-1/2'],
            'important' => ['.\!flex', ['!flex', 'flex'], '!flex'],
            'a leading digit written as a hex escape' => ['.\32 xl\:flex', ['2xl:flex'], '2xl:flex'],
            'a group variant names two classes' => [
                '.group-hover\:p-4:is(:where(.group):hover *)',
                ['group-hover:p-4'],
                'group-hover:p-4',
            ],
            'a pseudo-element' => [
                '.file\:hover\:flex::file-selector-button:hover',
                ['file:hover:flex'],
                'file:hover:flex',
            ],
            'the candidate is not the first class' => [
                '.parent .\[\.parent_\&\]\:p-4',
                ['[.parent_&]:p-4'],
                '[.parent_&]:p-4',
            ],
            'a selector list takes the first candidate in it' => ['.a, .b', ['b', 'a'], 'a'],
            'an arbitrary value with brackets and a colon' => [
                '.bg-\[url\(\\\'\/img\/a\.png\\\'\)\]',
                ["bg-[url('/img/a.png')]"],
                "bg-[url('/img/a.png')]",
            ],
            'the longest listed class wins over an earlier shorter one' => [
                '.flex .\[\.flex_\&\]\:p-4',
                ['flex', '[.flex_&]:p-4'],
                '[.flex_&]:p-4',
            ],
            'the longest wins whatever the order of the candidate list' => [
                '.flex .\[\.flex_\&\]\:p-4',
                ['[.flex_&]:p-4', 'flex'],
                '[.flex_&]:p-4',
            ],
            'a shorter listed class alone still counts' => ['.flex .parent', ['flex'], 'flex'],
            'equal lengths take the first in the selector' => ['.ab .cd', ['cd', 'ab'], 'ab'],
            'a list takes the first part first' => ['.\!flex, .flex\!', ['flex!', '!flex'], '!flex'],
            // An unlisted class cannot be told from a helper class such as `.group`, so a
            // listed class anywhere in the selector still owns the rule (a known limit).
            'a listed class beside an unlisted one owns the rule' => ['.dark .foo', ['dark'], 'dark'],
            'an authored class is nobody' => ['.btn', ['flex'], null],
            'an element selector' => ['h1', ['flex'], null],
            'the theme selector' => [':root, :host', ['flex'], null],
            'the universal selector' => ['*, ::after, ::before', ['flex'], null],
            'a class that merely resembles a candidate' => ['.flex-1', ['flex'], null],
            'no candidates at all' => ['.flex', [], null],
            'a class inside an attribute selector is not a class' => ['[class~=".flex"]', ['flex'], null],
            'a class inside a string is not a class' => ['a[title=".flex"]', ['flex'], null],
            'an escaped dot is not a class' => ['a\.flex', ['flex'], null],
        ];
    }

    /**
     * @test
     *
     * @dataProvider classLists
     *
     * @param list<string> $expected
     */
    public function classesAreListedInOrderAndUnescaped(string $selector, array $expected): void
    {
        self::assertSame($expected, CandidateAttribution::classesOf($selector));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function classLists(): array
    {
        return [
            'one class' => ['.a', ['a']],
            'two' => ['.a.b', ['a', 'b']],
            'inside a function' => [':not(.x) .y', ['x', 'y']],
            'with escapes' => ['.md\:p-4:hover', ['md:p-4']],
            'a hex escape' => ['.\32 xl', ['2xl']],
            'none' => ['h1 > p', []],
            'a trailing dot' => ['a.', []],
            'an attribute is skipped' => ['a[x=".y"].z', ['z']],
            'a string is skipped' => ['a[x=\'.y\'] .z', ['z']],
        ];
    }

    /**
     * @test
     *
     * @dataProvider escapedIdentifiers
     */
    public function unescapeIdentifierReversesCssEscaping(string $escaped, string $expected): void
    {
        self::assertSame($expected, CandidateAttribution::unescapeIdentifier($escaped));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapedIdentifiers(): array
    {
        return [
            'plain' => ['p-4', 'p-4'],
            'colon' => ['md\:flex', 'md:flex'],
            'slash' => ['w-1\/2', 'w-1/2'],
            'brackets and symbols' => ['\[\&\>\*\]\:underline', '[&>*]:underline'],
            'a leading digit as hex' => ['\32 xl\:flex', '2xl:flex'],
            'a two digit number' => ['\31 0', '10'],
            'a dot' => ['p-0\.5', 'p-0.5'],
            'important prefix' => ['\!flex', '!flex'],
            'important suffix' => ['flex\!', 'flex!'],
            'hex without a terminator before a non-hex letter' => ['\31x', '1x'],
            'a hex escape takes at most six digits' => ['\0000411', 'A1'],
            'an emoji' => ['\1f600 ', "\u{1F600}"],
            'NUL becomes the replacement character' => ['\0 ', "\u{FFFD}"],
            'a surrogate becomes the replacement character' => ['\d800 ', "\u{FFFD}"],
            'past the last code point' => ['\110000 ', "\u{FFFD}"],
            'a trailing backslash' => ['a\\', "a\u{FFFD}"],
            'an escaped backslash' => ['a\\\\b', 'a\\b'],
            'a percent' => ['w-\[50\%\]', 'w-[50%]'],
            'non ascii passes through' => ["caf\u{E9}", "caf\u{E9}"],
            'a hex escape ended by a newline' => ["\\31\nx", '1x'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider allAttributions
     *
     * @param list<string> $candidates
     * @param list<string> $expected
     */
    public function aSelectorListAttributesToEveryCandidate(string $selector, array $candidates, array $expected): void
    {
        self::assertSame($expected, (new CandidateAttribution($candidates))->attributeAll($selector));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function allAttributions(): array
    {
        return [
            'two candidates in one list' => ['.\!flex, .flex\!', ['!flex', 'flex!'], ['!flex', 'flex!']],
            'the list order, not the candidate order' => ['.\!flex, .flex\!', ['flex!', '!flex'], ['!flex', 'flex!']],
            'one part unlisted' => ['.flex, .grid', ['flex'], ['flex']],
            'the same candidate twice is once' => ['.flex:hover, .flex:focus', ['flex'], ['flex']],
            'each part takes its own longest' => [
                '.a .abc, .d .de',
                ['a', 'abc', 'd', 'de'],
                ['abc', 'de'],
            ],
            'a comma inside :is() does not split' => [':is(.a, .b) .c', ['a', 'b', 'c'], ['a']],
            'a comma inside a string does not split' => ['a[x=".b, .c"].d', ['b', 'c', 'd'], ['d']],
            'nothing listed' => ['.flex, .grid', [], []],
            'an empty selector' => ['', ['flex'], []],
        ];
    }

    /** @test */
    public function aDuplicateCandidateIsHarmless(): void
    {
        self::assertSame('flex', (new CandidateAttribution(['flex', 'flex']))->attribute('.flex'));
    }
}
