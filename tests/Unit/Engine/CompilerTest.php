<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\CompileOutput;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * `Engine\Compiler` (TASK-0034 criteria 1, 5, 7 and the security criteria): the
 * phases wired in order, the candidate cap, the import options, and the T-15
 * invariant that a hostile candidate set adds no declaration and the output
 * re-parses clean.
 */
final class CompilerTest extends TestCase
{
    private const ENTRY = "@import \"tailwindcss\" source(none);\n";

    /** @test */
    public function candidatesBecomeSortedUtilitiesInTheUtilitiesLayer(): void
    {
        $css = self::compile(self::ENTRY, ['p-4', 'hover:flex', 'flex', 'not-a-utility', 'flex'])->css;
        $utilities = \substr($css, (int) \strpos($css, '@layer utilities'));

        self::assertLessThan(\strpos($utilities, '.p-4'), \strpos($utilities, '.flex {'));
        self::assertLessThan(\strpos($utilities, '.hover\:flex'), \strpos($utilities, '.p-4'));
        self::assertSame(1, \substr_count($css, '.flex {'), 'a repeated candidate is compiled once');
        self::assertStringNotContainsString('not-a-utility', $css);
    }

    /**
     * Only used theme variables are emitted (`--spacing` for `p-4`), with what
     * the preflight reads.
     *
     * @test
     */
    public function onlyUsedThemeVariablesAreEmitted(): void
    {
        $css = self::compile(self::ENTRY, ['p-4'])->css;

        self::assertStringContainsString('--spacing: 0.25rem;', $css);
        self::assertStringContainsString('--default-font-family: var(--font-sans);', $css);
        self::assertStringNotContainsString('--color-red-500', $css);
    }

    /** @test */
    public function theImportantOptionMarksEveryUtilityDeclaration(): void
    {
        $css = self::compile("@import \"tailwindcss\" important source(none);\n", ['flex', 'hover:p-4'])->css;

        self::assertStringContainsString('display: flex !important;', $css);
        self::assertStringContainsString('padding: calc(var(--spacing) * 4) !important;', $css);
        self::assertStringNotContainsString('box-sizing: border-box !important', $css, 'the preflight is no utility');
    }

    /** @test */
    public function thePrefixIsRequiredAndRenamesTheThemeVariables(): void
    {
        $css = self::compile("@import \"tailwindcss\" prefix(tw) source(none);\n", ['tw:p-4', 'p-4'])->css;

        self::assertStringContainsString(".tw\\:p-4 {\n    padding: calc(var(--tw-spacing) * 4);", $css);
        self::assertStringContainsString('--tw-spacing: 0.25rem;', $css);
        self::assertStringNotContainsString('.p-4', $css);
    }

    /** @test */
    public function inlineSourcesAreCompiledAndExcludedOnesAreNot(): void
    {
        $output = self::compile(
            self::ENTRY . "@source inline(\"p-{1..3}\");\n@source not inline(\"p-2\");\n",
            ['p-2', 'flex']
        );

        self::assertStringContainsString('.p-1 {', $output->css);
        self::assertStringContainsString('.p-3 {', $output->css);
        self::assertStringNotContainsString('.p-2 {', $output->css);
        self::assertSame(['p-1', 'p-2', 'p-3'], $output->inlineCandidates);
    }

