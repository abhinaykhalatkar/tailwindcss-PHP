<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\Utilities;
use TailwindPhp\Engine\Data\Utilities\Layout;
use TailwindPhp\Engine\Utility\Registry;

/**
 * The table merges the per-family parts in catalogue order (ENG-A2) and
 * refuses a root two parts define; every row is valid and holds plain data.
 */
final class UtilitiesTest extends TestCase
{
    /** @test */
    public function thePartsAreMergedInOrder(): void
    {
        self::assertSame([Layout::class], Utilities::PARTS);
        self::assertSame(\array_keys(Layout::rows()), \array_keys(Utilities::table()));
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
