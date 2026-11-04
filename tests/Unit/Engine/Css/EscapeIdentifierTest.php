<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Token;
use TailwindPhp\Engine\Css\Tokenizer;

/**
 * Class-name escaping (design doc 07 §Application): the selector for a
 * candidate is `.` + `Escape::identifier(raw)`, escaped the way Tailwind does
 * it — the CSSOM `CSS.escape()` algorithm.
 */
final class EscapeIdentifierTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider candidates
     */
    public function candidatesAreEscapedLikeTailwind(string $raw, string $expected): void
    {
        self::assertSame($expected, Escape::identifier($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function candidates(): array
    {
        return [
            'plain' => ['flex', 'flex'],
            'empty' => ['', ''],
            'variant colon' => ['hover:flex', 'hover\\:flex'],
            'fraction slash' => ['w-1/2', 'w-1\\/2'],
            'arbitrary brackets and hash' => ['bg-[#fff]', 'bg-\\[\\#fff\\]'],
            'parens and percent' => ['w-[calc(100%-2px)]', 'w-\\[calc\\(100\\%-2px\\)\\]'],
            'decimal point' => ['p-0.5', 'p-0\\.5'],
            'important bang' => ['!flex', '\\!flex'],
            'trailing important bang' => ['flex!', 'flex\\!'],
            'comma' => ['grid-cols-[1fr,2fr]', 'grid-cols-\\[1fr\\,2fr\\]'],
            'underscore kept' => ['grid-cols-[1fr_2fr]', 'grid-cols-\\[1fr_2fr\\]'],
            'arbitrary variant' => ['[&>*]:p-4', '\\[\\&\\>\\*\\]\\:p-4'],
            'sibling combinators' => ['[&+a~b]:x', '\\[\\&\\+a\\~b\\]\\:x'],
            'quotes' => ["content-['x\"']", "content-\\[\\'x\\\"\\'\\]"],
            'space' => ['a b', 'a\\ b'],
            'at sign' => ['@container', '\\@container'],
            'leading digit' => ['2xl:flex', '\\32 xl\\:flex'],
            'leading zero' => ['0', '\\30 '],
            'leading dash then digit' => ['-1', '-\\31 '],
            'negative utility' => ['-mt-4', '-mt-4'],
            'lone dash' => ['-', '\\-'],
            'double dash' => ['--x', '--x'],
            'non-ascii kept' => ["caf\xC3\xA9", "caf\xC3\xA9"],
            'nul becomes U+FFFD' => ["a\0b", "a\xEF\xBF\xBDb"],
            'control byte as hex' => ["a\x01b", 'a\\1 b'],
            'delete as hex' => ["a\x7Fb", 'a\\7f b'],
            'newline as hex' => ["a\nb", 'a\\a b'],
            'backslash' => ['a\\b', 'a\\\\b'],
            'braces and semicolon' => ['a{b;c}', 'a\\{b\\;c\\}'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider roundTrippable
     */
    public function theEscapedFormTokenisesBackToTheRawName(string $raw): void
    {
        $tokens = Tokenizer::tokenize(Escape::identifier($raw));

        self::assertCount(2, $tokens);
        self::assertSame(Token::IDENT, $tokens[0]->type);
        self::assertSame($raw, $tokens[0]->value);
    }

    /**
     * Every candidate row except the empty one (no identifier) and the NUL one
     * (U+FFFD replaces it, by design).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function roundTrippable(): array
    {
        $rows = self::candidates();

        unset($rows['empty'], $rows['nul becomes U+FFFD']);

        return $rows;
    }

    /** @test */
    public function escapeStringIsUnchanged(): void
    {
        self::assertSame('a\\"b\\A c', Escape::string("a\"b\nc"));
    }
}
