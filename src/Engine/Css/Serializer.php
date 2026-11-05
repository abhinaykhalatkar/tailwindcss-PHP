<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;

/**
 * AST → CSS text (design doc 07 §Serializer).
 *
 * `pretty` is for humans and diffs: two-space indent, one declaration per
 * line, a blank line between top-level nodes, a trailing newline. Selectors,
 * preludes and values are written exactly as stored, so `parse(pretty(x))`
 * gives back the tree of `x`.
 *
 * `compact` drops every optional byte: no whitespace that is not needed,
 * `0.5` → `.5`, a zero length → `0`, rules without declarations (as the CLI
 * does), and adjacent rules with byte-identical blocks merged into one
 * selector list (Q7, ENG-A6: adjacent only, so the cascade order cannot
 * change). A rule whose selector has a vendor-prefixed pseudo (`::-moz-…`,
 * `:-webkit-…`) is never merged: a browser drops a whole rule over one
 * selector it does not know (ENG-A14). The rewrites are deliberately
 * conservative — nothing inside a function (`calc()`, colour functions,
 * `var()`), nothing in a custom property, no unit dropped from `flex` — and
 * colours are never rewritten (v4 keeps them as authored).
 *
 * Both forms drop blocks nested deeper than {@see Parser::MAX_DEPTH}; a parsed
 * tree never has them, a tree built in code might (T-05).
 *
 * Output is written as fragments and joined once (design doc 13 #19); compact
 * joins once per block because merging compares whole block bodies.
 */
final class Serializer
{
    /**
     * The most selectors `compact` merges into one list (T-05, TASK-0053): a
     * quarter of {@see Parser::MAX_CONSTRUCT_TOKENS}, since a merged selector
     * costs at least a comma, a delimiter and a name. The merge also stops
     * once the list would pass `MAX_CONSTRUCT_TOKENS` bytes — every token is at
     * least one byte — so a compact rule's prelude always re-parses; a long
     * run of identical bodies becomes several adjacent rules (same cascade).
     */
    public const MAX_MERGED_SELECTORS = 12500;

    /**
     * Length units whose zero may drop the unit in compact output.
     *
     * @var array<string, true>
     */
    private const LENGTH_UNITS = [
        'px' => true, 'rem' => true, 'em' => true, 'ex' => true, 'ch' => true, 'cap' => true, 'ic' => true,
        'lh' => true, 'rlh' => true, 'rex' => true, 'rch' => true, 'rcap' => true, 'ric' => true,
        'vw' => true, 'vh' => true, 'vi' => true, 'vb' => true, 'vmin' => true, 'vmax' => true,
        'svw' => true, 'svh' => true, 'svi' => true, 'svb' => true, 'svmin' => true, 'svmax' => true,
        'lvw' => true, 'lvh' => true, 'lvi' => true, 'lvb' => true, 'lvmin' => true, 'lvmax' => true,
        'dvw' => true, 'dvh' => true, 'dvi' => true, 'dvb' => true, 'dvmin' => true, 'dvmax' => true,
        'cqw' => true, 'cqh' => true, 'cqi' => true, 'cqb' => true, 'cqmin' => true, 'cqmax' => true,
        'cm' => true, 'mm' => true, 'q' => true, 'in' => true, 'pt' => true, 'pc' => true,
    ];

    /**
     * Properties where a unitless zero means something else than a zero
     * length (`flex: 1 0px` ≠ `flex: 1 0`), so the unit stays.
     *
     * @var array<string, true>
     */
    private const KEEP_ZERO_UNITS = [
        'flex' => true,
        '-webkit-flex' => true,
        '-ms-flex' => true,
    ];

    /**
     * Conditional group rules that compact output drops when their block is
     * empty.
     *
     * @var array<string, true>
     */
    private const DROPPABLE_WHEN_EMPTY = [
        'media' => true,
        'supports' => true,
        'container' => true,
        'starting-style' => true,
        'scope' => true,
    ];

    private function __construct()
    {
    }

    public static function pretty(StyleSheet $sheet): string
    {
        $fragments = [];
        $previous = null;

        foreach ($sheet->nodes as $node) {
            if (self::tooDeep($node, 0)) {
                continue;
            }

            // A banner comment is followed directly by the content it heads.
            if ($previous !== null && !$previous instanceof Comment) {
                $fragments[] = "\n";
            }

            self::prettyNode($node, '', 0, $fragments);
            $previous = $node;
        }

        return \implode('', $fragments);
    }

