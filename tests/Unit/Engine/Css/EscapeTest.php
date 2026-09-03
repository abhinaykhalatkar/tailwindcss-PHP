<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Css\Escape;

/**
 * CSS string-literal escaping (decision HBC-A2).
 *
 * This is the last thing between an exception message and a stylesheet served
 * to a browser, so the table below is the contract rather than an illustration.
 */
final class EscapeTest extends TestCase
{
    /** @test */
    public function classIsFinalAndCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(Escape::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    /**
     * @test
     *
     * @dataProvider escapingTable
     */
    public function theEscapingTableHolds(string $input, string $expected): void
    {
        self::assertSame($expected, Escape::string($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapingTable(): array
    {
        return [
            'empty' => ['', ''],
            'plain ascii' => ['hello world', 'hello world'],

            // Would otherwise end the literal.
            'a backslash' => ['a\\b', 'a\\\\b'],
            'a double quote' => ['a"b', 'a\\"b'],
            'only a quote' => ['"', '\\"'],
            'only a backslash' => ['\\', '\\\\'],

            // A raw newline is illegal inside a CSS string.
            'a line feed' => ["a\nb", 'a\\A b'],
            'a carriage return' => ["a\rb", 'a\\D b'],
            'a crlf' => ["a\r\nb", 'a\\D \\A b'],

            // NUL becomes U+FFFD, as a conforming parser would do anyway.
            'a nul byte' => ["a\0b", "a\xEF\xBF\xBDb"],

            // Left alone: legal inside a literal, and re-encoding would only
            // mangle the text in the overlay.
            'a single quote' => ["a'b", "a'b"],
            'a tab' => ["a\tb", "a\tb"],
            'multi-byte utf-8' => ['héllo — ✓', 'héllo — ✓'],
            'a css comment terminator' => ['a*/b', 'a*/b'],
            'an html tag' => ['<script>', '<script>'],
        ];
    }

    /**
     * The trailing space is part of the escape, not formatting. Without it the
     * next character is swallowed: `\A` followed by `1` parses as U+A1, not a
     * newline and then "1".
     *
     * @test
     */
    public function theHexEscapeKeepsItsTrailingSpaceBeforeAHexDigit(): void
    {
        self::assertSame('\\A 1', Escape::string("\n1"));
        self::assertSame('\\D f', Escape::string("\rf"));
    }

    /**
     * The escaped form must contain no character that could end the literal or
     * the rule — that is the whole job.
     *
     * @test
     */
    public function anAdversarialMessageCannotEscapeTheLiteral(): void
    {
        $escaped = Escape::string('"; } html { display: none } /*');

        // A substring check cannot tell an escaped quote from a bare one —
        // `\";` contains `";`. The property that matters is that every quote
        // is preceded by an *odd* number of backslashes, so none of them can
        // terminate the literal.
        for ($i = 0, $length = \strlen($escaped); $i < $length; ++$i) {
            if ($escaped[$i] !== '"') {
                continue;
            }

            $backslashes = 0;

            for ($j = $i - 1; $j >= 0 && $escaped[$j] === '\\'; --$j) {
                ++$backslashes;
            }

            self::assertSame(
                1,
                $backslashes % 2,
                \sprintf('The quote at offset %d is not escaped.', $i)
            );
        }
    }

    /**
     * The same property, stated over the whole escaping table rather than one
     * hostile string.
     *
     * @test
     *
     * @dataProvider escapingTable
     */
    public function noQuoteInTheOutputIsEverUnescaped(string $input): void
    {
        $escaped = Escape::string($input);
        $checked = 0;

        for ($i = 0, $length = \strlen($escaped); $i < $length; ++$i) {
            if ($escaped[$i] !== '"') {
                continue;
            }

            ++$checked;
            $backslashes = 0;

            for ($j = $i - 1; $j >= 0 && $escaped[$j] === '\\'; --$j) {
                ++$backslashes;
            }

            self::assertSame(1, $backslashes % 2);
        }

        // Holds for every row, including those with no quotes at all: escaping
        // never drops a quote, it only prefixes one — so a row that asserted
        // nothing above would still catch a quote going missing.
        self::assertSame(\substr_count($input, '"'), $checked);
    }

    /**
     * Byte-oriented: invalid UTF-8 must pass through rather than throw or be
     * silently dropped, because the input is an arbitrary exception message.
     *
     * @test
     */
    public function invalidUtf8PassesThroughUnchanged(): void
    {
        $invalid = "a\xC3\x28b";

        self::assertSame($invalid, Escape::string($invalid));
    }
}
