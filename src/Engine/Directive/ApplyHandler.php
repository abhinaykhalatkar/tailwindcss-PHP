<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * `@apply` (design doc 07 Phase 4): deferred. Each `@apply` is removed from
 * the rule that holds it and recorded with that rule as its host; the
 * assembler (TASK-0034) compiles the candidates and inlines them into the
 * host's `nodes`, where the `@apply` stood relative to the other
 * declarations being its concern. Nested rules are visited too.
 *
 * @phpstan-import-type Apply from ProcessedSheet
 */
final class ApplyHandler
{
    /**
     * A copy of $rule without its `@apply` at-rules (at any depth of nested
     * rules and conditional blocks), and the applies found, in order.
     *
     * @param list<Apply> $applies
     */
    public static function extract(
        Rule $rule,
        ?string $utility,
        array &$applies,
        DiagnosticBag $bag,
        int $depth = 0
    ): Rule {
        $host = new Rule($rule->selector, [], $rule->line, $rule->col);
        $host->nodes = self::strip($rule->nodes, $host, $utility, $applies, $bag, $depth);

        return $host;
    }

    /**
     * @param list<Node> $nodes
     * @param list<Apply> $applies
     *
     * @return list<Node>
     */
    private static function strip(
        array $nodes,
        Rule $host,
        ?string $utility,
        array &$applies,
        DiagnosticBag $bag,
        int $depth
    ): array {
        $kept = [];

        if ($depth > Parser::MAX_DEPTH) {
            return $nodes;
        }

        foreach ($nodes as $node) {
            if ($node instanceof AtRule && \strtolower($node->name) === 'apply') {
                $candidates = self::candidates($node->params);

                if ($candidates === []) {
                    $message = 'An empty @apply is ignored.';
                    $bag->add(Processor::diagnostic(Diagnostic::E_APPLY_UNKNOWN, $message, $node->line));
                } else {
                    $applies[] = [
                        'host' => $host,
                        'candidates' => $candidates,
                        'utility' => $utility,
                        'line' => $node->line,
                    ];
                }

                continue;
            }

            if ($node instanceof Rule) {
                $kept[] = self::extract($node, $utility, $applies, $bag, $depth + 1);

                continue;
            }

            if ($node instanceof AtRule && $node->nodes !== null) {
                // A conditional block (`@media`, …) inside the rule: its
                // contents are read as an `&` rule. When an @apply sits
                // directly in the block, that `&` rule is its host and stays
                // in the tree (`@media x { & { … } }`, which flattens to the
                // same CSS); otherwise the block keeps its own nodes.
                $before = \count($applies);
                $block = new Rule('&', $node->nodes, $node->line, $node->col);
                $inner = self::extract($block, $utility, $applies, $bag, $depth + 1);
                $direct = false;

                for ($i = $before, $count = \count($applies); $i < $count; ++$i) {
                    $direct = $direct || $applies[$i]['host'] === $inner;
                }

                $contents = $direct ? [$inner] : $inner->nodes;
                $kept[] = new AtRule($node->name, $node->params, $contents, $node->line, $node->col);

                continue;
            }

            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * The whitespace-separated candidates of an `@apply` prelude.
     *
     * @return list<string>
     */
    public static function candidates(string $params): array
    {
        $candidates = [];

        foreach (\explode(' ', \str_replace(["\t", "\n", "\r"], ' ', $params)) as $word) {
            if ($word !== '') {
                $candidates[] = $word;
            }
        }

        return $candidates;
    }
}
