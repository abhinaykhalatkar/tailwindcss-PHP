<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Support;

/**
 * A fixture utility part whose handler class is {@see FixtureHandlersA}.
 */
final class FixturePartWithHandlers
{
    public const HANDLERS = FixtureHandlersA::class;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function rows(): array
    {
        return [];
    }
}
