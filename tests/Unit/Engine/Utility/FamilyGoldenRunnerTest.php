<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FamilyGoldenResult;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FamilyGoldenRunner;

/**
 * The shared family gate (TASK-0049): every candidate of a corpus file that belongs
 * to the family is compiled with the real utility compiler, wrapped in its variants
 * by the real variant applier, and compared with the CLI's rules for it.
 *
 * Exercised here on a tiny synthetic corpus and golden in a temp directory, so each
 * outcome is pinned on its own: a match, the three failure classes, rejection parity,
 * a variant, the family restriction and the allow-list rules. The real corpora are
 * measured by the family tests built on the runner.
 */
final class FamilyGoldenRunnerTest extends TestCase
{
    private const GOLDEN = <<<'CSS'
        @layer utilities {
          .block {
            display: block;
          }
          .hidden {
            display: none;
          }
          .flex {
            display: grid;
          }
          .top-foo {
            top: 1px;
          }
          .hover\:block {
            &:hover {
              @media (hover: hover) {
                display: block;
              }
            }
          }
          .md\:hidden {
            @media (width >= 48rem) {
              display: none;
            }
          }
          .p-4 {
            padding: calc(var(--spacing) * 4);
          }
        }

        CSS;

    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        $this->root = TempTree::make('family-golden-');
        \mkdir($this->root . '/corpus/utilities', 0777, true);
        \mkdir($this->root . '/golden/utilities', 0777, true);
        \mkdir($this->root . '/extractor', 0777, true);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    // -------------------------------------------------------------- outcomes

    /** @test */
    public function aCandidateWhoseRulesMatchTheGoldenCounts(): void
    {
        $result = $this->measure(['block', 'hidden']);

        self::assertSame(2, $result->total());
        self::assertSame(2, $result->matched());
        self::assertSame([], $result->mismatches());
        self::assertSame(1.0, $result->rate());
    }

    /** @test */
    public function aDifferentValueIsFailValueAndNamesBothSides(): void
    {
        $result = $this->measure(['flex']);

        self::assertSame(1, $result->total());
        self::assertSame(0, $result->matched());
        self::assertCount(1, $result->mismatches());
        self::assertStringStartsWith('FAIL_VALUE flex (utilities/layout)', $result->mismatches()[0]);
        self::assertStringContainsString('display:grid', $result->mismatches()[0]);
        self::assertStringContainsString('display:flex', $result->mismatches()[0]);
    }

    /** @test */
    public function aRuleTheEngineProducesAndTheGoldenLacksIsFailExtra(): void
    {
        $result = $this->measure(['relative']);

        self::assertStringStartsWith('FAIL_EXTRA relative', $result->mismatches()[0]);
        self::assertStringContainsString('(nothing)', $result->mismatches()[0]);
    }

    /** @test */
    public function aRuleTheGoldenHasAndTheEngineLacksIsFailMissing(): void
    {
        $result = $this->measure(['top-foo']);

        self::assertStringStartsWith('FAIL_MISSING top-foo', $result->mismatches()[0]);
        self::assertStringContainsString('top:1px', $result->mismatches()[0]);
    }

    /** @test */
    public function bothSidesRejectingACandidateIsAMatch(): void
    {
        $result = $this->measure(['top-bar', 'zzz-nonsense']);

        self::assertSame(1, $result->total(), 'zzz-nonsense is not a layout root, so it is not counted');
        self::assertSame(1, $result->matched());
        self::assertSame([], $result->mismatches());
    }

    /** @test */
    public function variantsAreAppliedWithTheRealApplier(): void
    {
        $result = $this->measure(['hover:block', 'md:hidden']);

        self::assertSame(2, $result->matched(), $result->report());
    }

    /** @test */
    public function aVariantTheApplierRefusesLeavesNothingToCompare(): void
    {
        // `nope:block` is not a variant: the engine produces nothing, and so does the golden.
        $result = $this->measure(['nope:block']);

        self::assertSame(1, $result->matched());
    }

    /** @test */
    public function onlyTheFamilysCandidatesAreCounted(): void
    {
        $layout = $this->measure(['block', 'p-4']);
        $spacing = $this->measure(['block', 'p-4'], 'spacing-sizing');

        self::assertSame(1, $layout->total());
        self::assertSame(1, $spacing->total());
    }

    /** @test */
    public function theRateIsMatchedOverTotal(): void
    {
        $result = $this->measure(['block', 'hidden', 'flex', 'top-foo']);

        self::assertSame(4, $result->total());
        self::assertSame(2, $result->matched());
        self::assertSame(0.5, $result->rate());
        self::assertStringContainsString('2 of 4 candidates match (50.00 %)', $result->report());
    }

