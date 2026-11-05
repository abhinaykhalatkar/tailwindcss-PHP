<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Token;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The CSS tokenizer (design doc 07 §Phase 1): one row per token type, plus the
 * escape, newline and number rules of CSS Syntax Level 3 §4.
 *
 * @covers-requirement FR-COMP-2
 */
final class TokenizerTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider singleTokens
     */
    public function eachTokenTypeIsRecognised(string $css, string $type, string $value): void
    {
        $tokens = self::significant(Tokenizer::tokenize($css));

        self::assertCount(1, $tokens, 'exactly one token for ' . $css);
        self::assertSame($type, $tokens[0]->type);
        self::assertSame($value, $tokens[0]->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function singleTokens(): array
    {
        return [
            'ident' => ['color', Token::IDENT, 'color'],
            'ident with dashes and digits' => ['-webkit-box2', Token::IDENT, '-webkit-box2'],
            'custom property name' => ['--tw-ring', Token::IDENT, '--tw-ring'],
            'ident with non-ascii bytes' => ["caf\xC3\xA9", Token::IDENT, "caf\xC3\xA9"],
            'function' => ['rgb(', Token::FUNCTION_TOKEN, 'rgb'],
            'at-keyword' => ['@media', Token::AT_KEYWORD, 'media'],
            'hash' => ['#ff00aa', Token::HASH, 'ff00aa'],
            'hash starting with a digit' => ['#123', Token::HASH, '123'],
            'double-quoted string' => ['"a b"', Token::STRING, 'a b'],
            'single-quoted string' => ["'a b'", Token::STRING, 'a b'],
            'string keeps the other quote' => ['"it\'s"', Token::STRING, "it's"],
            'unquoted url' => ['url(img/a.png)', Token::URL, 'img/a.png'],
            'unquoted url with surrounding whitespace' => ['url(  a.png  )', Token::URL, 'a.png'],
            'number' => ['42', Token::NUMBER, '42'],
            'decimal number' => ['.5', Token::NUMBER, '.5'],
            'signed number' => ['-1.25', Token::NUMBER, '-1.25'],
            'exponent number' => ['1e3', Token::NUMBER, '1e3'],
            'dimension' => ['10px', Token::DIMENSION, '10'],
            'percentage' => ['50%', Token::PERCENTAGE, '50'],
            'delim' => ['*', Token::DELIM, '*'],
            'delim plus' => ['+', Token::DELIM, '+'],
            'delim bang' => ['!', Token::DELIM, '!'],
            'colon' => [':', Token::COLON, ':'],
            'semicolon' => [';', Token::SEMICOLON, ';'],
            'comma' => [',', Token::COMMA, ','],
            'left bracket' => ['[', Token::LEFT_BRACKET, '['],
            'right bracket' => [']', Token::RIGHT_BRACKET, ']'],
            'left paren' => ['(', Token::LEFT_PAREN, '('],
            'right paren' => [')', Token::RIGHT_PAREN, ')'],
            'left brace' => ['{', Token::LEFT_BRACE, '{'],
            'right brace' => ['}', Token::RIGHT_BRACE, '}'],
            'cdo' => ['<!--', Token::CDO, '<!--'],
            'cdc' => ['-->', Token::CDC, '-->'],
        ];
    }

    /** @test */
    public function whitespaceRunsAreOneToken(): void
    {
        $tokens = Tokenizer::tokenize("a \t\n  b");

        self::assertSame(
            [Token::IDENT, Token::WHITESPACE, Token::IDENT, Token::EOF],
            self::types($tokens)
        );
        self::assertSame(" \t\n  ", $tokens[1]->value);
    }

    /** @test */
    public function theStreamEndsWithEofAndKeepsReturningIt(): void
    {
        $tokenizer = new Tokenizer('a');

        // Three calls in one expression: what each returns, in order, is the fact.
        self::assertSame(
            [Token::IDENT, Token::EOF, Token::EOF],
            [$tokenizer->next()->type, $tokenizer->next()->type, $tokenizer->next()->type]
        );
    }

    /** @test */
    public function anEmptyInputIsJustEof(): void
    {
        self::assertSame([Token::EOF], self::types(Tokenizer::tokenize('')));
    }

    /** @test */
    public function dimensionsCarryTheirUnit(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('1.5rem -2em 3E2px'));

        self::assertSame(['1.5', '-2', '3E2'], self::values($tokens));
        self::assertSame(['rem', 'em', 'px'], [$tokens[0]->unit, $tokens[1]->unit, $tokens[2]->unit]);
    }

    /** @test */
    public function anEIsAUnitWhenNoExponentDigitFollows(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('2em'));

        self::assertSame(Token::DIMENSION, $tokens[0]->type);
        self::assertSame('2', $tokens[0]->value);
        self::assertSame('em', $tokens[0]->unit);
    }

    /** @test */
    public function minusFollowedByAnIdentIsAnIdentNotANumber(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('-moz-box -1 - 1'));

        self::assertSame(
            [Token::IDENT, Token::NUMBER, Token::DELIM, Token::NUMBER],
            self::types($tokens)
        );
    }

    /** @test */
    public function selectorPunctuationTokenises(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('.a>b+c~d'));

        self::assertSame(
            [
                Token::DELIM, Token::IDENT, Token::DELIM, Token::IDENT,
                Token::DELIM, Token::IDENT, Token::DELIM, Token::IDENT,
            ],
            self::types($tokens)
        );
    }

    /**
     * @test
     *
     * @dataProvider escapes
     */
    public function escapesAreDecodedInIdentsStringsAndUrls(string $css, string $type, string $value): void
    {
        $tokens = self::significant(Tokenizer::tokenize($css));

        self::assertCount(1, $tokens);
        self::assertSame($type, $tokens[0]->type);
        self::assertSame($value, $tokens[0]->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function escapes(): array
    {
        return [
            'hex escape with terminating space' => ['\\31 0', Token::IDENT, '10'],
            'hex escape without space' => ['\\41x', Token::IDENT, 'Ax'],
            'six-digit hex escape' => ['\\00004Ex', Token::IDENT, 'Nx'],
            'escaped colon' => ['hover\\:flex', Token::IDENT, 'hover:flex'],
            'escaped slash' => ['w-1\\/2', Token::IDENT, 'w-1/2'],
            'escaped bracket' => ['\\[x\\]', Token::IDENT, '[x]'],
            'escape to a multibyte code point' => ['\\e9', Token::IDENT, "\xC3\xA9"],
            'escape of zero becomes U+FFFD' => ['a\\0 b', Token::IDENT, "a\xEF\xBF\xBDb"],
            'escape of a surrogate becomes U+FFFD' => ['a\\D800 b', Token::IDENT, "a\xEF\xBF\xBDb"],
            'escape beyond U+10FFFF becomes U+FFFD' => ['a\\110000 b', Token::IDENT, "a\xEF\xBF\xBDb"],
            'escaped quote in a string' => ['"a\\"b"', Token::STRING, 'a"b'],
            'escaped newline continues a string' => ["\"a\\\nb\"", Token::STRING, 'ab'],
            'hex escape in a string' => ['"\\41 b"', Token::STRING, 'Ab'],
            'escape in an unquoted url' => ['url(a\\)b)', Token::URL, 'a)b'],
            'hash with an escape' => ['#a\\:b', Token::HASH, 'a:b'],
            'at-keyword with an escape' => ['@\\6D edia', Token::AT_KEYWORD, 'media'],
        ];
    }

    /** @test */
    public function aBackslashBeforeANewlineIsADelim(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("\\\n"));

        self::assertSame(Token::DELIM, $tokens[0]->type);
        self::assertSame('\\', $tokens[0]->value);
    }

    /** @test */
    public function urlWithAQuotedArgumentIsAFunction(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('url( "a.png" )'));

        self::assertSame([Token::FUNCTION_TOKEN, Token::STRING, Token::RIGHT_PAREN], self::types($tokens));
        self::assertSame('url', $tokens[0]->value);
        self::assertSame('a.png', $tokens[1]->value);
    }

    /** @test */
    public function urlIsCaseInsensitive(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('URL(a.png)'));

        self::assertSame(Token::URL, $tokens[0]->type);
        self::assertSame('a.png', $tokens[0]->value);
    }

    /** @test */
    public function anUnterminatedStringAtANewlineIsABadStringAndTheNewlineSurvives(): void
    {
        $bag = new DiagnosticBag();
        $tokens = Tokenizer::tokenize("\"abc\ndef", $bag);

        self::assertSame([Token::BAD_STRING, Token::WHITESPACE, Token::IDENT, Token::EOF], self::types($tokens));
        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
        self::assertSame(1, $bag->all()[0]->line());
        self::assertStringContainsString('column 1', $bag->all()[0]->message());
    }

    /** @test */
    public function anUnterminatedStringAtEofIsABadString(): void
    {
        $bag = new DiagnosticBag();
        $tokens = Tokenizer::tokenize('"abc', $bag);

        self::assertSame([Token::BAD_STRING, Token::EOF], self::types($tokens));
        self::assertCount(1, $bag);
    }

    /**
     * @test
     *
     * @dataProvider badUrls
     */
    public function malformedUrlsAreBadUrls(string $css): void
    {
        $bag = new DiagnosticBag();
        $tokens = self::significant(Tokenizer::tokenize($css, $bag));

        self::assertSame(Token::BAD_URL, $tokens[0]->type);
        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badUrls(): array
    {
        return [
            'unterminated at eof' => ['url(a.png'],
            'whitespace inside' => ['url(a b)'],
            'quote inside' => ['url(a"b)'],
            'open paren inside' => ['url(a(b)'],
            'non-printable inside' => ["url(a\x01b)"],
        ];
    }

    /** @test */
    public function aBadUrlConsumesItsRemnantsUpToTheClosingParen(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('url(a b c) x'));

        self::assertSame([Token::BAD_URL, Token::IDENT], self::types($tokens));
        self::assertSame('x', $tokens[1]->value);
    }

    /** @test */
    public function aBraceInsideAStringIsPartOfTheString(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('"}"'));

        self::assertSame([Token::STRING], self::types($tokens));
        self::assertSame('}', $tokens[0]->value);
    }

    /** @test */
    public function commentsAreDropped(): void
    {
        $tokens = Tokenizer::tokenize('a/* x */b/* y');

        self::assertSame([Token::IDENT, Token::IDENT, Token::EOF], self::types($tokens));
    }

    /** @test */
    public function anUnterminatedCommentIsReported(): void
    {
        $bag = new DiagnosticBag();

        Tokenizer::tokenize('a /* never closed', $bag);

        self::assertCount(1, $bag);
    }

    /** @test */
    public function closingHtmlCommentAndStyleTagsAreOrdinaryTokens(): void
    {
        $tokens = self::significant(Tokenizer::tokenize('</style>-->'));

        self::assertSame(
            [Token::DELIM, Token::DELIM, Token::IDENT, Token::DELIM, Token::CDC],
            self::types($tokens)
        );
    }

    /** @test */
    public function crlfCrAndFormFeedAllCountAsOneNewline(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("a\r\nb\rc\fd\ne"));

        self::assertSame([1, 2, 3, 4, 5], self::lines($tokens));
        self::assertSame([1, 1, 1, 1, 1], self::cols($tokens));
    }

    /** @test */
    public function positionsAreOneBasedLineAndByteColumn(): void
    {
        $tokens = self::significant(Tokenizer::tokenize(".a {\n  color: red;\n}"));

        self::assertSame([1, 1], [$tokens[0]->line, $tokens[0]->col]);
        self::assertSame([1, 4], [$tokens[2]->line, $tokens[2]->col]);
        self::assertSame([2, 3], [$tokens[3]->line, $tokens[3]->col]);
        self::assertSame([2, 10], [$tokens[5]->line, $tokens[5]->col]);
        self::assertSame([3, 1], [$tokens[7]->line, $tokens[7]->col]);
    }

    /** @test */
    public function positionsAfterAMultiLineCommentAreCorrect(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("/* a\nb\nc */ x"));

        self::assertSame([3, 6], [$tokens[0]->line, $tokens[0]->col]);
    }

    /** @test */
    public function aNulByteBecomesTheReplacementCharacter(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("a\0b"));

        self::assertSame([Token::IDENT], self::types($tokens));
        self::assertSame("a\xEF\xBF\xBDb", $tokens[0]->value);
    }

    /** @test */
    public function aLeadingByteOrderMarkIsStripped(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("\xEF\xBB\xBFa"));

        self::assertSame('a', $tokens[0]->value);
        self::assertSame(1, $tokens[0]->col);
    }

    /** @test */
    public function invalidUtf8BytesAreNameCharactersAndNeverFatal(): void
    {
        $tokens = self::significant(Tokenizer::tokenize("a\xFF\xFEb \xC3"));

        self::assertSame([Token::IDENT, Token::IDENT], self::types($tokens));
        self::assertSame("a\xFF\xFEb", $tokens[0]->value);
        self::assertSame("\xC3", $tokens[1]->value);
    }

    /** @test */
    public function rawSlicesReassembleTheSource(): void
    {
        $css = '.a\\:b{background:url( x.png ) #fff;content:"\\41"}@media (x){}';
        $tokenizer = new Tokenizer($css);
        $parts = [];

        do {
            $token = $tokenizer->next();
            $parts[] = $tokenizer->raw($token);
        } while ($token->type !== Token::EOF);

        self::assertSame($css, \implode('', $parts));
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<Token>
     */
    private static function significant(array $tokens): array
    {
        $kept = [];

        foreach ($tokens as $token) {
            if ($token->type !== Token::WHITESPACE && $token->type !== Token::EOF) {
                $kept[] = $token;
            }
        }

        return $kept;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private static function types(array $tokens): array
    {
        $types = [];

        foreach ($tokens as $token) {
            $types[] = $token->type;
        }

        return $types;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<string>
     */
    private static function values(array $tokens): array
    {
        $values = [];

        foreach ($tokens as $token) {
            $values[] = $token->value;
        }

        return $values;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<int>
     */
    private static function lines(array $tokens): array
    {
        $lines = [];

        foreach ($tokens as $token) {
            $lines[] = $token->line;
        }

        return $lines;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<int>
     */
    private static function cols(array $tokens): array
    {
        $cols = [];

        foreach ($tokens as $token) {
            $cols[] = $token->col;
        }

        return $cols;
    }
}
