<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\RootSetInterface;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * The root trie (doc 13 #16) and the definitions behind it. Its matching is
 * checked against `tests/Support/StaticRootSet`, the reference behaviour of
 * {@see RootSetInterface} the candidate parser was built against.
 */
final class RegistryTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider utilities
     *
     * @param list<string> $expected
     */
    public function rootsMatchLongestFirst(string $utility, array $expected): void
    {
        self::assertSame($expected, self::registry()->matchRoots($utility));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function utilities(): array
    {
        return [
            'a static root' => ['block', ['block']],
            'a static root is never a prefix' => ['block-4', []],
            'a functional root used alone' => ['border', ['border']],
            'a functional root with a value' => ['border-2', ['border']],
            'a longer root first' => ['border-t-red-500', ['border-t', 'border']],
            'a root that is both' => ['flex', ['flex']],
            'both, with a value' => ['flex-1', ['flex']],
            // A functional root alone is a root; the compiler refuses it when
            // the row has no `default`.
            'the bg edge: the root alone' => ['bg', ['bg']],
            'the bg edge: an empty value still splits' => ['bg-', ['bg']],
            'the bg edge: a value' => ['bg-red-500', ['bg']],
            'a value with dashes' => ['bg-red-500-x', ['bg']],
            'a bracketed value with dashes' => ['top-[calc(1px-2px)]', ['top']],
            'a double dash' => ['top--1', ['top']],
            'unknown' => ['nothing-here', []],
            'a static root after a functional prefix' => ['table-cell', ['table-cell']],
            'deep roots' => ['inset-x-0', ['inset-x', 'inset']],
        ];
    }

    /**
     * The trie agrees with the reference root set on every probe.
     *
     * @test
     */
    public function matchingAgreesWithTheReferenceRootSet(): void
    {
        $reference = new StaticRootSet(
            ['block', 'table', 'table-cell', 'flex', 'border'],
            ['bg', 'border', 'border-t', 'flex', 'top', 'inset', 'inset-x']
        );
        $registry = self::registry();
        $probes = [
            'block', 'block-4', 'table', 'table-cell', 'table-cell-x', 'flex', 'flex-1', 'flex-', 'border',
            'border-t', 'border-t-2', 'border-x', 'bg', 'bg-', 'bg--', 'bg-red-500', 'top', 'top-4', 'top--4',
            'inset-x', 'inset-x-4', 'inset-y-4', '', '-', 'x',
        ];

        foreach ($probes as $probe) {
            self::assertSame($reference->matchRoots($probe), $registry->matchRoots($probe), $probe);
        }
    }

    /** @test */
    public function definitionsAreBuiltOnFirstUseAndValidatedOnRequest(): void
    {
        $registry = self::registry();

        self::assertCount(2, $registry->definitions('flex'));
        self::assertSame([], $registry->definitions('unknown'));
        self::assertTrue($registry->has('block'));
        self::assertFalse($registry->has('blocks'));
        self::assertSame(\count($registry->roots()), $registry->validateAll());

        $broken = new Registry(['p' => ['props' => ['padding'], 'oops' => true]]);

        $this->expectException(\InvalidArgumentException::class);
        $broken->validateAll();
    }

    /** @test */
    public function aCustomUtilityIsAddedAsAStaticRoot(): void
    {
        $registry = self::registry();
        $registry->addCustom('tab-4', [new Declaration('tab-size', '4')]);

        self::assertSame(['tab-4'], $registry->matchRoots('tab-4'));
        self::assertSame([], $registry->matchRoots('tab-4-x'));
        self::assertCount(1, $registry->definitions('tab-4'));
        self::assertContains('tab-4', $registry->roots());

        // Added after a built-in root of the same name: tried after it.
        $registry->addCustom('block', [new Declaration('display', 'grid')]);

        self::assertCount(2, $registry->definitions('block'));
        self::assertNotNull($registry->definitions('block')[1]->customNodes());
    }

    /** @test */
    public function anInvalidCustomRootIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::registry()->addCustom('Not A Root', []);
    }

    /** @test */
    public function theDefaultRegistryHoldsTheBuiltInTable(): void
    {
        $registry = Registry::default();

        self::assertTrue($registry->has('container'));
        self::assertSame(['inset-x', 'inset'], $registry->matchRoots('inset-x-4'));
        self::assertGreaterThanOrEqual(60, $registry->validateAll());
    }

    private static function registry(): Registry
    {
        return new Registry([
            'block' => ['static' => ['display' => 'block']],
            'table' => ['static' => ['display' => 'table']],
            'table-cell' => ['static' => ['display' => 'table-cell']],
            'flex' => [
                'variants' => [
                    ['static' => ['display' => 'flex']],
                    ['props' => ['flex'], 'bare' => 'integer'],
                ],
            ],
            'border' => ['props' => ['border-width'], 'default' => '1px', 'bare' => 'integer'],
            'border-t' => ['props' => ['border-top-width'], 'bare' => 'integer'],
            'bg' => ['props' => ['background-color'], 'theme' => ['color']],
            'top' => ['props' => ['top'], 'bare' => 'spacing-multiplier'],
            'inset' => ['props' => ['inset'], 'bare' => 'spacing-multiplier'],
            'inset-x' => ['props' => ['inset-inline'], 'bare' => 'spacing-multiplier'],
        ]);
    }
}
