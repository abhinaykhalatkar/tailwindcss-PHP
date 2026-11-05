<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Output;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Data\Preflight;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Theme\ThemeRegistry;

/**
 * The output stylesheet, in the 4.1.18 CLI's shape (design doc 07 §Layers,
 * checked against every directive golden):
 *
 * 1. `@layer properties;` when any `@property` is registered, then the
 *    declared layer statement (`@layer theme, base, components, utilities;`);
 * 2. the Tailwind bundles in import order, each in the layer it was imported
 *    into (or unlayered): the theme as `:root, :host { … }` with only the
 *    variables {@see ThemeRegistry::emittable()} gives (used ones, static
 *    blocks, the `var()` closure, never `reference`), the preflight, and the
 *    generated utilities, sorted by the caller;
 * 3. the authored CSS in source order, as written (authored `@layer` blocks
 *    included, after the generated ones), `@apply` already resolved;
 * 4. one `@property` rule per registered `--tw-*` property, in registration
 *    order;
 * 5. the `@keyframes` of the emitted theme variables, in theme order;
 * 6. the `@layer properties` fallback: the initial values under the
 *    `@supports` test for browsers without `@property`.
 *
 * With the theme imported, the preflight's six theme reads print
 * `var(--default-…, <fallback>)` and count as used (so `--default-font-family`
 * and the `--font-sans` it names are emitted); without it, the fallback alone
 * (the form `Data\Preflight` holds). Marking them is the one change this class
 * makes to the registry. With `prefix(tw)` every theme variable is written
 * `--tw-…`, in its definition and wherever generated CSS reads it
 * ({@see self::prefixed()}); authored CSS is left as written.
 *
 * Builds nodes only; flattening and serialising are the caller's.
 */
final class Assembler
{
    /**
     * `@property` definitions, `name => [syntax, initial-value or null]`, as
     * the CLI registers them (every one `inherits: false`): every `@property`
     * of the committed goldens, and the complete set the pinned CLI emits for
     * the T1 inventory (16 632 candidates probed, the same 57 names). A name
     * missing here is a mistake in a row or handler: {@see self::assemble()}
     * throws.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    public const PROPERTIES = [
        '--tw-border-spacing-x' => ['<length>', '0'],
        '--tw-border-spacing-y' => ['<length>', '0'],
        '--tw-border-style' => ['*', 'solid'],
        '--tw-content' => ['*', '""'],
        '--tw-divide-x-reverse' => ['*', '0'],
        '--tw-divide-y-reverse' => ['*', '0'],
        '--tw-duration' => ['*', null],
        '--tw-ease' => ['*', null],
        '--tw-font-weight' => ['*', null],
        '--tw-gradient-from' => ['<color>', '#0000'],
        '--tw-gradient-from-position' => ['<length-percentage>', '0%'],
        '--tw-gradient-position' => ['*', null],
        '--tw-gradient-stops' => ['*', null],
        '--tw-gradient-to' => ['<color>', '#0000'],
        '--tw-gradient-to-position' => ['<length-percentage>', '100%'],
        '--tw-gradient-via' => ['<color>', '#0000'],
        '--tw-gradient-via-position' => ['<length-percentage>', '50%'],
        '--tw-gradient-via-stops' => ['*', null],
        '--tw-inset-ring-color' => ['*', null],
        '--tw-inset-ring-shadow' => ['*', '0 0 #0000'],
        '--tw-inset-shadow' => ['*', '0 0 #0000'],
        '--tw-inset-shadow-alpha' => ['<percentage>', '100%'],
        '--tw-inset-shadow-color' => ['*', null],
        '--tw-leading' => ['*', null],
        '--tw-numeric-figure' => ['*', null],
        '--tw-numeric-fraction' => ['*', null],
        '--tw-numeric-spacing' => ['*', null],
        '--tw-ordinal' => ['*', null],
        '--tw-outline-style' => ['*', 'solid'],
        '--tw-pan-x' => ['*', null],
        '--tw-pan-y' => ['*', null],
        '--tw-pinch-zoom' => ['*', null],
        '--tw-ring-color' => ['*', null],
        '--tw-ring-inset' => ['*', null],
        '--tw-ring-offset-color' => ['*', '#fff'],
        '--tw-ring-offset-shadow' => ['*', '0 0 #0000'],
        '--tw-ring-offset-width' => ['<length>', '0px'],
        '--tw-ring-shadow' => ['*', '0 0 #0000'],
        '--tw-rotate-x' => ['*', null],
        '--tw-rotate-y' => ['*', null],
        '--tw-rotate-z' => ['*', null],
        '--tw-scale-x' => ['*', '1'],
        '--tw-scale-y' => ['*', '1'],
        '--tw-scale-z' => ['*', '1'],
        '--tw-scroll-snap-strictness' => ['*', 'proximity'],
        '--tw-shadow' => ['*', '0 0 #0000'],
        '--tw-shadow-alpha' => ['<percentage>', '100%'],
        '--tw-shadow-color' => ['*', null],
        '--tw-skew-x' => ['*', null],
        '--tw-skew-y' => ['*', null],
        '--tw-slashed-zero' => ['*', null],
        '--tw-space-x-reverse' => ['*', '0'],
        '--tw-space-y-reverse' => ['*', '0'],
        '--tw-tracking' => ['*', null],
        '--tw-translate-x' => ['*', '0'],
        '--tw-translate-y' => ['*', '0'],
        '--tw-translate-z' => ['*', '0'],
    ];

    /**
     * The test under which the CLI writes the initial values for browsers that
     * ignore `@property` (Safari before 16.4, Firefox before 128).
     */
    public const PROPERTIES_FALLBACK = '((-webkit-hyphens: none) and (not (margin-trim: inline))) or '
        . '((-moz-orient: inline) and (not (color:rgb(from red r g b))))';