    // -------------------------------------------------------------- allow-list

    /** @test */
    public function anAllowListedCandidateThatReallyMismatchesIsSkipped(): void
    {
        $result = $this->measure(['block', 'flex'], 'layout', ['utilities/layout' => ['flex']]);

        self::assertSame(1, $result->total());
        self::assertSame(1, $result->matched());
        self::assertSame(['flex'], $result->skipped());
        self::assertSame([], $result->mismatches());
    }

    /** @test */
    public function anAllowListedCandidateThatMatchesIsAFailureNotASkip(): void
    {
        $result = $this->measure(['block'], 'layout', ['utilities/layout' => ['block']]);

        self::assertSame([], $result->skipped());
        self::assertCount(1, $result->mismatches());
        self::assertStringContainsString('allow-listed but matches', $result->mismatches()[0]);
        self::assertStringContainsString('block', $result->mismatches()[0]);
        self::assertLessThan(1.0, $result->rate());
    }

    /** @test */
    public function theAllowListIsPerCorpusEntry(): void
    {
        $result = $this->measure(['flex'], 'layout', ['utilities/other' => ['flex']]);

        self::assertSame(1, $result->total());
        self::assertSame(0, $result->matched());
        self::assertSame([], $result->skipped());
    }

    /** @test */
    public function anAllowListedCandidateOfAnotherFamilyIsNotReported(): void
    {
        $result = $this->measure(['block'], 'layout', ['utilities/layout' => ['p-4']]);

        self::assertSame([], $result->skipped());
        self::assertSame([], $result->mismatches());
    }

    // ------------------------------------------------------------- the files

    /** @test */
    public function extraEdgeFilesAreMeasuredWithTheFamilyFile(): void
    {
        \file_put_contents($this->root . '/corpus/utilities/layout.txt', "block\n");
        \file_put_contents($this->root . '/golden/utilities/layout.css', self::GOLDEN);
        \file_put_contents($this->root . '/corpus/utilities/edge-x.txt', "hidden\nflex\n");
        \file_put_contents($this->root . '/golden/utilities/edge-x.css', self::GOLDEN);

        $result = $this->runner([])->run('layout', ['edge-x']);

        self::assertSame(3, $result->total());
        self::assertSame(2, $result->matched());
        self::assertCount(1, $result->mismatches());
        self::assertStringContainsString('(utilities/edge-x)', $result->mismatches()[0]);
    }

    /** @test */
    public function theGoldenBannerAndOtherLayersAreIgnored(): void
    {
        \file_put_contents(
            $this->root . '/golden/utilities/layout.css',
            "@layer theme, base, components, utilities;\n@layer base {\n  .block {\n    color: red;\n  }\n}\n"
            . self::GOLDEN
        );
        \file_put_contents($this->root . '/corpus/utilities/layout.txt', "block\n");

        $result = $this->runner([])->run('layout');

        self::assertSame(1, $result->matched(), $result->report());
    }

    /** @test */
    public function aMissingGoldenFailsLoudly(): void
    {
        \file_put_contents($this->root . '/corpus/utilities/layout.txt', "block\n");

        $this->expectException(\RuntimeException::class);

        $this->runner([])->run('layout');
    }

    /** @test */
    public function itReadsTheCommittedAllowListByDefault(): void
    {
        $result = (new FamilyGoldenRunner())->run('layout');

        // The committed Layout corpus: nothing allow-listed there, and the
        // runner must at least find and compare its lines.
        self::assertGreaterThan(500, $result->total());
        self::assertSame([], $result->skipped());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<string> $candidates The synthetic corpus lines.
     * @param array<string, list<string>>|null $allowed
     */
    private function measure(array $candidates, string $family = 'layout', ?array $allowed = null): FamilyGoldenResult
    {
        \file_put_contents($this->root . '/corpus/utilities/' . $family . '.txt', \implode("\n", $candidates) . "\n");
        \file_put_contents($this->root . '/golden/utilities/' . $family . '.css', self::GOLDEN);

        return $this->runner($allowed ?? [])->run($family);
    }

    /**
     * A runner over the synthetic tree, with the real inventory and registries.
     *
     * @param array<string, list<string>> $allowed
     */
    private function runner(array $allowed): FamilyGoldenRunner
    {
        return new FamilyGoldenRunner(new CorpusPaths($this->root, $this->root . '/extractor'), null, $allowed);
    }
}
