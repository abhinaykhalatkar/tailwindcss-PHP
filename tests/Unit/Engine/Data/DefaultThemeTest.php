<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Data\DefaultTheme;

/**
 * The generated default theme (decision ENG-A5, review R-0001 finding 4).
 *
 * `DefaultTheme` is produced mechanically from the pinned CLI, which makes a
 * test that merely re-reads it circular. This one is the independent half: the
 * expectations below were written by hand from the Tailwind v4 documentation of
 * each theme namespace (design doc 07 §Phase 2), so a generator bug — or an
 * upstream change at the next pinned bump — shows up as a disagreement between
 * two sources instead of passing silently.
 *
 * No I/O: the class is plain arrays.
 */
final class DefaultThemeTest extends TestCase
{
    // ------------------------------------------------------------ the shape

    /** @test */
    public function itIsAFinalNonInstantiableDataClass(): void
    {
        $reflection = new ReflectionClass(DefaultTheme::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame('4.1.18', DefaultTheme::VERSION);
    }

    /** @test */
    public function thereAreAtLeastThreeHundredVariablesAndEveryOneIsACustomProperty(): void
    {
        $vars = DefaultTheme::vars();

        self::assertGreaterThanOrEqual(300, \count($vars));

        foreach ($vars as $name => $value) {
            self::assertSame('--', \substr((string) $name, 0, 2), (string) $name);
            self::assertIsString($value);
            self::assertNotSame('', \trim($value), $name . ' has no value');
            self::assertStringNotContainsString("\n", $value, $name . ' spans lines');
        }
    }

    /** @test */
    public function variablesKeepTheOrderTheThemeDeclaresThem(): void
    {
        $names = \array_keys(DefaultTheme::vars());

        // Fonts come first in theme.css, then the colour palette, then spacing.
        self::assertSame('--font-sans', $names[0]);
        self::assertLessThan(\array_search('--color-red-50', $names, true), \array_search('--font-sans', $names, true));
        self::assertLessThan(\array_search('--spacing', $names, true), \array_search('--color-red-50', $names, true));
    }

    // ------------------------------------------- hand-written, by namespace

    /**
     * Every namespace of doc 07 §Phase 2 with several sample variables each.
     * A value ending in `*` is a prefix check (long lists whose tail is
     * irrelevant); anything else must match exactly.
     *
     * @test
     *
     * @dataProvider expectedVariables
     */
    public function theDocumentedNamespacesAreAllThere(string $name, string $expected): void
    {
        $vars = DefaultTheme::vars();

        self::assertArrayHasKey($name, $vars);

        if (\substr($expected, -1) === '*') {
            $prefix = \substr($expected, 0, -1);

            if ($prefix === '') {
                self::fail('A prefix expectation needs something before the star.');
            }

            self::assertStringStartsWith($prefix, $vars[$name], $name);

            return;
        }

        self::assertSame($expected, $vars[$name], $name);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function expectedVariables(): array
    {
        $rows = [
            // --color-*
            ['--color-red-500', 'oklch(63.7% 0.237 25.331)'],
            ['--color-red-50', 'oklch(*'],
            ['--color-slate-950', 'oklch(*'],
            ['--color-blue-500', 'oklch(*'],
            ['--color-black', '#000'],
            ['--color-white', '#fff'],
            // --font-*
            ['--font-sans', 'ui-sans-serif, system-ui, sans-serif,*'],
            ['--font-serif', 'ui-serif, Georgia, Cambria,*'],
            ['--font-mono', 'ui-monospace, SFMono-Regular, Menlo,*'],
            // --text-* with their line-height companions
            ['--text-xs', '0.75rem'],
            ['--text-xs--line-height', 'calc(1 / 0.75)'],
            ['--text-xl', '1.25rem'],
            ['--text-xl--line-height', 'calc(1.75 / 1.25)'],
            ['--text-9xl', '8rem'],
            ['--text-9xl--line-height', '1'],
            // --font-weight-*
            ['--font-weight-thin', '100'],
            ['--font-weight-normal', '400'],
            ['--font-weight-bold', '700'],
            ['--font-weight-black', '900'],
            // --tracking-*, --leading-*
            ['--tracking-tight', '-0.025em'],
            ['--tracking-wide', '0.025em'],
            ['--leading-tight', '1.25'],
            ['--leading-normal', '1.5'],
            ['--leading-loose', '2'],
            // --breakpoint-*
            ['--breakpoint-sm', '40rem'],
            ['--breakpoint-md', '48rem'],
            ['--breakpoint-lg', '64rem'],
            ['--breakpoint-xl', '80rem'],
            ['--breakpoint-2xl', '96rem'],
            // --container-*
            ['--container-xs', '20rem'],
            ['--container-lg', '32rem'],
            ['--container-7xl', '80rem'],
            // --spacing
            ['--spacing', '0.25rem'],
            // --radius-*
            ['--radius-xs', '0.125rem'],
            ['--radius-sm', '0.25rem'],
            ['--radius-lg', '0.5rem'],
            ['--radius-4xl', '2rem'],
            // --shadow-*, --inset-shadow-*, --drop-shadow-*
            ['--shadow-2xs', '0 1px rgb(0 0 0 / 0.05)'],
            ['--shadow-sm', '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)'],
            ['--inset-shadow-2xs', 'inset 0 1px rgb(0 0 0 / 0.05)'],
            ['--inset-shadow-sm', 'inset 0 2px 4px rgb(0 0 0 / 0.05)'],
            ['--drop-shadow-md', '0 3px 3px rgb(0 0 0 / 0.12)'],
            ['--drop-shadow-2xl', '0 25px 25px rgb(0 0 0 / 0.15)'],
            // --blur-*
            ['--blur-xs', '4px'],
            ['--blur-md', '12px'],
            ['--blur-3xl', '64px'],
            // --ease-*
            ['--ease-in', 'cubic-bezier(0.4, 0, 1, 1)'],
            ['--ease-out', 'cubic-bezier(0, 0, 0.2, 1)'],
            ['--ease-in-out', 'cubic-bezier(0.4, 0, 0.2, 1)'],
            // --animate-*
            ['--animate-spin', 'spin 1s linear infinite'],
            ['--animate-ping', 'ping 1s cubic-bezier(0, 0, 0.2, 1) infinite'],
            ['--animate-pulse', 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite'],
            ['--animate-bounce', 'bounce 1s infinite'],
            // --aspect-*, --perspective-*
            ['--aspect-video', '16 / 9'],
            ['--perspective-dramatic', '100px'],
            ['--perspective-normal', '500px'],
            // the defaults the base layer reads
            ['--default-font-family', 'var(--font-sans)'],
            ['--default-mono-font-family', 'var(--font-mono)'],
        ];
        $cases = [];

        foreach ($rows as $row) {
            $cases[$row[0]] = $row;
        }

        return $cases;
    }

    /** @test */
    public function everyShadeOfTheRedPaletteIsDeclared(): void
    {
        $vars = DefaultTheme::vars();

        foreach (['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'] as $shade) {
            self::assertArrayHasKey('--color-red-' . $shade, $vars);
        }
    }

    /** @test */
    public function theTwentyTwoDefaultColoursAreDeclaredAtShade500(): void
    {
        $names = [
            'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue',
            'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'slate', 'gray', 'zinc', 'neutral', 'stone',
        ];
        $vars = DefaultTheme::vars();

        self::assertCount(22, $names);

        foreach ($names as $name) {
            self::assertArrayHasKey('--color-' . $name . '-500', $vars, $name);
        }
    }

    // ----------------------------------------------------------- keyframes

    /** @test */
    public function theFourDefaultAnimationsHaveKeyframes(): void
    {
        $keyframes = DefaultTheme::keyframes();

        self::assertSame(['spin', 'ping', 'pulse', 'bounce'], \array_keys($keyframes));

        foreach ($keyframes as $name => $css) {
            self::assertStringStartsWith('@keyframes ' . $name . ' {', $css);
            self::assertSame('}', \substr($css, -1), $name);
            self::assertSame(\substr_count($css, '{'), \substr_count($css, '}'), $name . ' is unbalanced');
        }

        self::assertStringContainsString('rotate(360deg)', $keyframes['spin']);
        self::assertStringContainsString('scale(2)', $keyframes['ping']);
        self::assertStringContainsString('opacity: 0.5', $keyframes['pulse']);
        self::assertStringContainsString('translateY(-25%)', $keyframes['bounce']);
    }

    /** @test */
    public function everyAnimateVariableNamesAKeyframesBlockThatExists(): void
    {
        $keyframes = DefaultTheme::keyframes();

        foreach (['spin', 'ping', 'pulse', 'bounce'] as $name) {
            self::assertStringStartsWith($name . ' ', DefaultTheme::vars()['--animate-' . $name]);
            self::assertArrayHasKey($name, $keyframes);
        }
    }
}