    /**
     * Preflight rules whose fonts read the theme: selector => variable stem.
     *
     * @var array<string, string>
     */
    private const THEMED_PREFLIGHT = [
        'html, :host' => 'default',
        ':host, html' => 'default',
        'code, kbd, samp, pre' => 'default-mono',
    ];

    /**
     * @var array<string, string> Declaration => the suffix of its theme variable.
     */
    private const THEMED_FONT_PROPERTIES = [
        'font-family' => 'font-family',
        'font-feature-settings' => 'font-feature-settings',
        'font-variation-settings' => 'font-variation-settings',
    ];

    private function __construct()
    {
    }

    /**
     * $variantFirst names those of $properties a variant registered before any
     * utility did (`before:` → `--tw-content`): the CLI writes their
     * descriptors `syntax`, `initial-value`, `inherits`, where a utility's
     * registration writes `syntax`, `inherits`, `initial-value` (TASK-0053).
     * The first registration wins, as in the CLI.
     *
     * @param list<Node> $utilities The generated utility rules, sorted.
     * @param list<string> $properties The `--tw-*` properties to register, in order.
     * @param list<string> $variantFirst
     *
     * @throws \LogicException When a property to register has no definition in {@see self::PROPERTIES}.
     */
    public static function assemble(
        ProcessedSheet $sheet,
        ThemeRegistry $theme,
        array $utilities,
        array $properties,
        array $variantFirst = []
    ): StyleSheet {
        $prefix = $sheet->prefix;
        $properties = \array_values(\array_unique($properties));
        $themed = \array_key_exists('theme', $sheet->bundles);

        // The themed preflight reads these (and, through them, the fonts).
        if ($themed && $sheet->preflight()) {
            foreach (self::THEMED_PREFLIGHT as $stem) {
                foreach (self::THEMED_FONT_PROPERTIES as $suffix) {
                    $theme->markUsed('--' . $stem . '-' . $suffix);
                }
            }
        }

        $emittable = $theme->emittable();
        $nodes = [];

        if ($properties !== []) {
            $nodes[] = new AtRule('layer', 'properties', null);
        }

        if ($sheet->layers !== []) {
            $nodes[] = new AtRule('layer', \implode(', ', $sheet->layers), null);
        }

        foreach ($sheet->bundles as $bundle => $layer) {
            switch ($bundle) {
                case 'theme':
                    $content = self::themeRule($emittable, $theme, $prefix);
                    break;
                case 'preflight':
                    $content = self::preflight($themed, $prefix);
                    break;
                case 'utilities':
                    $content = $utilities;
                    break;
                default:
                    $content = [];
            }

            if ($content === []) {
                continue;
            }

            if ($layer === null) {
                foreach ($content as $node) {
                    $nodes[] = $node;
                }
            } else {
                $nodes[] = new AtRule('layer', $layer, $content);
            }
        }

        foreach ($sheet->authored as $node) {
            $nodes[] = $node;
        }

        $byVariant = \array_flip($variantFirst);

        foreach ($properties as $property) {
            $nodes[] = self::propertyRule($property, isset($byVariant[$property]));
        }

        foreach (self::keyframesOf($emittable, $theme) as $keyframes) {
            $nodes[] = $keyframes;
        }

        if ($properties !== []) {
            $nodes[] = self::propertiesFallback($properties);
        }

        return new StyleSheet($nodes);
    }

