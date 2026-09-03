<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder so the suite exists; replaced by real tests in M0/M1.
 */
final class SuiteSmokeTest extends TestCase
{
    /** @test */
    public function bootstrapRan(): void
    {
        self::assertSame('UTC', \date_default_timezone_get());
    }
}
