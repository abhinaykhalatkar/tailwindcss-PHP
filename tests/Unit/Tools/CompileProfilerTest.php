<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Import\ResolvedImport;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\CompileProfiler;

/**
 * `tools/profile-compile.php`'s class (TASK-0041 criterion 2): the argument
 * parsing, the key validation (T-01: a key is looked up in the corpus listing,
 * never joined to a path), the request built for each corpus kind, the
 * profile's shape and the table it renders. Nothing here asserts a duration.
 */
final class CompileProfilerTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/CompileProfiler.php';
    }

    protected function setUp(): void
    {
        $this->root = TempTree::make('compile-profiler-');
        $this->put('corpus/utilities/tiny.txt', "# a comment\nflex p-4\n\nhover:underline\n");
        $this->put('corpus/variants/few.txt', "md:flex\nfocus:p-2\n");
        $this->put(
            'corpus/directives/entry.css',
            "@import \"tailwindcss\" source(none);\n@import \"./part.css\";\n.btn { @apply p-4; }\n"
        );
        $this->put('corpus/directives/entry.candidates.txt', "flex\nbtn-x\n");
        $this->put('corpus/directives/part.css', ".part { color: red; }\n");
        $this->put('fixtures/page.html', '<div class="flex"></div>');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    // ------------------------------------------------------------- arguments

    /**
     * @test
     *
     * @dataProvider goodArguments
     *
     * @param list<string> $arguments
     * @param array{key: string, runs: int, minify: bool} $expected
     */
    public function argumentsAreParsed(array $arguments, array $expected): void
    {
        self::assertSame($expected, CompileProfiler::parseArguments($arguments));
    }

    /**
     * @return array<string, array{0: list<string>, 1: array{key: string, runs: int, minify: bool}}>
     */
    public static function goodArguments(): array
    {
        return [
            'a key alone' => [['utilities/layout'], ['key' => 'utilities/layout', 'runs' => 5, 'minify' => false]],
            'runs and minify' => [
                ['utilities/layout', '--runs=3', '--minify'],
                ['key' => 'utilities/layout', 'runs' => 3, 'minify' => true],
            ],
            'options first' => [
                ['--minify', '--runs=1', 'variants/responsive'],
                ['key' => 'variants/responsive', 'runs' => 1, 'minify' => true],
            ],
            'the most runs' => [
                ['directives/apply', '--runs=50'],
                ['key' => 'directives/apply', 'runs' => 50, 'minify' => false],
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider badArguments
     *
     * @param list<string> $arguments
     */
    public function badArgumentsAreRefused(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompileProfiler::parseArguments($arguments);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function badArguments(): array
    {
        return [
            'no key' => [[]],
            'two keys' => [['utilities/layout', 'utilities/typography']],
            'no runs' => [['utilities/layout', '--runs=0']],
            'too many runs' => [['utilities/layout', '--runs=51']],
            'runs not a number' => [['utilities/layout', '--runs=3x']],
            'runs empty' => [['utilities/layout', '--runs=']],
            'runs negative' => [['utilities/layout', '--runs=-1']],
            'an unknown option' => [['utilities/layout', '--fast']],
        ];
    }

    // ------------------------------------------------------------------ keys

    /**
     * @test
     *
     * @dataProvider unknownKeys
     */
    public function aKeyOutsideTheListingIsRefusedWithoutAPath(string $key): void
    {
        try {
            $this->profiler()->request($key, false);
            self::fail('the key was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString('corpus/', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownKeys(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'traversal inside a group' => ['utilities/../../fixtures/page'],
            'a group' => ['utilities'],
            'a missing entry' => ['utilities/nope'],
            'a partial' => ['directives/part'],
            'an absolute path' => ['/etc/passwd'],
            'a NUL byte' => ["utilities/tiny\0.txt"],
            'empty' => [''],
        ];
    }

    /** @test */
    public function anExtractorFixtureIsNotACompile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a compile');

        $this->profiler()->request('extractor/page', false);
    }

    /** @test */
    public function keysListsTheCompilableEntries(): void
    {
        self::assertSame(['directives/entry', 'utilities/tiny', 'variants/few'], $this->profiler()->keys());
    }

    // -------------------------------------------------------------- requests

    /** @test */
    public function aCandidateListCompilesUnderTheGoldensPreamble(): void
    {
        $request = $this->profiler()->request('utilities/tiny', true);

        self::assertSame("@import \"tailwindcss\" source(none);\n", $request->entryCss);
        self::assertSame(['flex', 'p-4', 'hover:underline'], $request->candidates);
        self::assertTrue($request->minify);
        self::assertSame('input.css', $request->entryPath);
    }

    /** @test */
    public function aDirectiveEntryCompilesItsOwnCssWithItsPartials(): void
    {
        $request = $this->profiler()->request('directives/entry', false);

        self::assertStringContainsString('@apply p-4;', $request->entryCss);
        self::assertSame(['flex', 'btn-x'], $request->candidates);
        self::assertSame('entry.css', $request->entryPath);
        self::assertFalse($request->minify);

        $partial = $request->imports->resolve('./part.css', 'entry.css');

        self::assertInstanceOf(ResolvedImport::class, $partial);
        self::assertStringContainsString('.part', $partial->css());
    }

    // --------------------------------------------------------------- profile

    /**
     * @test
     *
     * @dataProvider compilableKeys
     */
    public function aProfileTimesEveryPhaseOfEveryRun(string $key, int $utilities): void
    {
        $profile = $this->profiler()->profile($key, 2, false);

        self::assertSame($key, $profile['key']);
        self::assertSame(2, $profile['runs']);
        self::assertSame(CompileProfiler::PHASES, \array_keys($profile['phases']));

        foreach ($profile['phases'] as $samples) {
            self::assertCount(2, $samples);
        }

        self::assertCount(2, $profile['compile']);
        self::assertSame($utilities, $profile['utilities']);
        self::assertTrue($profile['faithful'], 'the phases reproduce Engine\Compiler byte for byte');
        self::assertSame(0, $profile['diagnostics']);
        self::assertGreaterThan(0, $profile['peakBytes']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function compilableKeys(): array
    {
        return [
            'a utility list' => ['utilities/tiny', 3],
            'a variant list' => ['variants/few', 2],
            'a directive entry' => ['directives/entry', 1],
        ];
    }

    /**
     * Every committed corpus entry that compiles is reproduced by the phases,
     * so the timings describe the real compile, not an approximation of it.
     *
     * @test
     */
    public function thePhasesReproduceTheEngineOnTheCommittedCorpus(): void
    {
        $profiler = new CompileProfiler(new CorpusPaths());
        $diverged = [];

        foreach ($profiler->keys() as $key) {
            if (!$profiler->profile($key, 1, false)['faithful']) {
                $diverged[] = $key;
            }
        }

        self::assertSame([], $diverged);
    }

    /** @test */
    public function aMinifiedProfileIsFaithfulToo(): void
    {
        self::assertTrue($this->profiler()->profile('directives/entry', 1, true)['faithful']);
    }

    /** @test */
    public function runsOutsideTheBoundsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->profiler()->profile('utilities/tiny', CompileProfiler::MAX_RUNS + 1, false);
    }

    // ----------------------------------------------------------------- table

    /** @test */
    public function theTableShowsBestMedianAndShare(): void
    {
        $phases = [];

        foreach (CompileProfiler::PHASES as $index => $phase) {
            $phases[$phase] = [0.001 * ($index + 1), 0.002 * ($index + 1), 0.003 * ($index + 1)];
        }

        $table = CompileProfiler::render([
            'key' => 'utilities/layout',
            'runs' => 3,
            'minify' => false,
            'candidates' => 755,
            'utilities' => 750,
            'diagnostics' => 0,
            'phases' => $phases,
            'compile' => [0.080, 0.075, 0.090],
            'peakBytes' => 12 * 1048576 + 314573,
            'faithful' => true,
        ]);

        $expected = 'Profile of utilities/layout: 3 runs, pretty output, 755 candidates,'
            . " 750 utilities, 0 diagnostics.\n"
            . "\n"
            . "phase                   best ms   median ms    share\n"
            . "setup                      1.00        2.00     2.2%\n"
            . "parse                      2.00        4.00     4.4%\n"
            . "directives                 3.00        6.00     6.7%\n"
            . "apply                      4.00        8.00     8.9%\n"
            . "candidates                 5.00       10.00    11.1%\n"
            . "sort                       6.00       12.00    13.3%\n"
            . "assemble                   7.00       14.00    15.6%\n"
            . "flatten                    8.00       16.00    17.8%\n"
            . "serialize                  9.00       18.00    20.0%\n"
            . "sum of phases             45.00       90.00   100.0%\n"
            . "Engine\\Compiler           75.00       80.00\n"
            . "\n"
            . "Peak memory: 12.3 MB.\n"
            . "Engine\\Compiler has no phase hooks, so the phases are this profiler's own run"
            . " of its public steps;\n"
            . "that run produced Engine\\Compiler's output byte for byte."
            . " Best and median are over the runs after one warm-up, the GC paused.\n";

        self::assertSame($expected, $table);
    }

    /** @test */
    public function aDivergentRunSaysTheTimingsAreApproximate(): void
    {
        $phases = \array_fill_keys(CompileProfiler::PHASES, [0.001]);
        $table = CompileProfiler::render([
            'key' => 'directives/apply',
            'runs' => 1,
            'minify' => true,
            'candidates' => 1,
            'utilities' => 1,
            'diagnostics' => 1,
            'phases' => $phases,
            'compile' => [0.002],
            'peakBytes' => 1048576,
            'faithful' => false,
        ]);

        self::assertStringContainsString('1 run, minified output, 1 candidate, 1 utility, 1 diagnostic.', $table);
        self::assertStringContainsString('DIFFERED from Engine\\Compiler', $table);
        self::assertStringContainsString('approximate', $table);
    }

    private function put(string $relPath, string $contents): void
    {
        $path = $this->root . '/' . $relPath;

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0755, true);
        }

        \file_put_contents($path, $contents);
    }

    private function profiler(): CompileProfiler
    {
        return new CompileProfiler(new CorpusPaths($this->root, $this->root . '/fixtures'));
    }
}
