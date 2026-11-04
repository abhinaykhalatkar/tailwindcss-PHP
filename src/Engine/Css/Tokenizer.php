<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Str;

/**
 * CSS Syntax Level 3 tokenizer (§3.3 preprocessing, §4 tokenization), pulled
 * one token at a time by the parser (design doc 07 §Phase 1).
 *
 * Byte-oriented and regex-free on purpose (NFR-SEC-7, threat T-12): runs of
 * whitespace, name characters, digits and string contents are measured with
 * `strspn` / `strcspn`, which are C loops, so the per-byte PHP work is limited
 * to the few bytes that start or end a token (design doc 13 #15). Every path
 * through {@see self::next()} advances the position, and nothing ever rescans
 * earlier input, so the work is linear in the input length (threat T-05).
 *
 * Bytes ≥ 0x80 are name characters, as CSS Syntax says for non-ASCII code
 * points; invalid UTF-8 is therefore carried through as an opaque name rather
 * than rejected, which is what a browser does too.
 *
 * Errors are not exceptions. Unterminated strings, urls and comments become
 * `E_CSS_SYNTAX` diagnostics with the position, and tokenization carries on —
 * the parser decides how to recover. The number of diagnostics one input can
 * produce is capped so a hostile file cannot turn the bag into the cost.
 */
final class Tokenizer
{
    /**
     * Syntax diagnostics recorded per tokenizer (parser errors included, they
     * go through {@see self::report()}); one more says the rest were dropped.
     */
    public const MAX_DIAGNOSTICS = 100;

    /**
     * Bytes of a token's text echoed into a diagnostic (threat T-06).
     */
    public const SNIPPET_BYTES = 64;

    private const REPLACEMENT = "\xEF\xBF\xBD";

    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-'
        . "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F"
        . "\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF"
        . "\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF"
        . "\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF"
        . "\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        . "\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF"
        . "\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    /**
     * Bytes that are always a one-byte token of their own.
     *
     * @var array<string, string>
     */
    private const SINGLE_BYTE_TOKENS = [
        '{' => Token::LEFT_BRACE,
        '}' => Token::RIGHT_BRACE,
        '(' => Token::LEFT_PAREN,
        ')' => Token::RIGHT_PAREN,
        '[' => Token::LEFT_BRACKET,
        ']' => Token::RIGHT_BRACKET,
        ',' => Token::COMMA,
        ':' => Token::COLON,
        ';' => Token::SEMICOLON,
        '*' => Token::DELIM,
        '>' => Token::DELIM,
        '~' => Token::DELIM,
        '=' => Token::DELIM,
        '!' => Token::DELIM,
        '&' => Token::DELIM,
        '%' => Token::DELIM,
        '|' => Token::DELIM,
        '^' => Token::DELIM,
        '$' => Token::DELIM,
        '?' => Token::DELIM,
    ];

    /**
     * ASCII bytes that start an identifier (non-ASCII ones go the slow way).
     *
     * @var array<string, true>
     */
    private const ASCII_NAME_START = [
        'a' => true, 'b' => true, 'c' => true, 'd' => true, 'e' => true, 'f' => true, 'g' => true,
        'h' => true, 'i' => true, 'j' => true, 'k' => true, 'l' => true, 'm' => true, 'n' => true,
        'o' => true, 'p' => true, 'q' => true, 'r' => true, 's' => true, 't' => true, 'u' => true,
        'v' => true, 'w' => true, 'x' => true, 'y' => true, 'z' => true,
        'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true, 'G' => true,
        'H' => true, 'I' => true, 'J' => true, 'K' => true, 'L' => true, 'M' => true, 'N' => true,
        'O' => true, 'P' => true, 'Q' => true, 'R' => true, 'S' => true, 'T' => true, 'U' => true,
        'V' => true, 'W' => true, 'X' => true, 'Y' => true, 'Z' => true, '_' => true,
    ];

    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    private const DIGITS = '0123456789';

    /**
     * Whitespace after preprocessing: CR, CRLF and FF have become LF.
     */
    private const WHITESPACE = " \t\n";

