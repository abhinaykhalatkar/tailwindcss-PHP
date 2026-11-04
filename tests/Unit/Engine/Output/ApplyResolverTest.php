<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Output;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\CompileOutput;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Output\ApplyResolver;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * Phase 4, `@apply` (TASK-0034 criterion 4), through the whole engine as it runs:
 * the applied utilities merge into the host after its own declarations, sorted
 * like the utilities layer, variants nested under the host; a `@utility` that
 * applies carries the applied CSS; unknown candidates and runaway chains are
 * errors that leave the rest applied (`golden/directives/apply.css`).
 */
final class ApplyResolverTest extends TestCase
{
    private const ENTRY = "@import \"tailwindcss/theme.css\" layer(theme);\n";

    /** @test */
    public function appliedDeclarationsFollowTheHostsOwnInUtilityOrder(): void
    {
        $css = self::compile('.btn { color: red; @apply p-4 flex; }')->css;

        self::assertStringContainsString(
            ".btn {\n  color: red;\n  display: flex;\n  padding: calc(var(--spacing) * 4);\n}",
            $css
        );
    }

    /** @test */
    public function variantsStayNestedUnderTheHost(): void
    {
        $css = self::compile(".btn { @apply hover:p-4 md:flex; }\n")->css;

        self::assertStringContainsString(".btn:hover {\n    padding: calc(var(--spacing) * 4);\n  }", $css);
        self::assertStringContainsString("@media (width >= 48rem) {\n  .btn {\n    display: flex;\n  }\n}", $css);
        self::assertLessThan(\strpos($css, '@media (width >= 48rem)'), \strpos($css, '.btn:hover'), 'hover before md');
    }

    /** @test */
    public function eachApplyIsSortedOnItsOwnAndAppendedInOrder(): void
    {
        $css = self::compile('.x { @apply p-4; @apply flex; }')->css;

        self::assertStringContainsString("padding: calc(var(--spacing) * 4);\n  display: flex;", $css);
    }

    /** @test */
    public function anUnknownCandidateIsAnErrorAndTheRestIsApplied(): void
    {
        $output = self::compile('.x { @apply not-a-utility p-4; }');

        self::assertSame([Diagnostic::E_APPLY_UNKNOWN], self::codes($output));
        self::assertSame(Diagnostic::ERROR, $output->diagnostics->all()[0]->level());
        self::assertStringContainsString('not-a-utility', $output->diagnostics->all()[0]->message());
        self::assertStringContainsString('padding: calc(var(--spacing) * 4);', $output->css);
    }

    /** @test */
    public function aUtilityThatAppliesCarriesTheAppliedCss(): void
    {
        $entry = "@import \"tailwindcss/utilities.css\" layer(utilities);\n@utility btn { @apply p-4; }";
        $css = self::compile($entry, ['btn'])->css;

        self::assertStringContainsString(".btn {\n    padding: calc(var(--spacing) * 4);\n  }", $css);
    }

    /** @test */
    public function anApplyCycleIsADepthError(): void
    {
        $output = self::compile('@utility a { @apply b; } @utility b { @apply a; } .x { @apply a; }');

        self::assertContains(Diagnostic::E_APPLY_DEPTH, self::codes($output));
    }

    /** @test */
    public function aChainDeeperThanTheLimitIsADepthError(): void
    {
        $css = '';

        for ($i = 0; $i <= ApplyResolver::MAX_DEPTH + 1; ++$i) {
            $next = $i === ApplyResolver::MAX_DEPTH + 1 ? 'p-4' : 'u' . ($i + 1);
            $css .= '@utility u' . $i . ' { @apply ' . $next . '; } ';
        }

        $output = self::compile($css . '.x { @apply u0; }');

        self::assertContains(Diagnostic::E_APPLY_DEPTH, self::codes($output));
    }

