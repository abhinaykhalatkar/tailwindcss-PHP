<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Definition as VariantDefinition;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Support\Str;

/**
 * The directive processor (design doc 07 §Pipeline): one walk over the
 * parsed entry, each directive handed to its handler, everything else kept
 * as authored CSS. The result is a {@see ProcessedSheet}.
 *
 * | directive | handled by | effect |
 * |---|---|---|
 * | `@import` | {@see ImportHandler}, bundles here | relative imports spliced (in `@layer x` for |
 * |  |  | `layer(x)`); the bundles recorded, the theme bundle seeding the registry |
 * | `@layer` | {@see LayerHandler} | statements → layer order; blocks kept as written |
 * | `@theme` | {@see ThemeHandler} | merged into the theme registry |
 * | `@source` | {@see SourceHandler} | reported specs and inline candidates |
 * | `@utility` | {@see UtilityHandler} | registered with the utility registry |
 * | `@custom-variant` | {@see VariantHandler} | registered with the variant registry |
 * | `@apply` | {@see ApplyHandler} | deferred onto its host rule |
 * | `@plugin`, `@config` | here | `E_UNSUPPORTED_DIRECTIVE` (the CLI driver runs them, ADR-010) |
 *
 * Unknown at-rules are authored CSS. Bounded by {@see ProcessorOptions}
 * (imports, inline candidates) and by {@see Parser::MAX_DEPTH} (block
 * nesting). Its own diagnostics — the handlers' and the parser's for imported
 * files — are capped like the parser's: {@see Tokenizer::MAX_DIAGNOSTICS},
 * then one "Too many" summary, so a hostile import tree cannot grow the bag
 * without bound (T-05).
 */
final class Processor
{
    private const MAX_ECHO = 64;

    /**
     * @var DiagnosticBag Where handlers report, emptied into {@see self::$sink} after each node.
     */
    private $bag;

    /**
     * @var DiagnosticBag The caller's bag.
     */
    private $sink;

    /**
     * @var int Diagnostics passed to the caller's bag.
     */
    private $reported = 0;

    /**
     * @var SourceHandler
     */
    private $sourceHandler;

    /**
     * @var ThemeRegistry
     */
    private $theme;

    /**
     * @var UtilityRegistry
     */
    private $utilities;

    /**
     * @var VariantRegistry
     */
    private $variants;

    /**
     * @var ProcessorOptions
     */
    private $options;

    /**
     * @var ImportHandler
     */
    private $imports;

    /**
     * @var list<string>
     */
    private $layers = [];

    /**
     * @var array<string, string|null>
     */
    private $bundles = [];

    /**
     * @var list<array{host: Rule, candidates: list<string>, utility: string|null, line: int|null}>
     */
    private $applies = [];

    /**
     * @var list<array{kind: string, value: string}>
     */
    private $sources = [];

    /**
     * @var string|null
     */
    private $prefix;

    /**
     * @var bool
     */
    private $important = false;

    /**
     * @var array<string, VariantDefinition>
     */
    private $customVariants = [];

    private function __construct(
        ImportResolverInterface $imports,
        ThemeRegistry $theme,
        UtilityRegistry $utilities,
        VariantRegistry $variants,
        ProcessorOptions $options,
        DiagnosticBag $bag
    ) {
        $this->theme = $theme;
        $this->utilities = $utilities;
        $this->variants = $variants;
        $this->options = $options;
        $this->sink = $bag;
        $this->bag = new DiagnosticBag();
        $this->imports = new ImportHandler($imports, $options);
        $this->sourceHandler = new SourceHandler($options->maxInlineCandidates);
    }

