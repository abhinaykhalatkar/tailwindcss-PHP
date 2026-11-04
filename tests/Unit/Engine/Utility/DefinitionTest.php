<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Utility\Definition;

/**
 * The row schema of design doc 07 §Definition schema, validated when a row is
 * built: a mistake in the table fails loudly instead of silently dropping a
 * utility.
 */
final class DefinitionTest extends TestCase
{
    /** @test */
    public function aStaticRowExposesItsDeclarations(): void
    {
        $definition = Definition::fromRow('block', ['static' => ['display' => 'block']]);

        self::assertFalse($definition->isFunctional());
        self::assertSame(['display' => 'block'], $definition->staticDeclarations());
        self::assertSame([$definition], $definition->definitions());
    }

    /** @test */
    public function aFunctionalRowExposesTypedAccessors(): void
    {
        $definition = Definition::fromRow('top', [
            'props' => ['top'],
            'named' => ['auto' => 'auto'],
            'theme' => ['inset'],
            'bare' => 'spacing-multiplier',
            'fraction' => true,
            'negative' => true,
            'arbitrary' => ['length', 'percentage'],
            'default' => '0',
            'order' => 'top',
            'modifier' => 'opacity',
        ]);

        self::assertTrue($definition->isFunctional());
        self::assertSame(['top'], $definition->props());
        self::assertSame(['auto' => 'auto'], $definition->named());
        self::assertSame(['inset'], $definition->theme());
        self::assertSame('spacing-multiplier', $definition->bare());
        self::assertTrue($definition->fraction());
        self::assertTrue($definition->negative());
        self::assertTrue($definition->acceptsArbitrary());
        self::assertSame(['length', 'percentage'], $definition->arbitraryTypes());
        self::assertSame('0', $definition->defaultValue());
        self::assertSame('top', $definition->order());
        self::assertSame('opacity', $definition->modifier());
        self::assertNull($definition->staticDeclarations());
    }

    /** @test */
    public function arbitraryTrueAcceptsAnyTypeAndFalseNone(): void
    {
        $any = Definition::fromRow('z', ['props' => ['z-index'], 'arbitrary' => true]);
        $none = Definition::fromRow('float', ['props' => ['float'], 'named' => ['left' => 'left']]);

        self::assertTrue($any->acceptsArbitrary());
        self::assertNull($any->arbitraryTypes());
        self::assertFalse($none->acceptsArbitrary());
    }

    /** @test */
    public function variantsBecomeOrderedSubDefinitionsSharingTheOrder(): void
    {
        $definition = Definition::fromRow('flex', [
            'variants' => [
                ['static' => ['display' => 'flex']],
                ['props' => ['flex'], 'bare' => 'integer'],
            ],
            'order' => 'display',
        ]);
        $subs = $definition->definitions();

        self::assertTrue($definition->isFunctional());
        self::assertCount(2, $subs);
        self::assertSame(['display' => 'flex'], $subs[0]->staticDeclarations());
        self::assertSame(['flex'], $subs[1]->props());
        self::assertSame('display', $subs[1]->order());
    }

    /** @test */
    public function aHandlerRowTakesAValueOnlyWhenTheHandlerDoes(): void
    {
        self::assertFalse(Definition::fromRow('container', ['handler' => 'container'])->isFunctional());
        $aspect = ['handler' => 'aspect', 'props' => ['aspect-ratio']];

        self::assertTrue(Definition::fromRow('aspect', $aspect)->isFunctional());
        self::assertFalse(Definition::rowIsFunctional(['static' => ['display' => 'flex']]));
        self::assertTrue(Definition::rowIsFunctional(['variants' => [['static' => ['a' => 'b']], ['props' => ['x']]]]));
    }

    /** @test */
    public function aCustomUtilityKeepsItsNodes(): void
    {
        $nodes = [new Declaration('tab-size', '4')];
        $definition = Definition::custom('tab-4', $nodes);

        self::assertSame($nodes, $definition->customNodes());
        self::assertFalse($definition->isFunctional());
    }

    /**
     * @test
     *
     * @dataProvider invalidRows
     *
     * @param array<mixed> $row
     */
    public function invalidRowsAreRefusedAtBuildTime(string $root, array $row, string $problem): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($problem);

