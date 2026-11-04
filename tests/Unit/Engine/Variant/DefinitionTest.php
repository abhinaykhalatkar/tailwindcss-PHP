<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Variant;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Variant\Definition;

/**
 * One row of `Data\Variants::table()` as a validated value (design doc 07 §Phase 3c).
 *
 * A bad row is a programming error in the hand-maintained table, so it fails
 * loudly at build time with an exception, never as a runtime diagnostic.
 */
final class DefinitionTest extends TestCase
{
    /** @test */
    public function aSelectorRowKeepsItsParts(): void
    {
        $definition = Definition::fromRow('hover', [
            'selector' => '&:hover',
            'media' => '(hover: hover)',
            'order' => 112000,
        ]);

        self::assertSame('hover', $definition->name());
        self::assertSame(['&:hover'], $definition->selectors());
        self::assertSame('(hover: hover)', $definition->media());
        self::assertNull($definition->supports());
        self::assertNull($definition->container());
        self::assertNull($definition->compound());
        self::assertSame([], $definition->inject());
        self::assertSame(112000, $definition->order());
        self::assertFalse($definition->isFunctional());
    }

    /** @test */
    public function aMediaOnlyRowHasNoSelector(): void
    {
        $definition = Definition::fromRow('print', ['media' => 'print', 'order' => 160000]);

        self::assertSame([], $definition->selectors());
        self::assertSame('print', $definition->media());
    }

    /** @test */
    public function aSelectorMayBeAListOfSelectors(): void
    {
        $definition = Definition::fromRow('selection', [
            'selector' => ['& *::selection', '&::selection'],
            'order' => 78000,
        ]);

        self::assertSame(['& *::selection', '&::selection'], $definition->selectors());
    }

    /** @test */
    public function anInjectRowKeepsTheDeclarations(): void
    {
        $definition = Definition::fromRow('before', [
            'selector' => '&::before',
            'inject' => ['content' => 'var(--tw-content)'],
            'order' => 83000,
        ]);

        self::assertSame(['content' => 'var(--tw-content)'], $definition->inject());
    }

    /** @test */
    public function aFunctionalRowKeepsItsTemplateAndThemeSource(): void
    {
        $definition = Definition::fromRow('min', [
            'functional' => 'min',
            'media' => '(width >= {value})',
            'theme' => 'breakpoint',
            'order' => 144000,
        ]);

        self::assertTrue($definition->isFunctional());
        self::assertSame('min', $definition->functional());
        self::assertSame('breakpoint', $definition->theme());
        self::assertSame('(width >= {value})', $definition->media());
    }

    /** @test */
    public function aCompoundTemplateRowIsAcceptedWithItsInnerPlaceholder(): void
    {
        $definition = Definition::fromRow('group', [
            'compound' => 'group',
            'selector' => '&:is({inner} *)',
            'order' => 4000,
        ]);

        self::assertSame('group', $definition->compound());
        self::assertSame(['&:is({inner} *)'], $definition->selectors());
    }

    /** @test */
    public function anExpandedCompoundRowRemembersItsInnerSelector(): void
    {
        $definition = Definition::fromRow('group-hover', [
            'compound' => 'group',
            'inner' => '&:hover',
            'selector' => '&:is(:where(.group):hover *)',
            'media' => '(hover: hover)',
            'order' => 4113,
        ]);

        self::assertSame('&:hover', $definition->inner());
        self::assertSame('group', $definition->compound());
    }

    /**
     * @test
     *
     * @dataProvider badRows
     *
     * @param array<string, mixed> $row
     */
    public function aBadRowIsAProgrammingError(string $name, array $row): void
    {
        $this->expectException(InvalidArgumentException::class);

        Definition::fromRow($name, $row);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function badRows(): array
    {
        return [
            'an unknown key' => ['x', ['selector' => '&:x', 'order' => 1, 'colour' => 'red']],
            'a typo of a known key' => ['x', ['selecter' => '&:x', 'order' => 1]],
            'no order' => ['x', ['selector' => '&:x']],
            'a string order' => ['x', ['selector' => '&:x', 'order' => '1']],
            'a negative order' => ['x', ['selector' => '&:x', 'order' => -1]],
            'an empty row' => ['x', ['order' => 1]],
            'an empty name' => ['', ['selector' => '&:x', 'order' => 1]],
            'a name with a space' => ['a b', ['selector' => '&:x', 'order' => 1]],
            'a selector without an ampersand' => ['x', ['selector' => ':hover', 'order' => 1]],
            'an empty selector' => ['x', ['selector' => '', 'order' => 1]],
            'an empty selector list' => ['x', ['selector' => [], 'order' => 1]],
            'a non-string in the selector list' => ['x', ['selector' => ['&:x', 3], 'order' => 1]],
            'a selector that is not a string' => ['x', ['selector' => 3, 'order' => 1]],
            'an empty media query' => ['x', ['media' => '', 'order' => 1]],
            'a media query that is not a string' => ['x', ['media' => true, 'order' => 1]],
            'an empty supports' => ['x', ['supports' => '', 'order' => 1]],
            'an empty container' => ['x', ['container' => '', 'order' => 1]],
            'inject that is not a map' => ['x', ['selector' => '&:x', 'inject' => ['a'], 'order' => 1]],
            'an inject value that is not a string' => [
                'x',
                ['selector' => '&:x', 'inject' => ['a' => 1], 'order' => 1],
            ],
            'an empty inject property' => ['x', ['selector' => '&:x', 'inject' => ['' => 'a'], 'order' => 1]],
            'a compound that is not a string' => ['x', ['selector' => '&:x', 'compound' => 1, 'order' => 1]],
            'an empty compound' => ['x', ['selector' => '&:x', 'compound' => '', 'order' => 1]],
            'inner without a compound' => ['x', ['selector' => '&:x', 'inner' => '&:y', 'order' => 1]],
            'an inner without an ampersand' => [
                'x',
                ['selector' => '&:x', 'compound' => 'group', 'inner' => ':y', 'order' => 1],
            ],
            'a functional that is not a string' => ['x', ['functional' => 1, 'media' => '(a {value})', 'order' => 1]],
            'a functional row without a placeholder' => [
                'x',
                ['functional' => 'x', 'media' => '(width >= 1px)', 'order' => 1],
            ],
            'a theme that is not a string' => [
                'x',
                ['functional' => 'x', 'media' => '({value})', 'theme' => 1, 'order' => 1],
            ],
            'named that is not a list' => [
                'x',
                ['functional' => 'x', 'media' => '({value})', 'named' => 'a', 'order' => 1],
            ],
            'a non-string in named' => [
                'x',
                ['functional' => 'x', 'media' => '({value})', 'named' => [1], 'order' => 1],
            ],
        ];
    }

    /** @test */
    public function aBadRowNamesTheVariantButNeverEchoesAValue(): void
    {
        try {
            Definition::fromRow('hover', ['selector' => ':no-amp-here-SECRET', 'order' => 1]);
            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('hover', $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    /** @test */
    public function itIsFinalAndImmutable(): void
    {
        $reflection = new ReflectionClass(Definition::class);

        self::assertTrue($reflection->isFinal());

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isPrivate(), $property->getName() . ' must be private.');
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName());
        }
    }
}
