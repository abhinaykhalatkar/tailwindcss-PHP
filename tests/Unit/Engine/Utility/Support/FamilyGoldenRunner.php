<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Support;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser as CssParser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\Canonicalizer;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\Inventory;
use TailwindPhp\Tools\KnownDifferences;

/**
 * The shared gate every utility family runs on (TASK-0049; design doc 16
 * §Comparison, ENG-A9, ENG-A12).
 *
 * For each candidate of `corpus/utilities/<family>.txt` (and any extra corpus file
 * given, such as the hand-written `edge-*` files) whose root belongs to the family
 * by the hand-written inventory, it
 *
 * 1. parses the candidate with the real candidate parser, the real utility
 *    registry as its roots and the real variant registry as its variant roots;
 * 2. compiles the readings with the real `Utility\Compiler` against the default
 *    theme;
 * 3. wraps the nodes in the candidate's variants with the real `Variant\Applier`;
 * 4. flattens, canonicalises and compact-serialises the result;
 *
 * and compares it with the rules the pinned CLI emitted for that candidate in the
 * golden, treated the same way. Both sides producing nothing is a match
 * (rejection parity); otherwise the status is `FAIL_EXTRA` (the engine produced
 * what the CLI did not), `FAIL_MISSING` or `FAIL_VALUE`.
 *
 * **The allow-list is enforced, not just consulted.** A candidate listed in
 * `known-differences.php` for the corpus entry is skipped *only if it really does
 * mismatch*; one that matches is reported as a failure ("allow-listed but
 * matches"), so the list cannot hide a candidate the engine handles.
 *
 * A family test is then a few lines:
 *
 *     $result = (new FamilyGoldenRunner())->run('typography', ['edge-values', …]);
 *     self::assertGreaterThanOrEqual(0.98, $result->rate(), $result->report());
 *
 * Test support: not shipped, and free of PHPUnit assertions so a future console
 * `report` can reuse the measurement.
 */
final class FamilyGoldenRunner
{
    /**
     * @var CorpusPaths
     */
    private $paths;

    /**
     * @var Inventory
     */
    private $inventory;

    /**
     * @var array<string, list<string>> Corpus entry => the candidates the allow-list excuses.
     */
    private $allowed;

    /**
     * @param array<string, list<string>>|null $allowed Corpus entry => allow-listed candidates; by default
     *                                                  the committed `known-differences.php`.
     */
    public function __construct(?CorpusPaths $paths = null, ?Inventory $inventory = null, ?array $allowed = null)
    {
        $this->paths = $paths ?? new CorpusPaths();
        $this->inventory = $inventory ?? new Inventory();
        $this->allowed = $allowed ?? self::committedAllowList();
    }

    /**
     * Measure one family over its own corpus file and the given extra ones.
     *
     * @param list<string> $extra Names of further `corpus/utilities/<name>.txt` files, e.g. `edge-values`.
     *
     * @throws \RuntimeException When a corpus file or golden cannot be read.
     */
    public function run(string $family, array $extra = []): FamilyGoldenResult
    {
        $keys = ['utilities/' . $family];

        foreach ($extra as $name) {
            $keys[] = 'utilities/' . $name;
        }

        $registry = UtilityRegistry::default();
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $variants = new VariantRegistry(Variants::table(), self::breakpoints($theme));
        $parser = new CandidateParser($registry, null, $variants);
        $compiler = new Compiler($registry);
        $applier = new Applier($variants, new DiagnosticBag());

        $total = 0;
        $matched = 0;
        $mismatches = [];
        $skipped = [];

        foreach ($keys as $key) {
            $candidates = CorpusPaths::parseCandidates($this->read($this->paths->sourcePath($key), $key));
            $expected = self::goldenRules($this->read($this->paths->goldenPath($key), $key), $candidates);
            $excused = $this->allowed[$key] ?? [];

            foreach ($candidates as $candidate) {
                if ($this->familyOf($candidate) !== $family) {
                    continue;
                }

                $actual = self::ourRules($parser, $compiler, $applier, $theme, $candidate);
                $want = $expected[$candidate] ?? null;
                $same = $actual === $want;

                if (\in_array($candidate, $excused, true)) {
                    if ($same) {
                        ++$total;
                        $mismatches[] = $candidate . ' (' . $key . ') is allow-listed but matches the golden: '
                            . 'remove the entry from known-differences.php.';
                    } else {
                        $skipped[] = $candidate;
                    }

                    continue;
                }

                ++$total;

                if ($same) {
                    ++$matched;

                    continue;
                }

                $mismatches[] = \sprintf(
                    '%s %s (%s): expected %s, got %s',
                    $want === null ? 'FAIL_EXTRA' : ($actual === null ? 'FAIL_MISSING' : 'FAIL_VALUE'),
                    $candidate,
                    $key,
                    $want ?? '(nothing)',
                    $actual ?? '(nothing)'
                );
            }
        }

        return new FamilyGoldenResult($total, $matched, $mismatches, $skipped);
    }

    // ------------------------------------------------------------ the engine

    /**
     * The engine's rules for one candidate, rendered, or null when it produces none.
     */
    private static function ourRules(
        CandidateParser $parser,
        Compiler $compiler,
        Applier $applier,
        ThemeRegistry $theme,
        string $raw
    ): ?string {
        $readings = $parser->parse($raw);
        $compiled = $compiler->compileReadings($raw, $readings, $theme);

        if ($compiled === null || $readings === []) {
            return null;
        }

        $wrapped = $applier->wrap($compiled->nodes, $readings[0]->variants, '.' . Escape::identifier($raw));

        if ($wrapped === null) {
            return null;
        }

        // The golden's rules are flattened inside `@layer utilities`; the engine's go through the
        // same layer and the same decomposition, so both sides are shaped by one pipeline.
        $bag = new DiagnosticBag();
        $sheet = Nesting::flatten(new StyleSheet([new AtRule('layer', 'utilities', $wrapped->nodes())]), $bag);
        $pieces = [];

        foreach (self::utilityRules($sheet) as [$chain, $rule]) {
            $pieces[] = self::wrap($chain, $rule);
        }

        return self::render($pieces);
    }