    /**
     * $nodes with every theme variable written `--<prefix>-…`: each `var(--x`
     * whose `--x` is a theme variable, in values and preludes. Other custom
     * properties (`--tw-leading`, a utility's own) keep their names.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    public static function prefixed(array $nodes, ?string $prefix, ThemeRegistry $theme): array
    {
        if ($prefix === null) {
            return $nodes;
        }

        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out[] = new Declaration(
                    $node->property,
                    self::prefixReads($node->value, $prefix, $theme),
                    $node->important,
                    $node->line,
                    $node->col
                );
            } elseif ($node instanceof Rule) {
                $children = self::prefixed($node->nodes, $prefix, $theme);
                $out[] = new Rule($node->selector, $children, $node->line, $node->col);
            } elseif ($node instanceof AtRule) {
                $out[] = new AtRule(
                    $node->name,
                    self::prefixReads($node->params, $prefix, $theme),
                    $node->nodes === null ? null : self::prefixed($node->nodes, $prefix, $theme),
                    $node->line,
                    $node->col
                );
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $emittable
     *
     * @return list<Node>
     */
    private static function themeRule(array $emittable, ThemeRegistry $theme, ?string $prefix): array
    {
        if ($emittable === []) {
            return [];
        }

        $declarations = [];

        foreach ($emittable as $name => $value) {
            $declarations[] = new Declaration(
                self::prefixName((string) $name, $prefix),
                $prefix === null ? $value : self::prefixReads($value, $prefix, $theme)
            );
        }

        return [new Rule(':root, :host', $declarations)];
    }

