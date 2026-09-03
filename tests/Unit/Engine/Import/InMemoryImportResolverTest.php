<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Import;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The compiler's import tests are only as trustworthy as the resolver they
 * inject: a double that folded `..` wrongly would make a passing import test
 * mean nothing, so its lexical rules are pinned here.
 */
final class InMemoryImportResolverTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'a/b.css' => '.b{}',
        'a/deep/d.css' => '.d{}',
        'c.css' => '.c{}',
    ];

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(InMemoryImportResolver::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider resolutionCases
     */
    public function resolvesSpecifiersLexically(string $spec, string $from, ?string $expected): void
    {
        $resolved = $this->resolver()->resolve($spec, $from);

        if ($expected === null) {
            self::assertNull($resolved);

            return;
        }

        self::assertNotNull($resolved);
        self::assertSame($expected, $resolved->relPath());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function resolutionCases(): array
    {
        return [
            'sibling via ./' => ['./b.css', 'a/x.css', 'a/b.css'],
            'sibling without a prefix' => ['b.css', 'a/x.css', 'a/b.css'],
            'parent via ../' => ['../c.css', 'a/x.css', 'c.css'],
            'subdirectory' => ['./deep/d.css', 'a/x.css', 'a/deep/d.css'],
            'extension appended' => ['./b', 'a/x.css', 'a/b.css'],
            'extension appended after ..' => ['../c', 'a/x.css', 'c.css'],
            'importer at the project root' => ['./c.css', 'x.css', 'c.css'],
            'redundant . segments' => ['./././b.css', 'a/x.css', 'a/b.css'],
            'down then back up' => ['./deep/../b.css', 'a/x.css', 'a/b.css'],
            'unknown key' => ['./nope.css', 'a/x.css', null],
            'unknown key in a known directory' => ['../a/nope.css', 'a/x.css', null],
            'bare package name' => ['tailwindcss', 'a/x.css', null],
            'climbs above the project root' => ['../../c.css', 'a/x.css', null],
        ];
    }

    /** @test */
    public function returnsTheMappedCssAlongsideTheNormalizedPath(): void
    {
        $resolved = $this->resolver()->resolve('./b.css', 'a/x.css');

        self::assertNotNull($resolved);
        self::assertSame('a/b.css', $resolved->relPath());
        self::assertSame(self::MAP['a/b.css'], $resolved->css());
    }

    /** @test */
    public function resolvedIsEmptyBeforeAnythingIsAsked(): void
    {
        self::assertSame([], $this->resolver()->resolved());
    }

    /**
     * The order is the point: it is how a test sees the shape of the compiler's
     * walk, which the emitted CSS does not show.
     *
     * @test
     */
    public function resolvedRecordsEverySpecifierInOrderIncludingMisses(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve('./b.css', 'a/x.css');
        $resolver->resolve('./missing.css', 'a/x.css');
        $resolver->resolve('../c.css', 'a/x.css');
        $resolver->resolve('./b.css', 'a/x.css');

        self::assertSame(
            ['./b.css', './missing.css', '../c.css', './b.css'],
            $resolver->resolved()
        );
    }

    /**
     * Specifiers are recorded exactly as written, not as normalized: a test
     * asserting on the walk wants to see what the stylesheet said.
     *
     * @test
     */
    public function resolvedRecordsTheSpecifierNotTheResolvedPath(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve('./b', 'a/x.css');

        self::assertSame(['./b'], $resolver->resolved());
    }

    /** @test */
    public function anEmptyMapResolvesNothing(): void
    {
        $resolver = new InMemoryImportResolver([]);

        self::assertNull($resolver->resolve('./b.css', 'a/x.css'));
        self::assertSame(['./b.css'], $resolver->resolved());
    }

    private function resolver(): InMemoryImportResolver
    {
        return new InMemoryImportResolver(self::MAP);
    }
}
