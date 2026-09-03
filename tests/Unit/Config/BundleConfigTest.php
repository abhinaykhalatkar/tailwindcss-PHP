<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Config\BundleConfig;

/**
 * `BundleConfig` is a plain immutable view over the array `ConfigBuilder`
 * assembles, so it is built here directly from that same array shape: the value
 * object must hand back exactly what it was given, and nothing else.
 */
final class BundleConfigTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(BundleConfig::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyGetterRoundTripsItsValue(): void
    {
        $values = self::values();
        $bundle = new BundleConfig($values);

        self::assertSame('styles', $bundle->name());
        self::assertSame('/project/assets/styles/main.css', $bundle->input());
        self::assertSame('assets/styles/main.css', $bundle->inputRel());
        self::assertSame('assets/styles.css', $bundle->publicRoute());
        self::assertSame($values['input_roots'], $bundle->inputRoots());
        self::assertSame($values['source_roots'], $bundle->sourceRoots());
        self::assertSame(['css', 'scss'], $bundle->inputExtensions());
        self::assertSame(['twig', 'php'], $bundle->sourceExtensions());
        self::assertSame(['.git', 'node_modules'], $bundle->ignore());
        self::assertSame(['sr-only'], $bundle->safelist());
        self::assertSame(['debug-grid'], $bundle->blocklist());
        self::assertTrue($bundle->minify());
        self::assertSame($values['engine'], $bundle->engine());
        self::assertSame('scss', $bundle->preprocessor());
        self::assertSame(['style' => 'compressed'], $bundle->preprocessorOptions());
        self::assertSame($values['hash_input'], $bundle->hashInput());
    }

    /** @test */
    public function minifyReflectsTheStoredFlag(): void
    {
        $values = self::values();
        $values['minify'] = false;

        self::assertFalse((new BundleConfig($values))->minify());
    }

    /** @test */
    public function preprocessorIsNullWhenUnset(): void
    {
        $values = self::values();
        $values['preprocessor'] = null;

        self::assertNull((new BundleConfig($values))->preprocessor());
    }

    /** @test */
    public function preprocessorIsAStringWhenSet(): void
    {
        $values = self::values();
        $values['preprocessor'] = 'ScssPhp\\ScssPhp\\Compiler';

        $preprocessor = (new BundleConfig($values))->preprocessor();

        self::assertIsString($preprocessor);
        self::assertSame('ScssPhp\\ScssPhp\\Compiler', $preprocessor);
    }

    /**
     * The scanner builds its own value objects from these arrays, so the
     * `abs`/`rel`/`extensions` triple has to survive untouched.
     *
     * @test
     *
     * @dataProvider rootAccessors
     */
    public function theRootShapeSurvives(string $accessor, string $expectedRel): void
    {
        $bundle = new BundleConfig(self::values());

        /** @var list<array{abs: string, rel: string, extensions: list<string>}> $roots */
        $roots = $accessor === 'inputRoots' ? $bundle->inputRoots() : $bundle->sourceRoots();

        self::assertCount(1, $roots);

        $root = $roots[0];

        self::assertSame(['abs', 'rel', 'extensions'], \array_keys($root));
        self::assertSame('/project/' . $expectedRel, $root['abs']);
        self::assertSame($expectedRel, $root['rel']);
        self::assertIsArray($root['extensions']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rootAccessors(): array
    {
        return [
            'input roots' => ['inputRoots', 'assets/styles'],
            'source roots' => ['sourceRoots', 'templates'],
        ];
    }

    /**
     * The exact array `ConfigBuilder::buildBundle()` passes to the constructor.
     *
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        return [
            'name' => 'styles',
            'input' => '/project/assets/styles/main.css',
            'input_rel' => 'assets/styles/main.css',
            'public_route' => 'assets/styles.css',
            'input_roots' => [
                [
                    'abs' => '/project/assets/styles',
                    'rel' => 'assets/styles',
                    'extensions' => ['css', 'scss'],
                ],
            ],
            'source_roots' => [
                [
                    'abs' => '/project/templates',
                    'rel' => 'templates',
                    'extensions' => ['twig', 'php'],
                ],
            ],
            'input_extensions' => ['css', 'scss'],
            'source_extensions' => ['twig', 'php'],
            'ignore' => ['.git', 'node_modules'],
            'safelist' => ['sr-only'],
            'blocklist' => ['debug-grid'],
            'minify' => true,
            'engine' => [
                'prefix' => 'tw-',
                'important' => false,
                'preflight' => null,
            ],
            'preprocessor' => 'scss',
            'preprocessor_options' => ['style' => 'compressed'],
            'hash_input' => [
                'input_roots' => [
                    ['rel' => 'assets/styles', 'extensions' => ['css', 'scss']],
                ],
                'source_roots' => [
                    ['rel' => 'templates', 'extensions' => ['twig', 'php']],
                ],
                'ignore' => ['.git', 'node_modules'],
                'safelist' => ['sr-only'],
                'blocklist' => ['debug-grid'],
                'minify' => true,
                'engine' => [
                    'prefix' => 'tw-',
                    'important' => false,
                    'preflight' => null,
                ],
                'preprocessor' => 'scss',
                'preprocessor_options' => ['style' => 'compressed'],
            ],
        ];
    }
}
