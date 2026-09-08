<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\PreProcessor;

use PHPUnit\Framework\TestCase;
use TailwindPhp\PreProcessor\ScssPreProcessor;
use TailwindPhp\Tests\Support\TempTree;

/**
 * CCP-A32: bare-package imports are shielded and restored byte-exact; every
 * other Tailwind at-rule survives Sass as an unknown at-rule.
 *
 * Skipped without scssphp — the package is a suggestion, not a requirement.
 * Lives under Unit because the only filesystem it touches is scssphp's own
 * import resolution against a temp directory this test owns.
 */
final class ScssPreProcessorTest extends TestCase
{
    /**
     * @var string|null
     */
    private $root;

    protected function setUp(): void
    {
        if (!\class_exists(\ScssPhp\ScssPhp\Compiler::class)) {
            self::markTestSkipped('scssphp/scssphp is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * @test
     */
    public function aBarePackageImportSurvivesByteExact(): void
    {
        $css = $this->process("@import \"tailwindcss\";\n\$x: 4px;\n.a { padding: \$x; }\n");

        self::assertStringContainsString("@import \"tailwindcss\";\n", $css);
        self::assertStringContainsString('padding: 4px;', $css);
        self::assertStringNotContainsString('tw-shield', $css);
    }

    /**
     * @test
     */
    public function severalBarePackageImportsKeepTheirOrderAndQuotes(): void
    {
        $css = $this->process("@import 'tailwindcss/theme';\n@import \"tailwindcss/utilities\";\n.b { color: red; }\n");

        $theme = \strpos($css, "@import 'tailwindcss/theme';");
        $utilities = \strpos($css, '@import "tailwindcss/utilities";');

        self::assertNotFalse($theme);
        self::assertNotFalse($utilities);
        self::assertLessThan($utilities, $theme);
    }

    /**
     * A `.css` specifier and a `url()` are plain CSS imports to Sass already;
     * they are emitted, not resolved, so no shield is needed and none is used.
     *
     * @test
     */
    public function cssAndUrlImportsPassThroughSass(): void
    {
        $css = $this->process(
            "@import \"tailwindcss/utilities.css\";\n@import url(print.css) print;\n.c { margin: 0; }\n"
        );

        self::assertStringContainsString('@import "tailwindcss/utilities.css";', $css);
        self::assertStringContainsString('@import url(print.css) print;', $css);
    }

    /**
     * @test
     *
     * @dataProvider tailwindAtRules
     */
    public function aTailwindAtRuleIsPreserved(string $source, string $expected): void
    {
        $css = $this->process($source . "\n");

        self::assertStringContainsString($expected, $css);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tailwindAtRules(): array
    {
        return [
            'theme block' => ['@theme { --color-brand: #123456; }', "@theme {\n  --color-brand: #123456;\n}"],
            'utility block' => ['@utility tab-4 { tab-size: 4; }', "@utility tab-4 {\n  tab-size: 4;\n}"],
            'variant block' => ['@variant hocus { &:hover { color: red; } }', '@variant hocus'],
            'custom variant' => [
                '@custom-variant theme-midnight (&:where([data-theme="midnight"] *));',
                '@custom-variant theme-midnight',
            ],
            'source' => ['@source "../views";', '@source "../views";'],
            'plugin' => ['@plugin "@tailwindcss/typography";', '@plugin "@tailwindcss/typography";'],
            'apply inside a rule' => ['.btn { @apply px-3 py-1 rounded; }', '@apply px-3 py-1 rounded;'],
            'layer' => ['@layer components { .card { padding: 1rem; } }', '@layer components'],
        ];
    }

    /**
     * @test
     */
    public function scssFeaturesAreCompiledAroundTheShieldedLines(): void
    {
        $css = $this->process(
            "@import \"tailwindcss\";\n"
            . "\$gap: 8px;\n"
            . "@mixin stack { display: flex; gap: \$gap; }\n"
            . ".stack { @include stack; &--wide { gap: \$gap * 2; } }\n"
        );

        self::assertStringContainsString('@import "tailwindcss";', $css);
        self::assertStringContainsString('gap: 8px;', $css);
        self::assertStringContainsString('.stack--wide', $css);
        self::assertStringContainsString('gap: 16px;', $css);
        self::assertStringNotContainsString('@mixin', $css);
    }

    /**
     * @test
     */
    public function sassPartialsAreReportedAsProjectRelativeImports(): void
    {
        $root = $this->root();
        \file_put_contents($root . '/assets/styles/_tokens.scss', "\$brand: #123456;\n");
        $entry = "@import \"tailwindcss\";\n@import \"tokens\";\n.brand { color: \$brand; }\n";
        \file_put_contents($root . '/assets/styles/main.scss', $entry);

        $result = (new ScssPreProcessor($root, []))->process($entry, 'assets/styles/main.scss');

        self::assertStringContainsString('color: #123456;', $result->css());
        self::assertStringContainsString('@import "tailwindcss";', $result->css());
        self::assertSame(['assets/styles/_tokens.scss'], $result->imports());
    }

    /**
     * @test
     */
    public function aSassErrorBecomesARuntimeExceptionWithoutAnAbsolutePath(): void
    {
        try {
            $this->process(".broken { color: \$undefined; }\n");
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $e->getMessage());
        }
    }

    /**
     * A directive commented out inside a block comment stays a comment: the
     * shield must never plant a placeholder whose own terminator would close
     * the comment early (round-5 verifier, M-1).
     *
     * @test
     */
    public function aDirectiveInsideABlockCommentIsLeftAlone(): void
    {
        $css = $this->process(
            "/* legacy:\n@import \"tailwindcss\";\n@variant dark (&:where(.dark, .dark *));\n*/\n"
            . "\$c: red;\n.a { color: \$c; }\n"
        );

        self::assertStringContainsString('color: red;', $css);
        self::assertSame(
            1,
            \substr_count($css, '@import "tailwindcss";'),
            'the commented copy is still inside the comment'
        );
        self::assertStringNotContainsString('tw-shield', $css);
    }

    /**
     * A placeholder-looking comment already in the source (or in a partial) is
     * not ours and must survive verbatim instead of duplicating a directive
     * (round-5 verifier, M-2).
     *
     * @test
     */
    public function aForeignPlaceholderIsNotRestoredAsADirective(): void
    {
        $css = $this->process(
            "@import \"tailwindcss\";\n/*! tw-shield-0 */\n/*! tw-shield-deadbeef-0 */\n.a { color: red; }\n"
        );

        self::assertSame(1, \substr_count($css, '@import "tailwindcss";'));
        self::assertStringContainsString('/*! tw-shield-0 */', $css);
        self::assertStringContainsString('/*! tw-shield-deadbeef-0 */', $css);
    }

    private function process(string $scss): string
    {
        $root = $this->root();
        \file_put_contents($root . '/assets/styles/main.scss', $scss);

        return (new ScssPreProcessor($root, []))
            ->process($scss, 'assets/styles/main.scss')
            ->css();
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = TempTree::make('tailwindphp-scss');
            \mkdir($this->root . '/assets/styles', 0777, true);
        }

        return $this->root;
    }
}
