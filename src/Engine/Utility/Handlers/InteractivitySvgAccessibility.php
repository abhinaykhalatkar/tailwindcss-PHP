<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;

/**
 * The handlers of the interactivity, SVG and accessibility family (`Data\Utilities\InteractivitySvgAccessibility`).
 *
 * A stub until its wave-4 family task lands: it implements no handler yet, so
 * every handler name a row of this family needs stays in
 * `Handlers::unimplemented()` and compiles to nothing. TASK-0040 fills it.
 */
final class InteractivitySvgAccessibility implements FamilyHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function names(): array
    {
        // TODO M2-wave4 (TASK-0040): list the handler names this family implements.
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        // TODO M2-wave4 (TASK-0040): dispatch on $name.
        return null;
    }
}
