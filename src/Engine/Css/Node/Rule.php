<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * A qualified rule: `selector { nodes }`.
 *
 * The selector is kept as authored text, trimmed, with escapes untouched;
 * selectors are not parsed further (design doc 07 §Phase 1 — `SelectorList`
 * does the little the engine needs). `nodes` may contain declarations, nested
 * rules and at-rules until {@see \TailwindPhp\Engine\Css\Nesting::flatten()}
 * has run. Positions are filled only when the parser runs in debug mode.
 */
final class Rule implements Node
{
    /**
     * @var string
     */
    public $selector;

    /**
     * @var list<Node>
     */
    public $nodes;

    /**
     * @var int|null
     */
    public $line;

    /**
     * @var int|null
     */
    public $col;

    /**
     * @param list<Node> $nodes
     */
    public function __construct(string $selector, array $nodes = [], ?int $line = null, ?int $col = null)
    {
        $this->selector = $selector;
        $this->nodes = $nodes;
        $this->line = $line;
        $this->col = $col;
    }
}
