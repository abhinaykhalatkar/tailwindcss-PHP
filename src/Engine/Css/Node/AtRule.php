<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * An at-rule: `@name params;` (no block, `nodes` null) or
 * `@name params { nodes }` (a block, possibly empty).
 *
 * `name` is the authored name without the `@`; `params` is the prelude as
 * authored, trimmed. Directive handlers compare names case-insensitively.
 * Positions are filled only when the parser runs in debug mode.
 */
final class AtRule implements Node
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $params;

    /**
     * @var list<Node>|null Null when the at-rule has no block.
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
     * @param list<Node>|null $nodes
     */
    public function __construct(
        string $name,
        string $params = '',
        ?array $nodes = null,
        ?int $line = null,
        ?int $col = null
    ) {
        $this->name = $name;
        $this->params = $params;
        $this->nodes = $nodes;
        $this->line = $line;
        $this->col = $col;
    }
}
