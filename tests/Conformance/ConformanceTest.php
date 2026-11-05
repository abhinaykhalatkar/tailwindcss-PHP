<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Compiler as EngineCompiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\ConformanceReport;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\CssComparator;
use TailwindPhp\Tests\Conformance\Support\EngineRunner;
use TailwindPhp\Tests\Conformance\Support\Inventory;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tools\GoldenGenerator;
use TailwindPhp\Tools\KnownDifferences;

/**
 * The M2 gate (design doc 16 §Conformance suite, doc 08 §Acceptance thresholds,
 * TASK-0047): the whole PHP engine against every `utilities`, `variants` and
 * `directives` golden of the pinned CLI, measured with {@see CssComparator}.
 *
 * - **A row per corpus entry.** A directive golden must be reproduced exactly
 *   (`isIdentical()`, no threshold). A utilities or variants golden is compared
 *   candidate by candidate: every candidate that fails must be named in
 *   `known-differences.php` for that entry (an unlisted failure fails the row
 *   with its `FAIL_*` class, the candidate and the first differing rule), and
 *   every listed candidate that now passes fails the row too ("remove it from
 *   the file"), so the allow-list stays honest in both directions. A failure of
 *   a region (theme variables, `@property`, `@keyframes`, layers) is never
 *   excused: the allow-list names candidates only.
 * - **The T1 rate.** Over every utilities and variants candidate, strict: a
 *   known difference is not a pass. The threshold is `thresholds.php['T1']`,
 *   which only ever goes up. The per-family table is printed once, at the end.
 * - **`ERROR_PARSE`.** An engine output that does not re-parse is always a
 *   failure. A golden that carries a legitimate parser diagnostic (the CLI keeps
 *   `url(javascript:alert(1))`, which a CSS parser drops as a bad url) is read
 *   with the diagnostic tolerated, see {@see EngineRunner::golden()}: an
 *   allow-listed candidate whose golden rule was dropped is a confirmed
 *   difference, not one that passes now.
 *
 * Nothing is written (T-01) and no failure message carries a path (T-06). The
 * engine runs with its default limits: a hard limit that trips is a failure.
 */
final class ConformanceTest extends TestCase
{
    /**
     * Diagnostics that mean a hard limit tripped.
     */
    private const LIMITS = ['W_CANDIDATE_LIMIT', 'E_APPLY_DEPTH', 'E_IMPORT_DEPTH', 'W_FILE_TOO_LARGE'];

    /**
     * @var array<string, array{problems: list<string>, records: list<array{0: string, 1: string, 2: bool}>}>
     */
    private static $measured = [];

    /**
     * @var EngineRunner|null
     */
    private static $runner;

    /**
     * @var array<string, array<string, array<string, string>>>|null
     */
    private static $allowed;

