<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * CSS parser producing the engine's AST (design doc 07 §Phase 1).
 *
 * Every block — the stylesheet, a rule body, an at-rule body — is parsed the
 * same way, as nested CSS: declarations, rules and at-rules may all appear, in
 * any order, and nesting is preserved for the flattener.
 *
 * Deciding between "declaration" and "nested rule" is done at the terminator,
 * never by backtracking: the component values of the next construct are
 * collected up to the first top-level `{`, `;` or `}`; a `{` makes it a rule,
 * the others a declaration. A custom property (`--x:` first) is the one
 * exception — its value may itself contain `{ … }` blocks, so for it only `;`
 * and `}` end the construct. Each token is therefore read exactly once and the
 * parse is linear in the input (threat T-05).
 *
 * Recursion is bounded by {@see self::MAX_DEPTH}: a block nested deeper is
 * skipped iteratively, reported, and dropped with everything inside it. The
 * parser never throws on any input; it returns what it could parse, with the
 * problems in the diagnostic bag (browser-style recovery: a broken
 * declaration is dropped up to its `;` or `}`, a rule with a broken prelude is
 * dropped with its block).
 *
 * Selectors, at-rule preludes and values are rebuilt from the exact source
 * text of their tokens, so escapes and authored spacing survive; comments in
 * them are dropped because comments are not tokens.
 */
final class Parser
{
    /**
     * Deepest block nesting kept (design doc 07 §Complexity and limits).
     */
    public const MAX_DEPTH = 32;

    /**
     * @var Tokenizer
     */
    private $tokenizer;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var Token|null A token read but handed back for the enclosing block.
     */
    private $pending;

    /**
     * @var bool
     */
    private $eofReported = false;

    private function __construct(Tokenizer $tokenizer, bool $debug)
    {
        $this->tokenizer = $tokenizer;
        $this->debug = $debug;
    }

    /**
     * Parse $css. Never throws; syntax problems are added to $diagnostics as
     * `E_CSS_SYNTAX` errors with their line and column.
     *
     * @param bool $debug Keep line/column on the nodes (costs memory).
     */
    public static function parse(string $css, DiagnosticBag $diagnostics, bool $debug = false): StyleSheet
    {
        $parser = new self(new Tokenizer($css, $diagnostics), $debug);

        $nodes = $parser->contents(0);
        $banner = $parser->banner();

        if ($banner !== null) {
            \array_unshift($nodes, $banner);
        }

        return new StyleSheet($nodes);
    }

    /**
     * A `/*! … *\/` licence comment before any other content (design doc 07
     * §Phase 1); every other comment is dropped by the tokenizer.
     */
    private function banner(): ?Comment
    {
        $css = $this->tokenizer->source();
        $start = (int) \strspn($css, " \t\n");

        if (\substr($css, $start, 3) !== '/*!') {
            return null;
        }

        $close = \strpos($css, '*/', $start + 3);

        if ($close === false) {
            return null;
        }

        $text = (string) \substr($css, $start + 2, $close - $start - 2);

        if (!$this->debug) {
            return new Comment($text);
        }

        $prefix = (string) \substr($css, 0, $start);
        $lastNewline = \strrpos($prefix, "\n");

        return new Comment(
            $text,
            1 + \substr_count($prefix, "\n"),
            $lastNewline === false ? $start + 1 : $start - $lastNewline
        );
    }

    /**
     * The contents of one block, up to and including its closing `}` (or the
     * end of input). $level is the number of blocks around these contents.
     *
     * @return list<Node>
     */
    private function contents(int $level): array
    {
        $nodes = [];

        while (true) {
            $token = $this->next();
            $type = $token->type;

            if ($type === Token::WHITESPACE || $type === Token::SEMICOLON) {
                continue;
            }

            if ($type === Token::EOF) {
                if ($level > 0) {
                    $this->reportUnclosed($token);
                }

                return $nodes;
            }

            if ($type === Token::RIGHT_BRACE) {
                if ($level > 0) {
                    return $nodes;
                }

                $this->report('Unexpected closing brace', $token);

                continue;
            }

            // CSS Syntax §5.4.1: HTML comment markers are skipped at the top
            // level only; anywhere else they are ordinary tokens.
            if ($level === 0 && ($type === Token::CDO || $type === Token::CDC)) {
                continue;
            }

            $node = $type === Token::AT_KEYWORD ? $this->atRule($token, $level) : $this->qualified($token, $level);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }
    }

