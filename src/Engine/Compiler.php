<?php

declare(strict_types=1);

namespace TailwindPhp\Engine;

use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser as CssParser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Import\ResolvedImport;
use TailwindPhp\Engine\Output\ApplyResolver;
use TailwindPhp\Engine\Output\Assembler;
use TailwindPhp\Engine\Output\SortKey;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler as UtilityCompiler;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;

/**
 * The PHP engine (design doc 07 §Pipeline): one stylesheet from an entry, its
 * imports and a candidate list, phase by phase —
 *
 * 1. parse the entry (`Css\Parser`);
 * 2. directives (`Directive\Processor`): imports, `@theme`, `@source`,
 *    `@utility`, `@custom-variant`, `@layer`, `@apply` recorded;
 * 3. the variant registry with the final breakpoints and custom variants;
 * 4. `@apply` (`Output\ApplyResolver`), before any candidate, so a custom
 *    utility carries what it applies;
 * 5. the candidates — the request's and `@source inline()`'s, deduplicated,
 *    the excluded ones removed, at most `maxCandidates` (then one
 *    `W_CANDIDATE_LIMIT`) — each parsed, compiled (one memoising
 *    `Utility\Compiler` per compile), wrapped in its variants under its class
 *    selector and keyed with `Output\SortKey`; a candidate that is not a
 *    utility is skipped silently, as scanned text is noise more often than not;
 * 6. one sort, the used theme variables marked, `Output\Assembler`, then
 *    `Css\Nesting::flatten` and `Css\Serializer`.
 *
 * Pure (R7): no file, clock or randomness; every import is read through the
 * request's resolver (T-01/T-08). Only `CompiledUtility` nodes and parsed
 * authored nodes reach the serializer (T-15): the utility compiler's emission
 * guard has checked every generated value. The registries built from the
 * data tables are built once per instance and copied per compile, since
 * `@utility` and `@custom-variant` add to them.
 */
final class Compiler
{
    /**
     * The preflight's nodes (`Data\Preflight`, 4.1.18), with room to spare.
     */
    private const PREFLIGHT_NODES = 200;

    /**
     * @var UtilityRegistry|null
     */
    private $utilities;

    /**
     * @var VariantRegistry|null
     */
    private $variants;

    public function compile(CompileRequest $request): CompileOutput
    {
        $bag = new DiagnosticBag();
        $theme = new ThemeRegistry();
        $utilities = clone $this->baseUtilities();
        // The resolver, recording each identity it answers with, once.
        $recording = new class ($request->imports) implements ImportResolverInterface {
            /**
             * @var list<string>
             */
            private $resolved = [];

            /**
             * @var ImportResolverInterface
             */
            private $inner;

            public function __construct(ImportResolverInterface $inner)
            {
                $this->inner = $inner;
            }

            public function resolve(string $spec, string $fromRelPath): ?ResolvedImport
            {
                $resolved = $this->inner->resolve($spec, $fromRelPath);

                if ($resolved !== null && !\in_array($resolved->relPath(), $this->resolved, true)) {
                    $this->resolved[] = $resolved->relPath();
                }

                return $resolved;
            }

            /**
             * @return list<string>
             */
            public function resolved(): array
            {
                return $this->resolved;
            }
        };

        $entry = CssParser::parse($request->entryCss, $bag);
        $sheet = Processor::process(
            $entry,
            $recording,
            $theme,
            $utilities,
            clone $this->baseVariants(),
            new ProcessorOptions($request->entryPath, \strlen($request->entryCss), $request->preflight),
            $bag
        );

        $variants = new VariantRegistry(Variants::table(), $sheet->breakpoints);

        foreach ($sheet->customVariants as $name => $definition) {
            $variants->addCustom((string) $name, $definition);
        }

        $parser = new CandidateParser($utilities, $sheet->prefix, $variants);
        $compiler = new UtilityCompiler($utilities);
        // Unknown variants on scanned text are noise, not diagnostics.
        $applier = new Applier($variants, new DiagnosticBag());

        // T-05: the output must re-parse, so the whole sheet stays within the
        // parser's node cap — the applied bodies first, then the utilities.
        $fixed = self::fixedNodes($theme);
        $authored = ApplyResolver::flatSize($sheet->authored);
        $applies = new ApplyResolver(
            $parser,
            $compiler,
            $applier,
            $theme,
            $sheet->prefix,
            $sheet->breakpoints,
            $authored >= CssParser::MAX_NODES
                ? 0
                : \max(0, \min(ApplyResolver::MAX_EXPANDED_NODES, CssParser::MAX_NODES - $fixed - $authored))
        );
        $applies->resolve($sheet->applies, $bag);

        $entries = [];
        $used = [];
        [$candidates, $dropped] = self::candidates($request, $sheet);
        $authoredAfter = ApplyResolver::flatSize($sheet->authored);
        $room = $authoredAfter >= CssParser::MAX_NODES ? 0 : CssParser::MAX_NODES - $fixed - $authoredAfter;

        foreach ($candidates as $index => $raw) {
            if ($room <= 0) {
                $dropped += \count($candidates) - $index;

                break;
            }

            $readings = $parser->parse($raw);
            $compiled = $readings === [] ? null : $compiler->compileReadings($raw, $readings, $theme);

            if ($compiled === null) {
                continue;
            }

            $nodes = Assembler::prefixed($compiled->nodes, $sheet->prefix, $theme);

            if ($sheet->important) {
                $nodes = self::important($nodes);
            }

            $wrapped = $applier->wrap($nodes, $readings[0]->variants, '.' . Escape::identifier($raw));

            // Compiled and then refused (a variant the applier cannot apply):
            // the body was built all the same, so it is charged (T-05).
            if ($wrapped === null) {
                $cost = ApplyResolver::expandedSize($compiled->nodes, $room);

                if ($cost > $room) {
                    $dropped += \count($candidates) - $index;

                    break;
                }

                $room -= $cost;

                continue;
            }

            $size = ApplyResolver::flatSize($wrapped->nodes());

            if ($size > $room) {
                $dropped += \count($candidates) - $index;

                break;
            }

            $room -= $size;

            foreach ($compiled->usedVars as $name) {
                $used[$name] = true;
            }

            $entries[] = [
                SortKey::of(
                    $readings[0]->variants,
                    $wrapped->variantOrders(),
                    $compiled->sortKey,
                    ApplyResolver::declarations($compiled->nodes),
                    $raw,
                    $sheet->breakpoints
                ),
                $wrapped->nodes(),
                \array_merge($compiled->properties, $wrapped->properties()),
            ];
        }

        if ($dropped > 0) {
            $bag->add(new Diagnostic(Diagnostic::WARNING, Diagnostic::W_CANDIDATE_LIMIT, \sprintf(
                '%d candidates were not compiled: a stylesheet holds at most %d candidates and %d nodes.',
                $dropped,
                $request->maxCandidates,
                CssParser::MAX_NODES
            )));
        }

        \usort($entries, static function (array $a, array $b): int {
            return SortKey::compare($a[0], $b[0]);
        });

        $rules = [];
        $properties = [];

        foreach ($entries as [, $nodes, $registered]) {
            foreach ($nodes as $node) {
                $rules[] = $node;
            }

            foreach ($registered as $property) {
                $properties[] = $property;
            }
        }

        foreach (\array_merge(\array_keys($used), $applies->usedVars()) as $name) {
            $theme->markUsed((string) $name);
        }

        foreach ($applies->properties() as $property) {
            $properties[] = $property;
        }

        $assembled = Assembler::assemble($sheet, $theme, $rules, $properties);
        $flat = Nesting::flatten($assembled, $bag);
        $css = $request->minify ? Serializer::compact($flat) : Serializer::pretty($flat);

        return new CompileOutput(
            $css,
            $bag,
            $recording->resolved(),
            $sheet->sources,
            $sheet->inlineCandidates,
            [
                'candidates' => \count($request->candidates),
                'utilities' => \count($entries),
                'applies' => \count($sheet->applies),
            ]
        );
    }