    /**
     * The theme's breakpoints for the variant registry: `name => value`, in theme order.
     *
     * @return array<string, string>
     */
    private static function breakpoints(ThemeRegistry $theme): array
    {
        $breakpoints = [];

        foreach ($theme->namespaceKeys('breakpoint') as $key) {
            $value = $theme->resolve('breakpoint', $key);

            if ($value !== null) {
                $breakpoints[$key] = $value->value();
            }
        }

        return $breakpoints;
    }

    // ------------------------------------------------------------ the golden

    /**
     * Candidate => its golden rules rendered canonically, for every candidate the CLI emitted.
     *
     * @param list<string> $candidates
     *
     * @return array<string, string>
     */
    private static function goldenRules(string $css, array $candidates): array
    {
        $bag = new DiagnosticBag();
        $sheet = Nesting::flatten(CssParser::parse($css, $bag), $bag);
        $attribution = new CandidateAttribution($candidates);
        /** @var array<string, list<Node>> $byCandidate */
        $byCandidate = [];

        foreach (self::utilityRules($sheet) as [$chain, $rule]) {
            foreach ($attribution->attributeAll($rule->selector) as $candidate) {
                $byCandidate[$candidate][] = self::wrap($chain, $rule);
            }
        }

        $rendered = [];

        foreach ($byCandidate as $candidate => $nodes) {
            $rendered[(string) $candidate] = self::render($nodes);
        }

        return $rendered;
    }

    /**
     * The rules of the utilities layer of a flattened sheet, each with the at-rules around it
     * (inside the layer), outermost first. Only that layer holds utilities: a class selector in
     * another layer is not one.
     *
     * @return list<array{0: list<AtRule>, 1: Rule}>
     */
    private static function utilityRules(StyleSheet $sheet): array
    {
        $found = [];

        foreach ($sheet->nodes as $top) {
            $isUtilities = $top instanceof AtRule && $top->name === 'layer' && $top->params === 'utilities';

            if (!$top instanceof AtRule || !$isUtilities || $top->nodes === null) {
                continue;
            }

            foreach (self::rulesWithChains($top->nodes, []) as $pair) {
                $found[] = $pair;
            }
        }

        return $found;
    }

    /**
     * Every rule of a flattened tree with the at-rules around it, outermost first.
     *
     * @param array<array-key, Node> $nodes
     * @param list<AtRule> $chain
     *
     * @return list<array{0: list<AtRule>, 1: Rule}>
     */
    private static function rulesWithChains(array $nodes, array $chain): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $found[] = [$chain, $node];
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $inner = $chain;
                $inner[] = $node;

                foreach (self::rulesWithChains($node->nodes, $inner) as $pair) {
                    $found[] = $pair;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<AtRule> $chain
     */
    private static function wrap(array $chain, Rule $rule): Node
    {
        $node = $rule;

        foreach (\array_reverse($chain) as $atRule) {
            $node = new AtRule($atRule->name, $atRule->params, [$node]);
        }

        return $node;
    }

    /**
     * Nodes flattened, canonicalised and compact-serialised.
     *
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(new StyleSheet($nodes), $bag);

        return Serializer::compact(Canonicalizer::canonical($flat, $bag));
    }

    // ---------------------------------------------------------- the inventory

    /**
     * The inventory family of a candidate: that of the longest root its utility
     * part is, or starts with followed by `-`; null when it names no root. Decided
     * by the hand-written inventory (the 98 % denominator), never by the registry.
     */
    private function familyOf(string $candidate): ?string
    {
        $depth = 0;
        $start = 0;
        $length = \strlen($candidate);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $candidate[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif ($byte === ']' || $byte === ')') {
                --$depth;
            } elseif ($byte === ':' && $depth === 0) {
                $start = $i + 1;
            }
        }

        $utility = (string) \substr($candidate, $start);
        $utility = \trim($utility, '!');
        $utility = \ltrim($utility, '-');

        if ($utility !== '' && $utility[0] === '[') {
            return $this->inventory->family(Inventory::ARBITRARY_PROPERTY);
        }

        $best = null;

        foreach ($this->inventory->roots() as $root) {
            if ($root === Inventory::ARBITRARY_PROPERTY) {
                continue;
            }

            $matches = $utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0;

            if ($matches && ($best === null || \strlen($root) > \strlen($best))) {
                $best = $root;
            }
        }

        return $best === null ? null : $this->inventory->family($best);
    }

    // ------------------------------------------------------------- the files

    /**
     * @return array<string, list<string>>
     */
    private static function committedAllowList(): array
    {
        require_once \dirname(__DIR__, 5) . '/tools/KnownDifferences.php';

        $entries = KnownDifferences::load(\dirname(__DIR__, 4) . '/Conformance/known-differences.php');
        $allowed = [];

        foreach (KnownDifferences::index($entries) as $corpus => $candidates) {
            $allowed[(string) $corpus] = \array_map('strval', \array_keys($candidates));
        }

        return $allowed;
    }

    private function read(string $path, string $key): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('The corpus file or golden of ' . $key . ' cannot be read.');
        }

        return \str_replace("\r\n", "\n", $contents);
    }
}