    public static function compact(StyleSheet $sheet): string
    {
        return self::compactBlock($sheet->nodes, 0);
    }

    /**
     * Whether $node opens a block that would be nested deeper than
     * {@see Parser::MAX_DEPTH} when it sits in contents at $level.
     */
    private static function tooDeep(Node $node, int $level): bool
    {
        $hasBlock = $node instanceof Rule || ($node instanceof AtRule && $node->nodes !== null);

        return $hasBlock && $level + 1 > Parser::MAX_DEPTH;
    }

    /**
     * @param int $level Blocks around $node.
     * @param list<string> $fragments
     */
    private static function prettyNode(Node $node, string $indent, int $level, array &$fragments): void
    {
        if ($node instanceof Declaration) {
            $fragments[] = $indent . $node->property . ': ' . $node->value
                . ($node->important ? ' !important' : '') . ";\n";

            return;
        }

        if ($node instanceof Rule) {
            $fragments[] = $indent . $node->selector . " {\n";
            self::prettyChildren($node->nodes, $indent, $level, $fragments);
            $fragments[] = $indent . "}\n";

            return;
        }

        if ($node instanceof AtRule) {
            $head = $indent . '@' . $node->name . ($node->params === '' ? '' : ' ' . $node->params);

            if ($node->nodes === null) {
                $fragments[] = $head . ";\n";

                return;
            }

            $fragments[] = $head . " {\n";
            self::prettyChildren($node->nodes, $indent, $level, $fragments);
            $fragments[] = $indent . "}\n";

            return;
        }

        if ($node instanceof Comment) {
            $fragments[] = $indent . '/*' . $node->text . "*/\n";
        }
    }

    /**
     * @param list<Node> $nodes
     * @param int $level Blocks around the parent of $nodes.
     * @param list<string> $fragments
     */
    private static function prettyChildren(array $nodes, string $indent, int $level, array &$fragments): void
    {
        $inner = $indent . '  ';

        foreach ($nodes as $child) {
            if (!self::tooDeep($child, $level + 1)) {
                self::prettyNode($child, $inner, $level + 1, $fragments);
            }
        }
    }

    /**
     * One block's contents in compact form, merging adjacent rules whose
     * bodies are byte-identical.
     *
     * @param list<Node> $nodes
     * @param int $level Blocks around $nodes.
     */
    private static function compactBlock(array $nodes, int $level): string
    {
        /** @var list<string> $kinds 'rule', 'vendor-rule', 'decl' or 'other' */
        $kinds = [];
        /** @var list<string> $texts a rule's body, or the item's full text */
        $texts = [];
        /** @var array<int, list<string>> $selectors per rule item */
        $selectors = [];
        /** @var array<int, int> $preludeBytes per rule item: its selectors joined with commas */
        $preludeBytes = [];

        foreach ($nodes as $node) {
            if (self::tooDeep($node, $level)) {
                continue;
            }

            if ($node instanceof Rule) {
                $body = self::compactBlock($node->nodes, $level + 1);

                // A rule without declarations emits nothing (as the CLI).
                if ($body === '') {
                    continue;
                }

                $selector = SelectorList::minify($node->selector);
                $vendor = \strpos($selector, ':-') !== false;
                $last = \count($kinds) - 1;

                if (
                    !$vendor
                    && $last >= 0
                    && $kinds[$last] === 'rule'
                    && $texts[$last] === $body
                    && \count($selectors[$last]) < self::MAX_MERGED_SELECTORS
                    && $preludeBytes[$last] + 1 + \strlen($selector) <= Parser::MAX_CONSTRUCT_TOKENS
                ) {
                    $selectors[$last][] = $selector;
                    $preludeBytes[$last] += 1 + \strlen($selector);

                    continue;
                }

                $preludeBytes[\count($kinds)] = \strlen($selector);
                $selectors[\count($kinds)] = [$selector];
                $kinds[] = $vendor ? 'vendor-rule' : 'rule';
                $texts[] = $body;

                continue;
            }

            if ($node instanceof Declaration) {
                $kinds[] = 'decl';
                $texts[] = $node->property . ':' . self::compactValue($node) . ($node->important ? '!important' : '');

                continue;
            }

            if ($node instanceof AtRule) {
                $head = '@' . $node->name . ($node->params === '' ? '' : ' ' . self::minifyTokens($node->params, null));
                $body = $node->nodes === null ? null : self::compactBlock($node->nodes, $level + 1);

                // An empty conditional block emits nothing (as the CLI does);
                // an empty `@layer` block still fixes the layer order, so stays.
                if ($body === '' && isset(self::DROPPABLE_WHEN_EMPTY[\strtolower($node->name)])) {
                    continue;
                }

                $kinds[] = 'other';
                $texts[] = $body === null ? $head . ';' : $head . '{' . $body . '}';

                continue;
            }

            // Only a licence banner survives minification.
            if ($node instanceof Comment && \strncmp($node->text, '!', 1) === 0) {
                $kinds[] = 'other';
                $texts[] = '/*' . $node->text . '*/';
            }
        }

        $fragments = [];
        $count = \count($kinds);

        for ($i = 0; $i < $count; ++$i) {
            if ($kinds[$i] === 'rule' || $kinds[$i] === 'vendor-rule') {
                $fragments[] = \implode(',', $selectors[$i]) . '{' . $texts[$i] . '}';

                continue;
            }

            $fragments[] = $texts[$i];

            if ($kinds[$i] === 'decl' && $i < $count - 1) {
                $fragments[] = ';';
            }
        }

        return \implode('', $fragments);
    }


