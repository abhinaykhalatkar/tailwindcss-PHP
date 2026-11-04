<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\TablesTransitionsTransforms as TablesTransitionsTransformsHandlers;

/**
 * The tables, transitions and transforms utilities (design doc 08 §Utilities, T1): the rows
 * `Data\Utilities::table()` merges in catalogue order.
 *
 * A stub until its wave-4 family task lands: no rows yet, and its handler class
 * is {@see TablesTransitionsTransformsHandlers}. TASK-0039 fills it.
 */
final class TablesTransitionsTransforms
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = TablesTransitionsTransformsHandlers::class;

    private function __construct()
    {
    }

    /**
     * Root => row (design doc 07 §Definition schema), in catalogue order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rows(): array
    {
        // TODO M2-wave4 (TASK-0039): the T1 roots of this family.
        return [];
    }
}
