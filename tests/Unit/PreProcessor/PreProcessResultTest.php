<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\PreProcessor;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\PreProcessor\PreProcessResult;

final class PreProcessResultTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(PreProcessResult::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyFieldRoundTripsThroughItsGetter(): void
    {
        $result = new PreProcessResult('.a{color:red}', ['assets/styles/_variables.scss']);

        self::assertSame('.a{color:red}', $result->css());
        self::assertSame(['assets/styles/_variables.scss'], $result->imports());
    }

    /** @test */
    public function anEmptyResultIsAccepted(): void
    {
        $result = new PreProcessResult('', []);

        self::assertSame('', $result->css());
        self::assertSame([], $result->imports());
    }

    /** @test */
    public function severalImportsKeepTheirOrder(): void
    {
        $imports = [
            'assets/styles/_variables.scss',
            'assets/styles/_mixins.scss',
            'assets/styles/components/_button.scss',
        ];

        self::assertSame($imports, (new PreProcessResult('', $imports))->imports());
    }

    /**
     * The imports become the manifest's watched inputs, a JSON array; a gapped
     * PHP array would encode as an object and break the schema.
     *
     * @test
     */
    public function theImportListIsReindexed(): void
    {
        $result = new PreProcessResult('', [4 => 'assets/a.scss', 11 => 'assets/b.scss']);

        self::assertSame(['assets/a.scss', 'assets/b.scss'], $result->imports());
    }

    /** @test */
    public function thereAreNoSetters(): void
    {
        $reflection = new ReflectionClass(PreProcessResult::class);
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            $names[] = $method->getName();
        }

        \sort($names);

        self::assertSame(['__construct', 'css', 'imports'], $names);
    }
}