    /**
     * Bytes that end the plain run of an unquoted url: its terminator, the
     * characters that make it a bad url, an escape, and the non-printables of
     * CSS Syntax §4.2 (NUL is already U+FFFD by then; it is listed anyway).
     */
    private const URL_STOPS = ")\"'(\\ \t\n"
        . "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /**
     * @var string
     */
    private $css;

    /**
     * @var int
     */
    private $length;

    /**
     * @var int
     */
    private $pos = 0;

    /**
     * @var int Line of {@see self::$lineStart}.
     */
    private $line = 1;

    /**
     * @var int Offset of the first byte of the current line.
     */
    private $lineStart = 0;

    /**
     * @var int|false Offset of the next LF at or after the located position;
     *                caching it keeps position tracking linear on long lines.
     */
    private $nextNewline;

    /**
     * @var DiagnosticBag|null
     */
    private $diagnostics;

    /**
     * @var int
     */
    private $reported = 0;

    /**
     * @param DiagnosticBag|null $diagnostics Where syntax errors go; null
     *                                        drops them (the serializer
     *                                        re-tokenizes values it built).
     */
    public function __construct(string $css, ?DiagnosticBag $diagnostics = null)
    {
        $this->css = self::preprocess($css);
        $this->length = \strlen($this->css);
        $this->nextNewline = \strpos($this->css, "\n");
        $this->diagnostics = $diagnostics;
    }

    /**
     * Every token of $css, ending with exactly one EOF token.
     *
     * @return list<Token>
     */
    public static function tokenize(string $css, ?DiagnosticBag $diagnostics = null): array
    {
        $tokenizer = new self($css, $diagnostics);
        $tokens = [];

        do {
            $token = $tokenizer->next();
            $tokens[] = $token;
        } while ($token->type !== Token::EOF);

        return $tokens;
    }

    /**
     * CSS Syntax §3.3: CRLF, CR and FF become LF; NUL becomes U+FFFD (threat
     * T-15: a NUL must never reach a consumer that reads it as a terminator).
     * A leading UTF-8 byte-order mark is dropped, as decoding would.
     */
    public static function preprocess(string $css): string
    {
        if (\strncmp($css, "\xEF\xBB\xBF", 3) === 0) {
            $css = (string) \substr($css, 3);
        }

        return \strtr($css, ["\r\n" => "\n", "\r" => "\n", "\f" => "\n", "\0" => self::REPLACEMENT]);
    }

    /**
     * The preprocessed source the token offsets refer to.
     */
    public function source(): string
    {
        return $this->css;
    }

    /**
     * The exact source text of $token.
     */
    public function raw(Token $token): string
    {
        return (string) \substr($this->css, $token->start, $token->end - $token->start);
    }

