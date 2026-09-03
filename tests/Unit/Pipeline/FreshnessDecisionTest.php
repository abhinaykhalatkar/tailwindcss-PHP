<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Pipeline\FreshnessDecision;

/**
 * The six pipeline outcomes (design doc 04 §4, decisions CSF-A13, CCP-A15).
 */
final class FreshnessDecisionTest extends TestCase
{
    /** @test */
    public function classIsFinalAndCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(FreshnessDecision::class);

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
    }

    /**
     * Six, not three: `StalenessReport` answers "did the files change", while
     * the pipeline also accounts for an absent cache, a moved environment and
     * debug forcing a rebuild (CSF-A13).
     *
     * @test
     */
    public function theSixDecisionsAreExactlyThoseOfTheDesign(): void
    {
        self::assertSame(
            ['FRESH', 'TEMPLATES_CHANGED', 'INPUTS_CHANGED', 'NO_CACHE', 'ENV_CHANGED', 'DEBUG'],
            FreshnessDecision::all()
        );
    }

    /**
     * @test
     *
     * @dataProvider everyDecision
     */
    public function everyDecisionIsValidAndAsserts(string $decision): void
    {
        self::assertTrue(FreshnessDecision::isValid($decision));

        FreshnessDecision::assert($decision);

        self::assertContains($decision, FreshnessDecision::all());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyDecision(): array
    {
        $cases = [];

        foreach (FreshnessDecision::all() as $decision) {
            $cases[$decision] = [$decision];
        }

        return $cases;
    }

    /**
     * @test
     *
     * @dataProvider unknownDecisions
     */
    public function anUnknownDecisionIsRejected(string $decision): void
    {
        self::assertFalse(FreshnessDecision::isValid($decision));

        $this->expectException(\InvalidArgumentException::class);

        FreshnessDecision::assert($decision);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownDecisions(): array
    {
        return [
            'empty' => [''],
            'lower case' => ['fresh'],
            'an invented name' => ['STALE'],
            'with whitespace' => [' FRESH'],
        ];
    }

    /**
     * The constants are a published contract — the debug overlay prints them
     * and integrators match on them — so renaming one is a breaking change and
     * should fail here first.
     *
     * @test
     */
    public function eachConstantEqualsItsOwnName(): void
    {
        self::assertSame('FRESH', FreshnessDecision::FRESH);
        self::assertSame('TEMPLATES_CHANGED', FreshnessDecision::TEMPLATES_CHANGED);
        self::assertSame('INPUTS_CHANGED', FreshnessDecision::INPUTS_CHANGED);
        self::assertSame('NO_CACHE', FreshnessDecision::NO_CACHE);
        self::assertSame('ENV_CHANGED', FreshnessDecision::ENV_CHANGED);
        self::assertSame('DEBUG', FreshnessDecision::DEBUG);
    }
}
