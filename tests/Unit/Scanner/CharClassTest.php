<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Scanner\CharClass;

final class CharClassTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CharClass::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function tableHasExactlyTwoHundredFiftySixEntries(): void
    {
        self::assertCount(256, CharClass::TABLE);
    }

    /**
     * @test
     *
     * @dataProvider startCases
     */
    public function isReportsTheStartFlag(string $char, bool $expected): void
    {
        self::assertSame($expected, CharClass::is(\ord($char), CharClass::START));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function startCases(): array
    {
        return [
            'letter A' => ['A', true],
            'letter z' => ['z', true],
            'digit' => ['5', true],
            'underscore' => ['_', true],
            'at sign' => ['@', true],
            'bang' => ['!', true],
            'hyphen' => ['-', true],
            'open bracket' => ['[', true],
            'asterisk' => ['*', true],
            'comma is never a start byte' => [',', false],
            'colon is not a start byte' => [':', false],
            'space is not a start byte' => [' ', false],
            'close bracket is not a start byte' => [']', false],
            'open paren is not a start byte (CSF-A22b)' => ['(', false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider continueCases
     */
    public function isReportsTheContinueFlagAtDepthZero(string $char, bool $expected): void
    {
        self::assertSame($expected, CharClass::is(\ord($char), CharClass::CONTINUE));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function continueCases(): array
    {
        return [
            'letter' => ['a', true],
            'digit' => ['0', true],
            'colon' => [':', true],
            'slash' => ['/', true],
            'dot' => ['.', true],
            'percent' => ['%', true],
            'bang' => ['!', true],
            'at sign' => ['@', true],
            'asterisk' => ['*', true],
            'hash' => ['#', true],
            'ampersand' => ['&', true],
            'comma is never a depth-zero continue byte' => [',', false],
            'space is not a continue byte' => [' ', false],
            'open bracket is not a plain continue byte' => ['[', false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider bracketCases
     */
    public function isReportsOpenAndClose(string $char, int $flag, bool $expected): void
    {
        self::assertSame($expected, CharClass::is(\ord($char), $flag));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function bracketCases(): array
    {
        return [
            'open bracket is OPEN' => ['[', CharClass::OPEN, true],
            'open paren is OPEN' => ['(', CharClass::OPEN, true],
            'close bracket is CLOSE' => [']', CharClass::CLOSE, true],
            'close paren is CLOSE' => [')', CharClass::CLOSE, true],
            'open bracket is not CLOSE' => ['[', CharClass::CLOSE, false],
            'close bracket is not OPEN' => [']', CharClass::OPEN, false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider quoteCases
     */
    public function isReportsQuote(string $char, bool $expected): void
    {
        self::assertSame($expected, CharClass::is(\ord($char), CharClass::QUOTE));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function quoteCases(): array
    {
        return [
            'single quote' => ['\'', true],
            'double quote' => ['"', true],
            'backtick' => ['`', true],
            'letter is not a quote' => ['a', false],
        ];
    }

    /**
     * @test
     *
     * @dataProvider whitespaceCases
     */
    public function isReportsWhitespace(string $char, bool $expected): void
    {
        self::assertSame($expected, CharClass::is(\ord($char), CharClass::WHITESPACE));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function whitespaceCases(): array
    {
        return [
            'space' => [' ', true],
            'tab' => ["\t", true],
            'line feed' => ["\n", true],
            'carriage return' => ["\r", true],
            'form feed' => ["\x0C", true],
            'vertical tab' => ["\x0B", true],
            'letter is not whitespace' => ['a', false],
            'hyphen is not whitespace' => ['-', false],
        ];
    }

    /** @test */
    public function commaConstantMatchesTheAsciiCode(): void
    {
        self::assertSame(44, CharClass::COMMA);
        self::assertSame(',', \chr(CharClass::COMMA));
    }

    /** @test */
    public function isNeverThrowsForAnyByteValue(): void
    {
        self::expectNotToPerformAssertions();

        for ($byte = 0; $byte <= 255; ++$byte) {
            CharClass::is($byte, CharClass::START);
        }
    }
}
