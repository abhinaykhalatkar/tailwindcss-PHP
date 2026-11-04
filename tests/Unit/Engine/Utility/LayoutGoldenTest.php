<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
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
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\Canonicalizer;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The first real conformance signal (TASK-0032 criterion 9): every Layout
 * candidate of the utility corpus is parsed with the registry, compiled with
 * the default theme, and its rules are compared with the rules the pinned CLI
 * emitted for it — both sides flattened, canonicalised and serialised the
 * same way.
 *
 * Which candidates count is decided by the hand-written inventory (the 98 %
 * denominator, ENG-A12), never by the registry. Variants are not applied yet
 * (TASK-0031): for a candidate with N variants the N outermost at-rule
 * levels of the golden's rules are dropped, which covers the responsive
 * variants the Layout lines use; the selector is the full escaped class on
 * both sides.
 */
final class LayoutGoldenTest extends TestCase
{
    private const FILES = [
        'edge-values',
        'edge-fractions-negatives',
        'edge-arbitrary',
        'edge-modifiers',
        'edge-important',
    ];

    private const THRESHOLD = 0.98;

    /**
     * Stand-in for `Variant\Registry` until TASK-0031 lands: the variants the
     * Layout corpus lines use. A candidate with any other variant (`!md` in
     * `!md:flex`, which the candidate parser keeps as a variant name) is
     * refused by the applier, so nothing is expected from it.
     */
    private const KNOWN_VARIANTS = ['sm', 'md', 'lg', 'xl', '2xl', 'hover', 'focus', 'dark'];

    /** @test */
    public function layoutCandidatesMatchTheGoldens(): void
    {
        $inventory = new Inventory();
        $registry = Registry::default();
        $parser = new CandidateParser($registry);
        $compiler = new Compiler($registry);
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $root = \dirname(__DIR__, 3) . '/Conformance';
        $total = 0;
        $mismatches = [];

        foreach (self::FILES as $file) {
            $corpus = (string) \file_get_contents($root . '/corpus/utilities/' . $file . '.txt');
            $golden = (string) \file_get_contents($root . '/golden/utilities/' . $file . '.css');
            $candidates = CorpusPaths::parseCandidates($corpus);
            $expected = self::goldenRules($golden, $candidates);

            foreach ($candidates as $candidate) {
                if (self::familyOf($candidate, $inventory) !== 'layout') {
                    continue;
                }

                ++$total;
                $readings = [];

                foreach ($parser->parse($candidate) as $reading) {
                    if (self::variantsKnown($reading->variants)) {
                        $readings[] = $reading;
                    }
                }

                $compiled = $compiler->compileReadings($candidate, $readings, $theme);
                $actual = $compiled === null ? null : self::render(
                    [new Rule('.' . Escape::identifier($candidate), $compiled->nodes)],
                    0
                );
                $want = $expected[$candidate] ?? null;

                if ($actual === $want) {
                    continue;
                }

                $status = $want === null ? 'FAIL_EXTRA' : ($actual === null ? 'FAIL_MISSING' : 'FAIL_VALUE');
                $mismatches[] = \sprintf(
                    '%s %s (%s): expected %s, got %s',
                    $status,
                    $candidate,
                    $file,
                    $want ?? '(nothing)',
                    $actual ?? '(nothing)'
                );
            }
        }

        $rate = ($total - \count($mismatches)) / \max(1, $total);

        self::assertGreaterThan(30, $total, 'the corpus has Layout candidates');
        self::assertGreaterThanOrEqual(
            self::THRESHOLD,
            $rate,
            \sprintf(
                "%d of %d Layout candidates match:\n%s",
                $total - \count($mismatches),
                $total,
                \implode("\n", $mismatches)
            )
        );
        // The report lists every mismatch; today there is none.
        self::assertSame([], $mismatches);
    }

    /**
     * Candidate => its golden rules rendered canonically, for every candidate
     * the CLI emitted.
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
        /** @var array<string, list<array{0: list<AtRule>, 1: Rule}>> $byCandidate */
        $byCandidate = [];

        foreach (self::rulesWithChains($sheet->nodes, []) as [$chain, $rule]) {
            $candidate = $attribution->attribute($rule->selector);

            if ($candidate !== null) {
                $byCandidate[$candidate][] = [$chain, $rule];
            }
        }

        $rendered = [];

        foreach ($byCandidate as $candidate => $rules) {
            $strip = self::variantCount((string) $candidate);
            $nodes = [];

            foreach ($rules as [$chain, $rule]) {
                $kept = [];

                foreach ($chain as $atRule) {
                    if ($atRule->name !== 'layer') {
                        $kept[] = $atRule;
                    }
                }

                $nodes[] = self::wrap(\array_slice($kept, $strip), $rule);
            }

            $rendered[(string) $candidate] = self::render($nodes, 0);
        }

        return $rendered;
    }

    /**
     * $nodes flattened, canonicalised and compact-serialised.
     *
     * @param list<Node> $nodes
     */
    private static function render(array $nodes, int $unused): string
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(new StyleSheet($nodes), $bag);

        return Serializer::compact(Canonicalizer::canonical($flat, $bag));
    }

    /**
     * Every rule of a flattened tree with the at-rules around it, outermost
     * first.
     *
     * @param list<Node> $nodes
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
     * @param list<\TailwindPhp\Engine\Candidate\Variant> $variants
     */
    private static function variantsKnown(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (!\in_array($variant->name, self::KNOWN_VARIANTS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Top-level `:` separators of a candidate (its variant count).
     */
    private static function variantCount(string $candidate): int
    {
        $depth = 0;
        $count = 0;
        $length = \strlen($candidate);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $candidate[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif ($byte === ']' || $byte === ')') {
                --$depth;
            } elseif ($byte === ':' && $depth === 0) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The inventory family of a candidate's root: the longest inventory root
     * the utility part is, or starts with followed by `-`.
     */
    private static function familyOf(string $candidate, Inventory $inventory): ?string
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
        $best = null;

        foreach ($inventory->roots() as $root) {
            $matches = $utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0;

            if ($matches && ($best === null || \strlen($root) > \strlen($best))) {
                $best = $root;
            }
        }

        return $best === null ? null : $inventory->family($best);
    }
}