    /**
     * T-05 (round 2): an `@apply` fan-out — ten variants of the next level,
     * seven levels, under 1 KB — stops at the expansion budget with one
     * diagnostic (before: depth 6 took 18 s and 234 MB, depth 7 exhausted 1 GB).
     *
     * @test
     */
    public function anApplyFanOutStopsAtTheExpansionBudget(): void
    {
        $variants = ['hover', 'focus', 'active', 'visited', 'disabled', 'first', 'last', 'odd', 'even', 'checked'];
        $css = '';

        for ($i = 0; $i < 7; ++$i) {
            $next = $i === 6 ? 'p-4' : 'u' . ($i + 1);
            $applied = [];

            foreach ($variants as $variant) {
                $applied[] = $variant . ':' . $next;
            }

            $css .= '@utility u' . $i . ' { @apply ' . \implode(' ', $applied) . '; } ';
        }

        $request = new CompileRequest(
            "@import \"tailwindcss\" source(none);\n" . $css . '.x { @apply u0; } .y { @apply flex; }',
            new InMemoryImportResolver([]),
            ['u0']
        );
        $collecting = \gc_enabled();
        \gc_disable();
        $before = \memory_get_usage();

        try {
            $started = self::cpu();
            $output = (new Compiler())->compile($request);
            $elapsed = self::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertLessThan(1100, \strlen($request->entryCss));
        self::assertStringNotContainsString('display: flex', $output->css, 'the later @apply is left unapplied');
        self::assertSame([Diagnostic::E_APPLY_DEPTH], self::codes($output));
        self::assertStringContainsString('expands past 100000 nodes', $output->diagnostics->all()[0]->message());
        // A tripwire, not a budget (EngineBench owns those): about 0.8 s here.
        self::assertLessThan(3.0, $elapsed, \sprintf('%.2f s of CPU', $elapsed));
        self::assertLessThan(64 * 1048576, \memory_get_peak_usage() - $before);
    }

    /**
     * Round 3, the shapes that flattened past the cap with no diagnostic: the
     * applied bodies are charged as the flattener writes them (at-rule splits,
     * a run of declarations after a nested rule), so the budget stops each one
     * with one `E_APPLY_DEPTH` and the output re-parses within the cap.
     *
     * @test
     *
     * @dataProvider splittingApplies
     */
    public function appliedBodiesAreChargedAsTheyFlatten(string $css): void
    {
        $output = (new Compiler())->compile(new CompileRequest(
            "@import \"tailwindcss\" source(none);\n" . $css,
            new InMemoryImportResolver([]),
            []
        ));
        $bag = new \TailwindPhp\Engine\Diagnostic\DiagnosticBag();
        \TailwindPhp\Engine\Css\Parser::parse($output->css, $bag);

        self::assertSame([Diagnostic::E_APPLY_DEPTH], self::codes($output));
        self::assertSame([], $bag->toArray(), 'the output re-parses');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function splittingApplies(): array
    {
        $media = '@utility q { ';

        for ($i = 0; $i < 500; ++$i) {
            $media .= '@media (min-width:' . $i . 'px){color:red} ';
        }

        $media .= '} ';

        for ($i = 0; $i < 95; ++$i) {
            $media .= '.h' . $i . '{@apply q;} ';
        }

        $interleaved = '';

        for ($h = 0; $h < 100; ++$h) {
            $interleaved .= '.h' . $h . '{' . \str_repeat('@apply flex;@apply hover:flex;', 250) . '} ';
        }

        return ['at-rules holding declarations' => [$media], 'declarations after nested rules' => [$interleaved]];
    }

    /**
     * A refused body inside `@apply` (unknown to the applier after compiling)
     * is charged too: 200 of them over a fan-out utility stop at the budget.
     *
     * @test
     */
    public function aRefusedAppliedBodyIsCharged(): void
    {
        $variants = ['hover', 'focus', 'active', 'visited', 'disabled', 'first', 'last', 'odd', 'even', 'checked'];
        $css = "@import \"tailwindcss\" source(none);\n";

        for ($i = 0; $i < 4; ++$i) {
            $next = $i === 3 ? 'p-4' : 'u' . ($i + 1);
            $applied = \array_map(static function (string $v) use ($next): string {
                return $v . ':' . $next;
            }, $variants);
            $css .= '@utility u' . $i . ' { @apply ' . \implode(' ', $applied) . '; } ';
        }

        $refused = [];

        for ($i = 0; $i < 200; ++$i) {
            $refused[] = 'hover/x' . $i . ':u0';
        }

        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = self::cpu();
            $output = (new Compiler())->compile(new CompileRequest(
                $css . '.h { @apply ' . \implode(' ', $refused) . '; }',
                new InMemoryImportResolver([]),
                []
            ));
            $elapsed = self::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertContains(Diagnostic::E_APPLY_DEPTH, self::codes($output));
        // A tripwire, not a budget: about 0.35 s here (11.8 s before).
        self::assertLessThan(3.0, $elapsed, \sprintf('%.2f s of CPU', $elapsed));
    }

    /**
     * Under the budget a fan-out is applied in full.
     *
     * @test
     */
    public function aSmallFanOutIsAppliedInFull(): void
    {
        $css = '@utility a { @apply hover:p-4 focus:p-4; } @utility b { @apply hover:a focus:a; } .x { @apply b; }';
        $output = self::compile($css);

        self::assertSame([], self::codes($output));
        self::assertSame(4, \substr_count($output->css, 'padding: calc(var(--spacing) * 4);'));
    }

    /** @test */
    public function aChainWithinTheLimitResolves(): void
    {
        $output = self::compile('@utility a { @apply b; } @utility b { @apply p-4; } .x { @apply a; }');

        self::assertSame([], self::codes($output));
        self::assertStringContainsString(".x {\n  padding: calc(var(--spacing) * 4);\n}", $output->css);
    }

    /**
     * What applied utilities read and register is emitted: their theme
     * variables and their `@property` rules.
     *
     * @test
     */
    public function appliedUtilitiesUseTheThemeAndRegisterProperties(): void
    {
        $css = self::compile('.x { @apply p-4 ring-2; }')->css;

        self::assertStringContainsString('--spacing: 0.25rem;', $css);
        self::assertStringContainsString('@property --tw-ring-shadow {', $css);
    }

    /** @test */
    public function anImportantCandidateIsAppliedImportant(): void
    {
        self::assertStringContainsString(
            'padding: calc(var(--spacing) * 4) !important;',
            self::compile('.x { @apply p-4!; }')->css
        );
    }

    /**
     * @param list<string> $candidates
     */
    private static function compile(string $css, array $candidates = []): CompileOutput
    {
        $request = new CompileRequest(self::ENTRY . $css, new InMemoryImportResolver([]), $candidates);

        return (new Compiler())->compile($request);
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
}
