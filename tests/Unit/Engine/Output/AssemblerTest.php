<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Output;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Output\Assembler;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;

/**
 * The output's shape (TASK-0034 criterion 2), each row the 4.1.18 CLI's: layer
 * statements, the bundles in their layers, the used-only theme, the themed
 * preflight, authored CSS after the generated layers, then `@property`, the
 * theme's keyframes and the `@layer properties` fallback (`golden/directives/*`).
 */
final class AssemblerTest extends TestCase
{
    private const BUNDLES = ['theme' => 'theme', 'preflight' => 'base', 'utilities' => 'utilities'];

    private const LAYERS = ['theme', 'base', 'components', 'utilities'];

    /** @test */
    public function theLayersComeInTheCliOrderAndAuthoredCssAfterThem(): void
    {
        $authored = [new Rule('.unlayered', [new Declaration('color', 'blue')])];
        $sheet = self::assemble(self::sheet(self::BUNDLES, $authored), self::theme(), [self::utility('.flex')], []);

        self::assertSame(
            [
                '@layer theme, base, components, utilities',
                '@layer theme',
                '@layer base',
                '@layer utilities',
                '.unlayered',
            ],
            self::outline($sheet)
        );
    }

    /**
     * Registered properties add `@layer properties;` first, then `@property`
     * rules after the authored CSS and the fallback block last.
     *
     * @test
     */
    public function registeredPropertiesFrameTheSheet(): void
    {
        $authored = [new Rule('.authored', [new Declaration('color', 'red')])];
        $theme = self::theme();
        $theme->markUsed('--animate-spin');
        $sheet = self::assemble(
            self::sheet(['utilities' => 'utilities'], $authored, ['theme', 'base', 'components', 'utilities']),
            $theme,
            [self::utility('.ring-2')],
            ['--tw-ring-shadow', '--tw-ring-inset', '--tw-ring-shadow']
        );

        self::assertSame(
            [
                '@layer properties',
                '@layer theme, base, components, utilities',
                '@layer utilities',
                '.authored',
                '@property --tw-ring-shadow',
                '@property --tw-ring-inset',
                '@keyframes spin',
                '@layer properties',
            ],
            self::outline($sheet),
            'each property once; keyframes of an emitted variable'
        );
    }

    /** @test */
    public function thePropertyRulesAndTheFallbackHaveTheGoldenShape(): void
    {
        $css = self::render(self::assemble(
            self::sheet([]),
            self::theme(),
            [],
            ['--tw-ring-offset-width', '--tw-ring-color']
        ));

        // `golden/utilities/backgrounds-borders.css`.
        self::assertStringContainsString(
            '@property --tw-ring-offset-width{syntax:"<length>";inherits:false;initial-value:0px}',
            $css
        );
        self::assertStringContainsString('@property --tw-ring-color{syntax:"*";inherits:false}', $css);
        self::assertStringContainsString(
            '@layer properties{@supports ' . Assembler::PROPERTIES_FALLBACK
            . '{*, ::before, ::after, ::backdrop{--tw-ring-offset-width:0px;--tw-ring-color:initial}}}',
            $css
        );
    }

