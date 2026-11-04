<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;

/**
 * The outcome value object (design doc 16 §Comparison step 5, §Thresholds).
 */
final class ComparisonResultTest extends TestCase
{
    /** @test */
    public function passRateIsTheShareOfPassingCandidates(): void
    {
        $result = new ComparisonResult(
            ['a' => 'PASS', 'b' => 'PASS', 'c' => 'FAIL_VALUE', 'd' => 'PASS'],
            [self::failure('c', 'FAIL_VALUE')]
        );

        self::assertSame(0.75, $result->passRate());
        self::assertSame(3, $result->count(ComparisonResult::PASS));
        self::assertSame(1, $result->count(ComparisonResult::FAIL_VALUE));
        self::assertSame(0, $result->count(ComparisonResult::FAIL_ORDER));
    }

    /** @test */
    public function noCandidatesAndNoFailuresIsAPerfectRate(): void
    {
        $result = new ComparisonResult([], []);

        self::assertSame(1.0, $result->passRate());
        self::assertTrue($result->isIdentical());
    }

    /** @test */
    public function statusesKeepTheirOrder(): void
    {
        $result = new ComparisonResult(['z' => 'PASS', 'a' => 'FAIL_MISSING'], [self::failure('a', 'FAIL_MISSING')]);

        self::assertSame(['z', 'a'], \array_keys($result->statuses()));
    }

    /** @test */
    public function aRegionFailureDoesNotChangeThePassRateButIsNotIdentical(): void
    {
        $result = new ComparisonResult(['a' => 'PASS'], [self::failure('(theme) --x', 'FAIL_VALUE')]);

        self::assertSame(1.0, $result->passRate());
        self::assertFalse($result->isIdentical());
        self::assertCount(1, $result->failures());
    }

    /** @test */
    public function aParseFailureMakesEveryCandidateAnErrorAndTheRateZero(): void
    {
        $result = ComparisonResult::parseFailure(['a', 'b'], ['expected: E_CSS_SYNTAX line 1: broken']);

        self::assertSame(['a' => 'ERROR_PARSE', 'b' => 'ERROR_PARSE'], $result->statuses());
        self::assertSame(0.0, $result->passRate());
        self::assertTrue($result->hasParseErrors());
        self::assertFalse($result->isIdentical());
        self::assertSame(['expected: E_CSS_SYNTAX line 1: broken'], $result->diagnostics());
        self::assertSame(2, $result->count(ComparisonResult::ERROR_PARSE));
    }

    /** @test */
    public function aParseFailureWithNoCandidatesStillScoresZero(): void
    {
        $result = ComparisonResult::parseFailure([], ['actual: E_CSS_SYNTAX: broken']);

        self::assertSame(0.0, $result->passRate());
        self::assertFalse($result->isIdentical());
    }

    /** @test */
    public function summaryHasOneLinePerStatusThatOccursThenTheRate(): void
    {
        $result = new ComparisonResult(
            ['a' => 'PASS', 'b' => 'PASS', 'c' => 'FAIL_ORDER', 'd' => 'FAIL_MISSING', 'e' => 'PASS'],
            [self::failure('c', 'FAIL_ORDER'), self::failure('d', 'FAIL_MISSING')]
        );

        self::assertSame(
            "PASS 3\nFAIL_MISSING 1\nFAIL_ORDER 1\npass rate 3/5 (60.00 %)",
            $result->summary()
        );
    }

    /** @test */
    public function toArrayCarriesEverythingAsPlainData(): void
    {
        $failure = self::failure('c', 'FAIL_VALUE');
        $result = new ComparisonResult(['a' => 'PASS', 'c' => 'FAIL_VALUE'], [$failure]);

        self::assertSame(
            [
                'passRate' => 0.5,
                'counts' => [
                    'PASS' => 1,
                    'FAIL_MISSING' => 0,
                    'FAIL_VALUE' => 1,
                    'FAIL_ORDER' => 0,
                    'FAIL_EXTRA' => 0,
                    'ERROR_PARSE' => 0,
                ],
                'statuses' => ['a' => 'PASS', 'c' => 'FAIL_VALUE'],
                'failures' => [$failure],
                'diagnostics' => [],
            ],
            $result->toArray()
        );
    }

    /** @test */
    public function theStatusListIsTheSixDocumentedOnes(): void
    {
        self::assertSame(
            ['PASS', 'FAIL_MISSING', 'FAIL_VALUE', 'FAIL_ORDER', 'FAIL_EXTRA', 'ERROR_PARSE'],
            ComparisonResult::STATUSES
        );
    }

    /** @test */
    public function itIsFinalAndHasNoSetters(): void
    {
        $reflection = new ReflectionClass(ComparisonResult::class);

        self::assertTrue($reflection->isFinal());

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName());
        }
    }

    /**
     * @return array{candidate: string, status: string, expected: string, actual: string}
     */
    private static function failure(string $candidate, string $status): array
    {
        return ['candidate' => $candidate, 'status' => $status, 'expected' => '', 'actual' => ''];
    }
}
