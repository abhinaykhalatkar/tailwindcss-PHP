<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Css;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\SelectorList;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * Round-trip property (TASK-0024 criterion 9): for every fixture under
 * `tests/Fixtures/css/` and for a deterministic set of generated inputs,
 * `parse(pretty(parse(x)))` equals `parse(x)` node by node; compact output is
 * stable through the parser; flattened output re-parses to itself.
 *
 * @covers-requirement FR-COMP-2
 */
final class RoundTripTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider inputs
     */
    public function prettyOutputParsesBackToTheSameTree(string $css): void
    {
        $first = self::parseClean($css);
        $second = self::parseClean(Serializer::pretty($first));

        self::assertSame(AstDump::of($first), AstDump::of($second));
    }

    /**
     * @test
     *
     * @dataProvider inputs
     */
    public function compactOutputIsStableThroughTheParser(string $css): void
    {
        $once = Serializer::compact(self::parseClean($css));

        self::assertSame($once, Serializer::compact(self::parseClean($once)));
    }

    /**
     * @test
     *
     * @dataProvider inputs
     */
    public function flattenedOutputIsFlatAndRoundTrips(string $css): void
    {
        $bag = new DiagnosticBag();
        $flat = Nesting::flatten(self::parseClean($css), $bag);

        // The generator nests children under `.x::before`; dropping the
        // branches that can never match is the only diagnostic allowed.
        foreach ($bag->all() as $diagnostic) {
            self::assertStringContainsString('after a pseudo-element', $diagnostic->message());
        }

        self::assertNoNesting($flat->nodes, false);
        self::assertSame(AstDump::of($flat), AstDump::of(self::parseClean(Serializer::pretty($flat))));
    }

    /** @test */
    public function everyFixtureIsCovered(): void
    {
        self::assertGreaterThanOrEqual(6, \count(self::fixtureFiles()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function inputs(): array
    {
        $rows = [];

        foreach (self::fixtureFiles() as $file) {
            $rows['fixture ' . \basename($file)] = [(string) \file_get_contents($file)];
        }

        foreach (self::generated(120) as $i => $css) {
            $rows['generated ' . $i] = [$css];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function fixtureFiles(): array
    {
        $directory = \dirname(__DIR__, 4) . '/tests/Fixtures/css';
        $entries = \scandir($directory);
        $files = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if (\substr($entry, -4) === '.css') {
                $files[] = $directory . '/' . $entry;
            }
        }

        return $files;
    }

    /**
     * Deterministic nested stylesheets built from awkward pieces: escaped
     * selectors, strings holding braces, custom properties with blocks,
     * at-rules with and without blocks, `!important`. A fixed-seed linear
     * congruential generator picks the pieces, so the set is the same on
     * every run and every PHP version.
     *
     * @return list<string>
     */
    private static function generated(int $count): array
    {
        $state = 20261104;
        $inputs = [];

        for ($i = 0; $i < $count; ++$i) {
            $inputs[] = self::block($state, 0, 1 + $i % 4);
        }

        return $inputs;
    }

    private static function block(int &$state, int $depth, int $maxDepth): string
    {
        $selectors = [
            '.a', '.b:hover', '&:focus', '> .c', '.d .e', '[x="{;}"]', '.w-1\\/2', ':is(.f, .g)',
            '.\\32 xl\\:p-4', '.a, .b', 'h1 + p', '.x::before',
        ];
        $declarations = [
            'color: red', '--x: { a; b }', 'margin: 0 auto !important', 'content: "}"',
            'width: calc(1px + 0.5rem)', '--empty:', 'font: 12px/1.5 "A B", x', 'opacity: 0.5',
        ];
        $preludes = ['@media (x)', '@supports (display: grid)', '@layer base', '@container (width > 1px)'];
        $statements = ['@import "a.css"', '@apply flex', '@layer a, b'];

        $parts = [];
        $items = 1 + self::pick($state, 4);

        for ($i = 0; $i < $items; ++$i) {
            $kind = self::pick($state, $depth < $maxDepth ? 4 : 2);

            if ($kind === 0 && $depth > 0) {
                $parts[] = $declarations[self::pick($state, \count($declarations))] . ';';
            } elseif ($kind === 1) {
                $parts[] = $statements[self::pick($state, \count($statements))] . ';';
            } elseif ($kind === 2) {
                $selector = $selectors[self::pick($state, \count($selectors))];

                if ($depth === 0 && SelectorList::containsAmpersand($selector)) {
                    $selector = '.top';
                }

                if ($depth === 0 && $selector[0] === '>') {
                    $selector = '.root';
                }

                $parts[] = $selector . ' { ' . self::block($state, $depth + 1, $maxDepth) . ' }';
            } else {
                $parts[] = $preludes[self::pick($state, \count($preludes))] . ' { '
                    . self::block($state, $depth + 1, $maxDepth) . ' }';
            }
        }

        return \implode(' ', $parts);
    }

    private static function pick(int &$state, int $modulo): int
    {
        $state = ($state * 1103515245 + 12345) % 2147483648;

        return ($state >> 8) % $modulo;
    }

    private static function parseClean(string $css): StyleSheet
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);

        self::assertSame([], $bag->toArray(), "unexpected diagnostics for:\n" . $css);

        return $sheet;
    }

    /**
     * @param list<Node> $nodes
     */
    private static function assertNoNesting(array $nodes, bool $insideRule): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                self::assertFalse($insideRule);
                self::assertFalse(SelectorList::containsAmpersand($node->selector), $node->selector);
                self::assertNoNesting($node->nodes, true);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                self::assertFalse($insideRule);
                self::assertNoNesting($node->nodes, false);
            }
        }
    }
}
