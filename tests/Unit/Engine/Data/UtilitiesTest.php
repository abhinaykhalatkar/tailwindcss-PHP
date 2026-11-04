<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Data\Utilities;
use TailwindPhp\Engine\Data\Utilities\BackgroundsBorders;
use TailwindPhp\Engine\Data\Utilities\EffectsFilters;
use TailwindPhp\Engine\Data\Utilities\FlexboxGrid;
use TailwindPhp\Engine\Data\Utilities\InteractivitySvgAccessibility;
use TailwindPhp\Engine\Data\Utilities\Layout;
use TailwindPhp\Engine\Data\Utilities\SpacingSizing;
use TailwindPhp\Engine\Data\Utilities\TablesTransitionsTransforms;
use TailwindPhp\Engine\Data\Utilities\Typography;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The table merges the per-family parts in catalogue order (ENG-A2) and
 * refuses a root two parts define; every row is valid and holds plain data.
 */
final class UtilitiesTest extends TestCase
{
    /** @test */
    public function thePartsAreInDoc08OrderAndMergedInThatOrder(): void
    {
        self::assertSame(
            [
                Layout::class,
                FlexboxGrid::class,
                SpacingSizing::class,
                Typography::class,
                BackgroundsBorders::class,
                EffectsFilters::class,
                TablesTransitionsTransforms::class,
                InteractivitySvgAccessibility::class,
            ],
            Utilities::PARTS
        );

        // The families after Layout are stubs until their wave-4 task lands; whatever
        // they hold must come after Layout's rows in the merged table.
        $layout = \array_keys(Layout::rows());

        self::assertSame($layout, \array_slice(\array_keys(Utilities::table()), 0, \count($layout)));
    }

    /**
     * @test
     *
     * @dataProvider parts
     *
     * @param class-string $part
     */
    public function everyPartHasRowsAndAHandlerClass(string $part): void
    {
        $reflection = new ReflectionClass($part);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->hasMethod('rows'));
        self::assertIsArray($part::rows());
        self::assertTrue($reflection->hasConstant('HANDLERS'), $part . ' must name its handler class.');

        $handlers = $reflection->getConstant('HANDLERS');

        self::assertTrue($handlers === null || (\is_string($handlers) && \class_exists($handlers)), $part);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parts(): array
    {
        $rows = [];

        foreach (Utilities::PARTS as $part) {
            $rows[$part] = [$part];
        }

        return $rows;
    }

    /** @test */
    public function everyFamilyOfTheInventoryHasAPartExceptTheArbitraryProperty(): void
    {
        // doc 08 families, in order; the arbitrary property needs no part.
        $names = [];

        foreach (Utilities::PARTS as $part) {
            $names[] = (string) \substr($part, (int) \strrpos($part, '\\') + 1);
        }

        self::assertSame(
            ['Layout', 'FlexboxGrid', 'SpacingSizing', 'Typography', 'BackgroundsBorders', 'EffectsFilters',
                'TablesTransitionsTransforms', 'InteractivitySvgAccessibility'],
            $names
        );
        self::assertCount(\count(Inventory::FAMILIES) - 1, $names);
    }

    /** @test */
    public function aDuplicateRootAcrossPartsIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Utility root "flex" is defined by both Layout and Flexbox.');

        Utilities::merge([
            'Layout' => ['flex' => ['static' => ['display' => 'flex']]],
            'Flexbox' => ['flex' => ['props' => ['flex']]],
        ]);
    }

    /** @test */
    public function distinctRootsMergeInPartOrder(): void
    {
        $table = Utilities::merge([
            'A' => ['b' => ['static' => ['x' => 'y']], 'a' => ['static' => ['x' => 'z']]],
            'B' => ['c' => ['static' => ['x' => 'w']]],
        ]);

        self::assertSame(['b', 'a', 'c'], \array_keys($table));
    }

    /** @test */
    public function everyRowIsValid(): void
    {
        $registry = new Registry(Utilities::table());

        self::assertSame(\count(Utilities::table()), $registry->validateAll());
    }

    /**
     * No closures or objects in the data (R7, opcache): only scalars and arrays.
     *
     * @test
     */
    public function theTableHoldsPlainDataOnly(): void
    {
        $check = static function ($value) use (&$check): void {
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $check($item);
                }

                return;
            }

            self::assertTrue(\is_scalar($value), \gettype($value));
        };

        $check(Utilities::table());
    }
}
