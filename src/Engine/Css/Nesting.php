<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The nesting flattener (design doc 07 §Nesting flattener): nested CSS in,
 * browser-compatible flat CSS out, as Tailwind's own output is.
 *
 * - A nested rule's selector is resolved against its parent: every `&` is
 *   replaced by the parent selector, and a selector without `&` is a
 *   descendant (`.b` inside `.a` → `.a .b`, `> .b` → `.a > .b`). A type
 *   selector written right after `&` moves in front of it (`&div` in `.a` →
 *   `div.a`; with a complex parent, `div:is(.a .b)`).
 * - A parent *list* is expanded per selector when every parent is simple
 *   (`.a, .b { &:hover }` → `.a:hover, .b:hover`) or when any parent has a
 *   pseudo-element (`:is()` cannot hold one). When a parent is complex (a
 *   combinator), or the child uses `&` more than once, the list is wrapped in
 *   `:is()` instead, which keeps the meaning.
 * - Declarations keep their order relative to nested rules: the
 *   declarations between two nested rules become their own rule with the
 *   parent selector (CSS Nesting §2.3, nested declarations).
 * - Conditional group rules (`@media`, `@supports`, `@container`,
 *   `@starting-style`, `@layer`, `@scope`) inside a rule are hoisted above
 *   it, with the rule re-created inside; nested at-rules stay nested. Any
 *   other block at-rule inside a rule (`@keyframes`) is moved out unchanged.
 * - A top-level `&` means the scoping root and becomes `:scope`.
 * - Rules left without declarations are dropped.
 *
 * The output contains no `&` and no rule inside a rule. Two bounds keep the
 * work and the output linear in the input (threat T-05, ENG-A14): recursion
 * follows the tree and stops at {@see Parser::MAX_DEPTH}, and a resolved
 * selector may not exceed {@see self::MAX_SELECTOR_BYTES} — otherwise `& &`
 * chains or long parent lists would double the selector at every level. A
 * subtree over either bound is dropped with an `E_CSS_SYNTAX` diagnostic
 * (capped like the parser's). The input tree is never modified.
 */
final class Nesting
{
    /**
     * Longest selector a nested rule may resolve to (ENG-A14).
     */
    public const MAX_SELECTOR_BYTES = 8192;

    /**
     * At-rules whose block carries the enclosing rule's selector into it.
     *
     * @var array<string, true>
     */
    private const CONDITIONAL = [
        'media' => true,
        'supports' => true,
        'container' => true,
        'starting-style' => true,
        'layer' => true,
        'scope' => true,
    ];

    /**
     * @var DiagnosticBag|null
     */
    private $diagnostics;

    /**
     * @var int
     */
    private $reported = 0;

    private function __construct(?DiagnosticBag $diagnostics)
    {
        $this->diagnostics = $diagnostics;
    }

    public static function flatten(StyleSheet $sheet, ?DiagnosticBag $diagnostics = null): StyleSheet
    {
        $out = [];

        (new self($diagnostics))->walk($sheet->nodes, null, null, 0, $out);

        return new StyleSheet($out);
    }

    /**
     * Flatten the contents of one block into $out.
     *
     * $context describes the enclosing rule — its resolved selector, the
     * parts of it, whether any part is complex, whether any has a
     * pseudo-element, and its `:is()` form — or is null outside any rule.
     *
     * @param list<Node> $nodes The block's contents.
     * @param array{0: string, 1: list<string>, 2: bool, 3: bool, 4: string}|null $context
     * @param Rule|null $origin The rule $nodes came from, for positions.
     * @param int $level Blocks around $nodes.
     * @param list<Node> $out
     */
    private function walk(array $nodes, ?array $context, ?Rule $origin, int $level, array &$out): void
    {
        $chunk = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                self::flush($context, $origin, $chunk, $out);

                if (!$this->enter($level, $node->line)) {
                    continue;
                }

                $resolved = self::resolve($node->selector, $context);

                if ($resolved === null) {
                    $this->report(
                        \sprintf(
                            'Selector too long after nesting (more than %d bytes); the rule is dropped.',
                            self::MAX_SELECTOR_BYTES
                        ),
                        $node->line
                    );

                    continue;
                }

                $this->walk($node->nodes, self::context($resolved), $node, $level + 1, $out);

                continue;
            }

            if ($node instanceof AtRule && $node->nodes !== null) {
                self::flush($context, $origin, $chunk, $out);

                if ($this->enter($level, $node->line)) {
                    $conditional = isset(self::CONDITIONAL[\strtolower($node->name)]);
                    $inner = [];

                    $this->walk(
                        $node->nodes,
                        $conditional ? $context : null,
                        $conditional ? $origin : null,
                        $level + 1,
                        $inner
                    );

                    $out[] = new AtRule($node->name, $node->params, $inner, $node->line, $node->col);
                }

                continue;
            }

            if ($context === null) {
                $out[] = $node;
            } else {
                $chunk[] = $node;
            }
        }

        self::flush($context, $origin, $chunk, $out);
    }

    /**
     * Emit the declarations collected for the enclosing rule, if any.
     *
     * @param array{0: string, 1: list<string>, 2: bool, 3: bool, 4: string}|null $context
     * @param list<Node> $chunk
     * @param list<Node> $out
     */
    private static function flush(?array $context, ?Rule $origin, array &$chunk, array &$out): void
    {
        if ($context === null || $chunk === []) {
            return;
        }

        $out[] = $origin === null
            ? new Rule($context[0], $chunk)
            : new Rule($context[0], $chunk, $origin->line, $origin->col);
        $chunk = [];
    }

    /**
     * May a block be opened inside contents at $level?
     */
    private function enter(int $level, ?int $line): bool
    {
        if ($level + 1 <= Parser::MAX_DEPTH) {
            return true;
        }

        $this->report(
            \sprintf('CSS nesting too deep (more than %d levels); the block is dropped.', Parser::MAX_DEPTH),
            $line
        );

        return false;
    }

    private function report(string $message, ?int $line): void
    {
        if ($this->diagnostics === null || $this->reported > Tokenizer::MAX_DIAGNOSTICS) {
            return;
        }

        ++$this->reported;

        if ($this->reported > Tokenizer::MAX_DIAGNOSTICS) {
            $message = 'Too many CSS nesting errors; further ones are not reported.';
        }

        $this->diagnostics->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, $message, null, $line));
    }

    /**
     * Everything the children of a rule need about its resolved selector,
     * computed once per rule rather than once per child.
     *
     * @param list<string> $parents
     *
     * @return array{0: string, 1: list<string>, 2: bool, 3: bool, 4: string}
     */
    private static function context(array $parents): array
    {
        $complex = false;
        $pseudoElement = false;

        foreach ($parents as $parent) {
            $complex = $complex || SelectorList::isComplex($parent);
            $pseudoElement = $pseudoElement || SelectorList::hasPseudoElement($parent);
        }

        $joined = \implode(', ', $parents);

        return [$joined, $parents, $complex, $pseudoElement, ':is(' . $joined . ')'];
    }

    /**
     * Resolve a rule's selector against its enclosing rule, or null when the
     * result would exceed {@see self::MAX_SELECTOR_BYTES}. The size is
     * checked before each part is built, so an oversized selector is never
     * materialised.
     *
     * @param array{0: string, 1: list<string>, 2: bool, 3: bool, 4: string}|null $context
     *
     * @return list<string>|null
     */
    private static function resolve(string $selector, ?array $context): ?array
    {
        $resolved = [];
        $bytes = 0;

        foreach (SelectorList::split($selector) as $child) {
            if ($context === null) {
                if (!self::fits($bytes, $child, SelectorList::countAmpersands($child), ':scope')) {
                    return null;
                }

                $resolved[] = SelectorList::replaceAmpersand($child, ':scope');

                continue;
            }

            $parents = $context[1];
            $hoisted = SelectorList::hoistTypeSelectors($child);
            $typed = $hoisted !== $child;
            $child = $hoisted;
            $count = SelectorList::countAmpersands($child);

            if ($count === 0) {
                $child = '& ' . $child;
                $count = 1;
            }

            if (\count($parents) === 1) {
                // A type selector now sits in front of `&`; a complex parent
                // must be wrapped so the type applies to the whole of it.
                $replacement = $typed && $context[2] ? ':is(' . $parents[0] . ')' : $parents[0];
                $replacements = [$replacement];
            } elseif (!$context[3] && ($context[2] || $count > 1)) {
                $replacements = [$context[4]];
            } else {
                $replacements = $parents;
            }

            foreach ($replacements as $replacement) {
                if (!self::fits($bytes, $child, $count, $replacement)) {
                    return null;
                }

                $resolved[] = SelectorList::replaceAmpersand($child, $replacement);
            }
        }

        return $resolved;
    }

    /**
     * Add the size of $child with its $count ampersands replaced to $bytes;
     * false when the running total passes the limit.
     */
    private static function fits(int &$bytes, string $child, int $count, string $replacement): bool
    {
        $bytes += \strlen($child) + $count * (\strlen($replacement) - 1) + 2;

        return $bytes - 2 <= self::MAX_SELECTOR_BYTES;
    }
}
