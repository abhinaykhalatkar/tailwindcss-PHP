<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\ThemeNamespaces;

/**
 * The namespaces a broader namespace must not match (v4's
 * `ignoredThemeKeyMap`, read from the bundled source of the pinned CLI).
 */
final class ThemeNamespacesTest extends TestCase
{
    /** @test */
    public function theMapIsTheFiveEntriesOfTheCli(): void
    {
        self::assertSame(
            [
                'font' => ['font-weight', 'font-size'],
                'inset' => ['inset-shadow', 'inset-ring'],
                'text' => [
                    'text-color',
                    'text-decoration-color',
                    'text-decoration-thickness',
                    'text-indent',
                    'text-shadow',
                    'text-underline-offset',
                ],
                'grid-column' => ['grid-column-start', 'grid-column-end'],
                'grid-row' => ['grid-row-start', 'grid-row-end'],
            ],
            ThemeNamespaces::IGNORED
        );
    }

    /**
     * Every ignored namespace is a sub-namespace of the one that ignores it:
     * its name starts with that namespace and a dash (so a lookup in the
     * broader namespace could otherwise reach it).
     *
     * @test
     */
    public function everyIgnoredNamespaceSitsInsideItsParent(): void
    {
        foreach (ThemeNamespaces::IGNORED as $namespace => $ignored) {
            self::assertNotSame([], $ignored, $namespace);

            foreach ($ignored as $name) {
                self::assertStringStartsWith($namespace . '-', $name, $namespace);
                self::assertSame($name, \strtolower($name), 'lower-case namespace names');
            }
        }
    }

    /**
     * The map matters because the default theme has variables both namespaces
     * could claim: `--font-weight-bold` is in `font-weight`, not in `font`;
     * `--text-shadow-sm` is a text shadow, not a font size.
     *
     * @test
     */
    public function theDefaultThemeHasVariablesTheMapKeepsApart(): void
    {
        $vars = DefaultTheme::vars();

        self::assertArrayHasKey('--font-weight-bold', $vars);
        self::assertArrayHasKey('--text-shadow-sm', $vars);
        self::assertArrayHasKey('--font-sans', $vars);
        self::assertArrayHasKey('--text-sm', $vars);
    }

    /** @test */
    public function theMatchIsWholeWordsOnly(): void
    {
        self::assertTrue(ThemeNamespaces::isIgnored('font', 'weight-bold'));
        self::assertTrue(ThemeNamespaces::isIgnored('font', 'weight'));
        self::assertTrue(ThemeNamespaces::isIgnored('font', 'size-lg'));
        self::assertFalse(ThemeNamespaces::isIgnored('font', 'weightless'));
        self::assertFalse(ThemeNamespaces::isIgnored('font', 'sans'));
        self::assertTrue(ThemeNamespaces::isIgnored('text', 'shadow-sm'));
        self::assertFalse(ThemeNamespaces::isIgnored('text', 'shadowy'));
        self::assertFalse(ThemeNamespaces::isIgnored('text', 'sm'));
        self::assertFalse(ThemeNamespaces::isIgnored('breakpoint', 'weight-bold'), 'a namespace with no entry');
        self::assertFalse(ThemeNamespaces::isIgnored('font', ''), 'the bare variable is never ignored');
    }
}
