<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Theme;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Theme\ThemeValue;

/**
 * One resolved theme variable.
 */
final class ThemeValueTest extends TestCase
{
    /** @test */
    public function itCarriesTheNameTheValueAndTheTwoFlags(): void
    {
        $value = new ThemeValue('--color-red-500', 'oklch(63.7% 0.237 25.331)', true, false);

        self::assertSame('--color-red-500', $value->name());
        self::assertSame('oklch(63.7% 0.237 25.331)', $value->value());
        self::assertTrue($value->isInline());
        self::assertFalse($value->isReference());
    }

    /** @test */
    public function aReferenceVariableSaysSo(): void
    {
        $value = new ThemeValue('--x', '1', false, true);

        self::assertFalse($value->isInline());
        self::assertTrue($value->isReference());
    }

    /** @test */
    public function theValueIsStoredVerbatim(): void
    {
        $verbatim = '  <?php echo 1; ?> var(--a) { } \\31 0  ';

        self::assertSame($verbatim, (new ThemeValue('--x', $verbatim, false, false))->value());
    }

    /** @test */
    public function theClassIsFinalAndHasNoSetters(): void
    {
        $reflection = new ReflectionClass(ThemeValue::class);

        self::assertTrue($reflection->isFinal());

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName());
        }
    }
}
