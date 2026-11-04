<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * The root of a parsed stylesheet: an ordered list of top-level nodes.
 *
 * Not itself a {@see Node}, so a stylesheet can never end up nested inside a
 * block. Public properties, set once by the constructor and treated as
 * immutable by every consumer (design doc 13 #22); a transformation builds a
 * new tree rather than editing this one.
 */
final class StyleSheet
{
    /**
     * @var list<Node>
     */
    public $nodes;

    /**
     * @param list<Node> $nodes
     */
    public function __construct(array $nodes = [])
    {
        $this->nodes = $nodes;
    }
}
