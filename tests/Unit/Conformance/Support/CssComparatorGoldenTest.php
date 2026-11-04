<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\CssComparator;

/**
 * The comparator against the real goldens of the pinned CLI.
 *
 * Every golden must read back identical to itself, and a deliberate mutation of
 * a real golden must produce exactly the one failure it was designed to produce
 * and nothing else. The mutations are plain string edits of the golden text, so
 * they do not depend on the comparator's own parser to be made.
 */
final class CssComparatorGoldenTest extends TestCase
{
    /**
     * The golden whose rules the mutations edit. Its utilities layer is a flat
     * run of one-declaration rules, one per candidate.
     */
    private const MUTATION_KEY = 'utilities/edge-values';

    /**
     * The one golden the CLI emits CSS that our parser rejects: the pinned CLI
     * writes `url(javascript:alert(1))` unquoted and the parser reports it as a
     * malformed url. The comparator must say so rather than pass it.
     */
    private const UNPARSEABLE_KEY = 'utilities/edge-rejections';

    // ------------------------------------------------------------------ goldens

    /**
     * @test
     *
     * @dataProvider stylesheetKeys
     */
    public function everyGoldenIsIdenticalToItself(string $key): void
    {
        $paths = new CorpusPaths();
        $golden = self::read($paths->goldenPath($key));

        $result = (new CssComparator())->compare($golden, $golden, self::candidatesOf($paths, $key));

        self::assertTrue($result->isIdentical(), $key . "\n" . $result->summary());
        self::assertSame(1.0, $result->passRate());
        self::assertSame([], $result->diagnostics());
    }

