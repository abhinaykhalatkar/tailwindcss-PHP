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
use TailwindPhp\Support\Str;

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
 * text of their tokens, so escapes and authored spacing survive; a comment
 * between two tokens (comments are not tokens) becomes one space, so it can
 * never glue them together (`1px/**\/2px` stays two values, ENG-A14).
 *
 * Memory is bounded by {@see self::MAX_NODES}: once that many nodes exist the
 * rest of the input is abandoned with one diagnostic (ENG-A14).
 */
final class Parser
{
    /**
     * Deepest block nesting kept (design doc 07 §Complexity and limits).
     */
    public const MAX_DEPTH = 32;

    /**
     * Most nodes (rules, at-rules, declarations, comments) one stylesheet
     * may produce (ENG-A14).
     */
    public const MAX_NODES = 100000;

    /**
     * Most tokens one selector, prelude or declaration may hold (ENG-A14):
     * every token of a construct is kept until its terminator is seen, so
     * this bounds the parser's working memory.
     */
    public const MAX_CONSTRUCT_TOKENS = 50000;

    /**
     * Open brackets tracked exactly while skipping a dropped construct;
     * beyond this they are only counted (T-05, see {@see self::skipConstruct()}).
     */
    private const SKIP_STACK_LIMIT = 1024;

    /**
     * @var int
     */
    private $nodeCount = 0;

