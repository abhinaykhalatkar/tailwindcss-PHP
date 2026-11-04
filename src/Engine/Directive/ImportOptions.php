<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Token;
use TailwindPhp\Engine\Css\Tokenizer;

/**
 * The prelude of one `@import` (design doc 07 §Layers): the specifier, then
 * the options Tailwind understands — `layer(name)`, `source(none)` /
 * `source("…")`, `prefix(tw)`, `important`, `theme(static inline reference
 * default)`.
 *
 * Read with the CSS tokenizer, never with a regular expression (T-12). A
 * prelude that is not "a string or url(), then known options" is refused
 * (null from {@see self::parse()}) with the reason in `$problem`.
 */
final class ImportOptions
{
    /**
     * @var string The specifier as written, without quotes.
     */
    public $spec;

    /**
     * @var string|null `layer(name)`; an empty `layer` keyword is `''`.
     */
    public $layer;

    /**
     * @var string|null `source(none)` → `none`, `source("x")` → `x`.
     */
    public $source;

    /**
     * @var string|null
     */
    public $prefix;

    /**
     * @var bool
     */
    public $important;

    /**
     * @var list<string> The words inside `theme(...)`.
     */
    public $theme;

    private function __construct(string $spec)
    {
        $this->spec = $spec;
        $this->layer = null;
        $this->source = null;
        $this->prefix = null;
        $this->important = false;
        $this->theme = [];
    }

    /**
     * @param string|null $problem Set to why the prelude was refused.
     *
     * @param-out string|null $problem
     */
    public static function parse(string $params, ?string &$problem = null): ?self
    {
        $problem = null;
        $tokens = [];

        foreach (Tokenizer::tokenize($params) as $token) {
            if ($token->type !== Token::WHITESPACE && $token->type !== Token::EOF) {
                $tokens[] = $token;
            }
        }

        $first = $tokens[0] ?? null;

        if ($first !== null && ($first->type === Token::STRING || $first->type === Token::URL)) {
            $options = new self($first->value);
            $index = 1;
        } elseif (self::isUrlFunction($tokens)) {
            $options = new self($tokens[1]->value);
            $index = 3;
        } else {
            $problem = 'the specifier must be a string or url()';

            return null;
        }

        $count = \count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];
            $name = \strtolower($token->value);

            if ($token->type === Token::IDENT && $name === 'important') {
                $options->important = true;
                ++$index;

                continue;
            }

            if ($token->type === Token::IDENT && $name === 'layer') {
                $options->layer = '';
                ++$index;

                continue;
            }

            if ($token->type !== Token::FUNCTION_TOKEN) {
                $problem = 'unexpected text after the specifier';

                return null;
            }

            $arguments = [];
            ++$index;

            while ($index < $count && $tokens[$index]->type !== Token::RIGHT_PAREN) {
                $arguments[] = $tokens[$index];
                ++$index;
            }

            if ($index >= $count) {
                $problem = 'an option is not closed';

                return null;
            }

            ++$index;

            if (!$options->option($name, $arguments)) {
                $problem = \sprintf('unknown or malformed option %s()', \substr($name, 0, 20));

                return null;
            }
        }

        return $options;
    }

    /**
     * `url("…")`: a url function holding one string.
     *
     * @param list<Token> $tokens
     */
    private static function isUrlFunction(array $tokens): bool
    {
        return isset($tokens[0], $tokens[1], $tokens[2])
            && $tokens[0]->type === Token::FUNCTION_TOKEN
            && \strtolower($tokens[0]->value) === 'url'
            && $tokens[1]->type === Token::STRING
            && $tokens[2]->type === Token::RIGHT_PAREN;
    }

    /**
     * Whether this imports one of Tailwind's own bundles.
     */
    public function bundle(): ?string
    {
        switch ($this->spec) {
            case 'tailwindcss':
                return 'all';
            case 'tailwindcss/theme.css':
            case 'tailwindcss/theme':
                return 'theme';
            case 'tailwindcss/preflight.css':
            case 'tailwindcss/preflight':
                return 'preflight';
            case 'tailwindcss/utilities.css':
            case 'tailwindcss/utilities':
                return 'utilities';
            default:
                return null;
        }
    }

    /**
     * @param list<Token> $arguments
     */
    private function option(string $name, array $arguments): bool
    {
        $single = \count($arguments) === 1 ? $arguments[0] : null;

        switch ($name) {
            case 'layer':
                if ($single === null || $single->type !== Token::IDENT) {
                    return false;
                }

                $this->layer = $single->value;

                return true;
            case 'source':
                if ($single !== null && $single->type === Token::IDENT && \strtolower($single->value) === 'none') {
                    $this->source = 'none';

                    return true;
                }

                if ($single !== null && $single->type === Token::STRING && $single->value !== '') {
                    $this->source = $single->value;

                    return true;
                }

                return false;
            case 'prefix':
                if ($single === null || $single->type !== Token::IDENT || !self::isPrefix($single->value)) {
                    return false;
                }

                $this->prefix = $single->value;

                return true;
            case 'theme':
                foreach ($arguments as $argument) {
                    $word = \strtolower($argument->value);
                    $known = $word === 'static' || $word === 'inline' || $word === 'reference' || $word === 'default';

                    if ($argument->type !== Token::IDENT || !$known) {
                        return false;
                    }

                    $this->theme[] = $word;
                }

                return $this->theme !== [];
            case 'supports':
                // `@import … supports(…)` is plain CSS; the engine has no use for it.
                return false;
            default:
                return false;
        }
    }

    /**
     * A prefix is lower-case letters (Tailwind's rule for `prefix()`).
     */
    private static function isPrefix(string $prefix): bool
    {
        $length = \strlen($prefix);

        return $length > 0 && $length <= 32 && \strspn($prefix, 'abcdefghijklmnopqrstuvwxyz') === $length;
    }
}