    private function baseUtilities(): UtilityRegistry
    {
        if ($this->utilities === null) {
            $this->utilities = UtilityRegistry::default();
        }

        return $this->utilities;
    }

    private function baseVariants(): VariantRegistry
    {
        if ($this->variants === null) {
            $this->variants = new VariantRegistry(Variants::table());
        }

        return $this->variants;
    }

    /**
     * The request's candidates and the `@source inline()` ones, each once,
     * without the `@source not inline()` ones, at most `maxCandidates` — and
     * how many more there were.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function candidates(CompileRequest $request, ProcessedSheet $sheet): array
    {
        $excluded = \array_flip($sheet->excludedCandidates);
        $seen = [];
        $out = [];
        $over = 0;

        foreach ([$request->candidates, $sheet->inlineCandidates] as $list) {
            foreach ($list as $raw) {
                if (isset($seen[$raw]) || isset($excluded[$raw])) {
                    continue;
                }

                $seen[$raw] = true;

                if (\count($out) >= $request->maxCandidates) {
                    ++$over;

                    continue;
                }

                $out[] = $raw;
            }
        }

        return [$out, $over];
    }

    /**
     * Nodes the sheet needs whatever the candidates: the layer statements and
     * blocks, the preflight, every theme variable, every `@property` rule with
     * its fallback, and every `@keyframes` a theme variable names, counted
     * exactly (only those can be emitted).
     */
    private static function fixedNodes(ThemeRegistry $theme): int
    {
        $keyframes = 0;

        foreach (Assembler::keyframesOf($theme->allVars(), $theme) as $rule) {
            $keyframes += ApplyResolver::expandedSize([$rule], CssParser::MAX_NODES);
        }

        return 16 + self::PREFLIGHT_NODES + \count($theme->allVars())
            + 5 * \count(Assembler::PROPERTIES) + $keyframes;
    }

    /**
     * $nodes with every declaration `!important` (the import's `important`).
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function important(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out[] = new Declaration($node->property, $node->value, true, $node->line, $node->col);
            } elseif ($node instanceof Rule) {
                $out[] = new Rule($node->selector, self::important($node->nodes), $node->line, $node->col);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $out[] = new AtRule($node->name, $node->params, self::important($node->nodes), $node->line, $node->col);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }
}