    /**
     * @var bool True once {@see self::MAX_NODES} was reached.
     */
    private $stopped = false;

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
                if ($level > 0 && !$this->stopped) {
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

            if ($this->stopped) {
                return $nodes;
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
            if ($prelude[5]) {
                $this->skipBlock();

                return null;
            }

            $children = $this->block($level, $terminator);

            if ($children === null || $prelude[3] || !$this->admit($at)) {
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

        if ($prelude[3] || !$this->admit($at)) {
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
            if ($parts[5]) {
                $this->skipBlock();

                return null;
            }

            $children = $this->block($level, $terminator);

            if ($children === null || $parts[3]) {
                return null;
            }

            $selector = self::join($raws, $types, 0, \count($raws) - 1);

            if ($selector === '') {
                $this->report('Rule without a selector', $first);

                return null;
            }

            if (!$this->admit($first)) {
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
        $property = $raws[0];
        $nameEnd = 1;

        // Tailwind's namespace resets: `--color-*` and `--*` tokenize as the
        // identifier `--color-` / `--` and a `*` delimiter, and are kept as one
        // name. A star anywhere else in a property name is still invalid.
        if ($valid && $colon > 1 && $types[1] === Token::DELIM && $raws[1] === '*' && self::isResetStem($property)) {
            $property .= '*';
            $nameEnd = 2;
        }

        for ($i = $nameEnd; $valid && $i < $colon; ++$i) {
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

        if (!$this->admit($first)) {
            return null;
        }

        return $this->debug
            ? new Declaration($property, $value, $important, $first->line, $first->col)
            : new Declaration($property, $value, $important);
    }

    /**
     * Whether $ident can precede the `*` of a namespace reset: `--`, or a
     * custom property name ending in `-` (`--color-`, `--drop-shadow-`).
     */
    private static function isResetStem(string $ident): bool
    {
        return \strncmp($ident, '--', 2) === 0 && \substr($ident, -1) === '-';
    }

    /**
     * Collect the component values of one construct, starting at $token, up
     * to the first top-level terminator: `;` or `}` always, `{` unless this
     * is a custom-property declaration; the end of input in any case.
     * Parentheses, functions, brackets and braces nest, and a terminator
     * inside them is just a token (CSS Syntax §5.4.7 simple blocks).
     *
     * Returns the raw texts, the token types, the terminator, whether a
     * bad-string/bad-url was seen, the index of the first top-level colon
     * (-1 if none), and whether the construct was too long to keep (then the
     * texts are empty and the construct counts as invalid).
     *
     * @return array{0: list<string>, 1: list<string>, 2: Token, 3: bool, 4: int, 5: bool}
     */
    private function collect(Token $token, bool $declarationContext): array
    {
        // Hot loop: the tokenizer and source are read directly rather than
        // through next()/raw() (nothing sets $this->pending while collecting).
        $tokenizer = $this->tokenizer;
        $source = $tokenizer->source();
        $first = $token;
        $raws = [];
        $types = [];
        $stack = [];
        $invalid = false;
        $colon = -1;
        $significant = 0;
        $previousEnd = $token->start;
        $custom = false;
        $maybeCustom = $declarationContext
            && $token->type === Token::IDENT
            && \strncmp($token->value, '--', 2) === 0;

        while (true) {
            $type = $token->type;

            if ($type === Token::EOF) {
                break;
            }

            // A comment sat between the previous token and this one. CSS
            // drops comments, so normally nothing is needed — but where the
            // two tokens would otherwise read as one (`1px` + `2px`, `a` +
            // `b`), one space keeps them apart (CSS Syntax §9, ENG-A14).
            // Done first so the colon index below stays right.
            if ($token->start > $previousEnd && $raws !== []) {
                $last = \count($raws) - 1;

                if (self::wouldMerge($types[$last], $raws[$last], $type, $token)) {
                    $raws[] = ' ';
                    $types[] = Token::WHITESPACE;
                }
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

            if ($type !== Token::WHITESPACE && \count($raws) >= self::MAX_CONSTRUCT_TOKENS) {
                // Too long to keep (ENG-A14): free what was stored and only
                // find the terminator from here on, in constant memory.
                // Checked after the terminator tests and before this token
                // touches the bracket stack, so a construct of exactly
                // MAX_CONSTRUCT_TOKENS tokens is kept, its terminator never
                // counts, and the skip starts from the stack as it really is.
                $this->report(
                    \sprintf('Construct too long (more than %d tokens); dropped', self::MAX_CONSTRUCT_TOKENS),
                    $first
                );

                return [[], [], $this->skipConstruct($token, $stack, $custom), true, -1, true];
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

            // Leading whitespace is always trimmed later; never store it.
            if ($type !== Token::WHITESPACE || $raws !== []) {
                $raws[] = (string) \substr($source, $token->start, $token->end - $token->start);
                $types[] = $type;
            }

            $previousEnd = $token->end;
            $token = $tokenizer->next();
        }

        return [$raws, $types, $token, $invalid, $colon, false];
    }

    /**
     * Read on from $token to the terminator of a construct that was too long
     * to keep, storing nothing but the stack of expected closers — the same
     * bracket discipline as {@see self::collect()} (a `)` never closes a
     * `[`), so the construct ends exactly where it would have ended had it
     * been kept — exactly up to {@see self::SKIP_STACK_LIMIT} open brackets;
     * beyond that, by depth (a mismatched closer may then end it one level
     * early, which only moves the author's own text, never a boundary).
     *
     * The stack is bounded (T-05): past {@see self::SKIP_STACK_LIMIT} open
     * brackets — far beyond anything a kept construct could hold — further
     * openers are only counted, so a run of millions of `(` after the cap
     * costs no memory; while that count is above zero any closer decrements
     * it (plain depth counting), and the exact matching resumes once it is
     * back at zero.
     *
     * @param list<string> $stack The closers still open when skipping began.
     */
    private function skipConstruct(Token $token, array $stack, bool $custom): Token
    {
        $tokenizer = $this->tokenizer;
        $overflow = 0;

        while (true) {
            $type = $token->type;

            if ($type === Token::EOF) {
                return $token;
            }

            if ($stack === []) {
                if ($type === Token::SEMICOLON || $type === Token::RIGHT_BRACE) {
                    return $token;
                }

                if ($type === Token::LEFT_BRACE && !$custom) {
                    return $token;
                }
            }

            switch ($type) {
                case Token::FUNCTION_TOKEN:
                case Token::LEFT_PAREN:
                case Token::LEFT_BRACKET:
                case Token::LEFT_BRACE:
                    if ($overflow > 0 || \count($stack) >= self::SKIP_STACK_LIMIT) {
                        ++$overflow;
                    } else {
                        $stack[] = $type === Token::LEFT_BRACKET
                            ? Token::RIGHT_BRACKET
                            : ($type === Token::LEFT_BRACE ? Token::RIGHT_BRACE : Token::RIGHT_PAREN);
                    }

                    break;
                case Token::RIGHT_PAREN:
                case Token::RIGHT_BRACKET:
                case Token::RIGHT_BRACE:
                    if ($overflow > 0) {
                        --$overflow;
                    } elseif ($stack !== [] && $stack[\count($stack) - 1] === $type) {
                        \array_pop($stack);
                    }

                    break;
            }

            $token = $tokenizer->next();
        }
    }

    /**
     * Whether a token of type $previousType (raw text $previousRaw) directly
     * followed by $next would be read as one token — the pairs CSS Syntax §9
     * says need a comment between them when serialized. Only those pairs get
     * a space where a comment separated them; `a/**\/.b` stays `a.b`.
     */
    private static function wouldMerge(string $previousType, string $previousRaw, string $nextType, Token $next): bool
    {
        $nextIdentLike = $nextType === Token::IDENT || $nextType === Token::FUNCTION_TOKEN
            || $nextType === Token::URL || $nextType === Token::BAD_URL;
        $nextNumeric = $nextType === Token::NUMBER || $nextType === Token::PERCENTAGE
            || $nextType === Token::DIMENSION;
        $nextDelim = $nextType === Token::DELIM ? $next->value : '';
        $nextCdc = $nextType === Token::CDC;

        switch ($previousType) {
            case Token::IDENT:
                return $nextIdentLike || $nextNumeric || $nextDelim === '-' || $nextCdc
                    || $nextType === Token::LEFT_PAREN;
            case Token::AT_KEYWORD:
            case Token::HASH:
            case Token::DIMENSION:
                return $nextIdentLike || $nextNumeric || $nextDelim === '-' || $nextCdc;
            case Token::NUMBER:
                return $nextIdentLike || $nextNumeric || $nextDelim === '%';
            case Token::DELIM:
                switch ($previousRaw) {
                    case '#':
                        return $nextIdentLike || $nextNumeric || $nextDelim === '-';
                    case '-':
                        return $nextIdentLike || $nextNumeric || $nextDelim === '-' || $nextCdc;
                    case '@':
                        return $nextIdentLike || $nextDelim === '-' || $nextCdc;
                    case '.':
                    case '+':
                        return $nextNumeric;
                    case '/':
                        return $nextDelim === '*';
                }

                return false;
        }

        return false;
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

        $this->skipBlock();

        return null;
    }

    /**
     * Consume a block whose `{` was just read, up to its matching `}`,
     * creating nothing. Iterative on purpose: a hostile file may nest far
     * deeper than any recursion limit.
     */
    private function skipBlock(): void
    {
        $depth = 1;

        while (true) {
            $token = $this->next();

            if ($token->type === Token::EOF) {
                $this->pending = $token;

                return;
            }

            if ($token->type === Token::LEFT_BRACE) {
                ++$depth;
            } elseif ($token->type === Token::RIGHT_BRACE && --$depth === 0) {
                return;
            }
        }
    }

    /**
     * Count one more node against {@see self::MAX_NODES}. The node that
     * would pass the limit is refused, reported once, and the rest of the
     * input is abandoned — no node is created after that.
     */
    private function admit(Token $at): bool
    {
        if ($this->stopped) {
            return false;
        }

        if (++$this->nodeCount <= self::MAX_NODES) {
            return true;
        }

        $this->stopped = true;
        $this->report(\sprintf('Stylesheet too large; parsing stopped after %d nodes', self::MAX_NODES), $at);
        $this->tokenizer->skipToEnd();
        $this->pending = null;

        return false;
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

    /**
     * The snippet is the author's own text: made printable before the
     * tokenizer cuts it to its 64 bytes (T-06).
     */
    private function report(string $message, Token $at, string $snippet = ''): void
    {
        $this->tokenizer->report($message, $at->line, $at->col, Str::printable($snippet));
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
