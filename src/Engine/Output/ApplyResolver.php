<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Output;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser as CssParser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler as UtilityCompiler;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Support\Str;

/**
 * Phase 4, `@apply` (design doc 07 §Phase 4): each `@apply` the directive
 * processor recorded is compiled as candidates and merged into its host rule.
 *
 * - Each `@apply` is resolved on its own: its utilities sorted as the
 *   utilities layer sorts them ({@see SortKey}), each wrapped in its variants
 *   under `&`, and the body appended to the host — declarations go into the
 *   host itself, a variant's nested rule or at-rule stays nested under it
 *   (`.btn { …; &:hover { … } }`), as the CLI prints it.
 * - An `@apply` inside a `@utility` is resolved before any candidate that
 *   uses that utility is compiled, so the custom utility carries the applied
 *   CSS. A chain deeper than {@see self::MAX_DEPTH}, or a cycle, is
 *   `E_APPLY_DEPTH`; a candidate that compiles to nothing is
 *   `E_APPLY_UNKNOWN` (both errors, as in v4), and the rest of the list is
 *   still applied.
 *
 * The host's `nodes` are changed in place: the host is the authored rule (or
 * the custom utility's), so the assembled sheet sees the result. The theme
 * variables the applied utilities read and the `--tw-*` properties they
 * register are collected for the caller.
 *
 * @phpstan-import-type Apply from \TailwindPhp\Engine\Directive\ProcessedSheet
 */
final class ApplyResolver
{
    public const MAX_DEPTH = 8;

    /**
     * The nodes all applied bodies of one compile may expand to (T-05): the
     * parser's node cap, so an `@apply` fan-out (`@utility u_i { @apply
     * hover:u_j focus:u_j … }` over several levels, each level multiplying the
     * last) stops long before memory does.
     */
    public const MAX_EXPANDED_NODES = 100000;

    /**
     * At-rules `Css\Nesting` carries the enclosing rule's selector into.
     *
     * @var array<string, true>
     */
    private const CONDITIONAL = [
        'media' => true, 'supports' => true, 'container' => true,
        'starting-style' => true, 'layer' => true, 'scope' => true,
    ];

    private const MAX_ECHO = 64;

    /**
     * The test the compiler wraps an opacity colour's oklab form in.
     */
    private const COLOR_MIX_TEST = '(color: color-mix(in lab, red, red))';

    /**
     * @var CandidateParser
     */
    private $parser;

    /**
     * @var UtilityCompiler
     */
    private $compiler;

    /**
     * @var Applier
     */
    private $applier;

    /**
     * @var ThemeRegistry
     */
    private $theme;

    /**
     * @var string|null
     */
    private $prefix;

    /**
     * @var array<string, string>
     */
    private $breakpoints;

    /**
     * @var list<string>
     */
    private $used = [];

    /**
     * The `--tw-*` names the spliced bodies register, in splice order, each
     * with whether a variant registered it (true) or the utility (false).
     *
     * @var list<array{0: string, 1: bool}>
     */
    private $registrations = [];

    /**
     * @var int Expanded nodes the applied bodies may still add, this compile.
     */
    private $budget;

    /**
     * @var bool True once the budget is spent: nothing more is applied.
     */
    private $exhausted = false;

    /**
     * @param array<string, string> $breakpoints The theme's breakpoints, for {@see SortKey}.
     * @param int $budget Expanded nodes all applied bodies may add (at most {@see self::MAX_EXPANDED_NODES}).
     */
    public function __construct(
        CandidateParser $parser,
        UtilityCompiler $compiler,
        Applier $applier,
        ThemeRegistry $theme,
        ?string $prefix,
        array $breakpoints,
        int $budget = self::MAX_EXPANDED_NODES
    ) {
        $this->parser = $parser;
        $this->compiler = $compiler;
        $this->applier = $applier;
        $this->theme = $theme;
        $this->prefix = $prefix;
        $this->breakpoints = $breakpoints;
        $this->budget = \min($budget, self::MAX_EXPANDED_NODES);
    }

