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
 *   `div.a`); when the parent is complex or itself starts with a type
 *   selector, the parent is wrapped (`div:is(.a .b)`, `span:is(div)`).
 *   `&-x`, `&_x` and namespaced types (`&svg|a`) are not rewritten.
 * - A parent *list* is expanded per selector when every parent is simple
 *   (`.a, .b { &:hover }` → `.a:hover, .b:hover`) or when any parent has a
 *   pseudo-element (`:is()` cannot hold one). When a parent is complex (a
 *   combinator), or the child uses `&` more than once, the list is wrapped in
 *   `:is()` instead, which keeps the meaning.
 * - After a pseudo-element only pseudo-classes may follow, so a branch such
 *   as `.a::before b` can never match — and one invalid selector in a list
 *   makes a browser drop the whole rule. Such branches are dropped (the
 *   valid ones stay) with one diagnostic per rule.
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
 * The output contains no `&` and no rule inside a rule. Three bounds keep the
 * work and the output linear in the input (threat T-05, ENG-A14): recursion
 * stops at {@see Parser::MAX_DEPTH}; a selector that nesting expands may not
 * exceed {@see self::MAX_SELECTOR_BYTES} (otherwise `& &` chains or long
 * parent lists would double it at every level); and all selectors resolved
 * or emitted plus all at-rule preludes together may not exceed
 * {@see self::MAX_OUTPUT_BYTES} — many children of one long selector would
 * otherwise repeat it without limit. Past the first two a subtree is dropped;
 * past the budget the walk stops altogether. Each case is one `E_CSS_SYNTAX`
 * diagnostic (capped like the parser's). The input tree is never modified.
 *
 * @phpstan-type ParentContext array{
 *     0: string, 1: list<string>, 2: bool, 3: bool, 4: string, 5: list<bool>, 6: int,
 *     7: list<int>, 8: int, 9: int, 10: int, 11: list<bool>
 * }
 */
final class Nesting
{
    /**
     * Longest selector a nested rule may resolve to (ENG-A14).
     */
    public const MAX_SELECTOR_BYTES = 8192;

    /**
     * Total bytes of selectors and at-rule preludes one flatten may produce
     * (ENG-A14, amended) — the design doc 07 input cap.
     */
    public const MAX_OUTPUT_BYTES = 4194304;

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

    /**
     * @var int Bytes left of {@see self::MAX_OUTPUT_BYTES}.
     */
    private $budget = self::MAX_OUTPUT_BYTES;

    /**
     * @var bool True once the budget ran out.
     */
    private $stopped = false;

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
     * $context describes the enclosing rule (see {@see self::context()}) —
     * or is null outside any rule.
     *
     * @param list<Node> $nodes The block's contents.
     * @param ParentContext|null $context
     * @param Rule|null $origin The rule $nodes came from, for positions.
     * @param int $level Blocks around $nodes.
     * @param list<Node> $out
     */
    private function walk(array $nodes, ?array $context, ?Rule $origin, int $level, array &$out): void
    {
        $chunk = [];

        foreach ($nodes as $node) {
            if ($this->stopped) {
                return;
            }

            if ($node instanceof Rule) {
                $this->flush($context, $origin, $chunk, $out);

                if (!$this->enter($level, $node->line)) {
                    continue;
                }

                $resolved = $this->resolve($node->selector, $context, $node->line);

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

                if ($resolved === []) {
                    continue;
                }

                $child = self::context($resolved);

                if ($this->charge(\strlen($child[0]), $node->line)) {
                    $this->walk($node->nodes, $child, $node, $level + 1, $out);
                }

                continue;
            }

            if ($node instanceof AtRule && $node->nodes !== null) {
                $this->flush($context, $origin, $chunk, $out);

                if (!$this->enter($level, $node->line)) {
                    continue;
                }

                if (!$this->charge(\strlen($node->name) + \strlen($node->params), $node->line)) {
                    return;
                }

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

                continue;
            }

            if ($context === null) {
                $out[] = $node;
            } else {
                $chunk[] = $node;
            }
        }

        $this->flush($context, $origin, $chunk, $out);
    }

    /**
     * Emit the declarations collected for the enclosing rule, if any — each
     * emission repeats the rule's selector, so it is charged to the budget.
     *
     * @param ParentContext|null $context
     * @param list<Node> $chunk
     * @param list<Node> $out
     */
    private function flush(?array $context, ?Rule $origin, array &$chunk, array &$out): void
    {
        if ($context === null || $chunk === []) {
            return;
        }

        if ($this->charge(\strlen($context[0]), $origin === null ? null : $origin->line)) {
            $out[] = $origin === null
                ? new Rule($context[0], $chunk)
                : new Rule($context[0], $chunk, $origin->line, $origin->col);
        }

        $chunk = [];
    }

    /**
     * Take $bytes from the output budget; false (once reported) when it is
     * spent, after which nothing more is emitted.
     */
    private function charge(int $bytes, ?int $line): bool
    {
        if ($this->stopped) {
            return false;
        }

        $this->budget -= $bytes;

        if ($this->budget >= 0) {
            return true;
        }

        $this->stopped = true;
        $this->report(
            \sprintf('Nesting output too large (more than %d bytes); the rest is dropped.', self::MAX_OUTPUT_BYTES),
            $line
        );

        return false;
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
     * computed once per rule rather than once per child:
     *
     *  0 the joined selector · 1 its parts · 2 any part complex ·
     *  3 any part with a pseudo-element · 4 the `:is()` form ·
     *  5 per part: has a pseudo-element · 6 sum of the parts' bytes ·
     *  7 indexes of the parts without a pseudo-element · 8 their bytes ·
     *  9 bytes `:is()` wrapping adds over all parts for a typed child ·
     * 10 the same over the parts without a pseudo-element ·
     * 11 per part: a typed child must wrap it (complex or type-led).
     *
     * With 6–11 the size of any per-selector expansion is exact before a
     * single part is built, whatever the shape of the list (ENG-A14).
     *
     * @param list<string> $parents
     *
     * @return ParentContext
     */
    private static function context(array $parents): array
    {
        $complex = false;
        $pseudoElements = [];
        $needsWrap = [];
        $plainIndexes = [];
        $partBytes = 0;
        $plainBytes = 0;
        $wrapAll = 0;
        $wrapPlain = 0;

        foreach ($parents as $index => $parent) {
            $isComplex = SelectorList::isComplex($parent);
            $hasPseudo = SelectorList::hasPseudoElement($parent);
            $wrap = $isComplex || self::startsWithType($parent);
            $length = \strlen($parent);

            $complex = $complex || $isComplex;
            $pseudoElements[] = $hasPseudo;
            $needsWrap[] = $wrap;
            $partBytes += $length;
            $wrapAll += $wrap ? 5 : 0;

            if (!$hasPseudo) {
                $plainIndexes[] = $index;
                $plainBytes += $length;
                $wrapPlain += $wrap ? 5 : 0;
            }
        }

        $joined = \implode(', ', $parents);

        return [
            $joined,
            $parents,
            $complex,
            \count($plainIndexes) !== \count($parents),
            ':is(' . $joined . ')',
            $pseudoElements,
            $partBytes,
            $plainIndexes,
            $plainBytes,
            $wrapAll,
            $wrapPlain,
            $needsWrap,
        ];
    }

    /**
     * Resolve a rule's selector against its enclosing rule: the resolved
     * parts (empty when every branch was invalid), or null when nesting would
     * make it longer than {@see self::MAX_SELECTOR_BYTES}. The size of every
     * expansion is known exactly before a part is built (the context carries
     * the sums), so an oversized selector is refused in O(1) and never
     * materialised; the work done per child is proportional to the parts
     * actually emitted (threat T-05). A top-level selector is not nested, so
     * it is not capped.
     *
     * @param ParentContext|null $context
     *
     * @return list<string>|null
     */
    private function resolve(string $selector, ?array $context, ?int $line): ?array
    {
        $resolved = [];

        if ($context === null) {
            foreach (SelectorList::split($selector) as $child) {
                $resolved[] = SelectorList::replaceAmpersand($child, ':scope');
            }

            return $resolved;
        }

        $bytes = 0;
        $droppedBranch = false;
        $parents = $context[1];
        $all = \count($parents);

        foreach (SelectorList::split($selector) as $child) {
            $hoisted = SelectorList::hoistTypeSelectors($child);
            $typed = $hoisted !== $child;
            $child = $hoisted;
            $count = SelectorList::countAmpersands($child);

            if ($count === 0) {
                $child = '& ' . $child;
                $count = 1;
            }

            if ($all !== 1 && !$context[3] && ($context[2] || $count > 1)) {
                if (!self::fits($bytes, $child, $count, $context[4])) {
                    return null;
                }

                $resolved[] = SelectorList::replaceAmpersand($child, $context[4]);

                continue;
            }

            // Which parts keep this child: all of them, or — after a
            // pseudo-element parent, where only `&` plus pseudo-classes can
            // match — the parts without one. Decided once per child.
            $follows = !$context[3] || self::followsPseudoElement($child);
            $kept = $follows ? $all : \count($context[7]);

            if ($kept === 0) {
                $droppedBranch = true;

                continue;
            }

            // The exact size of the expansion, before building any of it:
            // each kept part repeats the child with its ampersands replaced
            // (plus `:is(` + `)` when a typed child must wrap the part).
            $partBytes = $follows ? $context[6] : $context[8];

            if ($typed) {
                $partBytes += $follows ? $context[9] : $context[10];
            }

            $expanded = $kept * (\strlen($child) - $count) + $count * $partBytes + 2 * ($kept - 1);

            if ($bytes + $expanded > self::MAX_SELECTOR_BYTES) {
                return null;
            }

            $bytes += $expanded + 2;
            $droppedBranch = $droppedBranch || !$follows;

            foreach (($follows ? \array_keys($parents) : $context[7]) as $index) {
                $parent = $parents[$index];
                // A type selector now sits in front of `&`; a parent that is
                // complex or starts with its own type must be wrapped.
                $replacement = $typed && $context[11][$index] ? ':is(' . $parent . ')' : $parent;

                $resolved[] = SelectorList::replaceAmpersand($child, $replacement);
            }
        }

        if ($droppedBranch) {
            $this->report(
                'A nested selector after a pseudo-element can never match; that part is dropped.',
                $line
            );
        }

        return $resolved;
    }

    /**
     * Whether $child is valid after a pseudo-element parent: `&` alone or
     * followed only by pseudo-classes (`&:hover`), nothing else.
     */
    private static function followsPseudoElement(string $child): bool
    {
        if ($child === '' || $child[0] !== '&' || SelectorList::countAmpersands($child) !== 1) {
            return false;
        }

        $rest = (string) \substr($child, 1);

        if ($rest === '') {
            return true;
        }

        return $rest[0] === ':'
            && (\strlen($rest) < 2 || $rest[1] !== ':')
            && !SelectorList::isComplex($rest);
    }

    /**
     * Whether $selector begins with a type or universal selector.
     */
    private static function startsWithType(string $selector): bool
    {
        if ($selector === '') {
            return false;
        }

        $o = \ord($selector[0]);

        return ($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || $o >= 128 || $o === 42 || $o === 124;
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