    /**
     * `Data\Preflight` as nodes; with the theme imported its font declarations
     * read `var(--default-…, <the value>)`.
     *
     * @return list<Node>
     */
    private static function preflight(bool $themed, ?string $prefix): array
    {
        $nodes = [];

        foreach (Preflight::ast() as $entry) {
            $node = self::fromArray($entry);

            if ($themed && $node instanceof Rule && isset(self::THEMED_PREFLIGHT[$node->selector])) {
                $stem = '--' . ($prefix === null ? '' : $prefix . '-') . self::THEMED_PREFLIGHT[$node->selector] . '-';
                $children = [];

                foreach ($node->nodes as $child) {
                    if ($child instanceof Declaration && isset(self::THEMED_FONT_PROPERTIES[$child->property])) {
                        $variable = $stem . self::THEMED_FONT_PROPERTIES[$child->property];
                        $child = new Declaration($child->property, 'var(' . $variable . ', ' . $child->value . ')');
                    }

                    $children[] = $child;
                }

                $node = new Rule($node->selector, $children);
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * One `Data\Preflight::ast()` entry as a node.
     *
     * @param array<string, mixed> $entry
     */
    private static function fromArray(array $entry): Node
    {
        $children = [];

        foreach ((array) ($entry['nodes'] ?? []) as $child) {
            if (\is_array($child)) {
                $children[] = self::fromArray($child);
            }
        }

        switch ($entry['type'] ?? '') {
            case 'decl':
                return new Declaration(
                    (string) $entry['property'],
                    (string) $entry['value'],
                    (bool) ($entry['important'] ?? false)
                );
            case 'rule':
                return new Rule((string) $entry['selector'], $children);
            case 'comment':
                return new Comment((string) ($entry['text'] ?? ''));
            default:
                return new AtRule(
                    (string) ($entry['name'] ?? ''),
                    (string) ($entry['params'] ?? ''),
                    \array_key_exists('nodes', $entry) && $entry['nodes'] !== null ? $children : null
                );
        }
    }

    /**
     * One `@property` rule; $variantOrder puts `initial-value` before
     * `inherits`, as the CLI writes a variant's registration.
     *
     * @throws \LogicException When $property has no definition in {@see self::PROPERTIES}.
     */
    private static function propertyRule(string $property, bool $variantOrder = false): AtRule
    {
        if (!isset(self::PROPERTIES[$property])) {
            throw new \LogicException(\sprintf('No @property definition for %s.', $property));
        }

        [$syntax, $initial] = self::PROPERTIES[$property];
        $syntaxDeclaration = new Declaration('syntax', '"' . $syntax . '"');
        $inherits = new Declaration('inherits', 'false');

        if ($initial === null) {
            return new AtRule('property', $property, [$syntaxDeclaration, $inherits]);
        }

        $initialValue = new Declaration('initial-value', $initial);
        $descriptors = $variantOrder
            ? [$syntaxDeclaration, $initialValue, $inherits]
            : [$syntaxDeclaration, $inherits, $initialValue];

        return new AtRule('property', $property, $descriptors);
    }

    /**
     * @param list<string> $properties
     */
    private static function propertiesFallback(array $properties): AtRule
    {
        $declarations = [];

        foreach ($properties as $property) {
            $declarations[] = new Declaration($property, self::PROPERTIES[$property][1] ?? 'initial');
        }

        return new AtRule('layer', 'properties', [
            new AtRule('supports', self::PROPERTIES_FALLBACK, [
                new Rule('*, ::before, ::after, ::backdrop', $declarations),
            ]),
        ]);
    }

    /**
     * The `@keyframes` an emitted variable names (`--animate-spin: spin 1s …`),
     * each once, in theme order.
     *
     * @param array<string, string> $emittable
     *
     * @return list<AtRule>
     */
    public static function keyframesOf(array $emittable, ThemeRegistry $theme): array
    {
        $found = [];

        foreach ($emittable as $value) {
            foreach (self::words($value) as $word) {
                if (isset($found[$word])) {
                    continue;
                }

                $keyframes = $theme->keyframes($word);

                if ($keyframes !== null) {
                    $found[$word] = $keyframes;
                }
            }
        }

        return \array_values($found);
    }

    /**
     * The identifiers of a value, split at spaces, commas and parentheses.
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        $words = [];
        $length = \strlen($value);
        $start = null;

        for ($i = 0; $i <= $length; ++$i) {
            $c = $i < $length ? $value[$i] : ' ';
            $separator = $c === ' ' || $c === ',' || $c === '(' || $c === ')' || $c === "\t" || $c === "\n";

            if ($separator) {
                if ($start !== null) {
                    $words[] = (string) \substr($value, $start, $i - $start);
                    $start = null;
                }
            } elseif ($start === null) {
                $start = $i;
            }
        }

        return $words;
    }

    private static function prefixName(string $name, ?string $prefix): string
    {
        return $prefix === null ? $name : '--' . $prefix . '-' . (string) \substr($name, 2);
    }

    /**
     * $text with each `var(--x` of a theme variable `--x` written `var(--<prefix>-x`.
     */
    private static function prefixReads(string $text, string $prefix, ThemeRegistry $theme): string
    {
        if (\strpos($text, 'var(--') === false) {
            return $text;
        }

        $out = '';
        $offset = 0;

        while (($at = \strpos($text, 'var(--', $offset)) !== false) {
            $start = $at + 4;
            $length = (int) \strspn(
                $text,
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_',
                $start
            );
            $name = (string) \substr($text, $start, $length);
            $out .= (string) \substr($text, $offset, $start - $offset);
            $out .= $theme->get($name) !== null ? self::prefixName($name, $prefix) : $name;
            $offset = $start + $length;
        }

        return $out . (string) \substr($text, $offset);
    }
}