    public static function process(
        StyleSheet $entry,
        ImportResolverInterface $imports,
        ThemeRegistry $theme,
        UtilityRegistry $utilities,
        VariantRegistry $variants,
        ProcessorOptions $options,
        DiagnosticBag $bag
    ): ProcessedSheet {
        $processor = new self($imports, $theme, $utilities, $variants, $options, $bag);
        $authored = $processor->walk($entry->nodes, $options->entryPath, [], 0);
        $breakpoints = ThemeHandler::breakpoints($theme, $processor->bag);
        $processor->absorb();

        return new ProcessedSheet(
            $processor->layers,
            $processor->bundles,
            $authored,
            $processor->applies,
            $processor->sources,
            $processor->sourceHandler->inline(),
            $processor->sourceHandler->excluded(),
            $processor->prefix,
            $processor->important,
            $breakpoints,
            $processor->customVariants
        );
    }

    /**
     * Process one block's nodes; the authored nodes that remain.
     *
     * @param list<Node> $nodes
     * @param list<string> $chain Resolved paths of the imports being processed.
     *
     * @return list<Node>
     */
    private function walk(array $nodes, string $from, array $chain, int $level): array
    {
        $out = [];

        if ($level > Parser::MAX_DEPTH) {
            return $out;
        }

        foreach ($nodes as $node) {
            $this->node($node, $from, $chain, $level, $out);
            $this->absorb();
        }

        return $out;
    }

    /**
     * Process one node into $out.
     *
     * @param list<string> $chain
     * @param list<Node> $out
     */
    private function node(Node $node, string $from, array $chain, int $level, array &$out): void
    {
        if ($node instanceof Rule) {
            $out[] = ApplyHandler::extract($node, null, $this->applies, $this->bag);

            return;
        }

        if (!$node instanceof AtRule) {
            $out[] = $node;

            return;
        }

        switch (\strtolower($node->name)) {
            case 'import':
                foreach ($this->import($node, $from, $chain, $level) as $spliced) {
                    $out[] = $spliced;
                }

                break;
            case 'layer':
                // Only a statement declares order (the CLI's `@layer` statement
                // lists declared layers; a block's name does not join it).
                if ($node->nodes === null) {
                    LayerHandler::record($this->layers, LayerHandler::names($node));
                } else {
                    $inner = $this->walk($node->nodes, $from, $chain, $level + 1);
                    $out[] = new AtRule($node->name, $node->params, $inner, $node->line, $node->col);
                }

                break;
            case 'theme':
                if ($node->nodes === null) {
                    $this->warn($node, Diagnostic::E_THEME_INVALID, '@theme needs a block.');
                } else {
                    ThemeHandler::apply($node, $this->theme, $this->bag);
                }

                break;
            case 'source':
                $this->sourceHandler->handle($node, $this->sources, $this->bag);

                break;
            case 'utility':
                UtilityHandler::handle($node, $this->utilities, $this->applies, $this->bag);

                break;
            case 'custom-variant':
                $added = VariantHandler::handle($node, $this->variants, $this->bag);

                if ($added !== null) {
                    $this->customVariants[$added[0]] = $added[1];
                }

                break;
            case 'plugin':
            case 'config':
                $this->warn($node, Diagnostic::E_UNSUPPORTED_DIRECTIVE, \sprintf(
                    '@%s is not supported by the PHP engine; use the CLI driver (driver: cli).',
                    \strtolower($node->name)
                ));

                break;
            case 'apply':
                $this->warn($node, Diagnostic::E_APPLY_UNKNOWN, '@apply outside a rule is ignored.');

                break;
            default:
                if ($node->nodes === null) {
                    $out[] = $node;
                } else {
                    $inner = $this->walk($node->nodes, $from, $chain, $level + 1);
                    $out[] = new AtRule($node->name, $node->params, $inner, $node->line, $node->col);
                }
        }
    }

    /**
     * @param list<string> $chain
     *
     * @return list<Node>
     */
    private function import(AtRule $node, string $from, array $chain, int $level): array
    {
        $problem = null;
        $import = ImportOptions::parse($node->params, $problem);

        if ($import === null) {
            $this->warn(
                $node,
                Diagnostic::E_CSS_SYNTAX,
                \sprintf('This @import is not understood (%s); it is kept as written.', (string) $problem)
            );

            return [$node];
        }

        $bundle = $import->bundle();

        if ($bundle !== null) {
            $this->bundle($bundle, $import, $node);

            return [];
        }

        $keep = false;
        $imported = $this->imports->import($import, $node, $from, $chain, $this->bag, $keep);

        if ($imported === null) {
            return $keep ? [$node] : [];
        }

        [$sheet, $path] = $imported;
        $chain[] = $path;
        $nodes = $this->walk($sheet->nodes, $path, $chain, $level);

        if ($import->layer !== null && $import->layer !== '') {
            return [new AtRule('layer', $import->layer, $nodes, $node->line, $node->col)];
        }

        return $nodes;
    }

