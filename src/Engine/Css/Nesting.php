<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
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
 *   (`.a, .b { &:hover }` → `.a:hover, .b:hover`). When a parent is complex
 *   (a combinator), or the child uses `&` more than once, the list is wrapped
 *   in `:is()` instead, which keeps the meaning (`& + &` must also match
 *   `.a + .b`).
 * - A list with a pseudo-element part cannot go into `:is()` whole (it cannot
 *   hold one). A child using `&` once is expanded per part — also when a part
 *   is complex, so `.a::before, .x .y, .z { &:h }` gives
 *   `.a::before:h, .x .y:h, .z:h`, where an `:is()` would have given every
 *   branch the specificity of its most specific part. A child using `&` more
 *   than once drops the pseudo-element parts (see below) and wraps the rest:
 *   `.a::before, a, .b { & + & }` → `:is(a, .b) + :is(a, .b)`.
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
 * - Rules left without declarations are dropped; a nested rule whose whole
 *   subtree holds no declaration and no at-rule is skipped before its
 *   selector is even resolved (it would serialize to nothing), so it raises
 *   none of the diagnostics below either — nothing is emitted, no limit hidden.
 *
 * The output contains no `&` and no rule inside a rule. Three bounds keep the
 * work and the output bounded by the budget (threat T-05, ENG-A14): recursion
 * stops at {@see Parser::MAX_DEPTH}; a selector that nesting expands may not
 * exceed {@see self::MAX_SELECTOR_BYTES} (otherwise `& &` chains or long
 * parent lists would double it at every level); and all selectors resolved
 * or emitted plus all at-rule preludes together may not exceed
 * {@see self::MAX_OUTPUT_BYTES} — many children of one long selector would
 * otherwise repeat it without limit (a few KB of nesting may still expand to
 * megabytes, up to the budget). A nested rule resolved against its parent
 * also costs {@see self::PART_CHARGE} bytes per part, so the parts resolved
 * stay proportional to what is charged even when they are one byte each; a
 * top-level rule and an emitted chunk cost their bytes only. A fourth bound
 * counts the nodes written (rules, at-rules and declarations, as the parser
 * counts them): past {@see Parser::MAX_NODES} the walk stops too, so the
 * output always re-parses.
 * Past the first two bounds a subtree is dropped; past the byte or the node
 * budget the walk stops altogether. Each case is one `E_CSS_SYNTAX` diagnostic, capped like
 * the parser's — except the budget stop, which is always reported. The input
 * tree is never modified.
 *
 * @phpstan-type ParentContext array{
 *     0: string, 1: list<string>, 2: bool, 3: bool, 4: string, 5: list<bool>, 6: int,
 *     7: list<int>, 8: int, 9: int, 10: int, 11: list<bool>, 12: string
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
     *
     * The budget charges work as well as output: a rule's selector is charged
     * once when it is resolved and once more each time a chunk of its
     * declarations is emitted, so it holds about 2 MiB of top-level selectors.
     * Authored CSS of that size (it fits the 4 MiB entry cap) leaves the
     * compiler's byte room empty — every candidate is dropped with
     * `W_CANDIDATE_LIMIT` — and then reaches this budget's stop
     * (`E_CSS_SYNTAX`, the tail lost); the byte room makes the stop
     * unreachable for candidate-driven shapes only, and the entry cap bounds
     * the authored case (TASK-0053). Declarations are not charged, so the
     * whole output can pass 4 MiB (5 000 theme variables write 5.1 MB): the
     * budget bounds selectors and preludes, not the sheet.
     */
    public const MAX_OUTPUT_BYTES = 4194304;

    /**
     * Bytes charged to {@see self::MAX_OUTPUT_BYTES} per selector part on
     * top of the selector's own bytes: resolving and inspecting a part costs
     * work whatever its length, so a list of many one-byte parts must not
     * get 4 MiB worth of them (T-05; 16 ≈ the per-part work of `context()`
     * measured in bytes of string handling).
     */
    public const PART_CHARGE = 16;

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
     * @var int Nodes left of {@see Parser::MAX_NODES} (rules, at-rules and
     *          declarations, as the parser counts them).
     */
    private $nodesLeft = Parser::MAX_NODES;

    /**
     * @var bool True once a budget ran out (bytes or nodes).
     */
    private $stopped = false;

    /**
     * @var \SplObjectStorage<Rule, bool> Whether a rule's subtree emits anything.
     */
    private $emits;

    /**
     * Nodes written as they are (already flattened, already charged): see
     * {@see self::flatten()}.
     *
     * @var \SplObjectStorage<Node, mixed>|null
     */
    private $flat;

    private function __construct(?DiagnosticBag $diagnostics)
    {
        $this->diagnostics = $diagnostics;
        $this->emits = new \SplObjectStorage();
    }

    /**
     * $flat holds nodes already flattened by {@see self::flattenCharged()}:
     * wherever they sit outside any rule they are written as they are, their
     * nodes still counted against the node cap and their bytes ($flatBytes,
     * the sum of their charges) taken from the byte budget up front — so the
     * budgets hold as if they were walked, without walking them twice.
     *
     * @param \SplObjectStorage<Node, mixed>|null $flat
     */
    public static function flatten(
        StyleSheet $sheet,
        ?DiagnosticBag $diagnostics = null,
        ?\SplObjectStorage $flat = null,
        int $flatBytes = 0
    ): StyleSheet {
        $out = [];
        $walker = new self($diagnostics);
        $walker->flat = $flat;

        if ($flatBytes > 0 && !$walker->charge($flatBytes, null)) {
            return new StyleSheet([]);
        }

        $walker->walk($sheet->nodes, null, null, 0, $out);

        return new StyleSheet($out);
    }

    /**
     * The bytes {@see self::flatten()} charges to {@see self::MAX_OUTPUT_BYTES}
     * for $nodes at the top level of a sheet — the same walk, the same
     * `charge()` and `PART_CHARGE`, its output discarded — or `PHP_INT_MAX`
     * when they alone would stop the walk (bytes or nodes).
     *
     * Charges add up: what top-level siblings cost does not depend on one
     * another, and a block at-rule passes no rule context down, so the
     * charge of a sheet is the sum of its top-level nodes' charges — the
     * compiler's byte room (T-05, TASK-0053) charges each utility this way
     * as it is appended and so stops before this budget can trip.
     *
     * @param list<Node> $nodes
     */
    public static function bytesOf(array $nodes): int
    {
        return self::flattenCharged($nodes)[1];
    }

    /**
     * $nodes flattened as {@see self::flatten()} writes them at the top level
     * of a sheet, with {@see self::bytesOf()} their charge — one walk for
     * both, so a caller that must know the charge before it keeps the nodes
     * (the compiler's byte room) need not flatten twice: flattening flat CSS
     * again gives it back unchanged, and costs a fraction of the first walk.
     * No diagnostics: past a budget the charge is `PHP_INT_MAX` and the nodes
     * are what was written before it.
     *
     * @param list<Node> $nodes
     *
     * @return array{0: list<Node>, 1: int}
     */
    public static function flattenCharged(array $nodes): array
    {
        $out = [];
        $walker = new self(null);
        $walker->walk($nodes, null, null, 0, $out);

        return [$out, $walker->stopped ? \PHP_INT_MAX : self::MAX_OUTPUT_BYTES - $walker->budget];
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

            if ($context === null && $this->flat !== null && $this->flat->offsetExists($node)) {
                if ($this->admit(self::nodeCount($node), null)) {
                    $out[] = $node;
                }

                continue;
            }

            if ($node instanceof Rule) {
                $this->flush($context, $origin, $chunk, $out);

                // Nothing to emit: skip it before resolving its selector, so
                // children that would serialize to nothing cost no work and
                // no budget (T-05).
                if (!$this->emitsAnything($node)) {
                    continue;
                }

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

                // Charged before its context is built. A nested rule also
                // pays PART_CHARGE per part: resolving it against a parent
                // list is where per-part work multiplies (parents × children).
                // A top-level rule pays its bytes only — its parts are read
                // once, in proportion to the input.
                if ($this->charge(self::cost($resolved, $context !== null), $node->line)) {
                    $this->walk($node->nodes, self::context($resolved), $node, $level + 1, $out);
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

                if (!$this->admit(1, $node->line)) {
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

                // A block that came out empty is not written, as the CLI
                // drops it (`@media (hover: hover) {}` under an empty
                // utility); its node goes back to the budget. A named
                // `@layer x {}` still fixes the layer order, so it becomes
                // the statement `@layer x;` (as lightningcss writes it).
                // Statements (`@layer x;`, `@import`) have no block and never
                // get here.
                if (!self::holdsAnything($inner)) {
                    if (\strtolower($node->name) === 'layer' && \trim($node->params) !== '') {
                        $out[] = new AtRule($node->name, $node->params, null, $node->line, $node->col);
                    } else {
                        ++$this->nodesLeft;
                    }

                    continue;
                }

                $out[] = new AtRule($node->name, $node->params, $inner, $node->line, $node->col);

                continue;
            }

            if ($context === null) {
                $line = $node instanceof Declaration || $node instanceof AtRule ? $node->line : null;

                if ($node instanceof Comment || $this->admit(1, $line)) {
                    $out[] = $node;
                }
            } else {
                $chunk[] = $node;
            }
        }

        $this->flush($context, $origin, $chunk, $out);
    }

    /**
     * The nodes the parser would count in $node and below (comments are none).
     */
    private static function nodeCount(Node $node): int
    {
        if ($node instanceof Comment) {
            return 0;
        }

        $count = 1;
        $children = $node instanceof Rule ? $node->nodes : ($node instanceof AtRule ? ($node->nodes ?? []) : []);

        foreach ($children as $child) {
            $count += self::nodeCount($child);
        }

        return $count;
    }

    /**
     * Whether flattened block contents hold anything but comments.
     *
     * @param list<Node> $nodes
     */
    private static function holdsAnything(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Comment) {
                return true;
            }
        }

        return false;
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

        $line = $origin === null ? null : $origin->line;

        // Bytes only: emitting a chunk does no per-part work.
        if ($this->charge(\strlen($context[0]), $line) && $this->admit(1 + self::countable($chunk), $line)) {
            $out[] = $origin === null
                ? new Rule($context[0], $chunk)
                : new Rule($context[0], $chunk, $origin->line, $origin->col);
        }

        $chunk = [];
    }

    /**
     * Take $count nodes from the node budget; false (once reported) when it is
     * spent, after which nothing more is emitted — so the output always fits
     * the parser's node cap and re-parses (T-05).
     */
    private function admit(int $count, ?int $line): bool
    {
        if ($this->stopped) {
            return false;
        }

        $this->nodesLeft -= $count;

        if ($this->nodesLeft >= 0) {
            return true;
        }

        $this->stopped = true;
        $this->report(
            \sprintf('Nesting output holds more than %d nodes; the rest is dropped.', Parser::MAX_NODES),
            $line,
            true
        );

        return false;
    }

    /**
     * The nodes of a chunk the parser counts (comments are not nodes to it).
     *
     * @param list<Node> $nodes
     */
    private static function countable(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if (!$node instanceof Comment) {
                ++$count;
            }
        }

        return $count;
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
        // Always delivered, even past the cap: it explains why the output
        // ends early (it can happen only once).
        $this->report(
            \sprintf('Nesting output too large (more than %d bytes); the rest is dropped.', self::MAX_OUTPUT_BYTES),
            $line,
            true
        );

        return false;
    }

    /**
     * Budget cost of a resolved selector made of $parts: its bytes as joined
     * by {@see self::context()}, plus {@see self::PART_CHARGE} per part when
     * it was resolved against a parent ($nested).
     *
     * @param list<string> $parts
     */
    private static function cost(array $parts, bool $nested): int
    {
        $bytes = 2 * (\count($parts) - 1) + ($nested ? self::PART_CHARGE * \count($parts) : 0);

        foreach ($parts as $part) {
            $bytes += \strlen($part);
        }

        return $bytes;
    }

    /**
     * Whether $rule's subtree holds anything that ends up in the output: a
     * declaration, or an at-rule (a statement such as `@import`, or a block,
     * which is emitted even when empty). Comments do not count. Memoised, so
     * each rule is inspected once however deep it sits; past
     * {@see Parser::MAX_DEPTH} levels (a hand-built tree) the answer is a
     * conservative yes and the walk's own depth cut applies.
     */
    private function emitsAnything(Rule $rule, int $depth = 0): bool
    {
        if ($this->emits->offsetExists($rule)) {
            return (bool) $this->emits[$rule];
        }

        $emits = $depth >= Parser::MAX_DEPTH;

        foreach ($emits ? [] : $rule->nodes as $node) {
            if ($node instanceof Declaration || $node instanceof AtRule) {
                $emits = true;

                break;
            }
        }

        if (!$emits) {
            foreach ($rule->nodes as $node) {
                if ($node instanceof Rule && $this->emitsAnything($node, $depth + 1)) {
                    $emits = true;

                    break;
                }
            }
        }

        $this->emits[$rule] = $emits;

        return $emits;
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

    /**
     * Add one diagnostic. Ordinary ones are capped at
     * {@see Tokenizer::MAX_DIAGNOSTICS} (the last being "Too many…"); one slot
     * above the cap is reserved for $always, the budget stop, so the
     * flattener never reports more than MAX_DIAGNOSTICS + 1, as before.
     */
    private function report(string $message, ?int $line, bool $always = false): void
    {
        if ($this->diagnostics === null) {
            return;
        }

        if (!$always) {
            if ($this->reported >= Tokenizer::MAX_DIAGNOSTICS) {
                return;
            }

            ++$this->reported;

            if ($this->reported === Tokenizer::MAX_DIAGNOSTICS) {
                $message = 'Too many CSS nesting errors; further ones are not reported.';
            }
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
     * 11 per part: a typed child must wrap it (complex or type-led) ·
     * 12 the `:is()` form of the parts without a pseudo-element, when there
     *    is a pseudo-element part and at least two others (else '').
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
        $anyPseudo = \count($plainIndexes) !== \count($parents);
        $plainIs = '';

        if ($anyPseudo && \count($plainIndexes) > 1) {
            $plain = [];

            foreach ($plainIndexes as $index) {
                $plain[] = $parents[$index];
            }

            $plainIs = ':is(' . \implode(', ', $plain) . ')';
        }

        return [
            $joined,
            $parents,
            $complex,
            $anyPseudo,
            ':is(' . $joined . ')',
            $pseudoElements,
            $partBytes,
            $plainIndexes,
            $plainBytes,
            $wrapAll,
            $wrapPlain,
            $needsWrap,
            $plainIs,
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

            // The list is wrapped in `:is()` when a part is complex or the
            // child uses `&` more than once (per-part expansion would pair
            // each part only with itself). Under a list with a pseudo-element
            // part only the second reason applies, and only the parts without
            // one are wrapped — the others can never match a combinator form
            // — when at least two remain (one remaining part is expanded on
            // its own below).
            $wrapList = $context[3]
                ? $count > 1 && $context[12] !== ''
                : $all !== 1 && ($context[2] || $count > 1);

            if ($wrapList) {
                $replacement = $context[3] ? $context[12] : $context[4];

                if (!self::fits($bytes, $child, $count, $replacement)) {
                    return null;
                }

                $resolved[] = SelectorList::replaceAmpersand($child, $replacement);
                $droppedBranch = $droppedBranch || $context[3];

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
