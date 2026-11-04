<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Support;

/**
 * A fixture utility part for a family that has no handlers: its handler class
 * is null.
 */
final class FixturePartWithoutHandlers
{
    public const HANDLERS = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function rows(): array
    {
        return [];
    }
}
