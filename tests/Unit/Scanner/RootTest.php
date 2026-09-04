<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Scanner\Root;

final class RootTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Root::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function exposesEveryConstructorValue(): void
    {
        $root = new Root('/project/assets/styles', 'assets/styles', Root::INPUT, ['css', 'scss']);

        self::assertSame('/project/assets/styles', $root->abs());
        self::assertSame('assets/styles', $root->rel());
        self::assertSame(Root::INPUT, $root->kind());
        self::assertSame(['css', 'scss'], $root->extensions());
    }

    /** @test */
    public function sourceKindIsAccepted(): void
    {
        $root = new Root('/project/templates', 'templates', Root::SOURCE, ['twig']);

        self::assertSame(Root::SOURCE, $root->kind());
    }

    /** @test */
    public function rejectsAnUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Root('/project/x', 'x', 'bogus', []);
    }

    /** @test */
    public function extensionsAreReindexedToAList(): void
    {
        $root = new Root('/project/x', 'x', Root::INPUT, [3 => 'css', 7 => 'scss']);

        self::assertSame(['css', 'scss'], $root->extensions());
    }

    /**
     * @test
     *
     * @dataProvider extensionCases
     */
    public function hasExtensionMatchesExactlyTheConfiguredList(string $extension, bool $expected): void
    {
        $root = new Root('/project/x', 'x', Root::INPUT, ['css', 'scss']);

        self::assertSame($expected, $root->hasExtension($extension));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function extensionCases(): array
    {
        return [
            'present' => ['css', true],
            'also present' => ['scss', true],
            'absent' => ['twig', false],
            'case sensitive' => ['CSS', false],
        ];
    }
}