    /**
     * A Tailwind bundle: `tailwindcss` (all three, in `theme`, `base` and
     * `utilities`), or one of `theme.css`, `preflight.css`, `utilities.css`
     * in the layer the import names (none without `layer()`).
     */
    private function bundle(string $bundle, ImportOptions $import, AtRule $node): void
    {
        if ($import->prefix !== null) {
            $this->prefix = $import->prefix;
        }

        $this->important = $this->important || $import->important;

        if ($import->source !== null) {
            $this->sources[] = $import->source === 'none'
                ? ['kind' => 'none', 'value' => '']
                : ['kind' => 'base', 'value' => $import->source];
        }

        if ($bundle === 'all') {
            LayerHandler::record($this->layers, ['theme', 'base', 'components', 'utilities']);
            $this->addBundle('theme', 'theme', $import, $node);
            $this->addBundle('preflight', 'base', $import, $node);
            $this->addBundle('utilities', 'utilities', $import, $node);

            return;
        }

        $layer = $import->layer === null || $import->layer === '' ? null : $import->layer;

        $this->addBundle($bundle, $layer, $import, $node);
    }

    private function addBundle(string $bundle, ?string $layer, ImportOptions $import, AtRule $node): void
    {
        if ($bundle === 'preflight' && !$this->options->preflight) {
            $this->bag->add(self::diagnostic(
                Diagnostic::I_PREFLIGHT_DISABLED,
                'Preflight is disabled by the configuration; the preflight import is skipped.',
                $node->line
            ));

            return;
        }

        if ($bundle === 'theme' && !\array_key_exists('theme', $this->bundles)) {
            ThemeHandler::seedDefault($this->theme, $import->theme);
        }

        if (!\array_key_exists($bundle, $this->bundles)) {
            $this->bundles[$bundle] = $layer;
        }
    }

    private function warn(AtRule $node, string $code, string $message): void
    {
        $this->bag->add(self::diagnostic($code, $message, $node->line));
    }

    /**
     * Move what the handlers reported into the caller's bag, up to
     * {@see Tokenizer::MAX_DIAGNOSTICS} and one summary; the rest is dropped.
     */
    private function absorb(): void
    {
        foreach ($this->bag->all() as $diagnostic) {
            if ($this->reported < Tokenizer::MAX_DIAGNOSTICS) {
                $this->sink->add($diagnostic);
            } elseif ($this->reported === Tokenizer::MAX_DIAGNOSTICS) {
                $this->sink->add(self::diagnostic(
                    Diagnostic::E_CSS_SYNTAX,
                    'Too many directive diagnostics; further ones are not reported.',
                    null
                ));
            } else {
                break;
            }

            ++$this->reported;
        }

        $this->bag = new DiagnosticBag();
    }

    /**
     * A diagnostic at the level its code names: `E_*` error, `W_*` warning,
     * `I_*` info.
     */
    public static function diagnostic(string $code, string $message, ?int $line): Diagnostic
    {
        $level = $code[0] === 'E' ? Diagnostic::ERROR : ($code[0] === 'W' ? Diagnostic::WARNING : Diagnostic::INFO);

        return new Diagnostic($level, $code, $message, null, $line);
    }

    /**
     * Author text echoed in a diagnostic: at most 64 bytes and an ellipsis,
     * never cutting a UTF-8 sequence (T-06).
     */
    public static function echo(string $text): string
    {
        return Str::truncateBytes($text, self::MAX_ECHO + 3, '…');
    }
}
