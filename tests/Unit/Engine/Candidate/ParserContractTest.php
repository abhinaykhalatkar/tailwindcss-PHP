<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * The parser → compiler contract (ENG-A4, ENG-A13, review R-0001 finding 3).
 *
 * These rows pin the *complete* interpretation list — order included — for
 * the shapes where the reading is ambiguous: overlapping roots, fraction vs
 * modifier, static vs functional. TASK-0032 (utility registry and compiler)
 * reuses {@see self::contract()} unchanged as its input contract, so a change
 * here is a change of that contract.
 */
final class ParserContractTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider contract
     *
     * @param list<array<string, mixed>> $expected
     */
    public function interpretationListsArePinned(string $raw, array $expected): void
    {
        $parser = new Parser(self::roots());

        self::assertSame($expected, CandidateDump::fields($parser->parse($raw)));
    }

    /**
     * The roots the contract rows are written against.
     */
    public static function roots(): StaticRootSet
    {
        return new StaticRootSet(
            ['flex', 'text-center', 'border'],
            ['border', 'border-t', 'w', 'bg', 'text', 'flex']
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<array<string, mixed>>}>
     */
    public static function contract(): array
    {
        return [
            'border-t-red-500: longest root first, each with its own tail' => [
                'border-t-red-500',
                [
                    self::functional('border-t-red-500', 'border-t', self::named('red-500')),
                    self::functional('border-t-red-500', 'border', self::named('t-red-500')),
                ],
            ],
            'border-red-500: one root' => [
                'border-red-500',
                [self::functional('border-red-500', 'border', self::named('red-500'))],
            ],
            'border-t: exact root is static, then the shorter root' => [
                'border-t',
                [
                    self::row('border-t', Candidate::STATIC, 'border-t', null, null),
                    self::functional('border-t', 'border', self::named('t')),
                ],
            ],
            'w-1/2: modifier reading, then the fraction' => [
                'w-1/2',
                [
                    self::functional('w-1/2', 'w', self::named('1'), [Modifier::NAMED => '2']),
                    self::functional('w-1/2', 'w', [NamedValue::class => '1/2 (fraction)']),
                ],
            ],
            'bg-red-500/50: modifier reading, then the full value' => [
                'bg-red-500/50',
                [
                    self::functional('bg-red-500/50', 'bg', self::named('red-500'), [Modifier::NAMED => '50']),
                    self::functional('bg-red-500/50', 'bg', self::named('red-500/50')),
                ],
            ],
            'bg-red-500/[0.33]: an arbitrary modifier has no full-value reading' => [
                'bg-red-500/[0.33]',
                [self::functional('bg-red-500/[0.33]', 'bg', self::named('red-500'), [Modifier::ARBITRARY => '0.33'])],
            ],
            'text-xl/8: line-height modifier, then the full value' => [
                'text-xl/8',
                [
                    self::functional('text-xl/8', 'text', self::named('xl'), [Modifier::NAMED => '8']),
                    self::functional('text-xl/8', 'text', self::named('xl/8')),
                ],
            ],
            'flex: static' => ['flex', [self::row('flex', Candidate::STATIC, 'flex', null, null)]],
            'flex-1: functional on the same root' => [
                'flex-1',
                [self::functional('flex-1', 'flex', self::named('1'))],
            ],
            'text-center: static first, then functional' => [
                'text-center',
                [
                    self::row('text-center', Candidate::STATIC, 'text-center', null, null),
                    self::functional('text-center', 'text', self::named('center')),
                ],
            ],
            'text-red-500: functional only' => [
                'text-red-500',
                [self::functional('text-red-500', 'text', self::named('red-500'))],
            ],
            'w-[50%]: arbitrary value, single root' => [
                'w-[50%]',
                [self::functional('w-[50%]', 'w', [ArbitraryValue::class => '[50%]'])],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function named(string $name): array
    {
        return [NamedValue::class => $name];
    }

    /**
     * @param array<string, string> $value
     * @param array<string, string>|null $modifier
     *
     * @return array<string, mixed>
     */
    private static function functional(string $raw, string $root, array $value, ?array $modifier = null): array
    {
        return self::row($raw, Candidate::FUNCTIONAL, $root, $value, $modifier);
    }

    /**
     * @param array<string, string>|null $value
     * @param array<string, string>|null $modifier
     *
     * @return array<string, mixed>
     */
    private static function row(string $raw, string $kind, string $root, ?array $value, ?array $modifier): array
    {
        return [
            'kind' => $kind,
            'root' => $root,
            'value' => $value,
            'modifier' => $modifier,
            'important' => false,
            'negative' => false,
            'property' => null,
            'raw' => $raw,
        ];
    }
}