    private function atRule(Token $at, int $level): ?AtRule
    {
        $prelude = $this->collect($this->next(), false);
        $terminator = $prelude[2];
        $name = (string) \substr($this->tokenizer->raw($at), 1);
        $params = self::join($prelude[0], $prelude[1], 0, \count($prelude[0]) - 1);

        if ($terminator->type === Token::LEFT_BRACE) {
            $children = $this->block($level, $terminator);

            if ($children === null || $prelude[3]) {
                return null;
            }

            return $this->debug
                ? new AtRule($name, $params, $children, $at->line, $at->col)
                : new AtRule($name, $params, $children);
        }

        if ($terminator->type !== Token::SEMICOLON) {
            // `}` or EOF belongs to the enclosing block.
            $this->pending = $terminator;
        }

        if ($prelude[3]) {
            return null;
        }

        return $this->debug ? new AtRule($name, $params, null, $at->line, $at->col) : new AtRule($name, $params);
    }

    /**
     * A rule or a declaration, starting at $first (never whitespace).
     *
     * @return Rule|Declaration|null
     */
    private function qualified(Token $first, int $level): ?Node
    {
        $parts = $this->collect($first, true);
        $raws = $parts[0];
        $types = $parts[1];
        $terminator = $parts[2];

        if ($terminator->type === Token::LEFT_BRACE) {
            $children = $this->block($level, $terminator);

            if ($children === null || $parts[3]) {
                return null;
            }

            $selector = self::join($raws, $types, 0, \count($raws) - 1);

            if ($selector === '') {
                $this->report('Rule without a selector', $first);

                return null;
            }

            return $this->debug
                ? new Rule($selector, $children, $first->line, $first->col)
                : new Rule($selector, $children);
        }

        if ($terminator->type !== Token::SEMICOLON) {
            $this->pending = $terminator;
        }

        if ($parts[3]) {
            // The tokenizer already reported the bad string or url.
            return null;
        }

        return $this->declaration($first, $raws, $types, $parts[4]);
    }

    /**
     * @param list<string> $raws
     * @param list<string> $types
     * @param int $colon Index of the first top-level colon, or -1.
     */
    private function declaration(Token $first, array $raws, array $types, int $colon): ?Declaration
    {
        $valid = $types[0] === Token::IDENT && $colon > 0;

        for ($i = 1; $valid && $i < $colon; ++$i) {
            $valid = $types[$i] === Token::WHITESPACE;
        }

        if (!$valid) {
            $this->report('Invalid declaration', $first, \implode('', $raws));

            return null;
        }

        $end = \count($raws) - 1;

        while ($end > $colon && $types[$end] === Token::WHITESPACE) {
            --$end;
        }

        $important = false;

        if ($end > $colon && $types[$end] === Token::IDENT && \strtolower($raws[$end]) === 'important') {
            $bang = $end - 1;

            while ($bang > $colon && $types[$bang] === Token::WHITESPACE) {
                --$bang;
            }

            if ($bang > $colon && $types[$bang] === Token::DELIM && $raws[$bang] === '!') {
                $important = true;
                $end = $bang - 1;
            }
        }

        $value = self::join($raws, $types, $colon + 1, $end);

        return $this->debug
            ? new Declaration($raws[0], $value, $important, $first->line, $first->col)
            : new Declaration($raws[0], $value, $important);
    }

