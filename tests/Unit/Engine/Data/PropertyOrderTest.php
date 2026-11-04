<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\PropertyOrder;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;

/**
 * v4's property order list (design doc 07 §Sorting) — and the proof that it
 * is the one the pinned CLI sorts by: in every utility golden, consecutive
 * utilities without variants come out in non-decreasing order of their
 * property sort (v4's comparison: position by position, then more
 * properties first).
 */
final class PropertyOrderTest extends TestCase
{
    private const GOLDENS = [
        'edge-values', 'edge-fractions-negatives', 'edge-arbitrary', 'edge-modifiers', 'edge-important', 'edge-colors',
    ];

    /** @test */
    public function theListHasEveryEntryOnceInV4Order(): void
    {
        $groups = PropertyOrder::GROUPS;

        self::assertCount(337, $groups);
        self::assertSame($groups, \array_values(\array_unique($groups)));
        self::assertSame('container-type', $groups[0]);
        self::assertSame('forced-color-adjust', $groups[336]);
        self::assertSame('4.1.18', PropertyOrder::VERSION);
        self::assertLessThan(PropertyOrder::index('margin'), PropertyOrder::index('--tw-container-component'));
        self::assertLessThan(PropertyOrder::index('padding'), PropertyOrder::index('margin'));
        self::assertLessThan(PropertyOrder::index('padding'), PropertyOrder::index('background-color'));
        self::assertSame(0, PropertyOrder::index('container-type'));
        self::assertNull(PropertyOrder::index('clip-path'));
    }

    /**
     * @test
     *
     * @dataProvider goldens
     */
    public function theCliSortsUtilitiesByThisList(string $golden): void
    {
        $root = \dirname(__DIR__, 3) . '/Conformance';
        $css = (string) \file_get_contents($root . '/golden/utilities/' . $golden . '.css');
        $corpus = (string) \file_get_contents($root . '/corpus/utilities/' . $golden . '.txt');
        $candidates = CorpusPaths::parseCandidates($corpus);
        $attribution = new CandidateAttribution($candidates);
        $sheet = Parser::parse($css, new DiagnosticBag());
        $registry = Registry::default();
        $parser = new CandidateParser($registry);
        $compiler = new Compiler($registry);
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $keys = [];

        foreach ($sheet->nodes as $node) {
            $utilities = $node instanceof AtRule && $node->name === 'layer' && $node->params === 'utilities';

            if (!$utilities || !$node instanceof AtRule || $node->nodes === null) {
                continue;
            }

            foreach ($node->nodes as $rule) {
                $candidate = $rule instanceof Rule ? $attribution->attribute($rule->selector) : null;

                // Variant-less utilities only (a variant sorts first), and
                // only those the registry knows: its sort key includes a
                // row's `order` override, which the CSS itself does not show
                // (`container`, v4's `--tw-sort`).
                if ($candidate === null || \strpos($candidate, ':') !== false) {
                    continue;
                }

                $compiled = $compiler->compileReadings($candidate, $parser->parse($candidate), $theme);

                if ($compiled !== null) {
                    $keys[] = [$candidate, $compiled->sortKey];
                }
            }
        }

        if ($keys === []) {
            self::markTestSkipped('No Layout utility in this golden yet (the families of wave 4 add theirs).');
        }

        for ($i = 1, $count = \count($keys); $i < $count; ++$i) {
            self::assertLessThanOrEqual(
                0,
                self::compare($keys[$i - 1][1], $keys[$i][1]),
                \sprintf('%s sorts after %s', $keys[$i - 1][0], $keys[$i][0])
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function goldens(): array
    {
        $cases = [];

        foreach (self::GOLDENS as $golden) {
            $cases[$golden] = [$golden];
        }

        return $cases;
    }

    /**
     * v4's comparison of two property sorts.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compare(array $a, array $b): int
    {
        for ($i = 0, $n = \min(\count($a), \count($b)); $i < $n; ++$i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return \count($b) <=> \count($a);
    }
}
