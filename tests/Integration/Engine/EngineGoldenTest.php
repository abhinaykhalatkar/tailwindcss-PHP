<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\CssComparator;
use TailwindPhp\Tests\Conformance\Support\Inventory;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tools\KnownDifferences;

/**
 * The M2 signal (TASK-0034 criterion 8): the whole engine — directives, candidates,
 * variants, `@apply`, the assembler, flattening and serialising — against the pinned
 * CLI's goldens, measured with `CssComparator`:
 *
 * - every directive golden is reproduced **exactly** (`isIdentical()`: the theme
 *   layer, the layers, authored CSS, `@property`, `@keyframes`);
 * - the variant goldens pass at ≥ 95 % (T1 variants; the allow-listed ones are
 *   skipped);
 * - every utility golden — the eight generated family files, the arbitrary
 *   properties and the `edge-*` files — passes at ≥ 98 % per file, every
 *   mismatch (candidate or region) listed in the failure message (round 2: the
 *   gate TASK-0047 builds on).
 *
 * Every engine output must also re-parse without a diagnostic (criterion 7).
 * `edge-rejections` is not measured: its golden holds `url(javascript:alert(1))`,
 * an unquoted `url(` the CSS parser rightly refuses, so the comparator reports
 * `ERROR_PARSE` for the whole file whatever the engine emits.
 */
final class EngineGoldenTest extends TestCase
{
    /**
     * Utility goldens not measured, with the reason.
     */
    private const UNMEASURED = [
        'edge-rejections' => 'its golden holds an unquoted url(javascript:…) the parser refuses (ERROR_PARSE)',
    ];

    private const ENTRY = "@import \"tailwindcss\" source(none);\n";

