<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * Marker for the four node kinds a block can contain: {@see Rule},
 * {@see AtRule}, {@see Declaration} and {@see Comment} (design doc 07 §Phase 1).
 *
 * Deliberately empty. The nodes are plain data with public properties (design
 * doc 13 #22) and every consumer — the nesting flattener, the serializer, the
 * directive handlers — switches on the concrete class, so a shared behavioural
 * interface would only add indirection to the hot path.
 */
interface Node
{
}