    /** @test */
    public function anUnknownPropertyIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        self::assemble(self::sheet([]), self::theme(), [], ['--tw-not-a-property']);
    }

    /**
     * Only used variables, their `var()` closure and those of static blocks;
     * with the preflight, the fonts it reads (`golden/directives/layer.css`).
     *
     * @test
     */
    public function theThemeLayerHoldsTheUsedVariablesAndWhatTheyRead(): void
    {
        $theme = self::theme();
        $theme->markUsed('--spacing');
        $withPreflight = self::themeNames(self::assemble(self::sheet(self::BUNDLES), $theme, [], []));

        self::assertSame(
            ['--font-sans', '--font-mono', '--spacing', '--default-font-family', '--default-mono-font-family'],
            $withPreflight
        );

        $theme = self::theme();
        $theme->markUsed('--spacing');

        self::assertSame(
            ['--spacing'],
            self::themeNames(self::assemble(self::sheet(['theme' => 'theme', 'utilities' => 'u']), $theme, [], []))
        );
    }

    /**
     * With the theme imported the preflight's fonts read the theme; without
     * it, the fallback alone (`golden/directives/import-preflight.css`).
     *
     * @test
     */
    public function thePreflightReadsTheThemeOnlyWhenItIsImported(): void
    {
        $themed = self::render(self::assemble(self::sheet(self::BUNDLES), self::theme(), [], []));
        $plain = self::render(self::assemble(self::sheet(['preflight' => null]), self::theme(), [], []));

        self::assertStringContainsString('font-family:var(--default-font-family, ui-sans-serif', $themed);
        self::assertStringContainsString('font-feature-settings:var(--default-font-feature-settings, normal)', $themed);
        self::assertStringContainsString('font-family:ui-sans-serif', $plain);
        self::assertStringNotContainsString('var(--default', $plain);
        $unlayered = '@layer theme, base, components, utilities;'
            . '*, ::after, ::before, ::backdrop, ::file-selector-button{';
        self::assertStringStartsWith($unlayered, $plain, 'unlayered');
    }

    /**
     * `prefix(tw)`: theme variables are written `--tw-…` where they are
     * defined and where they are read; other custom properties keep their
     * names (`golden/directives/import-prefix.css`).
     *
     * @test
     */
    public function aPrefixRenamesThemeVariablesOnly(): void
    {
        $theme = self::theme();
        $theme->markUsed('--text-lg');
        $theme->markUsed('--text-lg--line-height');
        $utility = new Rule('.tw\:text-lg', [
            new Declaration('font-size', 'var(--text-lg)'),
            new Declaration('line-height', 'var(--tw-leading, var(--text-lg--line-height))'),
        ]);
        $css = self::render(self::assemble(
            self::sheet(self::BUNDLES, [], self::LAYERS, 'tw'),
            $theme,
            Assembler::prefixed([$utility], 'tw', $theme),
            []
        ));

        self::assertStringContainsString('--tw-text-lg:1.125rem', $css);
        self::assertStringContainsString('--tw-default-font-family:var(--tw-font-sans)', $css);
        self::assertStringContainsString('font-family:var(--tw-default-font-family, ', $css);
        self::assertStringContainsString(
            '.tw\:text-lg{font-size:var(--tw-text-lg);line-height:var(--tw-leading, var(--tw-text-lg--line-height))}',
            $css
        );
    }

    /** @test */
    public function referenceVariablesAreNeverEmittedAndStaticBlocksAlways(): void
    {
        $theme = new ThemeRegistry();
        $theme->merge(['--color-a' => 'red'], new ThemeFlags(false, false, true));
        $theme->merge(['--color-b' => 'blue'], new ThemeFlags(false, true));
        $theme->markUsed('--color-a');

        $names = self::themeNames(self::assemble(self::sheet(['theme' => 'theme']), $theme, [], []));

        self::assertSame(['--color-b'], $names);
    }

    /** @test */
    public function emptyBundlesLeaveNoEmptyLayer(): void
    {
        $bundles = ['theme' => 'theme', 'utilities' => 'utilities'];
        $sheet = self::assemble(self::sheet($bundles), new ThemeRegistry(), [], []);

        self::assertSame(['@layer theme, base, components, utilities'], self::outline($sheet));
    }

    /**
     * @param list<Node> $utilities
     * @param list<string> $properties
     */
    private static function assemble(
        ProcessedSheet $sheet,
        ThemeRegistry $theme,
        array $utilities,
        array $properties
    ): StyleSheet {
        return Assembler::assemble($sheet, $theme, $utilities, $properties);
    }

    /**
     * @param array<string, string|null> $bundles
     * @param list<Node> $authored
     * @param list<string> $layers
     */
    private static function sheet(
        array $bundles,
        array $authored = [],
        array $layers = self::LAYERS,
        ?string $prefix = null
    ): ProcessedSheet {
        return new ProcessedSheet($layers, $bundles, $authored, [], [], [], [], $prefix, false, [], []);
    }

    /**
     * The nodes as written, compactly: `prop:value;`, `selector{…}`, `@name params{…}` or `@name params;`.
     */
    private static function render(StyleSheet $sheet): string
    {
        return self::nodes($sheet->nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function nodes(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out .= $node->property . ':' . $node->value . ';';
            } elseif ($node instanceof Rule) {
                $out .= $node->selector . '{' . \rtrim(self::nodes($node->nodes), ';') . '}';
            } elseif ($node instanceof AtRule) {
                $out .= '@' . $node->name . ' ' . $node->params
                    . ($node->nodes === null ? ';' : '{' . \rtrim(self::nodes($node->nodes), ';') . '}');
            }
        }

        return $out;
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }

    private static function utility(string $selector): Rule
    {
        return new Rule($selector, [new Declaration('display', 'flex')]);
    }

    /**
     * The top-level nodes as `@name params` or selectors.
     *
     * @return list<string>
     */
    private static function outline(StyleSheet $sheet): array
    {
        $outline = [];

        foreach ($sheet->nodes as $node) {
            if ($node instanceof AtRule) {
                $outline[] = '@' . $node->name . ' ' . $node->params;
            } else {
                $outline[] = $node instanceof Rule ? $node->selector : '?';
            }
        }

        return $outline;
    }

    /**
     * The variable names of the `:root, :host` rule.
     *
     * @return list<string>
     */
    private static function themeNames(StyleSheet $sheet): array
    {
        foreach ($sheet->nodes as $node) {
            $isTheme = $node instanceof AtRule && $node->name === 'layer' && $node->params === 'theme';

            if ($isTheme && $node instanceof AtRule && $node->nodes !== null) {
                $names = [];

                foreach ($node->nodes[0] instanceof Rule ? $node->nodes[0]->nodes : [] as $declaration) {
                    if ($declaration instanceof Declaration) {
                        $names[] = $declaration->property;
                    }
                }

                return $names;
            }
        }

        return [];
    }
}
