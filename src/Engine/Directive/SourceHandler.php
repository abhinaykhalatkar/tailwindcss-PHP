<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Token;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Scanner\BraceExpander;

/**
 * `@source` (design doc 07 §Pipeline): reported, never acted on here.
 *
 * - `@source "path"` / `@source not "path"` → a path spec for the scanner,
 *   which confines it (T-01); nothing is opened or resolved here.
 * - `@source inline("…")` / `@source not inline("…")` → candidates, each
 *   whitespace-separated word expanded with {@see BraceExpander} (`{a,b}`,
 *   `{1..5}`); they go to the candidate parser like scanned ones (T-15).
 *
 * Bounded per sheet (T-05): the candidates are sets, each distinct word is
 * expanded once however often it is written, and at most
 * `ProcessorOptions::maxInlineCandidates` distinct values are kept, included
 * and excluded together — past it one `W_CANDIDATE_LIMIT`, and nothing more is
 * expanded. A word with unbalanced braces refuses its directive before
 * anything is added; anything that is not a path or `inline()`, or an
 * expansion the expander refuses, is `W_SOURCE_IGNORED`.
 *
 * @phpstan-import-type SourceSpec from ProcessedSheet
 */
final class SourceHandler
{
    /**
     * @var int
     */
    private $limit;

    /**
     * @var array<string, true>
     */
    private $inline = [];

    /**
     * @var array<string, true>
     */
    private $excluded = [];

    /**
     * @var array<string, true> "+word" / "-word" already expanded.
     */
    private $words = [];

    /**
     * @var bool True once the limit was reached (and reported).
     */
    private $full = false;

    public function __construct(int $limit)
    {
        $this->limit = $limit;
    }

    /**
     * @param list<SourceSpec> $sources
     */
    public function handle(AtRule $source, array &$sources, DiagnosticBag $bag): void
    {
        $tokens = [];

        foreach (Tokenizer::tokenize($source->params) as $token) {
            if ($token->type !== Token::WHITESPACE && $token->type !== Token::EOF) {
                $tokens[] = $token;
            }
        }

        $negated = isset($tokens[0]) && $tokens[0]->type === Token::IDENT && \strtolower($tokens[0]->value) === 'not';

        if ($negated) {
            \array_shift($tokens);
        }

        $count = \count($tokens);

        if ($count === 1 && $tokens[0]->type === Token::STRING && $tokens[0]->value !== '' && $source->nodes === null) {
            $sources[] = ['kind' => $negated ? 'not' : 'path', 'value' => $tokens[0]->value];

            return;
        }

        $isInline = $count === 3 && $tokens[0]->type === Token::FUNCTION_TOKEN
            && \strtolower($tokens[0]->value) === 'inline'
            && $tokens[1]->type === Token::STRING && $tokens[2]->type === Token::RIGHT_PAREN;

        if (!$isInline || $source->nodes !== null) {
            self::ignore($source, 'it is not a path or inline() source', $bag);

            return;
        }

        $this->addInline($tokens[1]->value, $negated, $source, $bag);
    }

    /**
     * @return list<string>
     */
    public function inline(): array
    {
        return \array_map('strval', \array_keys($this->inline));
    }

    /**
     * @return list<string>
     */
    public function excluded(): array
    {
        return \array_map('strval', \array_keys($this->excluded));
    }

    private function addInline(string $text, bool $negated, AtRule $source, DiagnosticBag $bag): void
    {
        $sign = $negated ? '-' : '+';
        $words = [];

        foreach (\explode(' ', \str_replace(["\t", "\n", "\r"], ' ', $text)) as $word) {
            if ($word === '' || isset($this->words[$sign . $word])) {
                continue;
            }

            if (!self::balanced($word)) {
                self::ignore($source, 'its brace pattern is malformed', $bag);

                return;
            }

            $words[$word] = true;
        }

        foreach (\array_keys($words) as $word) {
            $word = (string) $word;

            if ($this->full) {
                return;
            }

            try {
                $expanded = BraceExpander::expand($word);
            } catch (ScanLimitException $exception) {
                self::ignore($source, 'its brace pattern expands too far', $bag);

                return;
            }

            $this->words[$sign . $word] = true;

            foreach ($expanded as $candidate) {
                if ($candidate !== '' && !$this->add($candidate, $negated)) {
                    $this->full = true;
                    $bag->add(Processor::diagnostic(
                        Diagnostic::W_CANDIDATE_LIMIT,
                        \sprintf('More than %d @source inline() candidates; the rest are ignored.', $this->limit),
                        $source->line
                    ));

                    return;
                }
            }
        }
    }

    /**
     * Add one candidate; false when the set is full and $candidate is new.
     */
    private function add(string $candidate, bool $negated): bool
    {
        $known = isset($this->inline[$candidate]) || isset($this->excluded[$candidate]);

        if (!$known && \count($this->inline) + \count($this->excluded) >= $this->limit) {
            return false;
        }

        if ($negated) {
            $this->excluded[$candidate] = true;
        } else {
            $this->inline[$candidate] = true;
        }

        return true;
    }

    /**
     * Braces balanced and nested (`{` before its `}`), as the expander needs.
     */
    private static function balanced(string $word): bool
    {
        $depth = 0;
        $length = \strlen($word);

        for ($i = 0; $i < $length; ++$i) {
            if ($word[$i] === '{') {
                ++$depth;
            } elseif ($word[$i] === '}' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    private static function ignore(AtRule $source, string $why, DiagnosticBag $bag): void
    {
        $bag->add(Processor::diagnostic(
            Diagnostic::W_SOURCE_IGNORED,
            \sprintf('@source %s is ignored: %s.', Processor::echo($source->params), $why),
            $source->line
        ));
    }
}
