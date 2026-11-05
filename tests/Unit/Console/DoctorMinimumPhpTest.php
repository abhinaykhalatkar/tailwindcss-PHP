<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Console\Command\DoctorCommand;

/**
 * `doctor` reports the PHP floor that `composer.json` declares (`require.php`),
 * so a host below it is told so before anything else fails (TASK-0059: the
 * floor moved from 7.3 to 8.2).
 */
final class DoctorMinimumPhpTest extends TestCase
{
    /** @test */
    public function theMinimumIsTheFloorComposerJsonDeclares(): void
    {
        $minimum = (new \ReflectionClassConstant(DoctorCommand::class, 'MINIMUM_PHP'))->getValue();
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true);

        self::assertSame('8.2.0', $minimum);
        self::assertIsArray($manifest);
        self::assertSame('>=8.2', $manifest['require']['php'] ?? null, 'doctor and composer.json name the same floor');
    }
}