        Definition::fromRow($root, $row);
    }

    /**
     * @return array<string, array{0: string, 1: array<mixed>, 2: string}>
     */
    public static function invalidRows(): array
    {
        return [
            'unknown key' => ['p', ['props' => ['padding'], 'colour' => true], 'unknown key "colour"'],
            'static with props' => [
                'x',
                ['static' => ['a' => 'b'], 'props' => ['c']],
                'a static definition takes no "props"',
            ],
            'empty static' => ['x', ['static' => []], 'at least one declaration'],
            'static value not a string' => ['x', ['static' => ['a' => 1]], 'must map strings to strings'],
            'props not a list' => ['x', ['props' => ['a' => 'b']], '"props" must be a list of strings'],
            'unknown bare kind' => ['x', ['props' => ['a'], 'bare' => 'decimal'], '"bare" must be one of'],
            'unknown modifier' => ['x', ['props' => ['a'], 'modifier' => 'alpha'], '"modifier" must be one of'],
            'fraction not a bool' => ['x', ['props' => ['a'], 'fraction' => 'yes'], '"fraction" must be a boolean'],
            'unknown data type' => ['x', ['props' => ['a'], 'arbitrary' => ['colour']], 'unknown arbitrary data type'],
            'empty data type list' => ['x', ['props' => ['a'], 'arbitrary' => []], 'non-empty list of data types'],
            'unknown handler' => ['x', ['handler' => 'magic'], 'unknown handler'],
            'functional without props or handler' => ['x', ['bare' => 'integer'], 'needs "props" or a "handler"'],
            'order not in the property order' => [
                'x',
                ['props' => ['a'], 'order' => 'nonsense'],
                'not in Data\PropertyOrder',
            ],
            'empty default' => ['x', ['props' => ['a'], 'default' => ''], '"default" must be a non-empty string'],
            // Round 2: an explicit null is a mistake, not an absent key.
            'null default' => ['x', ['props' => ['a'], 'default' => null], '"default" must be a non-empty string'],
            'null handler' => ['x', ['props' => ['a'], 'handler' => null], 'unknown handler'],
            'null bare' => ['x', ['props' => ['a'], 'bare' => null], '"bare" must be a non-empty string'],
            // Round 3: every key, not only the string ones.
            'null variants' => ['x', ['variants' => null], '"variants" must be a non-empty list of rows'],
            'null arbitrary' => ['x', ['props' => ['a'], 'arbitrary' => null], '"arbitrary" must be a list of strings'],
            'null theme' => ['x', ['props' => ['a'], 'theme' => null], '"theme" must be a list of strings'],
            'null props' => ['x', ['props' => null, 'handler' => 'aspect'], '"props" must be a list of strings'],
            'null named' => ['x', ['props' => ['a'], 'named' => null], '"named" must map strings to strings'],
            'null fraction' => ['x', ['props' => ['a'], 'fraction' => null], '"fraction" must be a boolean'],
            'null negative' => ['x', ['props' => ['a'], 'negative' => null], '"negative" must be a boolean'],
            'null static' => ['x', ['static' => null], '"static" must map strings to strings'],
            'key next to variants' => [
                'x',
                ['variants' => [['props' => ['a']]], 'props' => ['b']],
                'cannot sit next to "variants"',
            ],
            'empty variants' => ['x', ['variants' => []], 'non-empty list of rows'],
            'nested variants' => ['x', ['variants' => [['variants' => []]]], 'takes neither'],
            // An explicit null inside a sub-row is still a "variants" key, as it is at the top level.
            'nested null variants' => [
                'x',
                ['variants' => [['props' => ['display'], 'variants' => null]]],
                'takes neither',
            ],
            'nested null order' => [
                'x',
                ['variants' => [['props' => ['display'], 'order' => null]]],
                'takes neither',
            ],
            'properties not --tw-' => ['x', ['static' => ['a' => 'b'], 'properties' => ['--x']], 'properties'],
            'properties with an upper-case name' => [
                'x',
                ['props' => ['a'], 'properties' => ['--tw-Shadow']],
                'properties',
            ],
            'properties not a list' => ['x', ['props' => ['a'], 'properties' => '--tw-x'], 'properties'],
            'variant not a row' => ['x', ['variants' => ['flex']], 'must be a row'],
            'upper-case root' => ['Flex', ['static' => ['display' => 'flex']], 'Invalid utility root'],
            'root with a double dash' => ['a--b', ['static' => ['a' => 'b']], 'Invalid utility root'],
            'root ending in a dash' => ['a-', ['static' => ['a' => 'b']], 'Invalid utility root'],
            'empty root' => ['', ['static' => ['a' => 'b']], 'Invalid utility root'],
        ];
    }

    /** @test */
    public function propertiesToRegisterAreExposedForStaticAndFunctionalRows(): void
    {
        $static = Definition::fromRow('x', ['static' => ['a' => 'b'], 'properties' => ['--tw-a', '--tw-a']]);
        $functional = Definition::fromRow('y', ['props' => ['a'], 'properties' => ['--tw-b-1']]);
        $plain = Definition::fromRow('z', ['props' => ['a']]);

        self::assertSame(['--tw-a'], $static->properties());
        self::assertSame(['--tw-b-1'], $functional->properties());
        self::assertSame([], $plain->properties());
        self::assertTrue(Definition::isRegistrableProperty('--tw-shadow-color'));
        self::assertFalse(Definition::isRegistrableProperty('--tw-'));
        self::assertFalse(Definition::isRegistrableProperty('--shadow'));
        self::assertFalse(Definition::isRegistrableProperty('color'));
    }
}
