<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Css\Node\Node;

/**
 * What one candidate compiles to (design doc 07 §Compilation output), before
 * variants and the class selector are applied.
 *
 * - `nodes`: the utility body — declarations, plus nested at-rules or `&`
 *   rules where the utility needs them (`container`'s `@media` blocks, an
 *   opacity fallback's `@supports`); the variant applier (TASK-0031) wraps
 *   them under the class selector.
 * - `usedVars`: theme variables the body reads; the caller marks them used
 *   when it emits the utility (never before, so a reading that was tried and
 *   dropped marks nothing).
 * - `properties`: `--tw-*` custom properties needing an `@property`
 *   registration (the assembler emits them once, TASK-0034).
 * - `sortKey`: v4's property sort — the {@see \TailwindPhp\Engine\Data\PropertyOrder}
 *   positions of the declared properties, ascending, without duplicates (or
 *   the single position of the row's `order`); the assembler compares these
 *   lists, then the candidate text (TASK-0034 builds its `SortKey` from it).
 *
 * Public properties set once by the constructor.
 */
final class CompiledUtility
{
    /**
     * @var list<Node>
     */
    public $nodes;

    /**
     * @var list<string>
     */
    public $usedVars;

    /**
     * @var list<string>
     */
    public $properties;

    /**
     * @var list<int>
     */
    public $sortKey;

    /**
     * @param list<Node> $nodes
     * @param list<string> $usedVars
     * @param list<string> $properties
     * @param list<int> $sortKey
     */
    public function __construct(array $nodes, array $usedVars, array $properties, array $sortKey)
    {
        $this->nodes = $nodes;
        $this->usedVars = $usedVars;
        $this->properties = $properties;
        $this->sortKey = $sortKey;
    }
}