    /**
     * Resolve every `@apply`, in source order.
     *
     * @param list<Apply> $applies
     */
    public function resolve(array $applies, DiagnosticBag $bag): void
    {
        /** @var array<string, list<int>> $byUtility */
        $byUtility = [];

        foreach ($applies as $index => $apply) {
            if ($apply['utility'] !== null) {
                $byUtility[$apply['utility']][] = $index;
            }
        }

        $done = [];

        foreach (\array_keys($applies) as $index) {
            $this->apply($applies, $index, $byUtility, $done, [], $bag);
        }
    }

    /**
     * The theme variables the applied utilities read.
     *
     * @return list<string>
     */
    public function usedVars(): array
    {
        return \array_values(\array_unique($this->used));
    }

    /**
     * The `--tw-*` properties the applied utilities register, in order.
     *
     * @return list<string>
     */
    public function properties(): array
    {
        $names = [];

        foreach ($this->registrations as [$property]) {
            $names[] = $property;
        }

        return \array_values(\array_unique($names));
    }

    /**
     * Every registration of the spliced bodies, in splice order: the name and
     * whether a variant registered it. The CLI writes a name's `@property`
     * descriptors in the order of its first registration's kind (TASK-0053).
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /**
     * @param list<Apply> $applies
     * @param array<string, list<int>> $byUtility
     * @param array<int, true> $done
     * @param list<string> $chain The custom utilities being resolved, outermost first.
     */
    private function apply(
        array $applies,
        int $index,
        array $byUtility,
        array &$done,
        array $chain,
        DiagnosticBag $bag
    ): void {
        if (isset($done[$index])) {
            return;
        }

        $done[$index] = true;

        if ($this->exhausted) {
            return;
        }

        $apply = $applies[$index];
        $entries = [];

        // The utility being completed is on the chain itself, so a candidate
        // that leads back to it is a cycle, never a node nested in itself.
        if ($apply['utility'] !== null && !\in_array($apply['utility'], $chain, true)) {
            $chain[] = $apply['utility'];
        }

        foreach ($apply['candidates'] as $raw) {
            $readings = $this->parser->parse($raw);
            $root = $readings === [] ? null : $readings[0]->root;

            // A custom utility's own `@apply`s first, so it is complete.
            if ($root !== null && isset($byUtility[$root])) {
                if (\in_array($root, $chain, true) || \count($chain) >= self::MAX_DEPTH) {
                    $bag->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_APPLY_DEPTH, \sprintf(
                        '@apply %s nests deeper than %d levels or applies itself; it is ignored.',
                        self::echo($raw),
                        self::MAX_DEPTH
                    ), null, $apply['line']));

                    continue;
                }

                $inner = $chain;
                $inner[] = $root;

                foreach ($byUtility[$root] as $dependency) {
                    $this->apply($applies, $dependency, $byUtility, $done, $inner, $bag);
                }

                if ($this->exhausted) {
                    return;
                }
            }

            $cost = 0;
            $entry = $this->compile($raw, $readings, $cost);

            // A body compiled and then refused (a variant the applier cannot
            // apply) cost its expansion all the same: it is charged, so a list
            // of such candidates cannot spend CPU for free (T-05).
            if ($entry === null && $cost > $this->budget) {
                $this->exhaust($raw, $apply['line'], $bag);

                return;
            }

            $this->budget -= $cost;