    /**
     * Record an `E_CSS_SYNTAX` diagnostic, subject to {@see self::MAX_DIAGNOSTICS}.
     *
     * The snippet is cut to {@see self::SNIPPET_BYTES} and never carries a
     * file name — the caller knows which file this was and adds it (T-06).
     */
    public function report(string $message, int $line, int $col, string $snippet = ''): void
    {
        if ($this->diagnostics === null || $this->reported > self::MAX_DIAGNOSTICS) {
            return;
        }

        ++$this->reported;

        if ($this->reported > self::MAX_DIAGNOSTICS) {
            $this->diagnostics->add(new Diagnostic(
                Diagnostic::ERROR,
                Diagnostic::E_CSS_SYNTAX,
                'Too many CSS syntax errors; further ones are not reported.',
                null,
                $line
            ));

            return;
        }

        $text = \sprintf('%s at line %d, column %d', $message, $line, $col);

        if ($snippet !== '') {
            $text .= ' near: ' . Str::truncateBytes($snippet, self::SNIPPET_BYTES);
        }

        $this->diagnostics->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, $text . '.', null, $line));
    }

    /**
     * Abandon the rest of the input: every later {@see self::next()} is EOF.
     * Used by the parser once a hard limit is reached (ENG-A14), so the
     * remainder costs nothing at all.
     */
    public function skipToEnd(): void
    {
        $this->pos = $this->length;
    }

    /**
     * The next token; EOF forever once the input is exhausted.
     */
    public function next(): Token
    {
        $css = $this->css;
        $length = $this->length;
        $p = $this->pos;

        // Comments are not tokens (§4.3.2): skip any run of them first.
        while ($p + 1 < $length && $css[$p] === '/' && $css[$p + 1] === '*') {
            $close = \strpos($css, '*/', $p + 2);

            if ($close === false) {
                $this->locate($p);
                $this->report('Unterminated comment', $this->line, $p - $this->lineStart + 1);
                $p = $length;

                break;
            }

            $p = $close + 2;
        }

        if ($this->nextNewline !== false && $this->nextNewline < $p) {
            $this->locate($p);
        }

        $line = $this->line;
        $col = $p - $this->lineStart + 1;

        if ($p >= $length) {
            $this->pos = $length;

            return new Token(Token::EOF, '', $length, $length, $line, $col);
        }

        // Fast paths for the commonest tokens, inlined because a method call
        // is a large share of the per-token cost on PHP 7.x/8.x without
        // opcache (design doc 13 #15). They produce exactly what consume()
        // would.
        $c = $css[$p];

        if (isset(self::SINGLE_BYTE_TOKENS[$c])) {
            $this->pos = $p + 1;

            return new Token(self::SINGLE_BYTE_TOKENS[$c], $c, $p, $p + 1, $line, $col);
        }

        if ($c === ' ' || $c === "\n" || $c === "\t") {
            $n = (int) \strspn($css, self::WHITESPACE, $p);
            $this->pos = $p + $n;

            return new Token(Token::WHITESPACE, (string) \substr($css, $p, $n), $p, $p + $n, $line, $col);
        }

        if (isset(self::ASCII_NAME_START[$c])) {
            $n = (int) \strspn($css, self::NAME_BYTES, $p);
            $end = $p + $n;

            if ($end >= $length || ($css[$end] !== '\\' && $css[$end] !== '(')) {
                $this->pos = $end;

                return new Token(Token::IDENT, (string) \substr($css, $p, $n), $p, $end, $line, $col);
            }
        }

        $token = $this->consume($p, $line, $col);
        $this->pos = $token->end;

        return $token;
    }

    private function consume(int $p, int $line, int $col): Token
    {
        $css = $this->css;
        $c = $css[$p];

        switch ($c) {
            case ' ':
            case "\t":
            case "\n":
                $n = (int) \strspn($css, self::WHITESPACE, $p);

                return new Token(Token::WHITESPACE, (string) \substr($css, $p, $n), $p, $p + $n, $line, $col);
            case '"':
            case "'":
                return $this->string($p, $c, $line, $col);
            case '#':
                if ($p + 1 < $this->length && (self::isNameByte($css[$p + 1]) || $this->isValidEscape($p + 1))) {
                    $name = $this->name($p + 1);

                    return new Token(Token::HASH, $name[0], $p, $name[1], $line, $col);
                }

                return $this->delim($p, $line, $col);
            case '(':
                return new Token(Token::LEFT_PAREN, '(', $p, $p + 1, $line, $col);
            case ')':
                return new Token(Token::RIGHT_PAREN, ')', $p, $p + 1, $line, $col);
            case '[':
                return new Token(Token::LEFT_BRACKET, '[', $p, $p + 1, $line, $col);
            case ']':
                return new Token(Token::RIGHT_BRACKET, ']', $p, $p + 1, $line, $col);
            case '{':
                return new Token(Token::LEFT_BRACE, '{', $p, $p + 1, $line, $col);
            case '}':
                return new Token(Token::RIGHT_BRACE, '}', $p, $p + 1, $line, $col);
            case ',':
                return new Token(Token::COMMA, ',', $p, $p + 1, $line, $col);
            case ':':
                return new Token(Token::COLON, ':', $p, $p + 1, $line, $col);
            case ';':
                return new Token(Token::SEMICOLON, ';', $p, $p + 1, $line, $col);
            case '+':
            case '.':
                if ($this->startsNumber($p)) {
                    return $this->numeric($p, $line, $col);
                }

                return $this->delim($p, $line, $col);
            case '-':
                if ($this->startsNumber($p)) {
                    return $this->numeric($p, $line, $col);
                }

                if (\substr($css, $p, 3) === '-->') {
                    return new Token(Token::CDC, '-->', $p, $p + 3, $line, $col);
                }

                if ($this->startsIdent($p)) {
                    return $this->identLike($p, $line, $col);
                }

                return $this->delim($p, $line, $col);
            case '<':
                if (\substr($css, $p, 4) === '<!--') {
                    return new Token(Token::CDO, '<!--', $p, $p + 4, $line, $col);
                }

                return $this->delim($p, $line, $col);
            case '@':
                if ($this->startsIdent($p + 1)) {
                    $name = $this->name($p + 1);

                    return new Token(Token::AT_KEYWORD, $name[0], $p, $name[1], $line, $col);
                }

                return $this->delim($p, $line, $col);
            case '\\':
                if ($this->isValidEscape($p)) {
                    return $this->identLike($p, $line, $col);
                }

                // A backslash before a newline is a parse error and a delim.
                return $this->delim($p, $line, $col);
        }

        if (self::isDigit($c)) {
            return $this->numeric($p, $line, $col);
        }

        if (self::isNameStart($c)) {
            return $this->identLike($p, $line, $col);
        }

        return $this->delim($p, $line, $col);
    }

    private function delim(int $p, int $line, int $col): Token
    {
        return new Token(Token::DELIM, $this->css[$p], $p, $p + 1, $line, $col);
    }

    /**
     * §4.3.4: ident, function, url or bad-url.
     */
    private function identLike(int $p, int $line, int $col): Token
    {
        $name = $this->name($p);
        $end = $name[1];

        if ($end >= $this->length || $this->css[$end] !== '(') {
            return new Token(Token::IDENT, $name[0], $p, $end, $line, $col);
        }

        if (\strtolower($name[0]) === 'url') {
            $inner = $end + 1;
            $inner += (int) \strspn($this->css, self::WHITESPACE, $inner);

            if ($inner >= $this->length || ($this->css[$inner] !== '"' && $this->css[$inner] !== "'")) {
                return $this->url($p, $inner, $line, $col);
            }
        }

        return new Token(Token::FUNCTION_TOKEN, $name[0], $p, $end + 1, $line, $col);
    }

    /**
     * §4.3.5: a quoted string. Unterminated — at a newline (which is left for
     * the next token) or at the end of input — gives a bad-string.
     */
    private function string(int $start, string $quote, int $line, int $col): Token
    {
        $css = $this->css;
        $length = $this->length;
        $stops = $quote . "\\\n";
        $value = '';
        $p = $start + 1;

        while (true) {
            $run = (int) \strcspn($css, $stops, $p);

            if ($run > 0) {
                $value .= \substr($css, $p, $run);
                $p += $run;
            }

            if ($p >= $length || $css[$p] === "\n") {
                $this->report('Unterminated string', $line, $col, (string) \substr($css, $start, $p - $start));

                return new Token(Token::BAD_STRING, $value, $start, $p, $line, $col);
            }

            if ($css[$p] === $quote) {
                return new Token(Token::STRING, $value, $start, $p + 1, $line, $col);
            }

            // A backslash.
            if ($p + 1 >= $length) {
                ++$p;

                continue;
            }

            if ($css[$p + 1] === "\n") {
                // An escaped newline continues the string and contributes nothing.
                $p += 2;

                continue;
            }

            $escape = $this->escape($p);
            $value .= $escape[0];
            $p = $escape[1];
        }
    }

    /**
     * §4.3.6: an unquoted url; $p is the first byte after `url(` and its
     * leading whitespace.
     */
    private function url(int $start, int $p, int $line, int $col): Token
    {
        $css = $this->css;
        $length = $this->length;
        $value = '';

        while (true) {
            $run = (int) \strcspn($css, self::URL_STOPS, $p);

            if ($run > 0) {
                $value .= \substr($css, $p, $run);
                $p += $run;
            }

            if ($p >= $length) {
                $this->report('Unterminated url', $line, $col, (string) \substr($css, $start, $p - $start));

                return new Token(Token::BAD_URL, $value, $start, $p, $line, $col);
            }

            $c = $css[$p];

            if ($c === ')') {
                return new Token(Token::URL, $value, $start, $p + 1, $line, $col);
            }

            if ($c === ' ' || $c === "\t" || $c === "\n") {
                $p += (int) \strspn($css, self::WHITESPACE, $p);

                if ($p < $length && $css[$p] === ')') {
                    return new Token(Token::URL, $value, $start, $p + 1, $line, $col);
                }

                return $this->badUrl($start, $p, $line, $col);
            }

            if ($c === '\\' && $this->isValidEscape($p)) {
                $escape = $this->escape($p);
                $value .= $escape[0];
                $p = $escape[1];

                continue;
            }

            return $this->badUrl($start, $p, $line, $col);
        }
    }

    /**
     * §4.3.14: consume the remnants of a bad url up to and including `)`.
     */
    private function badUrl(int $start, int $p, int $line, int $col): Token
    {
        $css = $this->css;
        $length = $this->length;

        while ($p < $length) {
            $p += (int) \strcspn($css, ')\\', $p);

            if ($p >= $length) {
                break;
            }

            if ($css[$p] === ')') {
                ++$p;

                break;
            }

            // A backslash: skip the escape so an escaped `)` does not end the url.
            $p = $this->isValidEscape($p) ? $this->escape($p)[1] : $p + 1;
        }

        $this->report('Malformed url', $line, $col, (string) \substr($css, $start, $p - $start));

        return new Token(Token::BAD_URL, '', $start, $p, $line, $col);
    }

    /**
     * §4.3.3: number, percentage or dimension.
     */
    private function numeric(int $start, int $line, int $col): Token
    {
        $css = $this->css;
        $length = $this->length;
        $p = $start;

        if ($css[$p] === '+' || $css[$p] === '-') {
            ++$p;
        }

        $p += (int) \strspn($css, self::DIGITS, $p);

        if ($p + 1 < $length && $css[$p] === '.' && self::isDigit($css[$p + 1])) {
            ++$p;
            $p += (int) \strspn($css, self::DIGITS, $p);
        }

        if ($p < $length && ($css[$p] === 'e' || $css[$p] === 'E')) {
            $q = $p + 1;

            if ($q < $length && ($css[$q] === '+' || $css[$q] === '-')) {
                ++$q;
            }

            if ($q < $length && self::isDigit($css[$q])) {
                $p = $q + (int) \strspn($css, self::DIGITS, $q);
            }
        }

        $number = (string) \substr($css, $start, $p - $start);

        if ($this->startsIdent($p)) {
            $unit = $this->name($p);

            return new Token(Token::DIMENSION, $number, $start, $unit[1], $line, $col, $unit[0]);
        }

        if ($p < $length && $css[$p] === '%') {
            return new Token(Token::PERCENTAGE, $number, $start, $p + 1, $line, $col);
        }

        return new Token(Token::NUMBER, $number, $start, $p, $line, $col);
    }

    /**
     * §4.3.12: consume a name starting at $p.
     *
     * @return array{0: string, 1: int} The decoded name and the offset after it.
     */
    private function name(int $p): array
    {
        $css = $this->css;
        $name = '';

        while (true) {
            $run = (int) \strspn($css, self::NAME_BYTES, $p);

            if ($run > 0) {
                $name .= \substr($css, $p, $run);
                $p += $run;
            }

            if ($p < $this->length && $css[$p] === '\\' && $this->isValidEscape($p)) {
                $escape = $this->escape($p);
                $name .= $escape[0];
                $p = $escape[1];

                continue;
            }

            return [$name, $p];
        }
    }

    /**
     * §4.3.7: consume an escape; $p is at the backslash.
     *
     * @return array{0: string, 1: int} The decoded bytes and the offset after the escape.
     */
    private function escape(int $p): array
    {
        $css = $this->css;
        ++$p;

        if ($p >= $this->length) {
            return [self::REPLACEMENT, $p];
        }

        $digits = (int) \strspn($css, self::HEX_DIGITS, $p, 6);

        if ($digits === 0) {
            return [$css[$p], $p + 1];
        }

        $codePoint = (int) \hexdec((string) \substr($css, $p, $digits));
        $p += $digits;

        // One whitespace after a hex escape belongs to the escape.
        if ($p < $this->length && ($css[$p] === ' ' || $css[$p] === "\t" || $css[$p] === "\n")) {
            ++$p;
        }

        return [self::encode($codePoint), $p];
    }

    /**
     * UTF-8 bytes of $codePoint; U+FFFD for zero, surrogates and anything
     * beyond U+10FFFF (§4.3.7).
     */
    private static function encode(int $codePoint): string
    {
        if ($codePoint === 0 || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $codePoint > 0x10FFFF) {
            return self::REPLACEMENT;
        }

        if ($codePoint < 0x80) {
            return \chr($codePoint);
        }

        if ($codePoint < 0x800) {
            return \chr(0xC0 | ($codePoint >> 6)) . \chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint < 0x10000) {
            return \chr(0xE0 | ($codePoint >> 12))
                . \chr(0x80 | (($codePoint >> 6) & 0x3F))
                . \chr(0x80 | ($codePoint & 0x3F));
        }

        return \chr(0xF0 | ($codePoint >> 18))
            . \chr(0x80 | (($codePoint >> 12) & 0x3F))
            . \chr(0x80 | (($codePoint >> 6) & 0x3F))
            . \chr(0x80 | ($codePoint & 0x3F));
    }

    /**
     * §4.3.8: a backslash that is not followed by a newline.
     */
    private function isValidEscape(int $p): bool
    {
        return $p < $this->length
            && $this->css[$p] === '\\'
            && ($p + 1 >= $this->length || $this->css[$p + 1] !== "\n");
    }

    /**
     * §4.3.9: would the bytes at $p start an identifier?
     */
    private function startsIdent(int $p): bool
    {
        if ($p >= $this->length) {
            return false;
        }

        $c = $this->css[$p];

        if ($c === '-') {
            if ($p + 1 >= $this->length) {
                return false;
            }

            $next = $this->css[$p + 1];

            return $next === '-' || self::isNameStart($next) || $this->isValidEscape($p + 1);
        }

        if ($c === '\\') {
            return $this->isValidEscape($p);
        }

        return self::isNameStart($c);
    }

    /**
     * §4.3.10: would the bytes at $p start a number?
     */
    private function startsNumber(int $p): bool
    {
        $css = $this->css;
        $length = $this->length;
        $c = $css[$p];

        if ($c === '+' || $c === '-') {
            if ($p + 1 >= $length) {
                return false;
            }

            if (self::isDigit($css[$p + 1])) {
                return true;
            }

            return $css[$p + 1] === '.' && $p + 2 < $length && self::isDigit($css[$p + 2]);
        }

        if ($c === '.') {
            return $p + 1 < $length && self::isDigit($css[$p + 1]);
        }

        return self::isDigit($c);
    }

    /**
     * Advance line tracking to $p. Positions only ever move forward, and each
     * LF is looked up once, so tracking is linear overall.
     */
    private function locate(int $p): void
    {
        while ($this->nextNewline !== false && $this->nextNewline < $p) {
            ++$this->line;
            $this->lineStart = $this->nextNewline + 1;
            $this->nextNewline = \strpos($this->css, "\n", $this->lineStart);
        }
    }

    private static function isDigit(string $c): bool
    {
        $o = \ord($c);

        return $o >= 48 && $o <= 57;
    }

    private static function isNameStart(string $c): bool
    {
        $o = \ord($c);

        return ($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || $o === 95 || $o >= 128;
    }

    private static function isNameByte(string $c): bool
    {
        $o = \ord($c);

        return self::isNameStart($c) || ($o >= 48 && $o <= 57) || $o === 45;
    }
}
