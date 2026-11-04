<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\Colors;
use TailwindPhp\Engine\Data\DefaultTheme;

/**
 * The colour keywords v4 hard-codes, and the palette names that are theme
 * variables instead (checked against `golden/utilities/edge-colors.css`:
 * `bg-current` → `currentcolor`, `bg-black` → `var(--color-black)`).
 */
final class ColorsTest extends TestCase
{
    /** @test */
    public function theKeywordsEmitTheirCssValue(): void
    {
        self::assertSame('currentcolor', Colors::special('current'));
        self::assertSame('inherit', Colors::special('inherit'));
        self::assertSame('transparent', Colors::special('transparent'));
        self::assertNull(Colors::special('black'));
        self::assertNull(Colors::special('red-500'));
    }

    /** @test */
    public function blackAndWhiteAreDefaultThemeVariables(): void
    {
        $vars = DefaultTheme::vars();

        foreach (Colors::THEME_NAMED as $name) {
            self::assertArrayHasKey('--color-' . $name, $vars);
        }

        foreach (\array_keys(Colors::SPECIAL) as $keyword) {
            self::assertArrayNotHasKey('--color-' . $keyword, $vars, 'a keyword is never a theme variable');
        }
    }

    /** @test */
    public function theGoldenAgrees(): void
    {
        $css = (string) \file_get_contents(\dirname(__DIR__, 3) . '/Conformance/golden/utilities/edge-colors.css');

        self::assertStringContainsString(".bg-current {\n    background-color: currentcolor;", $css);
        self::assertStringContainsString(".bg-inherit {\n    background-color: inherit;", $css);
        self::assertStringContainsString(".bg-black {\n    background-color: var(--color-black);", $css);
    }
}