    /**
     * Every minified golden must at least be readable: the minifier also drops and
     * merges rules (it is a size check), so only a parse error is a failure.
     *
     * @test
     *
     * @dataProvider stylesheetKeys
     */
    public function theMinifiedGoldenIsReadableByTheParser(string $key): void
    {
        $paths = new CorpusPaths();
        $minified = $paths->goldenPath($key, true);

        if (!\is_file($minified)) {
            self::markTestSkipped('No minified golden for ' . $key . '.');
        }

        $result = (new CssComparator())->compare(
            self::read($paths->goldenPath($key)),
            self::read($minified),
            self::candidatesOf($paths, $key)
        );

        self::assertFalse($result->hasParseErrors(), $key . "\n" . \implode("\n", $result->diagnostics()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stylesheetKeys(): array
    {
        $paths = new CorpusPaths();
        $rows = [];

        foreach ($paths->keys() as $key) {
            if ($paths->kind($key) === CorpusPaths::KIND_EXTRACTOR || $key === self::UNPARSEABLE_KEY) {
                continue;
            }

            $rows[$key] = [$key];
        }

        return $rows;
    }

    /** @test */
    public function theUnparseableGoldenIsAParseErrorOnBothSides(): void
    {
        $paths = new CorpusPaths();
        $golden = self::read($paths->goldenPath(self::UNPARSEABLE_KEY));

        $result = (new CssComparator())->compare($golden, $golden, self::candidatesOf($paths, self::UNPARSEABLE_KEY));

        self::assertTrue($result->hasParseErrors());
        self::assertSame(0.0, $result->passRate());
        self::assertFalse($result->isIdentical());
        self::assertStringContainsString('url', \implode("\n", $result->diagnostics()));
    }

    /** @test */
    public function everyCorpusEntryHasAGoldenTheTestsAbovePick(): void
    {
        $paths = new CorpusPaths();
        $keys = \array_keys(self::stylesheetKeys());

        self::assertGreaterThanOrEqual(30, \count($keys));
        self::assertContains(self::MUTATION_KEY, $keys);
        self::assertNotContains(self::UNPARSEABLE_KEY, $keys);
        self::assertTrue($paths->has(self::UNPARSEABLE_KEY));
    }

    /** @test */
    public function theLargestGoldenComparesWithinTheTimeBudget(): void
    {
        $paths = new CorpusPaths();
        $largest = '';
        $size = -1;

        foreach (\array_keys(self::stylesheetKeys()) as $key) {
            $bytes = \strlen(self::read($paths->goldenPath($key)));

            if ($bytes > $size) {
                $size = $bytes;
                $largest = $key;
            }
        }

        $golden = self::read($paths->goldenPath($largest));
        $candidates = self::candidatesOf($paths, $largest);
        $start = \microtime(true);

        $result = (new CssComparator())->compare($golden, $golden, $candidates);

        $seconds = \microtime(true) - $start;

        self::assertTrue($result->isIdentical());
        self::assertLessThan(5.0, $seconds, $largest . ' took ' . \round($seconds, 2) . ' s');
    }

    // --------------------------------------------------------------- mutations

    /** @test */
    public function aDeletedRuleIsExactlyOneMissing(): void
    {
        [$golden, $candidates] = self::mutationSubject();
        $actual = self::without($golden, '.top-0');

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertSame(['top-0' => 'FAIL_MISSING'], self::failuresOf($result));
        self::assertSame(1, $result->count(ComparisonResult::FAIL_MISSING));
        self::assertSame(\count($result->statuses()) - 1, $result->count(ComparisonResult::PASS));
    }

    /** @test */
    public function aRuleOnlyTheActualSideHasIsExactlyOneExtra(): void
    {
        [$golden, $candidates] = self::mutationSubject();
        $expected = self::without($golden, '.z-auto');

        $result = (new CssComparator())->compare($expected, $golden, $candidates);

        self::assertSame(['z-auto' => 'FAIL_EXTRA'], self::failuresOf($result));
        self::assertSame(1, $result->count(ComparisonResult::FAIL_EXTRA));
    }

    /** @test */
    public function aChangedValueIsExactlyOneValueFailure(): void
    {
        [$golden, $candidates] = self::mutationSubject();

        self::assertStringContainsString("    bottom: auto;\n", $golden);

        $actual = \str_replace("    bottom: auto;\n", "    bottom: 0;\n", $golden);

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertSame(['bottom-auto' => 'FAIL_VALUE'], self::failuresOf($result));
        self::assertSame(1, $result->count(ComparisonResult::FAIL_VALUE));
    }

    /** @test */
    public function twoAdjacentRulesSwappedIsExactlyOneOrderFailure(): void
    {
        [$golden, $candidates] = self::mutationSubject();
        $first = self::block($golden, '.z-0');
        $second = self::block($golden, '.z-auto');

        self::assertStringContainsString($first . $second, $golden);

        $actual = \str_replace($first . $second, $second . $first, $golden);

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertSame(1, $result->count(ComparisonResult::FAIL_ORDER));
        self::assertSame(
            \count($result->statuses()) - 1,
            $result->count(ComparisonResult::PASS)
        );
        self::assertSame(['z-auto' => 'FAIL_ORDER'], self::failuresOf($result));
    }

    /** @test */
    public function aRuleMovedAcrossManyOthersIsStillExactlyOneOrderFailure(): void
    {
        [$golden, $candidates] = self::mutationSubject();
        $moved = self::block($golden, '.inset-0');
        $actual = self::without($golden, '.inset-0');
        $anchor = self::block($actual, '.z-auto');

        $actual = \str_replace($anchor, $anchor . $moved, $actual);

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertSame(['inset-0' => 'FAIL_ORDER'], self::failuresOf($result));
    }

    /** @test */
    public function aChangedThemeVariableIsARegionFailureAndNoCandidateFails(): void
    {
        [$golden, $candidates] = self::mutationSubject();

        self::assertStringContainsString('--spacing: 0.25rem;', $golden);

        $actual = \str_replace('--spacing: 0.25rem;', '--spacing: 0.3rem;', $golden);

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertSame(['(theme) --spacing' => 'FAIL_VALUE'], self::failuresOf($result));
        self::assertSame(1.0, $result->passRate());
    }

    /** @test */
    public function reformattingAGoldenChangesNothing(): void
    {
        [$golden, $candidates] = self::mutationSubject();

        // Collapse every newline and indentation run: a different layout, the same CSS.
        $actual = \str_replace(["\n    ", "\n  ", "\n"], ' ', $golden);

        $result = (new CssComparator())->compare($golden, $actual, $candidates);

        self::assertTrue($result->isIdentical(), $result->summary());
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function mutationSubject(): array
    {
        $paths = new CorpusPaths();

        return [
            self::read($paths->goldenPath(self::MUTATION_KEY)),
            self::candidatesOf($paths, self::MUTATION_KEY),
        ];
    }

    /**
     * The text of a one-declaration utility rule as the CLI prints it.
     */
    private static function block(string $css, string $selector): string
    {
        $start = \strpos($css, "\n  " . $selector . " {\n");

        self::assertNotFalse($start, $selector . ' is not in the golden.');

        $end = \strpos($css, "\n  }\n", (int) $start + 1);

        self::assertNotFalse($end);

        return \substr($css, (int) $start + 1, (int) $end + 5 - (int) $start - 1);
    }

    private static function without(string $css, string $selector): string
    {
        return \str_replace(self::block($css, $selector), '', $css);
    }

    /**
     * Candidate => status, for the candidates that did not pass.
     *
     * @return array<string, string>
     */
    private static function failuresOf(ComparisonResult $result): array
    {
        $failed = [];

        foreach ($result->failures() as $failure) {
            $failed[$failure['candidate']] = $failure['status'];
        }

        return $failed;
    }

    /**
     * @return list<string>
     */
    private static function candidatesOf(CorpusPaths $paths, string $key): array
    {
        $file = $paths->kind($key) === CorpusPaths::KIND_DIRECTIVE
            ? $paths->candidatesPath($key)
            : $paths->sourcePath($key);

        self::assertNotNull($file);

        return CorpusPaths::parseCandidates(self::read($file));
    }

    private static function read(string $path): string
    {
        $contents = \file_get_contents($path);

        self::assertNotFalse($contents, $path . ' is unreadable.');

        return \str_replace("\r\n", "\n", (string) $contents);
    }
}
