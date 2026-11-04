<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;

/**
 * Arbitrary value validation and normalisation (design doc 07 §Arbitrary
 * value validation, v4 `decodeArbitraryValue`).
 */
final class ArbitraryValueTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider normalisation
     */
    public function safeValuesAreNormalisedLikeV4(string $raw, string $expected): void
    {
        self::assertSame($expected, ArbitraryValue::validate($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalisation(): array
    {
        return [
            'plain' => ['32rem', '32rem'],
            'underscores become spaces' => ['1fr_auto_1fr', '1fr auto 1fr'],
            'escaped underscore is kept' => ['a\\_b', 'a_b'],
            'other escapes are kept' => ['a\\:b', 'a\\:b'],
            'underscores kept inside url()' => ['url(/my_image.png)', 'url(/my_image.png)'],
            'underscores kept inside a quoted url()' => ["url('/my_image.png')", "url('/my_image.png')"],
            'underscores kept inside src()' => ['src(a_b.woff)', 'src(a_b.woff)'],
            'underscores kept in the variable name of var()' => ['var(--my_var)', 'var(--my_var)'],
            'underscores decoded in the var() fallback' => ['var(--my_var,a_b)', 'var(--my_var,a b)'],
            'underscores kept in the first theme() argument' => ['theme(--my_key)', 'theme(--my_key)'],
            'underscores decoded around url()' => ['url(a_b)_no-repeat', 'url(a_b) no-repeat'],
            'underscores in strings become spaces' => ["'a_b'", "'a b'"],
            'quotes are preserved' => ['"Inter"', '"Inter"'],
            'calc subtraction gets spaces' => ['calc(100%-2rem)', 'calc(100% - 2rem)'],
            'calc addition after underscores' => ['calc(1px_+_2px)', 'calc(1px + 2px)'],
            'calc multiplication and division' => ['calc(1/2*100%)', 'calc(1 / 2 * 100%)'],
            'calc with var() operands' => ['calc(var(--a)-var(--b))', 'calc(var(--a) - var(--b))'],
            'calc negative number is a sign' => ['calc(-1*var(--x))', 'calc(-1 * var(--x))'],
            'calc exponent is not an operator' => ['calc(1e-3*2)', 'calc(1e-3 * 2)'],
            'calc hyphenated names inside var() untouched' => ['calc(var(--my-x)*2)', 'calc(var(--my-x) * 2)'],
            'min() commas get a space' => ['min(1px,2px)', 'min(1px, 2px)'],
            'clamp() mixed' => ['clamp(1rem,2vw+1rem,3rem)', 'clamp(1rem, 2vw + 1rem, 3rem)'],
            'nested parens inside calc' => ['calc((1px+2px)*3)', 'calc((1px + 2px) * 3)'],
            'non-math functions untouched' => ['rgb(0,0,0)', 'rgb(0,0,0)'],
            'nth-child an+b untouched' => [':nth-child(2n+1)', ':nth-child(2n+1)'],
            'ambiguous dash left alone' => ['calc(1rem-x)', 'calc(1rem-x)'],
            'brackets inside a selector value' => ['&[data-x]', '&[data-x]'],
            '256 bytes' => [\str_repeat('a', 256), \str_repeat('a', 256)],
        ];
    }

    /**
     * @test
     *
     * @dataProvider unsafe
     */
    public function unsafeValuesAreRejected(string $raw): void
    {
        self::assertFalse(ArbitraryValue::isSafe($raw));
        self::assertNull(ArbitraryValue::validate($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafe(): array
    {
        return [
            '257 bytes' => [\str_repeat('a', 257)],
            'semicolon' => ['red;color:blue'],
            'open brace' => ['a{b'],
            'close brace' => ['a}b'],
            'brace inside a string' => ["'}'"],
            'newline' => ["a\nb"],
            'carriage return' => ["a\rb"],
            'control byte' => ["a\x01b"],
            'delete byte' => ["a\x7Fb"],
            'comment opener' => ['a/*b'],
            'unterminated double-quoted string' => ['"abc'],
            'unterminated single-quoted string' => ["'abc"],
            'trailing backslash' => ['abc\\'],
            'escaped newline' => ["a\\\nb"],
            'unbalanced open paren' => ['calc(1px'],
            'unbalanced close paren' => ['1px)'],
            'unbalanced open bracket' => ['[a'],
            'unbalanced close bracket' => ['a]'],
            'crossed nesting' => ['(a[b)c]'],
            'javascript url' => ['url(javascript:alert(1))'],
            'javascript url any case' => ['url(JavaScript:alert(1))'],
            'javascript url with spaces' => ['url( java script:alert(1))'],
            'javascript url with underscores' => ['url(java_script:x)'],
            'javascript url with an escape' => ['url(java\\73 cript:x)'],
            'javascript url with a six-digit escape' => ['url(java\\000073cript:x)'],
            'javascript url with an escaped colon' => ['url(javascript\\3A x)'],
            'javascript url with a quoted escape' => ['url("\\6A avascript:x")'],
            'data html url with an escape' => ["url('da\\74 a:text/html,x')"],
            'javascript url quoted' => ["url('javascript:x')"],
            'vbscript url' => ['url(vbscript:x)'],
            'data html url' => ['url("data:text/html,<b>x</b>")'],
            'data html url with a space' => ['url(data:_text/html,x)'],
            'javascript in a bare string' => ['"javascript:x"'],
            'javascript in src()' => ['src(javascript:x)'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider allowedUrls
     */
    public function ordinaryUrlsAreAllowed(string $raw): void
    {
        self::assertTrue(ArbitraryValue::isSafe($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'relative' => ['url(/img/a.png)'],
            'https' => ['url(https://example.com/a.png)'],
            'quoted' => ['url("a b.png")'],
            'data image' => ["url('data:image/png,abc')"],
            'image-set' => ['image-set("a.png"_1x,"b.png"_2x)'],
            // A hex escape takes up to six hex digits: `\0073c` is U+073C, so
            // this url does not spell a scheme at all (CSS Syntax §4.3.7).
            'hex escape swallowing a following hex digit' => ['url(java\\0073cript:x)'],
            'quoted data url with a semicolon' => ["url('data:image/svg+xml;base64,x')"],
            // Inside an unquoted url token `;` and the comment opener are part
            // of the url (CSS Syntax §4.3.6), not terminators — as in v4.
            'unquoted data url with a semicolon' => ['url(data:image/svg+xml;base64,x)'],
            'unquoted url holding a comment opener' => ['url(a/*b)'],
            'unquoted url with surrounding spaces' => ['url( a.png )'],
            'upper-case URL function' => ['URL(a.png)'],
        ];
    }

    /** @test */
    public function parseSplitsATypeHint(): void
    {
        $value = ArbitraryValue::parse('length:var(--x)');

        self::assertNotNull($value);
        self::assertSame('length:var(--x)', $value->raw);
        self::assertSame('var(--x)', $value->normalised);
        self::assertSame('length', $value->dataType);
    }

    /** @test */
    public function parseWithoutAHint(): void
    {
        $value = ArbitraryValue::parse('#0088cc');

        self::assertNotNull($value);
        self::assertSame('#0088cc', $value->normalised);
        self::assertNull($value->dataType);
    }

    /**
     * @test
     *
     * @dataProvider notHints
     */
    public function onlyALowerCaseWordBeforeAColonIsAHint(string $raw): void
    {
        $value = ArbitraryValue::parse($raw);

        self::assertNotNull($value);
        self::assertNull($value->dataType);
        self::assertSame($raw, $value->normalised);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notHints(): array
    {
        return [
            'upper case' => ['Length:1px'],
            'digit' => ['a1:b'],
            'url with a scheme inside' => ['url(https://x)'],
            'leading colon' => [':x'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider empties
     */
    public function emptyValuesHaveNoParse(string $raw): void
    {
        self::assertNull(ArbitraryValue::parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function empties(): array
    {
        return [
            'empty' => [''],
            'underscore only' => ['_'],
            'hint only' => ['color:'],
            'hint and space' => ['color:_'],
        ];
    }
}
