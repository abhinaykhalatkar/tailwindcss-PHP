<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Variant;

use TailwindPhp\Engine\Css\Node\Node;

/**
 * A utility's nodes after its variants have been applied (`Applier::wrap`).
 *
 * `nodes` is one rule, the candidate's class selector, holding the nested
 * variant structure exactly as v4 writes it (`.c { &:hover { @media … { … } } }`);
 * `Css\Nesting::flatten` turns it into the final flat CSS. `properties` names the
 * `--tw-*` custom properties the variants rely on (`--tw-content` for `before:`
 * and `after:`), to be registered with `@property`. `variantOrders` holds each
 * variant's order in the order the variants were written, for the sort key.
 */
final class Wrapped
{
    /**
     * @var list<Node>
     */
    private $nodes;

    /**
     * @var list<string>
     */
    private $properties;

    /**
     * @var list<int>
     */
    private $variantOrders;

    /**
     * @param list<Node> $nodes
     * @param list<string> $properties
     * @param list<int> $variantOrders
     */
    public function __construct(array $nodes, array $properties, array $variantOrders)
    {
        $this->nodes = $nodes;
        $this->properties = $properties;
        $this->variantOrders = $variantOrders;
    }

    /**
     * @return list<Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<string>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    /**
     * @return list<int>
     */
    public function variantOrders(): array
    {
        return $this->variantOrders;
    }
}
