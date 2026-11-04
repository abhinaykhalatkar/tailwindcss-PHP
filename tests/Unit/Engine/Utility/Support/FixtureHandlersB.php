<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Support;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;

/**
 * A fixture family handler that claims the handler name "container", for the
 * dispatcher's build-time refusals.
 */
final class FixtureHandlersB implements FamilyHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function names(): array
    {
        return ['container'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): array
    {
        return [[], []];
    }
}
