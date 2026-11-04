<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Variant\Definition as VariantDefinition;

/**
 * What the directive {@see Processor} hands the assembler (TASK-0034): the
 * entry with every directive consumed, and the facts the directives stated.
 *
 * - `layers`: the declared layer order — `@layer a, b;` statements and the
 *   `tailwindcss` bundle's `theme, base, components, utilities` — which is
 *   what the CLI's leading `@layer` statement lists (a block's name, or an
 *   import's `layer(x)`, does not join it).
 * - `bundles`: the Tailwind bundles imported, in import order, bundle name
 *   (`theme`, `preflight`, `utilities`) => the layer they were imported into,
 *   or null when imported without `layer()`. A disabled preflight is absent.
 * - `authored`: the authored CSS in source order, imports spliced (a
 *   `layer(x)` import wrapped in `@layer x { … }`), authored `@layer` blocks
 *   kept as written — the 4.1.18 CLI emits them in this order after its own
 *   layers (see {@see self::byLayer()} for the per-layer view).
 * - `applies`: each `@apply`, removed from its rule: the host rule (its
 *   `nodes` are where the resolved utilities go), the candidates, and the
 *   `@utility` it belongs to, if any.
 * - `sources`: `@source` specs and `@import … source()` options, never opened
 *   here (T-01: the scanner confines them).
 * - `inlineCandidates` / `excludedCandidates`: `@source inline()` /
 *   `@source not inline()`, braces expanded — raw strings for the candidate
 *   parser, never compiled here (T-15).
 * - `prefix`, `important`: from the `tailwindcss` import options.
 * - `breakpoints`: the theme's usable breakpoints after every `@theme`,
 *   `name => value`, and `customVariants`: the `@custom-variant`s added — the
 *   variant registry takes breakpoints only when it is built, so the
 *   assembler builds the final one from these two.
 *
 * Public properties set once by the processor.
 *
 * @phpstan-type SourceSpec array{kind: string, value: string}
 * @phpstan-type Apply array{host: Rule, candidates: list<string>, utility: string|null, line: int|null}
 */
final class ProcessedSheet
{
    /**
     * @var list<string>
     */
    public $layers;

    /**
     * @var array<string, string|null>
     */
    public $bundles;

    /**
     * @var list<Node>
     */
    public $authored;

    /**
     * @var list<Apply>
     */
    public $applies;

    /**
     * @var list<SourceSpec> `kind`: `path`, `not` (an `@source` path or its
     *                       negation), `none` or `base` (`@import … source()`).
     */
    public $sources;

    /**
     * @var list<string>
     */
    public $inlineCandidates;

    /**
     * @var list<string>
     */
    public $excludedCandidates;

    /**
     * @var string|null
     */
    public $prefix;

    /**
     * @var bool
     */
    public $important;

    /**
     * @var array<string, string>
     */
    public $breakpoints;

    /**
     * @var array<string, VariantDefinition>
     */
    public $customVariants;

    /**
     * @param list<string> $layers
     * @param array<string, string|null> $bundles
     * @param list<Node> $authored
     * @param list<Apply> $applies
     * @param list<SourceSpec> $sources
     * @param list<string> $inlineCandidates
     * @param list<string> $excludedCandidates
     * @param array<string, string> $breakpoints
     * @param array<string, VariantDefinition> $customVariants
     */
    public function __construct(
        array $layers,
        array $bundles,
        array $authored,
        array $applies,
        array $sources,
        array $inlineCandidates,
        array $excludedCandidates,
        ?string $prefix,
        bool $important,
        array $breakpoints,
        array $customVariants
    ) {
        $this->layers = $layers;
        $this->bundles = $bundles;
        $this->authored = $authored;
        $this->applies = $applies;
        $this->sources = $sources;
        $this->inlineCandidates = $inlineCandidates;
        $this->excludedCandidates = $excludedCandidates;
        $this->prefix = $prefix;
        $this->important = $important;
        $this->breakpoints = $breakpoints;
        $this->customVariants = $customVariants;
    }

    /**
     * Whether the preflight bundle is part of the output.
     */
    public function preflight(): bool
    {
        return \array_key_exists('preflight', $this->bundles);
    }

    /**
     * The authored nodes grouped by the layer they sit in, in source order:
     * `base`, `components`, `utilities`, any other layer name, and `''` for
     * unlayered CSS. A top-level `@layer x { … }` contributes its contents to
     * `x` (a nested `@layer` inside it stays as written).
     *
     * @return array<string, list<Node>>
     */
    public function byLayer(): array
    {
        $groups = [];

        foreach ($this->authored as $node) {
            if ($node instanceof AtRule && $node->name === 'layer' && $node->nodes !== null && $node->params !== '') {
                foreach ($node->nodes as $child) {
                    $groups[$node->params][] = $child;
                }

                continue;
            }

            $groups[''][] = $node;
        }

        return $groups;
    }
}