    /**
     * Collect the component values of one construct, starting at $token, up
     * to the first top-level terminator: `;` or `}` always, `{` unless this
     * is a custom-property declaration; the end of input in any case.
     * Parentheses, functions, brackets and braces nest, and a terminator
     * inside them is just a token (CSS Syntax §5.4.7 simple blocks).
     *
     * Returns the raw texts, the token types, the terminator, whether a
     * bad-string/bad-url was seen, and the index of the first top-level
     * colon (-1 if none).
     *
     * @return array{0: list<string>, 1: list<string>, 2: Token, 3: bool, 4: int}
     */
    private function collect(Token $token, bool $declarationContext): array
    {
        // Hot loop: the tokenizer and source are read directly rather than
        // through next()/raw() (nothing sets $this->pending while collecting).
        $tokenizer = $this->tokenizer;
        $source = $tokenizer->source();
        $raws = [];
        $types = [];
        $stack = [];
        $invalid = false;
        $colon = -1;
        $significant = 0;
        $custom = false;
        $maybeCustom = $declarationContext
            && $token->type === Token::IDENT
            && \strncmp($token->value, '--', 2) === 0;

        while (true) {
            $type = $token->type;

            if ($type === Token::EOF) {
                break;
            }

            if ($stack === []) {
                if ($type === Token::SEMICOLON || $type === Token::RIGHT_BRACE) {
                    break;
                }

                if ($type === Token::LEFT_BRACE && !$custom) {
                    break;
                }

                if ($type === Token::COLON && $colon < 0) {
                    $colon = \count($raws);
                    $custom = $maybeCustom && $significant === 1;
                }
            }

            switch ($type) {
                case Token::FUNCTION_TOKEN:
                case Token::LEFT_PAREN:
                    $stack[] = Token::RIGHT_PAREN;

                    break;
                case Token::LEFT_BRACKET:
                    $stack[] = Token::RIGHT_BRACKET;

                    break;
                case Token::LEFT_BRACE:
                    $stack[] = Token::RIGHT_BRACE;

                    break;
                case Token::RIGHT_PAREN:
                case Token::RIGHT_BRACKET:
                case Token::RIGHT_BRACE:
                    if ($stack !== [] && $stack[\count($stack) - 1] === $type) {
                        \array_pop($stack);
                    }

                    break;
                case Token::BAD_STRING:
                case Token::BAD_URL:
                    $invalid = true;

                    break;
            }

            if ($type !== Token::WHITESPACE) {
                ++$significant;
            }

            $raws[] = (string) \substr($source, $token->start, $token->end - $token->start);
            $types[] = $type;
            $token = $tokenizer->next();
        }

        return [$raws, $types, $token, $invalid, $colon];
    }

    /**
     * Parse the block opened by $open, or skip and drop it when it would be
     * nested deeper than {@see self::MAX_DEPTH}.
     *
     * @return list<Node>|null Null when the block was dropped.
     */
    private function block(int $level, Token $open): ?array
    {
        if ($level + 1 <= self::MAX_DEPTH) {
            return $this->contents($level + 1);
        }

        $this->report(
            \sprintf('CSS nesting too deep (more than %d levels); the block is dropped', self::MAX_DEPTH),
            $open
        );

        // Iterative on purpose: a hostile file may nest far deeper than any
        // recursion limit.
        $depth = 1;

        while (true) {
            $token = $this->next();

            if ($token->type === Token::EOF) {
                $this->pending = $token;

                return null;
            }

            if ($token->type === Token::LEFT_BRACE) {
                ++$depth;
            } elseif ($token->type === Token::RIGHT_BRACE && --$depth === 0) {
                return null;
            }
        }
    }

    private function next(): Token
    {
        if ($this->pending !== null) {
            $token = $this->pending;
            $this->pending = null;

            return $token;
        }

        return $this->tokenizer->next();
    }

    private function reportUnclosed(Token $eof): void
    {
        if ($this->eofReported) {
            return;
        }

        $this->eofReported = true;
        $this->report('Unexpected end of input; a block was not closed', $eof);
    }

    private function report(string $message, Token $at, string $snippet = ''): void
    {
        $this->tokenizer->report($message, $at->line, $at->col, $snippet);
    }

    /**
     * Concatenate $raws[$from..$to], leaving out whitespace tokens at either
     * end. Trimming by token rather than by byte keeps an escaped trailing
     * space (`.a\ `) intact.
     *
     * @param list<string> $raws
     * @param list<string> $types
     */
    private static function join(array $raws, array $types, int $from, int $to): string
    {
        while ($from <= $to && $types[$from] === Token::WHITESPACE) {
            ++$from;
        }

        while ($to >= $from && $types[$to] === Token::WHITESPACE) {
            --$to;
        }

        if ($from > $to) {
            return '';
        }

        return \implode('', \array_slice($raws, $from, $to - $from + 1));
    }
}