            if ($entry === null) {
                $bag->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_APPLY_UNKNOWN, \sprintf(
                    'Cannot apply unknown utility class %s.',
                    self::echo($raw)
                ), null, $apply['line']));

                continue;
            }

            // Every declaration is written exactly once: a body with more of
            // them than the budget is refused before it is measured.
            if (self::declarationsAtMost($entry[1], $this->budget) > $this->budget) {
                $this->exhaust($raw, $apply['line'], $bag);

                return;
            }

            $entries[] = $entry + [4 => $raw];
        }

        \usort($entries, static function (array $a, array $b): int {
            return SortKey::compare($a[0], $b[0]);
        });

        $host = $apply['host'];

        // Charged before anything is spliced, in splice order: each body as
        // the flattener will write it inside the host. A body that starts with
        // declarations continues the host's last run of declarations (no new
        // rule) only when the host ends with one; after a nested rule or
        // at-rule it opens a new rule, which is counted.
        $endsWithDeclarations = self::endsWithDeclarations($host->nodes);
        $total = 0;

        foreach ($entries as [, $nodes, , , $raw]) {
            if ($nodes === []) {
                continue;
            }

            $size = self::flatSize([new Rule('.x', $nodes)]);

            if ($endsWithDeclarations && !self::isBlock($nodes[0]) && $size !== \PHP_INT_MAX) {
                --$size;
            }

            $total = $size === \PHP_INT_MAX ? \PHP_INT_MAX : $total + $size;

            if ($total > $this->budget) {
                $this->exhaust($raw, $apply['line'], $bag);

                return;
            }

            $endsWithDeclarations = !self::isBlock($nodes[\count($nodes) - 1]);
        }

        $this->budget -= $total;

        // Registered as spliced, in splice order — each candidate's utility
        // names, then its variants' — and only what is spliced.
        foreach ($entries as [, $nodes, $byUtility, $byVariant]) {
            foreach ($nodes as $node) {
                $host->nodes[] = $node;
            }

            foreach ($byUtility as $property) {
                $this->registrations[] = [$property, false];
            }

            foreach ($byVariant as $property) {
                $this->registrations[] = [$property, true];
            }
        }
    }

    /**
     * A rule or a block at-rule: what interrupts a run of declarations.
     */
    private static function isBlock(Node $node): bool
    {
        return $node instanceof Rule || ($node instanceof \TailwindPhp\Engine\Css\Node\AtRule && $node->nodes !== null);
    }

    /**
     * Whether the host's contents end with a run of declarations (so the
     * flattener has already written its rule).
     *
     * @param list<Node> $nodes
     */
    private static function endsWithDeclarations(array $nodes): bool
    {
        for ($i = \count($nodes) - 1; $i >= 0; --$i) {
            if ($nodes[$i] instanceof \TailwindPhp\Engine\Css\Node\Comment) {
                continue;
            }

            return !self::isBlock($nodes[$i]);
        }

        return false;
    }

    /**
     * The sort key and the body (the children of the `&` rule) of one
     * applied candidate, or null when it compiles to nothing.
     *
     * @param list<Candidate> $readings
     * @param int $cost Set to the nodes of a body that compiled but is refused.
     *
     * @return array{0: SortKey, 1: list<Node>, 2: list<string>, 3: list<string>}|null
     */
    private function compile(string $raw, array $readings, int &$cost): ?array
    {
        $compiled = $this->compiler->compileReadings($raw, $readings, $this->theme);

        if ($compiled === null || $readings === []) {
            return null;
        }

        $nodes = Assembler::prefixed($compiled->nodes, $this->prefix, $this->theme);
        $wrapped = $this->applier->wrap($nodes, $readings[0]->variants, '&');

        if ($wrapped === null) {
            $cost = self::expandedSize($compiled->nodes, $this->budget);

            return null;
        }

        $body = [];

        foreach ($wrapped->nodes() as $node) {
            if ($node instanceof Rule && $node->selector === '&') {
                foreach ($node->nodes as $child) {
                    $body[] = $child;
                }
            } else {
                $body[] = $node;
            }
        }

        foreach ($compiled->usedVars as $name) {
            $this->used[] = $name;
        }

        $key = SortKey::of(
            $readings[0]->variants,
            $wrapped->variantOrders(),
            $compiled->sortKey,
            self::declarations($compiled->nodes),
            $raw,
            $this->breakpoints
        );

        return [$key, $body, $compiled->properties, $wrapped->properties()];
    }

    /**
     * The nodes $nodes take once flattened inside the utilities layer, as the
     * parser counts them (rules, at-rules, declarations); a one-rule body of
     * declarations is counted without flattening. Past the parser's node cap
     * (where the flattener stops) the answer is "too many": `PHP_INT_MAX`.
     *
     * @param list<Node> $nodes
     */
    public static function flatSize(array $nodes): int
    {
        $count = self::flatCount($nodes, false, CssParser::MAX_NODES + 1);

        return $count > CssParser::MAX_NODES ? \PHP_INT_MAX : $count;
    }

    /**
     * {@see self::flatSize()} without flattening: what `Css\Nesting` writes,
     * counted the way it writes it — each run of declarations inside a rule is
     * one rule plus its declarations, each block at-rule one node (a
     * conditional one keeps the rule around it), a declaration outside any
     * rule one node, comments nothing. Counted only up to $limit + 1, a shared
     * sub-tree once per place, so the cost is bounded by $limit whatever the
     * tree expands to.
     *
     * @param list<Node> $nodes
     */
    private static function flatCount(array $nodes, bool $inRule, int $limit): int
    {
        $count = 0;
        $open = false;

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $open = false;
                $count += self::flatCount($node->nodes, true, $limit - $count);
            } elseif ($node instanceof \TailwindPhp\Engine\Css\Node\AtRule && $node->nodes !== null) {
                $open = false;
                $conditional = $inRule && isset(self::CONDITIONAL[\strtolower($node->name)]);
                $count += 1 + self::flatCount($node->nodes, $conditional, $limit - $count - 1);
            } else {
                if ($inRule && !$open) {
                    ++$count;
                    $open = true;
                }

                if (!$node instanceof \TailwindPhp\Engine\Css\Node\Comment) {
                    ++$count;
                }
            }

            if ($count > $limit) {
                return $count;
            }
        }

        return $count;
    }
    /**
     * Declarations of $nodes at any depth, a shared sub-tree once per place —
     * counted only up to $limit + 1.
     *
     * @param list<Node> $nodes
     */
    private static function declarationsAtMost(array $nodes, int $limit): int
    {
        $count = 0;
        $stack = [$nodes];

        while (($top = \array_pop($stack)) !== null) {
            foreach ($top as $node) {
                if ($node instanceof \TailwindPhp\Engine\Css\Node\Declaration) {
                    if (++$count > $limit) {
                        return $count;
                    }
                } elseif ($node instanceof Rule) {
                    $stack[] = $node->nodes;
                } elseif ($node instanceof \TailwindPhp\Engine\Css\Node\AtRule && $node->nodes !== null) {
                    $stack[] = $node->nodes;
                }
            }
        }

        return $count;
    }

    /**
     * The budget is spent: one diagnostic, nothing more is applied.
     */
    private function exhaust(string $raw, ?int $line, DiagnosticBag $bag): void
    {
        $this->exhausted = true;
        $bag->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_APPLY_DEPTH, \sprintf(
            '@apply %s expands past %d nodes; it and the remaining @apply rules are ignored.',
            self::echo($raw),
            self::MAX_EXPANDED_NODES
        ), null, $line));
    }

    /**
     * How many nodes $nodes write out, a shared sub-tree once per place it
     * appears — counted only up to $limit + 1, so the count costs at most that
     * however large the tree would expand.
     *
     * @param list<Node> $nodes
     */
    public static function expandedSize(array $nodes, int $limit): int
    {
        $count = 0;
        $stack = [$nodes];

        while (($top = \array_pop($stack)) !== null) {
            foreach ($top as $node) {
                if (++$count > $limit) {
                    return $count;
                }

                if ($node instanceof Rule) {
                    $stack[] = $node->nodes;
                } elseif ($node instanceof \TailwindPhp\Engine\Css\Node\AtRule && $node->nodes !== null) {
                    $stack[] = $node->nodes;
                }
            }
        }

        return $count;
    }

    /**
     * The declaration count the CLI breaks sort ties with: every declaration of
     * the utility at any depth (`bg-linear-to-*`, whose own `@supports` adds
     * one, sorts before `bg-gradient-to-*`) — except the opacity fallback's
     * `@supports (color: color-mix(…))` copy, which v4 adds after sorting
     * (`bg-blue-500` sorts before `bg-red-500/50`).
     *
     * @param list<Node> $nodes
     */
    public static function declarations(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if ($node instanceof \TailwindPhp\Engine\Css\Node\Declaration) {
                ++$count;
            } elseif ($node instanceof Rule) {
                $count += self::declarations($node->nodes);
            } elseif ($node instanceof \TailwindPhp\Engine\Css\Node\AtRule && $node->nodes !== null) {
                $opacityFallback = $node->name === 'supports' && $node->params === self::COLOR_MIX_TEST;
                $count += $opacityFallback ? 0 : self::declarations($node->nodes);
            }
        }

        return $count;
    }

    private static function echo(string $text): string
    {
        return Str::truncateBytes($text, self::MAX_ECHO + 3, '…');
    }
}