    /**
     * @test
     *
     * @dataProvider directives
     */
    public function aDirectiveGoldenIsReproducedExactly(string $name): void
    {
        $paths = new CorpusPaths();
        $key = 'directives/' . $name;
        $candidatesFile = \dirname($paths->sourcePath($key)) . '/' . $name . '.candidates.txt';
        $candidates = \is_file($candidatesFile) ? CorpusPaths::parseCandidates(self::read($candidatesFile)) : [];
        $css = self::compile(self::read($paths->sourcePath($key)), $candidates, self::partials($paths), $name . '.css');
        $result = (new CssComparator())->compare(self::read($paths->goldenPath($key)), $css, $candidates);

        self::assertTrue($result->isIdentical(), $name . ":\n" . self::describe($result, null));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function directives(): array
    {
        $paths = new CorpusPaths();
        $cases = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('directives/apply')) . '/*.css') as $file) {
            $name = \basename((string) $file, '.css');

            if (\is_file($paths->root() . '/golden/directives/' . $name . '.css')) {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /** @test */
    public function everyDirectiveGoldenHasARow(): void
    {
        self::assertCount(15, self::directives());
    }

    /** @test */
    public function variantGoldensPassAtLeastNinetyFivePercent(): void
    {
        $paths = new CorpusPaths();
        $allowed = self::allowed();
        $pass = 0;
        $total = 0;
        $report = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('variants/media')) . '/*.txt') as $file) {
            $key = 'variants/' . \basename((string) $file, '.txt');
            $candidates = CorpusPaths::parseCandidates(self::read((string) $file));
            $result = (new CssComparator())->compare(
                self::read($paths->goldenPath($key)),
                self::compile(self::ENTRY, $candidates, [], 'input.css'),
                $candidates
            );

            [$filePass, $fileTotal] = self::tally($result, $allowed[$key] ?? [], null);
            $pass += $filePass;
            $total += $fileTotal;
            $report[] = \sprintf('%s %d/%d', $key, $filePass, $fileTotal)
                . self::describe($result, null, $allowed[$key] ?? []);
        }

        self::assertGreaterThan(700, $total, 'the variant corpus is measured');
        self::assertGreaterThanOrEqual(0.95, $pass / $total, \implode("\n", $report));
    }

    /**
     * @test
     *
     * @dataProvider utilityFiles
     */
    public function aUtilityGoldenPassesAtLeastNinetyEightPercent(string $name): void
    {
        $paths = new CorpusPaths();
        $key = 'utilities/' . $name;
        $allowed = self::allowed()[$key] ?? [];
        $candidates = CorpusPaths::parseCandidates(self::read($paths->sourcePath($key)));
        $result = (new CssComparator())->compare(
            self::read($paths->goldenPath($key)),
            self::compile(self::ENTRY, $candidates, [], 'input.css'),
            $candidates
        );

        [$pass, $total] = self::tally($result, $allowed, null);

        self::assertGreaterThan(0, $total, $key . ' is measured');
        // A region (theme variables, `@property`, the properties fallback,
        // layers) fails the row too: the allow-list covers candidates only.
        self::assertSame([], self::regionFailures($result), $key . self::describe($result, null, $allowed));
        self::assertGreaterThanOrEqual(
            0.98,
            $pass / $total,
            \sprintf('%s %d/%d', $key, $pass, $total) . self::describe($result, null, $allowed)
        );
    }

    /**
     * Every utility corpus file except the unmeasured ones.
     *
     * @return array<string, array{0: string}>
     */
    public static function utilityFiles(): array
    {
        $paths = new CorpusPaths();
        $cases = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('utilities/layout')) . '/*.txt') as $file) {
            $name = \basename((string) $file, '.txt');

            if (!isset(self::UNMEASURED[$name])) {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /** @test */
    public function everyFamilyCorpusIsMeasured(): void
    {
        $files = \array_keys(self::utilityFiles());
        $inventory = new Inventory();

        foreach (\array_keys($inventory->families()) as $family) {
            if ($family !== $inventory->family(Inventory::ARBITRARY_PROPERTY)) {
                self::assertContains($family, $files, 'the ' . $family . ' corpus has a row');
            }
        }

        self::assertGreaterThanOrEqual(15, \count($files));
    }

    /**
     * @param list<string> $candidates
     * @param array<string, string> $files
     */
    private static function compile(string $entry, array $candidates, array $files, string $entryPath): string
    {
        $output = (new Compiler())->compile(new CompileRequest(
            $entry,
            new InMemoryImportResolver($files),
            $candidates,
            false,
            true,
            CompileRequest::DEFAULT_MAX_CANDIDATES,
            $entryPath
        ));
        $bag = new DiagnosticBag();

        Parser::parse($output->css, $bag);
        self::assertSame([], $bag->toArray(), 'the engine output re-parses clean');

        return $output->css;
    }

    /**
     * PASS count and total over the measured candidates: Layout ones when an
     * inventory is given, never the allow-listed ones.
     *
     * @param list<string> $allowed
     *
     * @return array{0: int, 1: int}
     */
    private static function tally(ComparisonResult $result, array $allowed, ?Inventory $inventory): array
    {
        $pass = 0;
        $total = 0;

        foreach ($result->statuses() as $candidate => $status) {
            $candidate = (string) $candidate;

            if ($inventory !== null && self::familyOf($inventory, $candidate) !== 'layout') {
                continue;
            }

            // Allow-listed: the engine refuses it on purpose (the family and
            // variant golden tests assert the refusal; the CLI may emit
            // nothing for it too, so a sheet-level PASS proves nothing).
            if (\in_array($candidate, $allowed, true)) {
                continue;
            }

            ++$total;

            if ($status === ComparisonResult::PASS) {
                ++$pass;
            }
        }

        return [$pass, $total];
    }

    /**
     * The failures of the sheet's regions (not attributed to a candidate).
     *
     * @return list<string>
     */
    private static function regionFailures(ComparisonResult $result): array
    {
        $regions = [];

        foreach ($result->failures() as $failure) {
            $candidate = (string) $failure['candidate'];

            if ($candidate !== '' && $candidate[0] === '(') {
                $regions[] = $failure['status'] . ' ' . $candidate;
            }
        }

        return $regions;
    }

    /**
     * The failures, one per line with their class.
     *
     * @param list<string> $allowed
     */
    private static function describe(ComparisonResult $result, ?Inventory $inventory, array $allowed = []): string
    {
        $lines = '';

        foreach ($result->failures() as $failure) {
            $candidate = (string) $failure['candidate'];

            if (\in_array($candidate, $allowed, true)) {
                continue;
            }

            if ($inventory !== null && $candidate[0] !== '(' && self::familyOf($inventory, $candidate) !== 'layout') {
                continue;
            }

            $lines .= "\n  " . $failure['status'] . ' ' . $candidate
                . "\n    expected " . \substr((string) $failure['expected'], 0, 300)
                . "\n    actual   " . \substr((string) $failure['actual'], 0, 300);
        }

        return $lines;
    }

    /**
     * The inventory family of a candidate's utility (variants and `!` stripped).
     */
    private static function familyOf(Inventory $inventory, string $candidate): ?string
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

        $utility = \ltrim(\trim((string) \substr($candidate, $start), '!'), '-');

        if ($utility !== '' && $utility[0] === '[') {
            return $inventory->family(Inventory::ARBITRARY_PROPERTY);
        }

        $best = null;

        foreach ($inventory->roots() as $root) {
            $matches = $utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0;

            $longer = $best === null || \strlen($root) > \strlen($best);

            if ($root !== Inventory::ARBITRARY_PROPERTY && $matches && $longer) {
                $best = $root;
            }
        }

        return $best === null ? null : $inventory->family($best);
    }

    /**
     * Allow-listed candidates by corpus entry.
     *
     * @return array<string, list<string>>
     */
    private static function allowed(): array
    {
        require_once \dirname(__DIR__, 3) . '/tools/KnownDifferences.php';

        $allowed = [];
        $entries = KnownDifferences::load(\dirname(__DIR__, 2) . '/Conformance/known-differences.php');

        foreach (KnownDifferences::index($entries) as $corpus => $candidates) {
            $allowed[(string) $corpus] = \array_map('strval', \array_keys($candidates));
        }

        return $allowed;
    }

    /**
     * Every directive fixture by file name, for `@import "./x.css"`.
     *
     * @return array<string, string>
     */
    private static function partials(CorpusPaths $paths): array
    {
        $files = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('directives/apply')) . '/*.css') as $file) {
            $files[\basename((string) $file)] = self::read((string) $file);
        }

        return $files;
    }

    private static function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('Cannot read ' . \basename($path) . '.');
        }

        return \str_replace("\r\n", "\n", $contents);
    }
}
