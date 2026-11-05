<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\Hash;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\CliRunner;
use TailwindPhp\Tools\GoldenGenerator;

/**
 * The golden generator (design doc 16 §Golden generation; decisions ENG-A1,
 * ENG-A7, ENG-A11; threats T-01, T-04, T-18).
 *
 * The generator takes the compile step as a callable, so the interesting parts
 * — what input is assembled, how the banner is stripped and checked, what is
 * written, what `--check` calls drift — are asserted against a small in-memory
 * double that returns banner-bearing output. The fixture binary prints no
 * banner, which is exactly what makes it useful for one row: wired through the
 * real {@see CliRunner}, the generator must refuse its output.
 *
 * No network and no real Tailwind binary are involved anywhere in this class.
 */
final class GoldenGeneratorTest extends TestCase
{
    private const VERSION = '4.1.18';

    private const BANNER = "/*! tailwindcss v4.1.18 | MIT License | https://tailwindcss.com */\n";

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var list<array{input: string, candidates: string, files: array<string, string>, minify: bool}>
     */
    private $calls = [];

    /**
     * @var string
     */
    private $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        self::loadTools();

        $this->root = TempTree::make('tailwindphp-golden-generator');
        $this->calls = [];
        $this->output = self::BANNER . "@layer utilities {\n  .p-4 {\n    padding: 1rem;\n  }\n}\n";

        $this->put('conformance/golden/VERSION', self::VERSION . "\n");
        $this->put('conformance/corpus/utilities/README.txt', "generated later\n");
        $this->put('conformance/corpus/variants/responsive.txt', "# responsive\nsm:p-4 md:p-4\n\nlg:p-4\n");
        $this->put('conformance/corpus/variants/pseudo.txt', "hover:p-4\n");
        $this->put(
            'conformance/corpus/directives/theme-basic.css',
            "@import \"tailwindcss\" source(none);\n@theme { --x: 1; }"
        );
        $this->put('conformance/corpus/directives/theme-basic.candidates.txt', "p-4\nbg-x\n");
        $this->put('conformance/corpus/directives/partial.css', ".card { color: red; }\n");
        $this->put('fixtures/extractor/twig-basic.twig', '<div class="p-4 md:flex">');

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------- input assembly

    /** @test */
    public function aCandidateEntryUsesTheEngA7PreambleAndOneSpaceSeparatedLine(): void
    {
        $this->generator()->run('variants/responsive', false);

        self::assertCount(2, $this->calls);

        // ENG-A7: `@source none;` is not valid on 4.1, so detection is switched
        // off through the import's own option.
        self::assertSame(
            "@import \"tailwindcss\" source(none);\n@source \"./candidates.txt\";\n",
            $this->calls[0]['input']
        );
        self::assertSame('sm:p-4 md:p-4 lg:p-4', $this->calls[0]['candidates']);
        self::assertSame([], $this->calls[0]['files']);
    }

    /** @test */
    public function eachEntryIsCompiledPlainAndThenMinified(): void
    {
        $this->generator()->run('variants/responsive', false);

        self::assertFalse($this->calls[0]['minify']);
        self::assertTrue($this->calls[1]['minify']);
        self::assertSame($this->calls[0]['input'], $this->calls[1]['input']);
        self::assertSame($this->calls[0]['candidates'], $this->calls[1]['candidates']);
    }

    /** @test */
    public function aDirectiveEntryIsTheFixtureItselfWithTheCandidateSourceAppended(): void
    {
        $this->generator()->run('directives/theme-basic', false);

        // The fixture had no trailing newline; the appended directive must not
        // be glued to its last line.
        self::assertSame(
            "@import \"tailwindcss\" source(none);\n@theme { --x: 1; }\n@source \"./candidates.txt\";\n",
            $this->calls[0]['input']
        );
        self::assertSame('p-4 bg-x', $this->calls[0]['candidates']);
        self::assertSame(['partial.css' => ".card { color: red; }\n"], $this->calls[0]['files']);
    }

    /** @test */
    public function anExtractorEntryPointsTheSourceDirectiveAtTheFixtureAndCompilesOnce(): void
    {
        $this->output = self::BANNER
            . "@layer utilities {\n  .p-4 {\n    padding: 1rem;\n  }\n  .md\\:flex {\n    @media (width >= 48rem) {\n"
            . "      display: flex;\n    }\n  }\n}\n";

        $this->generator()->run('extractor/twig-basic', false);

        self::assertCount(1, $this->calls);
        self::assertFalse($this->calls[0]['minify']);
        self::assertSame(
            "@import \"tailwindcss\" source(none);\n@source \"./twig-basic.twig\";\n",
            $this->calls[0]['input']
        );
        self::assertSame(['twig-basic.twig' => '<div class="p-4 md:flex">'], $this->calls[0]['files']);
        self::assertSame(
            "[\n    \"md:flex\",\n    \"p-4\"\n]\n",
            \file_get_contents($this->golden('extractor/twig-basic.json'))
        );
    }

