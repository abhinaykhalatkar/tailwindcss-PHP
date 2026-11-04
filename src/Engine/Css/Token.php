<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

/**
 * One CSS token (CSS Syntax Level 3 §4; design doc 07 §Phase 1).
 *
 * A small final class with public properties rather than getters: the
 * tokenizer creates one per token on the hot path, and property reads are
 * measurably cheaper than method calls on PHP 7.x (design doc 13 #22). The
 * properties are written once, by the constructor, and never again.
 *
 * `value` is the *decoded* payload (escapes resolved): the name for ident,
 * function, at-keyword and hash; the contents for string and url; the numeric
 * text for number, percentage and dimension (`unit` holds the unit). The exact
 * source text of a token is `Tokenizer::raw($token)`, from the byte offsets
 * `start` (inclusive) and `end` (exclusive) — the parser rebuilds selectors and
 * values from those slices so authored escapes survive untouched.
 */
final class Token
{
    public const IDENT = 'ident';
    public const FUNCTION_TOKEN = 'function';
    public const AT_KEYWORD = 'at-keyword';
    public const HASH = 'hash';
    public const STRING = 'string';
    public const BAD_STRING = 'bad-string';
    public const URL = 'url';
    public const BAD_URL = 'bad-url';
    public const NUMBER = 'number';
    public const DIMENSION = 'dimension';
    public const PERCENTAGE = 'percentage';
    public const DELIM = 'delim';
    public const WHITESPACE = 'whitespace';
    public const COLON = 'colon';
    public const SEMICOLON = 'semicolon';
    public const COMMA = 'comma';
    public const LEFT_BRACKET = '[';
    public const RIGHT_BRACKET = ']';
    public const LEFT_PAREN = '(';
    public const RIGHT_PAREN = ')';
    public const LEFT_BRACE = '{';
    public const RIGHT_BRACE = '}';
    public const CDO = 'cdo';
    public const CDC = 'cdc';
    public const EOF = 'eof';

    /**
     * @var string One of the type constants above.
     */
    public $type;

    /**
     * @var string
     */
    public $value;

    /**
     * @var string Unit of a dimension token; empty for every other type.
     */
    public $unit;

    /**
     * @var int Byte offset of the first byte, in the preprocessed source.
     */
    public $start;

    /**
     * @var int Byte offset just past the last byte.
     */
    public $end;

    /**
     * @var int 1-based line.
     */
    public $line;

    /**
     * @var int 1-based byte column.
     */
    public $col;

    public function __construct(
        string $type,
        string $value,
        int $start,
        int $end,
        int $line,
        int $col,
        string $unit = ''
    ) {
        $this->type = $type;
        $this->value = $value;
        $this->start = $start;
        $this->end = $end;
        $this->line = $line;
        $this->col = $col;
        $this->unit = $unit;
    }
}
