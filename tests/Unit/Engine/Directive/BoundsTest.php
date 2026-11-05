<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Import\ResolvedImport;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\Tripwire;

/**
 * TASK-0033 round 2 (T-05): the processor's work is bounded whatever the
 * input — import fan-out, its own diagnostics, `@source inline()` expansion —
 * measured on the review's probe shapes (CPU time with the cycle collector
 * paused, memory `host-php-zts-no-opcache-timing`).
 */
final class BoundsTest extends TestCase
{
    /**
     * 16 levels, each file importing the next twice (65 535 import instances
     * before round 2: 4.27 s, 65 535 resolver calls, 98 304 diagnostics).
     *
     * @test
     */
    public function aDoublingImportTreeIsCappedAndMemoised(): void
    {
        $files = [];

        for ($i = 1; $i <= 16; ++$i) {
            $next = $i < 16 ? \str_repeat('@import "./f' . ($i + 1) . '.css";', 2) : '';
            $files['f' . $i . '.css'] = $next . '.f' . $i . '{x:1}';
        }

        [$sheet, $bag, $elapsed, $calls] = self::measure('@import "./f1.css";', $files);

        self::assertLessThanOrEqual(1001, $calls, 'resolver calls');
        Tripwire::assertUnder(5.0, $elapsed, __FUNCTION__);
        self::assertLessThanOrEqual(Tokenizer::MAX_DIAGNOSTICS + 1, \count($bag));
        self::assertNotSame([], $sheet->authored);
    }

    /**
     * 50 000 imports of one tiny file (1.1 MB of entry): one diagnostic at the
     * cap, the file resolved and parsed once.
     *
     * @test
     */
    public function manyImportsOfOneFileStopAtTheCap(): void
    {
        $entry = \str_repeat('@import "./tiny.css";', 50000);
        [$sheet, $bag, $elapsed, $calls] = self::measure($entry, ['tiny.css' => '.t{x:1}']);

        self::assertSame(1, $calls, 'one (specifier, file) pair is resolved once');
        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
        self::assertStringContainsString('more than 1000 imports', $bag->all()[0]->message());
        self::assertCount(1000, $sheet->authored);
        Tripwire::assertUnder(10.0, $elapsed, __FUNCTION__);
    }

    /**
     * A diamond of self-importing files: every refusal is a diagnostic, but
     * the bag is capped like the parser's.
     *
     * @test
     */
    public function aDiamondOfSelfImportsHasCappedDiagnostics(): void
    {
        $files = [];

        for ($i = 1; $i <= 12; ++$i) {
            $self = '@import "./d' . $i . '.css";';
            $files['d' . $i . '.css'] = $self . \str_repeat('@import "./d' . ($i + 1) . '.css";', 2);
        }

        $files['d13.css'] = '.end{x:1}';
        [, $bag] = self::measure('@import "./d1.css";', $files);

        self::assertLessThanOrEqual(Tokenizer::MAX_DIAGNOSTICS + 1, \count($bag));
        self::assertStringContainsString('Too many directive diagnostics', $bag->all()[\count($bag) - 1]->message());
    }