    private static function compactValue(Declaration $declaration): string
    {
        // A custom property's value is opaque: any byte of it may matter to
        // whoever reads the variable.
        if (\strncmp($declaration->property, '--', 2) === 0) {
            return $declaration->value;
        }

        return self::minifyTokens($declaration->value, \strtolower($declaration->property));
    }

    /**
     * Re-tokenize $text and write it back without optional whitespace.
     * Numbers are shortened only when $property is given (values, not
     * at-rule preludes) and only outside functions.
     */
    private static function minifyTokens(string $text, ?string $property): string
    {
        $tokenizer = new Tokenizer($text);
        $zeroUnits = $property !== null && !isset(self::KEEP_ZERO_UNITS[$property]);
        $fragments = [];
        $last = '';
        $space = false;
        $depth = 0;

        while (true) {
            $token = $tokenizer->next();
            $type = $token->type;

            if ($type === Token::EOF) {
                break;
            }

            if ($type === Token::WHITESPACE) {
                $space = true;

                continue;
            }

            $raw = $tokenizer->raw($token);

            $numeric = $type === Token::NUMBER || $type === Token::PERCENTAGE || $type === Token::DIMENSION;

            if ($numeric && $property !== null && $depth === 0) {
                $raw = self::compactNumber($token, $raw, $zeroUnits);
            }

            if ($type === Token::FUNCTION_TOKEN || $type === Token::LEFT_PAREN) {
                ++$depth;
            } elseif ($type === Token::RIGHT_PAREN && $depth > 0) {
                --$depth;
            }

            if ($space) {
                if ($last !== '' && \strpos(',(:', $last) === false && \strpos(',)', $raw[0]) === false) {
                    $fragments[] = ' ';
                }

                $space = false;
            }

            $fragments[] = $raw;
            $last = $raw[\strlen($raw) - 1];
        }

        return \implode('', $fragments);
    }

    private static function compactNumber(Token $token, string $raw, bool $zeroUnits): string
    {
        $number = $token->value;

        $zeroLength = $token->type === Token::DIMENSION
            && self::isZero($number)
            && isset(self::LENGTH_UNITS[\strtolower($token->unit)]);

        if ($zeroUnits && $zeroLength) {
            return '0';
        }

        $suffix = (string) \substr($raw, \strlen($number));
        $sign = '';
        $digits = $number;

        if ($number[0] === '+' || $number[0] === '-') {
            $sign = $number[0];
            $digits = (string) \substr($number, 1);
        }

        if (\strlen($digits) > 2 && $digits[0] === '0' && $digits[1] === '.') {
            return $sign . \substr($digits, 1) . $suffix;
        }

        return $raw;
    }

    private static function isZero(string $number): bool
    {
        $digits = \ltrim($number, '+-');

        return $digits !== ''
            && \strspn($digits, '0.') === \strlen($digits)
            && \strpos($digits, '0') !== false;
    }
}