    // ---------------------------------------------------------- the goldens

    /** @test */
    public function goldensAreWrittenWithoutTheBannerAndWithLfOnly(): void
    {
        $this->output = \str_replace("\n", "\r\n", self::BANNER . "@layer utilities {\n}\n");

        $results = $this->generator()->run('variants/pseudo', false);

        self::assertSame("@layer utilities {\n}\n", \file_get_contents($this->golden('variants/pseudo.css')));
        self::assertSame("@layer utilities {\n}\n", \file_get_contents($this->golden('variants/pseudo.min.css')));
        self::assertSame('written', $results[0]['status']);
        self::assertSame('golden/variants/pseudo.css', $results[0]['file']);
    }

    /** @test */
    public function runningAgainChangesNothing(): void
    {
        $this->generator()->run('variants', false);

        $before = \file_get_contents($this->golden('variants/responsive.css'));
        $results = $this->generator()->run('variants', false);

        self::assertSame($before, \file_get_contents($this->golden('variants/responsive.css')));

        foreach ($results as $result) {
            self::assertSame('unchanged', $result['status']);
        }
    }

    /** @test */
    public function aBannerFromAnotherReleaseIsRefusedAndNothingIsWritten(): void
    {
        $this->output = "/*! tailwindcss v4.2.0 | MIT License | https://tailwindcss.com */\n.x{}\n";

        try {
            $this->generator()->run('variants/pseudo', false);
            self::fail('A stylesheet from another release must not become a golden.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('4.2.0', $e->getMessage());
            self::assertStringContainsString('4.1.18', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->golden('variants/pseudo.css'));
    }

    /** @test */
    public function throughTheRealRunnerTheFixtureBinarysBannerlessOutputIsRefused(): void
    {
        RequiresSpawning::check();

        $environment = new Environment();
        $runner = new CliRunner(
            self::fixtureBinary(),
            self::VERSION,
            Checksums::fromTable([
                self::VERSION => [$environment->platform() => Hash::sha256File(self::fixtureBinary())],
            ]),
            $environment,
            new Process(),
            new Filesystem(),
            $this->root,
            20.0
        );

        $generator = new GoldenGenerator($this->paths(), [$runner, 'compile'], self::VERSION);

        try {
            $generator->run('variants/pseudo', false);
            self::fail('Output without a banner cannot be tied to a release.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('banner', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->golden('variants/pseudo.css'));
    }

    // ---------------------------------------------------------------- --only

    /** @test */
    public function onlyAcceptsAnEntryOrAGroupAndNothingElse(): void
    {
        $this->generator()->run('variants/pseudo', false);

        self::assertCount(2, $this->calls);

        $this->calls = [];
        $results = $this->generator()->run('variants', false);

        self::assertCount(4, $this->calls);
        // One result per golden file, so each key appears for its plain and
        // its minified golden.
        self::assertSame(
            ['variants/pseudo', 'variants/responsive'],
            \array_values(\array_unique(\array_column($results, 'key')))
        );
    }

    /**
     * T-01: the filter is looked up in the corpus listing, never joined to a path.
     *
     * @test
     *
     * @dataProvider hostileSelectors
     */
    public function onlyRejectsAnythingOutsideTheListing(string $selector): void
    {
        try {
            $this->generator()->run($selector, false);
            self::fail('"' . $selector . '" must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('--only', $e->getMessage());
        }

        self::assertSame([], $this->calls, 'Nothing may be compiled for a rejected selector.');
        self::assertFileDoesNotExist($this->root . '/x');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileSelectors(): array
    {
        return [
            'parent traversal' => ['../x'],
            'deep traversal' => ['variants/../../../x'],
            'unknown entry' => ['variants/nope'],
            'unknown group' => ['nonsense'],
            'absolute path' => ['/etc/passwd'],
            'empty' => [''],
        ];
    }

    // --------------------------------------------------------------- --check

    /** @test */
    public function checkReportsAnUnchangedGoldenAsUnchanged(): void
    {
        $this->generator()->run('variants/pseudo', false);

        $results = $this->generator()->run('variants/pseudo', true);

        self::assertSame(['unchanged', 'unchanged'], \array_column($results, 'status'));
        self::assertFalse(GoldenGenerator::hasDrift($results));
    }

    /** @test */
    public function checkDetectsDriftAndLeavesTheCommittedGoldenAlone(): void
    {
        $this->generator()->run('variants/pseudo', false);

        $this->output = self::BANNER . "@layer utilities {\n  .p-8 {\n  }\n}\n";
        $results = $this->generator()->run('variants/pseudo', true);

        self::assertSame(['drift', 'drift'], \array_column($results, 'status'));
        self::assertTrue(GoldenGenerator::hasDrift($results));
        self::assertStringContainsString(
            'padding: 1rem',
            (string) \file_get_contents($this->golden('variants/pseudo.css'))
        );
    }

    /** @test */
    public function checkTreatsAMissingGoldenAsDriftAndDoesNotCreateIt(): void
    {
        $results = $this->generator()->run('variants/pseudo', true);

        self::assertSame(['missing', 'missing'], \array_column($results, 'status'));
        self::assertTrue(GoldenGenerator::hasDrift($results));
        self::assertFileDoesNotExist($this->golden('variants/pseudo.css'));
    }

    /** @test */
    public function aRegenerationThatChangesAGoldenIsReportedAsWritten(): void
    {
        $this->generator()->run('variants/pseudo', false);

        $this->output = self::BANNER . "@layer utilities {\n  .p-8 {\n  }\n}\n";
        $results = $this->generator()->run('variants/pseudo', false);

        self::assertSame(['written', 'written'], \array_column($results, 'status'));
        self::assertStringContainsString('.p-8', (string) \file_get_contents($this->golden('variants/pseudo.css')));
    }

    // ------------------------------------------------------------- the banner

    /** @test */
    public function stripBannerRemovesTheFirstLineOnly(): void
    {
        self::assertSame(
            "@layer theme {}\n",
            GoldenGenerator::stripBanner(self::BANNER . "@layer theme {}\n", self::VERSION)
        );
        self::assertSame(
            '@layer theme{}',
            GoldenGenerator::stripBanner(self::BANNER . '@layer theme{}', self::VERSION)
        );
    }

    /**
     * @test
     *
     * @dataProvider unusableBanners
     */
    public function stripBannerRefusesAnythingItCannotTieToTheExpectedRelease(string $css): void
    {
        $this->expectException(\RuntimeException::class);

        GoldenGenerator::stripBanner($css, self::VERSION);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableBanners(): array
    {
        return [
            'no banner' => ["@layer theme {}\n"],
            'empty' => [''],
            'another release' => ["/*! tailwindcss v4.1.19 | MIT License | https://tailwindcss.com */\n.x{}\n"],
            'banner not first' => [".x{}\n/*! tailwindcss v4.1.18 | MIT License | https://tailwindcss.com */\n"],
            'prefix of a longer version' => [
                "/*! tailwindcss v4.1.180 | MIT License | https://tailwindcss.com */\n.x{}\n",
            ],
            'unterminated' => ['/*! tailwindcss v4.1.18 | MIT License'],
        ];
    }

    // ------------------------------------------------- selector un-escaping

    /**
     * @test
     *
     * @dataProvider escapedIdentifiers
     */
    public function unescapeIdentifierReversesCssEscaping(string $escaped, string $expected): void
    {
        self::assertSame($expected, GoldenGenerator::unescapeIdentifier($escaped));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapedIdentifiers(): array
    {
        return [
            'plain' => ['p-4', 'p-4'],
            'colon' => ['md\\:flex', 'md:flex'],
            'slash' => ['w-1\\/2', 'w-1/2'],
            'brackets' => ['\\[\\&\\>\\*\\]\\:underline', '[&>*]:underline'],
            'leading digit as hex' => ['\\32 xl\\:flex', '2xl:flex'],
            'two digit number' => ['\\31 0', '10'],
            'dot' => ['p-0\\.5', 'p-0.5'],
            'important prefix' => ['\\!flex', '!flex'],
            'important suffix' => ['flex\\!', 'flex!'],
            'star' => ['\\*\\:p-4', '*:p-4'],
            'hex without terminator before a non-hex letter' => ['\\31x', '1x'],
            'a hex escape takes at most six digits' => ['\\0000411', 'A1'],
            'five digit emoji with its terminator' => ['\\1f600 ', "\u{1F600}"],
            'hex terminated by a newline' => ["\\31\nx", '1x'],
            'nul becomes the replacement character' => ['\\0 ', "\u{FFFD}"],
            'surrogate becomes the replacement character' => ['\\d800 ', "\u{FFFD}"],
            'out of range becomes the replacement character' => ['\\110000 ', "\u{FFFD}"],
            'trailing backslash' => ['a\\', "a\u{FFFD}"],
            'escaped backslash' => ['a\\\\b', 'a\\b'],
            'percent' => ['w-\\[50\\%\\]', 'w-[50%]'],
            'non ascii passes through' => ["caf\u{E9}", "caf\u{E9}"],
        ];
    }

    // ------------------------------------------------- accepted candidates

    /** @test */
    public function acceptedCandidatesAreTheClassSelectorsOfTheUtilitiesLayer(): void
    {
        $css = <<<'CSS'
            @layer theme, base, components, utilities;
            @layer base {
              .not-a-candidate { color: red; }
            }
            @layer utilities {
              .flex {
                display: flex;
              }
              .hover\:text-blue-500 {
                &:hover {
                  @media (hover: hover) {
                    color: blue;
                  }
                }
              }
              .md\:inline-block {
                @media (width >= 48rem) {
                  display: inline-block;
                }
              }
              @media (width >= 40rem) {
                .sm\:p-4 {
                  padding: 1rem;
                }
              }
              .group-hover\:p-4 {
                &:is(:where(.group):hover *) {
                  padding: 1rem;
                }
              }
              .\[\&_p\]\:underline p {
                text-decoration-line: underline;
              }
              .space-x-4 > :not(:last-child) {
                margin: 0;
              }
              .\32 xl\:flex, .\!flex {
                display: flex;
              }
              .flex {
                display: flex;
              }
            }
            @property --tw-x {
              syntax: "*";
            }
            CSS;

        self::assertSame(
            [
                '!flex',
                '2xl:flex',
                '[&_p]:underline',
                'flex',
                'group-hover:p-4',
                'hover:text-blue-500',
                'md:inline-block',
                'sm:p-4',
                'space-x-4',
            ],
            GoldenGenerator::acceptedCandidates($css)
        );
    }

    /** @test */
    public function bracesInStringsAndCommentsDoNotConfuseTheScan(): void
    {
        $css = <<<'CSS'
            @layer utilities {
              /* } .commented { */
              .before\:content-\[\'\{\'\] {
                content: "} .fake { ";
              }
              .flex {
                display: flex;
              }
            }
            .after-the-layer {
              color: red;
            }
            CSS;

        self::assertSame(["before:content-['{']", 'flex'], GoldenGenerator::acceptedCandidates($css));
    }

    /** @test */
    public function aStylesheetWithoutAUtilitiesLayerAcceptsNothing(): void
    {
        self::assertSame([], GoldenGenerator::acceptedCandidates("@layer base {\n  .x { color: red; }\n}\n"));
        self::assertSame([], GoldenGenerator::acceptedCandidates(''));
        self::assertSame([], GoldenGenerator::acceptedCandidates("@layer utilities {\n}\n"));
    }

    /** @test */
    public function aMinifiedStylesheetIsScannedLikeAnyOther(): void
    {
        $css = '@layer utilities{.flex{display:flex}.md\\:p-4{@media (width>=48rem){padding:1rem}}}';

        self::assertSame(['flex', 'md:p-4'], GoldenGenerator::acceptedCandidates($css));
    }

    // ---------------------------------------------------------------- helpers

    private function generator(): GoldenGenerator
    {
        $compile = function (string $input, string $candidates, array $files, bool $minify): string {
            $this->calls[] = [
                'input' => $input,
                'candidates' => $candidates,
                'files' => $files,
                'minify' => $minify,
            ];

            return $minify ? \rtrim($this->output, "\n") : $this->output;
        };

        return new GoldenGenerator($this->paths(), $compile, self::VERSION);
    }

    private function paths(): CorpusPaths
    {
        return new CorpusPaths($this->root . '/conformance', $this->root . '/fixtures/extractor');
    }

    private function golden(string $relative): string
    {
        return $this->root . '/conformance/golden/' . $relative;
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0777, true);
        }

        \file_put_contents($path, $contents);
    }

    private static function fixtureBinary(): string
    {
        $script = \dirname(__DIR__, 2) . '/Fixtures/bin/fake-tailwindcss';

        if (\DIRECTORY_SEPARATOR === '\\') {
            return \str_replace('\\', '/', $script) . '.cmd';
        }

        if (!\is_executable($script)) {
            \chmod($script, 0755);
        }

        return $script;
    }

    private static function loadTools(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/CliRunner.php';
        require_once \dirname(__DIR__, 3) . '/tools/GoldenGenerator.php';
    }
}
