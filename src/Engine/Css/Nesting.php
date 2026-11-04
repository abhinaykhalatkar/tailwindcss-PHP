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
 *   descendant (`.b` inside `.a` → `.a .b`, `> .b` → `.a > .b`).
 * - A parent *list* is expanded per selector when every parent is simple
 *   (`.a, .b { &:hover }` → `.a:hover, .b:hover`); when any parent is
 *   complex (a combinator or pseudo-element), or the child uses `&` more than
 *   once, the list is wrapped in `:is()` instead, which keeps the meaning.
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
 * The output contains no `&` and no rule inside a rule. Recursion follows the
 * tree, so it is bounded by {@see Parser::MAX_DEPTH}: a deeper subtree (only
 * possible for a tree built in code) is dropped with an `E_CSS_SYNTAX`
 * diagnostic. The input tree is never modified.
 */
final class Nesting
{
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

    private function __construct()
    {
    }

    public static function flatten(StyleSheet $sheet, ?DiagnosticBag $diagnostics = null): StyleSheet
    {
        $out = [];

        self::walk($sheet->nodes, null, null, 0, $out, $diagnostics);

        return new StyleSheet($out);
    }

    /**
     * Flatten the contents of one block into $out.
     *
     * @param list<Node> $nodes The block's contents.
     * @param string|null $selector The resolved selector of the enclosing rule;
     *                              null outside any rule.
     * @param Rule|null $origin The rule $nodes came from, for positions.
     * @param int $level Blocks around $nodes.
     * @param list<Node> $out
     */
    private static function walk(
        array $nodes,
        ?string $selector,
        ?Rule $origin,
        int $level,
        array &$out,
        ?DiagnosticBag $diagnostics
    ): void {
        $chunk = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                self::flush($selector, $origin, $chunk, $out);

                if (self::enter($level, $node->line, $diagnostics)) {
                    self::walk(
                        $node->nodes,
                        self::resolve($node->selector, $selector),
                        $node,
                        $level + 1,
                        $out,
                        $diagnostics
                    );
                }

                continue;
            }

            if ($node instanceof AtRule && $node->nodes !== null) {
                self::flush($selector, $origin, $chunk, $out);

                if (self::enter($level, $node->line, $diagnostics)) {
                    $conditional = isset(self::CONDITIONAL[\strtolower($node->name)]);
                    $inner = [];

                    self::walk(
                        $node->nodes,
                        $conditional ? $selector : null,
                        $conditional ? $origin : null,
                        $level + 1,
                        $inner,
                        $diagnostics
                    );

                    $out[] = new AtRule($node->name, $node->params, $inner, $node->line, $node->col);
                }

                continue;
            }

            if ($selector === null) {
                $out[] = $node;
            } else {
                $chunk[] = $node;
            }
        }

        self::flush($selector, $origin, $chunk, $out);
    }

    /**
     * Emit the declarations collected for $selector, if any.
     *
     * @param list<Node> $chunk
     * @param list<Node> $out
     */
    private static function flush(?string $selector, ?Rule $origin, array &$chunk, array &$out): void
    {
        if ($selector === null || $chunk === []) {
            return;
        }

        $out[] = $origin === null
            ? new Rule($selector, $chunk)
            : new Rule($selector, $chunk, $origin->line, $origin->col);
        $chunk = [];
    }

    /**
     * May a block be opened inside contents at $level?
     */
    private static function enter(int $level, ?int $line, ?DiagnosticBag $diagnostics): bool
    {
        if ($level + 1 <= Parser::MAX_DEPTH) {
            return true;
        }

        if ($diagnostics !== null) {
            $diagnostics->add(new Diagnostic(
                Diagnostic::ERROR,
                Diagnostic::E_CSS_SYNTAX,
                \sprintf('CSS nesting too deep (more than %d levels); the block is dropped.', Parser::MAX_DEPTH),
                null,
                $line
            ));
        }

        return false;
    }

    /**
     * Resolve a nested rule's selector against its parent's resolved selector.
     */
    private static function resolve(string $selector, ?string $parent): string
    {
        $resolved = [];

        if ($parent === null) {
            foreach (SelectorList::split($selector) as $child) {
                $resolved[] = SelectorList::replaceAmpersand($child, ':scope');
            }

            return \implode(', ', $resolved);
        }

        $parents = SelectorList::split($parent);
        $complex = false;

        foreach ($parents as $candidate) {
            if (SelectorList::isComplex($candidate)) {
                $complex = true;

                break;
            }
        }

        $wrapped = ':is(' . \implode(', ', $parents) . ')';

        foreach (SelectorList::split($selector) as $child) {
            $count = SelectorList::countAmpersands($child);

            if ($count === 0) {
                $child = '& ' . $child;
                $count = 1;
            }

            if (\count($parents) === 1) {
                $resolved[] = SelectorList::replaceAmpersand($child, $parents[0]);
            } elseif ($complex || $count > 1) {
                $resolved[] = SelectorList::replaceAmpersand($child, $wrapped);
            } else {
                foreach ($parents as $one) {
                    $resolved[] = SelectorList::replaceAmpersand($child, $one);
                }
            }
        }

        return \implode(', ', $resolved);
    }
}