    /**
     * @test
     *
     * @dataProvider entries
     */
    public function theEngineMatchesTheGolden(string $key): void
    {
        $measured = self::measure($key);

        self::assertSame([], $measured['problems'], $key . ":\n" . \implode("\n", $measured['problems']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entries(): array
    {
        $cases = [];

        foreach (self::runner()->keys() as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /**
     * A row that trips a hard limit is a failure, not a skip: the check reads the engine's
     * `CODE: message` diagnostics, so the format it relies on is pinned here.
     *
     * @test
     *
     * @dataProvider diagnostics
     */
    public function aTrippedHardLimitIsRecognised(string $diagnostic, bool $limit): void
    {
        self::assertSame($limit, self::isLimit($diagnostic), $diagnostic);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function diagnostics(): array
    {
        return [
            'the candidate cap' => ['W_CANDIDATE_LIMIT: 70000 candidates were not compiled.', true],
            'the apply depth' => ['E_APPLY_DEPTH: @apply nests too deeply.', true],
            'the import depth' => ['E_IMPORT_DEPTH: imports nest too deeply.', true],
            'a file that is too large' => ['W_FILE_TOO_LARGE: x', true],
            'an unknown utility is expected' => ['W_UNKNOWN_UTILITY: zzz', false],
            'a limit named in the message only' => ['W_UNKNOWN_UTILITY: W_CANDIDATE_LIMIT', false],
            'no code at all' => ['plain text', false],
            'nothing' => ['', false],
        ];
    }

    /**
     * Open item of the golden tooling: does the CLI accept an unquoted `url(data:…)` arbitrary
     * value? It does, and writes it as given (probed against the pinned 4.1.18 with a `;` and
     * markup inside the token as well); no corpus line holds one, so the fact is pinned here:
     * the engine agrees with the CLI, and nothing needs listing in `known-differences.php`.
     *
     * @test
     *
     * @dataProvider dataUrls
     */
    public function anUnquotedDataUrlIsAcceptedAsTheCliDoes(string $candidate, string $declaration): void
    {
        $output = (new EngineCompiler())->compile(new CompileRequest(
            "@import \"tailwindcss\" source(none);\n",
            new InMemoryImportResolver([]),
            [$candidate]
        ));

        self::assertStringContainsString($declaration, $output->css, $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dataUrls(): array
    {
        return [
            'unquoted, base64' => [
                'bg-[url(data:image/png;base64,AAAA)]',
                'background-image: url(data:image/png;base64,AAAA);',
            ],
            'unquoted, with a semicolon and markup' => [
                'bg-[url(data:image/svg+xml;utf8,<svg/>)]',
                'background-image: url(data:image/svg+xml;utf8,<svg/>);',
            ],
            'quoted' => [
                'bg-[url("data:image/png;base64,AAAA")]',
                'background-image: url("data:image/png;base64,AAAA");',
            ],
        ];
    }

    /** @test */
    public function everyGroupOfTheCorpusHasRows(): void
    {
        $groups = [];

        foreach (self::runner()->keys() as $key) {
            $groups[(string) \strstr($key, '/', true)] = true;
        }

        self::assertSame(['utilities', 'variants', 'directives'], \array_keys($groups));
        self::assertGreaterThanOrEqual(25, \count(self::runner()->keys()));
    }

    /**
     * An allow-list entry for a corpus entry that is not measured (a typo, a
     * removed file, the extractor group) excuses nothing and would never be
     * noticed otherwise.
     *
     * @test
     */
    public function everyAllowListEntryBelongsToAMeasuredUtilitiesOrVariantsEntry(): void
    {
        $measured = self::runner()->keys();
        $problems = [];

        foreach (self::allowed() as $corpus => $candidates) {
            $corpus = (string) $corpus;
            $group = (string) \strstr($corpus, '/', true);

            if (!\in_array($corpus, $measured, true)) {
                $problems[] = $corpus . ' is not a corpus entry the engine is measured on';
            } elseif ($group === 'directives') {
                $problems[] = $corpus . ' is a directive entry: those are reproduced exactly, nothing is excused';
            }
        }

        self::assertSame([], $problems, \implode("\n", $problems));
    }

    /**
     * Open item of the comparator and golden tooling: two implementations of
     * identifier unescaping (`GoldenGenerator`, `CandidateAttribution`) must
     * agree. They are compared on the whole escaped corpus: every candidate of
     * the utilities and variants groups, escaped by the engine's own
     * `Css\Escape` as it appears in a selector, comes back as itself from both.
     *
     * @test
     */
    public function theTwoIdentifierUnescapersAgreeOnTheEscapedCorpus(): void
    {
        require_once \dirname(__DIR__, 2) . '/tools/GoldenGenerator.php';

        $disagreements = [];
        $count = 0;

        foreach (self::runner()->keys() as $key) {
            if (\strncmp($key, 'directives/', 11) === 0) {
                continue;
            }

            foreach (self::runner()->candidates($key) as $candidate) {
                $escaped = Escape::identifier($candidate);
                $one = GoldenGenerator::unescapeIdentifier($escaped);
                $two = CandidateAttribution::unescapeIdentifier($escaped);
                ++$count;

                if ($one !== $two || $one !== $candidate) {
                    $disagreements[] = \substr($candidate, 0, 60);
                }
            }
        }

        self::assertGreaterThan(5000, $count, 'the escaped corpus is large');
        self::assertSame([], \array_slice($disagreements, 0, 10), \count($disagreements) . ' disagree');
    }

    /**
     * The T1 gate: the strict pass rate over every utilities and variants
     * candidate, per family in the table printed at the end of the run. A rate
     * below `thresholds.php['T1']` fails and lists the gap per family; the
     * threshold is never lowered here.
     *
     * Kept last: the table is printed once, after every row has been measured.
     *
     * @test
     */
    public function theT1PassRateMeetsTheThreshold(): void
    {
        $report = new ConformanceReport();
        $problems = [];

        foreach (self::runner()->keys() as $key) {
            $measured = self::measure($key);

            foreach ($measured['records'] as [$row, $status, $known]) {
                $report->record($row, $status, $known);
            }

            if ($measured['problems'] !== []) {
                $problems[] = $key;
            }
        }

        $t1 = \array_values(\array_diff(\array_keys($report->rows()), ['directives']));
        $thresholds = require __DIR__ . '/thresholds.php';
        $rate = $report->rate($t1);

        \fwrite(\STDERR, "\n" . $report->render('Conformance, strict rate (a known difference is not a pass)'));
        \fwrite(\STDERR, \sprintf(
            "T1 (utilities and variants): %.2f %% over %d candidates, threshold %.0f %%\n",
            $rate * 100,
            $report->total($t1),
            $thresholds['T1'] * 100
        ));

        self::assertGreaterThan(5000, $report->total($t1), 'the corpus is measured');
        self::assertSame([], $problems, 'These entries do not match: ' . \implode(', ', $problems));
        self::assertGreaterThanOrEqual($thresholds['T1'], $rate, "\n" . $report->render('Below the T1 threshold'));
    }

    // ---------------------------------------------------------------- measuring

    /**
     * Compile one entry, compare it with its golden and settle every candidate
     * against the allow-list.
     *
     * @return array{problems: list<string>, records: list<array{0: string, 1: string, 2: bool}>}
     */
    private static function measure(string $key): array
    {
        if (isset(self::$measured[$key])) {
            return self::$measured[$key];
        }

        $runner = self::runner();
        $directive = \strncmp($key, 'directives/', 11) === 0;
        $candidates = $runner->candidates($key);
        $run = $runner->run($key);
        $golden = $runner->golden($key);
        $result = (new CssComparator())->compare($golden['css'], $run['css'], $candidates);
        $listed = self::allowed()[$key] ?? [];
        $problems = [];
        $records = [];

        foreach ($run['reparse'] as $diagnostic) {
            $problems[] = 'The engine output does not re-parse: ' . $diagnostic;
        }

        foreach ($run['diagnostics'] as $diagnostic) {
            if (self::isLimit($diagnostic)) {
                $problems[] = 'A hard limit tripped: ' . \substr($diagnostic, 0, 200);
            }
        }

        foreach ($result->diagnostics() as $diagnostic) {
            $problems[] = ComparisonResult::ERROR_PARSE . ': ' . \substr($diagnostic, 0, 200);
        }

        if ($directive) {
            if (!$result->isIdentical() && !$result->hasParseErrors()) {
                $problems[] = 'The golden is not reproduced exactly:' . self::describe($result, []);
            }

            foreach ($result->statuses() as $candidate => $status) {
                $records[] = ['directives', (string) $status, false];
            }

            return self::$measured[$key] = ['problems' => $problems, 'records' => $records];
        }

        // A listed candidate whose rule the CLI writes outside the utilities layer (a variant that
        // wraps the rule in `@import url(x) { … }`) leaves both utility lists empty, a PASS, and its
        // rule is an `(authored)` region the engine does not write. The two are one difference: the
        // region is paired with the candidate by the wrapper the candidate names, and both are
        // confirmed. Nothing else in a region is excused by the allow-list.
        $paired = [];
        $claimed = [];

        foreach ($result->statuses() as $candidate => $status) {
            $candidate = (string) $candidate;
            $wrapper = $status === ComparisonResult::PASS && isset($listed[$candidate])
                ? self::wrapperOf($candidate)
                : null;

            if ($wrapper === null) {
                continue;
            }

            foreach ($result->failures() as $failure) {
                $label = $failure['candidate'];

                if (
                    !isset($claimed[$label])
                    && \strncmp($label, '(authored)', 10) === 0
                    && $failure['status'] === ComparisonResult::FAIL_MISSING
                    && \strncmp($failure['expected'], $wrapper . '{', \strlen($wrapper) + 1) === 0
                ) {
                    $claimed[$label] = true;
                    $paired[$candidate] = true;

                    break;
                }
            }
        }

        // A region (theme, `@property`, layers) is compared as a whole and never excused by a
        // candidate (the last region difference, `@property` descriptor order, is fixed in the
        // assembler: TASK-0053).
        foreach ($result->failures() as $failure) {
            $label = $failure['candidate'];

            if ($label === '' || $label[0] !== '(' || isset($claimed[$label])) {
                continue;
            }

            $problems[] = $failure['status'] . ' ' . $label . self::excerpt($failure['expected'], $failure['actual']);
        }

        $first = [];

        foreach ($result->failures() as $failure) {
            if (!isset($first[$failure['candidate']])) {
                $first[$failure['candidate']] = $failure;
            }
        }

        $inVariants = \strncmp($key, 'variants/', 9) === 0;

        foreach ($result->statuses() as $candidate => $status) {
            $candidate = (string) $candidate;
            $isListed = isset($listed[$candidate]);
            $row = $inVariants ? 'variants' : ConformanceReport::rowOf(self::inventory(), $candidate);

            if (
                $status === ComparisonResult::PASS
                && $isListed
                && !isset($paired[$candidate])
                && !\in_array($candidate, $golden['tolerated'], true)
            ) {
                $problems[] = 'STALE ' . $candidate
                    . ' is listed in known-differences.php but passes now: remove it from the file.';
                $records[] = [$row, $status, false];

                continue;
            }

            if ($status !== ComparisonResult::PASS && !$isListed) {
                $failure = $first[$candidate] ?? null;
                $problems[] = $status . ' ' . $candidate
                    . ($failure === null ? '' : self::excerpt($failure['expected'], $failure['actual']));
            }

            $records[] = [$row, $status, $isListed];
        }

        foreach (\array_keys($listed) as $candidate) {
            if (!\in_array((string) $candidate, $candidates, true)) {
                $problems[] = 'DANGLING ' . $candidate . ' is listed for this entry but is not one of its candidates.';
            }
        }

        return self::$measured[$key] = ['problems' => $problems, 'records' => $records];
    }

    /**
     * The failures of a result, one per line with their class.
     *
     * @param list<string> $skip Candidates not to describe.
     */
    private static function describe(ComparisonResult $result, array $skip): string
    {
        $text = '';
        $shown = 0;

        foreach ($result->failures() as $failure) {
            if (\in_array($failure['candidate'], $skip, true)) {
                continue;
            }

            if (++$shown > 12) {
                $text .= "\n  … and more";

                break;
            }

            $text .= "\n  " . $failure['status'] . ' ' . $failure['candidate']
                . self::excerpt($failure['expected'], $failure['actual']);
        }

        return $text;
    }

    /**
     * Whether an engine diagnostic (`CODE: message`) says a hard limit tripped.
     */
    public static function isLimit(string $diagnostic): bool
    {
        return \in_array((string) \strstr($diagnostic, ':', true), self::LIMITS, true);
    }

    /**
     * The at-rule a candidate wraps its rule in when its variant is an arbitrary at-rule
     * (`[@import_url(x)]:flex` is `@import url(x)`), or null.
     */
    private static function wrapperOf(string $candidate): ?string
    {
        if ($candidate === '' || $candidate[0] !== '[') {
            return null;
        }

        $depth = 0;

        for ($i = 0, $length = \strlen($candidate); $i < $length; ++$i) {
            if ($candidate[$i] === '[') {
                ++$depth;
            } elseif ($candidate[$i] === ']' && --$depth === 0) {
                $inner = \str_replace('_', ' ', (string) \substr($candidate, 1, $i - 1));

                return $inner !== '' && $inner[0] === '@' ? $inner : null;
            }
        }

        return null;
    }

    private static function excerpt(string $expected, string $actual): string
    {
        return "\n    expected " . \substr($expected, 0, 300) . "\n    actual   " . \substr($actual, 0, 300);
    }

    // ------------------------------------------------------------------ fixtures

    private static function runner(): EngineRunner
    {
        if (self::$runner === null) {
            self::$runner = new EngineRunner(new CorpusPaths());
        }

        return self::$runner;
    }

    private static function inventory(): Inventory
    {
        static $inventory = null;

        if ($inventory === null) {
            $inventory = new Inventory();
        }

        return $inventory;
    }

    /**
     * Allow-listed candidates by corpus entry: corpus => candidate => entry.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private static function allowed(): array
    {
        if (self::$allowed === null) {
            require_once \dirname(__DIR__, 2) . '/tools/KnownDifferences.php';

            self::$allowed = KnownDifferences::index(KnownDifferences::load(__DIR__ . '/known-differences.php'));
        }

        return self::$allowed;
    }
}
