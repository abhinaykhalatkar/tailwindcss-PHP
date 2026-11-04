<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;

/**
 * Plain-array projection of an AST, for node-by-node comparison in tests.
 *
 * Positions are left out on purpose: two trees are structurally equal when
 * their kinds, texts and children match, wherever the nodes came from.
 *
 *     Rule        → ['rule', selector, children]
 *     AtRule      → ['at', name, params, children|null]
 *     Declaration → ['decl', property, value, important]
 *     Comment     → ['comment', text]
 */
final class AstDump
{
    private function __construct()
    {
    }

    /**
     * @return list<array<int, mixed>>
     */
    public static function of(StyleSheet $sheet): array
    {
        return self::nodes($sheet->nodes);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<array<int, mixed>>
     */
    public static function nodes(array $nodes): array
    {
        $dump = [];

        foreach ($nodes as $node) {
            $dump[] = self::node($node);
        }

        return $dump;
    }

    /**
     * @return array<int, mixed>
     */
    public static function node(Node $node): array
    {
        if ($node instanceof Rule) {
            return ['rule', $node->selector, self::nodes($node->nodes)];
        }

        if ($node instanceof AtRule) {
            return ['at', $node->name, $node->params, $node->nodes === null ? null : self::nodes($node->nodes)];
        }

        if ($node instanceof Declaration) {
            return ['decl', $node->property, $node->value, $node->important];
        }

        if ($node instanceof Comment) {
            return ['comment', $node->text];
        }

        throw new \LogicException('Unknown node class ' . \get_class($node));
    }

    /**
     * Deepest block nesting in the tree (a top-level rule's block is level 1).
     */
    public static function depth(StyleSheet $sheet): int
    {
        return self::depthOf($sheet->nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function depthOf(array $nodes): int
    {
        $max = 0;

        foreach ($nodes as $node) {
            $children = null;

            if ($node instanceof Rule) {
                $children = $node->nodes;
            } elseif ($node instanceof AtRule) {
                $children = $node->nodes;
            }

            if ($children !== null) {
                $max = \max($max, 1 + self::depthOf($children));
            }
        }

        return $max;
    }
}
