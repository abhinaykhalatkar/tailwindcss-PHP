<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FamilyGoldenRunner;

/**
 * The interactivity-svg-accessibility family's gate (TASK-0040): every candidate of the
 * generated corpus (`corpus/utilities/interactivity-svg-accessibility.txt`, variants included)
 * and the lines of this family in the hand-written edge files is compiled with the real utility compiler, wrapped
 * in its variants by the real variant applier, and compared with the rules the
 * pinned CLI emitted for it. Which candidates count is decided by the
 * hand-written inventory (the 98 % denominator, ENG-A12), never by the registry.
 */
final class InteractivitySvgAccessibilityGoldenTest extends TestCase
{
    private const EDGE_FILES = [
        'edge-values',
        'edge-fractions-negatives',
        'edge-arbitrary',
        'edge-modifiers',
        'edge-important',
    ];

    private const THRESHOLD = 0.98;

    /** @test */
    public function interactivitySvgAccessibilityCandidatesMatchTheGoldens(): void
    {
        $result = (new FamilyGoldenRunner())->run('interactivity-svg-accessibility', self::EDGE_FILES);

        self::assertGreaterThan(400, $result->total(), 'the corpus has interactivity-svg-accessibility candidates');
        self::assertGreaterThanOrEqual(self::THRESHOLD, $result->rate(), $result->report());
        self::assertSame([], $result->mismatches(), $result->report());
    }

    /**
     * The CLI's order, not only its values: in the golden, consecutive
     * utilities without variants come out in non-decreasing order of their
     * property sort (v4's comparison: position by position, then more
     * properties first), so the sort keys the compiler gives these roots
     * (`space-x` sorts as a gap, handler rows sort by what they declare)
     * must reproduce it.
     *
     * @test
     */
    public function theSortKeysReproduceTheCliOrder(): void
    {
        $root = \dirname(__DIR__, 3) . '/Conformance';
        $css = (string) \file_get_contents($root . '/golden/utilities/interactivity-svg-accessibility.css');
        $corpus = (string) \file_get_contents($root . '/corpus/utilities/interactivity-svg-accessibility.txt');
        $attribution = new CandidateAttribution(CorpusPaths::parseCandidates($corpus));
        $registry = Registry::default();
        $parser = new CandidateParser($registry);
        $compiler = new Compiler($registry);
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $keys = [];

        foreach (Parser::parse($css, new DiagnosticBag())->nodes as $node) {
            if (!$node instanceof AtRule || $node->name !== 'layer' || $node->params !== 'utilities') {
                continue;
            }

            foreach ($node->nodes ?? [] as $rule) {
                $candidate = $rule instanceof Rule ? $attribution->attribute($rule->selector) : null;

                if ($candidate === null || \strpos($candidate, ':') !== false) {
                    continue;
                }

                $compiled = $compiler->compileReadings($candidate, $parser->parse($candidate), $theme);

                if ($compiled !== null) {
                    $keys[] = [$candidate, $compiled->sortKey];
                }
            }
        }

        self::assertGreaterThan(200, \count($keys));

        for ($i = 1, $count = \count($keys); $i < $count; ++$i) {
            self::assertLessThanOrEqual(
                0,
                self::compareSort($keys[$i - 1][1], $keys[$i][1]),
                \sprintf('%s sorts after %s', $keys[$i - 1][0], $keys[$i][0])
            );
        }
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compareSort(array $a, array $b): int
    {
        for ($i = 0, $n = \min(\count($a), \count($b)); $i < $n; ++$i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return \count($b) <=> \count($a);
    }
}
