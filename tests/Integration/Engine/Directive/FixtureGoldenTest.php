<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Preflight;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Conformance\Support\Canonicalizer;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * TASK-0033 criterion 9: every directive fixture, processed with its partials
 * in an {@see InMemoryImportResolver}, against the pinned CLI's golden:
 *
 * 1. the `@layer` statement order (the CLI's own `properties` layer aside);
 * 2. the theme variables the golden emits — the registry's `emittable()` after
 *    marking every variable the golden reads outside its theme block;
 * 3. the base layer when preflight is imported — `Data\Preflight::css()`;
 * 4. the authored nodes, in source order, after the CLI's generated layers
 *    (its `@property` and `@keyframes` output aside). An `@apply` host is
 *    compared by selector: its body is the assembler's (TASK-0034).
 */
final class FixtureGoldenTest extends TestCase
{
    private const FONT_SETTINGS = ['font-feature-settings', 'font-variation-settings'];

    /**
     * @test
     *
     * @dataProvider fixtures
     */
    public function theFixtureAgreesWithItsGolden(string $name): void
    {
        $corpus = self::corpus();
        $css = (string) \file_get_contents($corpus . '/' . $name . '.css');
        $golden = Parser::parse((string) \file_get_contents(self::golden($name)), new DiagnosticBag());
        $bag = new DiagnosticBag();
        $theme = new ThemeRegistry();
        $sheet = Processor::process(
            Parser::parse($css, $bag),
            new InMemoryImportResolver(self::partials()),
            $theme,
            UtilityRegistry::default(),
            new VariantRegistry(Variants::table()),
            new ProcessorOptions($name . '.css', \strlen($css)),
            $bag
        );

        self::assertSame([], $bag->toArray(), 'no diagnostics');
        self::assertSame(self::goldenLayers($golden), $sheet->layers, 'layer order');
        self::assertSame(
            self::goldenThemeVars($golden, $sheet->prefix),
            self::emitted($theme, $golden, $sheet->prefix),
            'theme variables'
        );

        if ($sheet->preflight()) {
            $expected = self::preflight(\array_key_exists('theme', $sheet->bundles), $sheet->prefix);

            self::assertSame(self::render($expected), self::render(self::goldenBase($golden, $sheet)), 'base layer');
        }

        self::assertSame(
            self::render(self::withoutHostBodies(self::goldenAuthored($golden, $sheet), $sheet)),
            self::render(self::withoutHostBodies($sheet->authored, $sheet)),
            'authored nodes'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        $cases = [];

        foreach ((array) \glob(self::corpus() . '/*.css') as $file) {
            $name = \basename((string) $file, '.css');

            if (\is_file(self::golden($name))) {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /** @test */
    public function everyDirectiveGoldenHasAFixtureRow(): void
    {
        self::assertCount(15, self::fixtures());
    }

    /**
     * @return list<string>
     */
    private static function goldenLayers(StyleSheet $golden): array
    {
        $layers = [];

        foreach ($golden->nodes as $node) {
            if ($node instanceof AtRule && $node->name === 'layer' && $node->nodes === null) {
                foreach (\explode(',', $node->params) as $layer) {
                    $layer = \trim($layer);

                    if ($layer !== 'properties' && !\in_array($layer, $layers, true)) {
                        $layers[] = $layer;
                    }
                }
            }
        }

        return $layers;
    }

    /**
     * The variables of the golden's `:root, :host` rule, in order, without the
     * `prefix()` the CLI writes into every emitted name (`--tw-font-sans` is
     * the registry's `--font-sans`; renaming on output is the assembler's).
     *
     * @return list<string>
     */
    private static function goldenThemeVars(StyleSheet $golden, ?string $prefix): array
    {
        $root = self::themeRule($golden->nodes);
        $names = [];

        foreach ($root === null ? [] : $root->nodes as $node) {
            if ($node instanceof Declaration) {
                $names[] = self::unprefixed($node->property, $prefix);
            }
        }

        return $names;
    }

    /**
     * The registry's emitted variable names after marking what the golden
     * reads outside its theme rule.
     *
     * @return list<string>
     */
    private static function emitted(ThemeRegistry $theme, StyleSheet $golden, ?string $prefix): array
    {
        $root = self::themeRule($golden->nodes);
        $reads = [];
        self::collectReads($golden->nodes, $root, $reads);

        foreach (\array_keys($reads) as $name) {
            $theme->markUsed(self::unprefixed((string) $name, $prefix));
        }

        return \array_map('strval', \array_keys($theme->emittable()));
    }

    /**
     * @param list<Node> $nodes
     * @param array<string, true> $reads
     */
    private static function collectReads(array $nodes, ?Rule $skip, array &$reads): void
    {
        foreach ($nodes as $node) {
            if ($node === $skip) {
                continue;
            }

            if ($node instanceof Declaration) {
                self::reads($node->value, $reads);
            } elseif ($node instanceof Rule) {
                self::collectReads($node->nodes, $skip, $reads);
            } elseif ($node instanceof AtRule) {
                self::reads($node->params, $reads);
                self::collectReads($node->nodes ?? [], $skip, $reads);
            }
        }
    }

    /**
     * @param array<string, true> $reads
     */
    private static function reads(string $text, array &$reads): void
    {
        $offset = 0;

        while (($at = \strpos($text, 'var(--', $offset)) !== false) {
            $start = $at + 4;
            $length = (int) \strspn($text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.', $start);
            $reads[(string) \substr($text, $start, $length)] = true;
            $offset = $start + $length;
        }
    }

    /**
     * @param list<Node> $nodes
     */
    private static function themeRule(array $nodes): ?Rule
    {
        foreach ($nodes as $node) {
            if ($node instanceof Rule && $node->selector === ':root, :host') {
                return $node;
            }

            $isTheme = $node instanceof AtRule && $node->name === 'layer' && $node->params === 'theme';

            if ($isTheme && $node instanceof AtRule && $node->nodes !== null) {
                return self::themeRule($node->nodes);
            }
        }

        return null;
    }

    private static function unprefixed(string $name, ?string $prefix): string
    {
        $mark = $prefix === null ? '' : '--' . $prefix . '-';

        if ($mark === '' || \strncmp($name, $mark, \strlen($mark)) !== 0) {
            return $name;
        }

        return '--' . \substr($name, \strlen($mark));
    }

    /**
     * `Data\Preflight` as the CLI emits it. Preflight's source reads six values
     * through `--theme(--default-…, <fallback>)`; `Data\Preflight` holds the
     * form printed without the theme (the fallback alone), and with the theme
     * imported the CLI prints `var(--default-…, <fallback>)` instead — the
     * assembler (TASK-0034) has to do the same.
     *
     * @return list<Node>
     */
    private static function preflight(bool $withTheme, ?string $prefix): array
    {
        $nodes = Parser::parse(Preflight::css(), new DiagnosticBag())->nodes;

        if (!$withTheme) {
            return $nodes;
        }

        $themed = [
            ':host, html' => 'default',
            'html, :host' => 'default',
            'code, kbd, samp, pre' => 'default-mono',
        ];
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule && isset($themed[$node->selector])) {
                $base = $themed[$node->selector];
                $children = [];

                foreach ($node->nodes as $child) {
                    $variable = null;

                    if ($child instanceof Declaration && $child->property === 'font-family') {
                        $variable = '--' . ($prefix === null ? '' : $prefix . '-') . $base . '-font-family';
                    } elseif ($child instanceof Declaration && \in_array($child->property, self::FONT_SETTINGS, true)) {
                        $variable = '--' . ($prefix === null ? '' : $prefix . '-') . $base . '-' . $child->property;
                    }

                    $children[] = $variable === null || !$child instanceof Declaration
                        ? $child
                        : new Declaration($child->property, 'var(' . $variable . ', ' . $child->value . ')');
                }

                $out[] = new Rule($node->selector, $children);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return list<Node>
     */
    private static function goldenBase(StyleSheet $golden, ProcessedSheet $sheet): array
    {
        $layer = $sheet->bundles['preflight'];

        if ($layer === null) {
            return $golden->nodes;
        }

        foreach ($golden->nodes as $node) {
            $isLayer = $node instanceof AtRule && $node->name === 'layer' && $node->params === $layer;

            if ($isLayer && $node instanceof AtRule && $node->nodes !== null) {
                return $node->nodes;
            }
        }

        return [];
    }

    /**
     * The golden's nodes after the CLI's generated layers.
     *
     * @return list<Node>
     */
    private static function goldenAuthored(StyleSheet $golden, ProcessedSheet $sheet): array
    {
        if (\count($sheet->bundles) > 0 && \in_array(null, $sheet->bundles, true)) {
            // A bundle imported without layer(): its output is the golden itself.
            return [];
        }

        $generated = [];

        foreach (['theme', 'preflight', 'utilities'] as $bundle) {
            if (isset($sheet->bundles[$bundle])) {
                $generated[] = (string) $sheet->bundles[$bundle];
            }
        }

        $authored = [];

        foreach ($golden->nodes as $node) {
            $isLayer = $node instanceof AtRule && $node->name === 'layer';

            if ($isLayer && ($node->nodes === null || $node->params === 'properties')) {
                continue;
            }

            if ($node instanceof AtRule && ($node->name === 'property' || $node->name === 'keyframes')) {
                continue;
            }

            if ($isLayer && $generated !== [] && $node->params === $generated[0]) {
                \array_shift($generated);

                continue;
            }

            $authored[] = $node;
        }

        return $authored;
    }

    /**
     * $nodes with the bodies of `@apply` hosts emptied (matched by selector,
     * at any depth): what the host holds after the assembler inlined the
     * utilities is not the processor's output.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function withoutHostBodies(array $nodes, ProcessedSheet $sheet): array
    {
        $hosts = [];

        foreach ($sheet->applies as $apply) {
            $hosts[$apply['host']->selector] = true;
        }

        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $children = isset($hosts[$node->selector]) ? [] : self::withoutHostBodies($node->nodes, $sheet);
                $out[] = new Rule($node->selector, $children);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $out[] = new AtRule($node->name, $node->params, self::withoutHostBodies($node->nodes, $sheet));
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Flattened, canonicalised, compact CSS — with empty rules kept visible.
     *
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(new StyleSheet(self::markEmpty($nodes)), $bag);

        return Serializer::compact(Canonicalizer::canonical($flat, $bag));
    }

    /**
     * Give empty rules a marker declaration so the serializer keeps them.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function markEmpty(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $children = self::markEmpty($node->nodes);
                $out[] = new Rule($node->selector, $children === [] ? [new Declaration('--empty', '1')] : $children);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $out[] = new AtRule($node->name, $node->params, self::markEmpty($node->nodes));
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function partials(): array
    {
        $map = [];

        foreach ((array) \glob(self::corpus() . '/*.css') as $file) {
            $map[\basename((string) $file)] = (string) \file_get_contents((string) $file);
        }

        return $map;
    }

    private static function corpus(): string
    {
        return \dirname(__DIR__, 3) . '/Conformance/corpus/directives';
    }

    private static function golden(string $name): string
    {
        return \dirname(__DIR__, 3) . '/Conformance/golden/directives/' . $name . '.css';
    }
}