    /** @test */
    public function withoutThePreflightTheBaseLayerIsEmptyAndSaidSo(): void
    {
        $request = new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), ['flex'], false, false);
        $output = (new Compiler())->compile($request);

        self::assertStringNotContainsString('box-sizing: border-box', $output->css);
        self::assertSame([Diagnostic::I_PREFLIGHT_DISABLED], self::codes($output));
    }

    /**
     * T-06: the imports are the resolver's identities (project-relative),
     * each once, never an absolute path.
     *
     * @test
     */
    public function importsAreReportedAsTheResolversIdentities(): void
    {
        $output = self::compile(
            "@import \"./a.css\";\n@import \"./a.css\";\n@import \"./b.css\";\n",
            [],
            ['a.css' => '.a{color:red}', 'b.css' => '@import "./a.css"; .b{color:blue}']
        );

        self::assertSame(['a.css', 'b.css'], $output->imports);
        self::assertSame([], self::codes($output));
    }

    /**
     * T-05: 300 000 candidates stop at the cap with one warning, the rest
     * ignored.
     *
     * @test
     */
    public function theCandidateCapStopsWithOneWarning(): void
    {
        $candidates = [];

        for ($i = 0; $i < 300000; ++$i) {
            $candidates[] = 'x-' . $i;
        }

        $candidates[] = 'flex';
        $output = self::compile(self::ENTRY, $candidates);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        self::assertStringContainsString(
            '270001 candidates were not compiled: a stylesheet holds at most 30000 candidates',
            $output->diagnostics->all()[0]->message()
        );
        self::assertStringNotContainsString('.flex', $output->css, 'past the cap nothing is compiled');
    }

    /**
     * T-05 (round 2): 100 000 valid candidates (700 KB) stop at the candidate
     * cap with one warning naming what was dropped, the output re-parses, and
     * it takes ≤ 2 s of CPU (5.8 s, 272 MB and no diagnostic before).
     *
     * @test
     */
    public function oneHundredThousandValidCandidatesStayBounded(): void
    {
        $candidates = [];

        for ($i = 0; $i < 100000; ++$i) {
            $candidates[] = 'w-' . $i;
        }

        [$output, $cpu] = self::timed(new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), $candidates));
        $bag = new DiagnosticBag();
        Parser::parse($output->css, $bag);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        $message = $output->diagnostics->all()[0]->message();
        self::assertStringContainsString('70000 candidates were not compiled', $message);
        self::assertSame([], $bag->toArray(), 'the output re-parses');
        // A tripwire, not a budget (EngineBench owns those): about 1.4 s here.
        self::assertLessThan(10.0, $cpu, \sprintf('%.2f s of CPU', $cpu));
    }

    /**
     * T-05 (round 3): a body that compiles and is then refused (a modifier on
     * a variant that takes none, `hover/x1:u0`) still costs its expansion, so
     * it is charged: 30 000 such candidates over a fan-out utility exhaust the
     * room with one warning (11.5 s for 200 of them, nothing charged, before).
     *
     * @test
     *
     * @dataProvider refusedBodies
     */
    public function aRefusedBodyIsChargedAllTheSame(string $import, string $prefix): void
    {
        $candidates = [];

        for ($i = 0; $i < 30000; ++$i) {
            $candidates[] = $prefix . 'hover/x' . $i . ':u0';
        }

        $request = new CompileRequest(self::fanOut($import, $prefix), new InMemoryImportResolver([]), $candidates);
        [$output, $cpu] = self::timed($request);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        self::assertSame(0, $output->stats['utilities']);
        self::assertReparsesWithinTheCap($output);
        // A tripwire, not a budget: about 0.5 s here.
        self::assertLessThan(10.0, $cpu, \sprintf('%.2f s of CPU', $cpu));
    }

    /**
     * The same charge, fast: 300 refused bodies exhaust the room in a fraction
     * of a second (uncharged, each recompiles the fan-out: ≈ 17 s and no
     * warning), so a regression fails here quickly rather than as a slow run
     * of the 30 000-candidate row.
     *
     * @test
     */
    public function aFewHundredRefusedBodiesAlreadyExhaustTheRoom(): void
    {
        $candidates = [];

        for ($i = 0; $i < 300; ++$i) {
            $candidates[] = 'hover/x' . $i . ':u0';
        }

        $request = new CompileRequest(
            self::fanOut('@import "tailwindcss" source(none);', ''),
            new InMemoryImportResolver([]),
            $candidates
        );
        [$output, $cpu] = self::timed($request);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        self::assertLessThan(5.0, $cpu, \sprintf('%.2f s of CPU', $cpu));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedBodies(): array
    {
        return [
            'plain' => ['@import "tailwindcss" source(none);', ''],
            'prefix and important' => ['@import "tailwindcss" prefix(tw) important source(none);', 'tw:'],
        ];
    }

    /**
     * Authored CSS alone may flatten past the parser's node cap (nesting splits
     * rules): the flattener stops at the cap with one diagnostic, and the
     * output still re-parses.
     *
     * @test
     */
    public function authoredNestingPastTheCapStillReparses(): void
    {
        $css = self::ENTRY;

        for ($h = 0; $h < 190; ++$h) {
            $css .= '.h' . $h . '{';

            for ($i = 0; $i < 300; ++$i) {
                $css .= $i % 2 === 1 ? '& .c' . $i . '{color:blue}' : 'color:red;';
            }

            $css .= '} ';
        }

        $output = self::compile($css, []);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], self::codes($output));
        self::assertStringContainsString('holds more than 100000 nodes', $output->diagnostics->all()[0]->message());
        self::assertReparsesWithinTheCap($output);
    }

    /**
     * The theme's keyframes are counted exactly in the room the utilities
     * leave (round 3): 8 000 static `@keyframes` and 49 000 utilities at a
     * limit of 100 000 stop at the node cap and re-parse.
     *
     * @test
     */
    public function theThemesKeyframesAreCountedInTheRoom(): void
    {
        $theme = '@theme static { ';

        for ($i = 0; $i < 8000; ++$i) {
            $theme .= '--animate-k' . $i . ': k' . $i . ' 1s; @keyframes k' . $i . ' { to { opacity: 0 } } ';
        }

        $candidates = [];

        for ($i = 0; $i < 49000; ++$i) {
            $candidates[] = 'w-' . $i;
        }

        $request = new CompileRequest(
            self::ENTRY . $theme . '}',
            new InMemoryImportResolver([]),
            $candidates,
            false,
            true,
            100000
        );
        $output = (new Compiler())->compile($request);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        self::assertSame(8000, \substr_count($output->css, '@keyframes k'));
        self::assertReparsesWithinTheCap($output);
    }

    /**
     * With a high candidate limit the output's node cap stops the compile, so
     * the stylesheet always re-parses (`Parser::MAX_NODES`), variants and all.
     *
     * @test
     */
    public function theOutputNeverPassesTheParsersNodeCap(): void
    {
        $candidates = [];

        foreach (['', 'hover:', 'md:', 'md:hover:'] as $variant) {
            for ($i = 0; $i < 20000; ++$i) {
                $candidates[] = $variant . 'p-' . $i;
            }
        }

        $request = new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), $candidates, false, true, 100000);
        $output = (new Compiler())->compile($request);
        $bag = new DiagnosticBag();
        Parser::parse($output->css, $bag);

        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], self::codes($output));
        self::assertStringContainsString('100000 nodes', $output->diagnostics->all()[0]->message());
        self::assertSame([], $bag->toArray(), 'the output re-parses: within the node cap');
        self::assertGreaterThan(20000, $output->stats['utilities']);
    }

    /**
     * T-15: hostile candidates produce no declaration, and the output re-parses
     * without a diagnostic.
     *
     * @test
     */
    public function hostileCandidatesAddNothingAndTheOutputParsesClean(): void
    {
        $hostile = [
            'bg-[url(javascript:alert(1))]',
            'p-[1px;color:red]',
            '[color:red}body{x:y]',
            'x}body{background:red}',
            'hover:[&]{}:flex',
            '[--x:1;--y:2]',
            "p-[1px\ncolor:red]",
            'p-[1px/*x*/]',
            'before:content-["*/}"]',
        ];
        $clean = self::compile(self::ENTRY, [])->css;
        $output = self::compile(self::ENTRY, $hostile);
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($output->css, $bag);

        self::assertSame([], $bag->toArray(), 'the output re-parses clean');
        self::assertSame($clean, $output->css, 'no hostile candidate added a node');
        self::assertNoValue($sheet->nodes, ['javascript', 'color:red', 'body{', '*/']);
    }

    /** @test */
    public function theSameRequestGivesTheSameBytesAndTheMinifiedFormIsCompact(): void
    {
        $engine = new Compiler();
        $first = $engine->compile(new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), ['flex', 'p-4']));
        $second = $engine->compile(new CompileRequest(self::ENTRY, new InMemoryImportResolver([]), ['flex', 'p-4']));
        $resolver = new InMemoryImportResolver([]);
        $minified = $engine->compile(new CompileRequest(self::ENTRY, $resolver, ['flex', 'p-4'], true));

        self::assertSame($first->css, $second->css);
        self::assertStringContainsString('.flex{display:flex}', $minified->css);
    }

    /**
     * A `@utility` and a `@custom-variant` of one compile do not leak into the
     * next (the registries are copied per compile).
     *
     * @test
     */
    public function oneCompilesCustomUtilitiesDoNotLeakIntoTheNext(): void
    {
        $engine = new Compiler();
        $first = $engine->compile(new CompileRequest(
            self::ENTRY . "@utility tab-4 { tab-size: 4; }\n@custom-variant pointer (@media (pointer: fine));\n",
            new InMemoryImportResolver([]),
            ['tab-4', 'pointer:flex']
        ));
        $resolver = new InMemoryImportResolver([]);
        $second = $engine->compile(new CompileRequest(self::ENTRY, $resolver, ['tab-4', 'pointer:flex']));

        self::assertStringContainsString('.tab-4 {
    tab-size: 4;', $first->css);
        self::assertStringContainsString('@media (pointer: fine)', $first->css);
        self::assertStringNotContainsString('.tab-4', $second->css);
        self::assertStringNotContainsString('pointer: fine', $second->css);
    }

    /** @test */
    public function theStatsCountWhatWasCompiled(): void
    {
        $output = self::compile(self::ENTRY . '.x { @apply p-4; }', ['flex', 'p-4', 'nope']);

        self::assertSame(['candidates' => 3, 'utilities' => 2, 'applies' => 1], $output->stats);
    }

    /**
     * `@utility u0 … u3`, each applying ten variants of the next (u3 applies `p-4`).
     */
    private static function fanOut(string $import, string $prefix): string
    {
        $variants = ['hover', 'focus', 'active', 'visited', 'disabled', 'first', 'last', 'odd', 'even', 'checked'];
        $css = $import . "\n";

        for ($i = 0; $i < 4; ++$i) {
            $next = $i === 3 ? 'p-4' : 'u' . ($i + 1);
            $applied = [];

            foreach ($variants as $variant) {
                $applied[] = $prefix . $variant . ':' . $next;
            }

            $css .= '@utility u' . $i . ' { @apply ' . \implode(' ', $applied) . '; } ';
        }

        return $css;
    }

    /**
     * The output re-parses without a diagnostic and holds at most the
     * parser's node cap (rules, at-rules, declarations).
     */
    private static function assertReparsesWithinTheCap(CompileOutput $output): void
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($output->css, $bag);

        self::assertSame([], $bag->toArray(), 'the output re-parses');
        self::assertLessThanOrEqual(Parser::MAX_NODES, self::nodes($sheet->nodes));
    }

    /**
     * @param list<Node> $nodes
     */
    private static function nodes(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $count += 1 + self::nodes($node->nodes);
            } elseif ($node instanceof AtRule) {
                $count += 1 + self::nodes($node->nodes ?? []);
            } elseif ($node instanceof Declaration) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The output and the CPU seconds of one compile, the cycle collector paused.
     *
     * @return array{0: CompileOutput, 1: float}
     */
    private static function timed(CompileRequest $request): array
    {
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = self::cpu();
            $output = (new Compiler())->compile($request);
            $elapsed = self::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        return [$output, $elapsed];
    }

    private static function cpu(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \microtime(true);
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }

    /**
     * @param list<string> $candidates
     * @param array<string, string> $files
     */
    private static function compile(string $entry, array $candidates, array $files = []): CompileOutput
    {
        return (new Compiler())->compile(new CompileRequest($entry, new InMemoryImportResolver($files), $candidates));
    }

    /**
     * @return list<string>
     */
    private static function codes(CompileOutput $output): array
    {
        $codes = [];

        foreach ($output->diagnostics->all() as $diagnostic) {
            $codes[] = $diagnostic->code();
        }

        return $codes;
    }

    /**
     * @param list<Node> $nodes
     * @param list<string> $needles
     */
    private static function assertNoValue(array $nodes, array $needles): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                foreach ($needles as $needle) {
                    self::assertStringNotContainsString($needle, $node->property . ':' . $node->value);
                }
            } elseif ($node instanceof Rule) {
                self::assertNoValue($node->nodes, $needles);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                self::assertNoValue($node->nodes, $needles);
            }
        }
    }
}