    /**
     * 6 000 distinct words of 512 expansions each (3.07 M strings and 253 MB
     * before round 2).
     *
     * @test
     */
    public function inlineCandidatesStopAtTheCap(): void
    {
        $words = [];

        for ($i = 0; $i < 6000; ++$i) {
            $words[] = 'w' . $i . '-{1..512}';
        }

        [$sheet, $bag, $elapsed, , $peak] = self::measure('@source inline("' . \implode(' ', $words) . '");', []);

        self::assertCount(20000, $sheet->inlineCandidates);
        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::W_CANDIDATE_LIMIT, $bag->all()[0]->code());
        Tripwire::assertUnder(5.0, $elapsed, __FUNCTION__);
        self::assertLessThan(32 * 1024 * 1024, $peak, \sprintf('peak %.1f MB', $peak / 1048576));
    }

    /**
     * 3 000 repeats of one 512-expansion word (280 MB for 512 values before
     * round 2): each distinct word is expanded once, so 512 values and no
     * limit reached.
     *
     * @test
     */
    public function aRepeatedInlineWordIsExpandedOnce(): void
    {
        $text = \implode(' ', \array_fill(0, 3000, 'p-{1..512}'));
        $css = '@source inline("' . $text . '"); @source inline("' . $text . '");';
        [$sheet, $bag, $elapsed, , $peak] = self::measure($css, []);

        self::assertCount(512, $sheet->inlineCandidates);
        self::assertSame([], $bag->toArray());
        Tripwire::assertUnder(5.0, $elapsed, __FUNCTION__);
        self::assertLessThan(32 * 1024 * 1024, $peak, \sprintf('peak %.1f MB', $peak / 1048576));
    }

    /**
     * A file imported several times is parsed once: its parser diagnostic
     * appears once.
     *
     * @test
     */
    public function aFileImportedTwiceIsParsedOnce(): void
    {
        $entry = \str_repeat('@import "./broken.css";', 5);
        [$sheet, $bag] = self::measure($entry, ['broken.css' => '.b{x:1} }']);

        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $bag->all()[0]->code());
        self::assertCount(5, $sheet->authored, 'each import still splices its rule');
    }

    /**
     * 20 000 separate directives with the same word: the word is expanded
     * once for the whole sheet (20 000 × 512 expansions otherwise).
     *
     * @test
     */
    public function aWordRepeatedAcrossDirectivesIsExpandedOnce(): void
    {
        $css = \str_repeat('@source inline("p-{1..512}");', 20000);
        [$sheet, $bag, $elapsed] = self::measure($css, []);

        self::assertCount(512, $sheet->inlineCandidates);
        self::assertSame([], $bag->toArray());
        Tripwire::assertUnder(9.0, $elapsed, __FUNCTION__);
    }

    /** @test */
    public function includedAndExcludedCandidatesAreSets(): void
    {
        $run = new ProcessorHarness('@source inline("a b a {a,b}"); @source not inline("c c");');

        self::assertSame(['a', 'b'], $run->sheet->inlineCandidates);
        self::assertSame(['c'], $run->sheet->excludedCandidates);
    }

    /** @test */
    public function theInlineCapCountsIncludedAndExcludedTogether(): void
    {
        $options = new ProcessorOptions('input.css', 0, true, 4194304, 16, 1000, 3);
        $run = new ProcessorHarness('@source inline("a b"); @source not inline("c d");', [], $options);

        self::assertSame(['a', 'b'], $run->sheet->inlineCandidates);
        self::assertSame(['c'], $run->sheet->excludedCandidates);
        self::assertSame([Diagnostic::W_CANDIDATE_LIMIT], $run->codes());
    }

    /**
     * Round 1 left the entry out when its size was not passed; the budget
     * now always counts it (a 3.1 MB entry plus a 2.08 MB import is refused).
     *
     * @test
     */
    public function theEntryCountsAgainstTheByteBudget(): void
    {
        $entry = '.e{content:"' . \str_repeat('a', 3100000) . '"}@import "./big.css";';
        $run = new ProcessorHarness($entry, ['big.css' => '.b{content:"' . \str_repeat('b', 2080000) . '"}']);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertStringContainsString('exceed 4194304 bytes', $run->messages()[0]);
    }

    /**
     * `E_*` codes are errors, `W_*` warnings, `I_*` information.
     *
     * @test
     */
    public function diagnosticLevelsFollowTheirCodes(): void
    {
        $run = new ProcessorHarness('@plugin "x"; @source glob("*"); .a { @apply ; } @utility a-* { x: y; }');
        $levels = [];

        foreach ($run->bag->all() as $diagnostic) {
            $levels[$diagnostic->code()][] = $diagnostic->level();
        }

        self::assertSame(
            [
                Diagnostic::E_UNSUPPORTED_DIRECTIVE => [Diagnostic::ERROR, Diagnostic::ERROR],
                Diagnostic::W_SOURCE_IGNORED => [Diagnostic::WARNING],
                Diagnostic::E_APPLY_UNKNOWN => [Diagnostic::ERROR],
            ],
            $levels
        );
    }

    /** @test */
    public function echoesNeverCutAMultibyteCharacter(): void
    {
        $spec = './' . \str_repeat('é', 40) . '.css';
        $run = new ProcessorHarness('@import "' . $spec . '";');
        $message = $run->messages()[0];

        self::assertNotFalse(\json_encode($message), 'valid UTF-8');
        self::assertStringContainsString('…', $message);
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{0: ProcessedSheet, 1: DiagnosticBag, 2: float, 3: int, 4: int}
     */
    private static function measure(string $css, array $files): array
    {
        $resolver = new class (new InMemoryImportResolver($files)) implements ImportResolverInterface {
            /**
             * @var int
             */
            public $calls = 0;

            /**
             * @var ImportResolverInterface
             */
            private $inner;

            public function __construct(ImportResolverInterface $inner)
            {
                $this->inner = $inner;
            }

            public function resolve(string $spec, string $fromRelPath): ?ResolvedImport
            {
                ++$this->calls;

                return $this->inner->resolve($spec, $fromRelPath);
            }
        };
        $bag = new DiagnosticBag();
        $entry = Parser::parse($css, $bag);
        $collecting = \gc_enabled();
        \gc_disable();

        if (\function_exists('memory_reset_peak_usage')) {
            \memory_reset_peak_usage();
        }

        $before = \memory_get_usage();

        try {
            $started = Tripwire::cpu();
            $sheet = Processor::process(
                $entry,
                $resolver,
                new ThemeRegistry(),
                UtilityRegistry::default(),
                new VariantRegistry(Variants::table()),
                new ProcessorOptions('input.css', \strlen($css)),
                $bag
            );
            $elapsed = Tripwire::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        return [$sheet, $bag, $elapsed, $resolver->calls, \memory_get_peak_usage() - $before];
    }
}
