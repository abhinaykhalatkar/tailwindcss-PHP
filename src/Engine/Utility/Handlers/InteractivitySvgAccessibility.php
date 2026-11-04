<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;

/**
 * The handlers of the interactivity, SVG and accessibility family (`Data\Utilities\InteractivitySvgAccessibility`).
 *
 * This family implements no handler (TASK-0040): every root is a data row, the
 * CLI's output for the keyword, spacing, snap, touch and paint utilities being
 * plain declarations. The class stays so the family has the same shape as the
 * others and a later root that needs a handler has its place.
 */
final class InteractivitySvgAccessibility implements FamilyHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function names(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        return null;
    }
}
