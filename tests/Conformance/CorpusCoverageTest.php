<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\Inventory;
use TailwindPhp\Tools\CorpusGenerator;
use TailwindPhp\Tools\GoldenGenerator;

/**
 * The generated T1 utilities corpus covers the inventory (design doc 08
 * §Acceptance thresholds; decisions ENG-A10, ENG-A12).
 *
 * The inventory is the denominator of the 98 % gate, so a root or a form that
 * silently gets no candidates would shrink it unnoticed. This test reads the
 * committed corpus files, not the generator's memory, and checks that every form
 * of every inventory root is there, that every line names a known root, that every
 * line was accepted by the pinned CLI (the golden is the witness), and that the
 * committed files are exactly what the generator writes. The shape of the corpus
 * (line counts per family) is pinned as literals, so a table edit that changes it
 * must say so here.
 */
final class CorpusCoverageTest extends TestCase
{
    /**
     * Lines per generated family file, and their total.
     */
    private const COUNTS = [
        'layout' => 755,
        'flexbox-grid' => 500,
        'spacing-sizing' => 598,
        'typography' => 516,
        'backgrounds-borders' => 937,
        'effects-filters' => 117,
        'tables-transitions-transforms' => 339,
        'interactivity-svg-accessibility' => 660,
        'arbitrary-property' => 10,
    ];

    private const TOTAL = 4432;

    /**
     * Forms an inventory root lists that the corpus cannot give a sample for, and why.
     * Reported as inventory findings; the list must stay exactly this long.
     *
     * @var array<string, string>
     */
    private const NO_SAMPLE = [
        'list:theme' => 'doc 08 names `--list-style-*` but the 4.1.18 default theme defines no such variable.',
    ];

    private const VARIANT_PREFIXES = ['md:', 'hover:', 'md:hover:', 'dark:', 'group-hover:', '[&>*]:'];

    /**
     * @var CorpusGenerator|null
     */
    private static $generator;

    /**
     * @var Inventory|null
     */
    private static $inventory;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2) . '/tools/CorpusGenerator.php';
        require_once \dirname(__DIR__, 2) . '/tools/GoldenGenerator.php';
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function families(): array
    {
        $rows = [];

        foreach (Inventory::FAMILIES as $family) {
            $rows[$family] = [$family];
        }

        return $rows;
    }

    // ----------------------------------------------------------- the files

    /**
     * @test
     *
     * @dataProvider families
     */
    public function theCommittedFileIsExactlyWhatTheGeneratorWrites(string $family): void
    {
        $committed = self::read(self::path($family));

        self::assertTrue(CorpusGenerator::isGenerated($committed), $family . ' has no generated header.');
        self::assertSame(self::generator()->render($family), $committed, $family . ' is stale.');
        self::assertStringNotContainsString("\r", $committed);
    }

    /** @test */
    public function theGeneratorsCheckModeFindsNoDriftInTheCommittedTree(): void
    {
        $results = self::generator()->run(null, true);

        self::assertCount(\count(Inventory::FAMILIES), $results);
        self::assertFalse(CorpusGenerator::hasDrift($results));
    }

    /** @test */
    public function theLineCountsArePinned(): void
    {
        $counts = [];

        foreach (Inventory::FAMILIES as $family) {
            $counts[$family] = \count(self::lines(self::read(self::path($family))));
        }

        self::assertSame(self::COUNTS, $counts);
        self::assertSame(self::TOTAL, \array_sum($counts));
    }

    /** @test */
    public function theHandWrittenEdgeFilesAreNotGeneratedAndAreLeftAlone(): void
    {
        $paths = new CorpusPaths();
        $edge = 0;

        foreach ($paths->select('utilities') as $key) {
            $name = (string) \substr($key, \strlen('utilities/'));

            if (\strncmp($name, 'edge-', 5) !== 0) {
                continue;
            }

            ++$edge;

            self::assertFalse(
                CorpusGenerator::isGenerated(self::read($paths->sourcePath($key))),
                $key . ' must stay hand written.'
            );
        }

        self::assertSame(7, $edge);
    }

    /** @test */
    public function everyFamilyHasACorpusEntryAndTheEdgeFilesAreTheOnlyOthers(): void
    {
        $paths = new CorpusPaths();
        $names = [];

        foreach ($paths->select('utilities') as $key) {
            $names[] = (string) \substr($key, \strlen('utilities/'));
        }

        foreach (Inventory::FAMILIES as $family) {
            self::assertContains($family, $names);
        }

        foreach ($names as $name) {
            self::assertTrue(
                \in_array($name, Inventory::FAMILIES, true) || \strncmp($name, 'edge-', 5) === 0,
                $name . ' is neither a family nor an edge file.'
            );
        }
    }

    // ------------------------------------------------------- the inventory

    /** @test */
    public function everyFormOfEveryRootIsInTheCorpusExceptTheDocumentedGaps(): void
    {
        $missing = [];
        $sections = [];

        foreach (Inventory::FAMILIES as $family) {
            foreach (self::sections(self::read(self::path($family))) as $root => $lines) {
                $sections[$root] = $lines;
            }
        }

        foreach (self::inventory()->roots() as $root) {
            self::assertArrayHasKey($root, $sections, $root . ' has no section in the corpus.');

            $forms = self::generator()->linesByForm($root);

            foreach (self::inventory()->expectedForms($root) as $form) {
                if ($forms[$form] === []) {
                    $missing[] = $root . ':' . $form;

                    continue;
                }

                foreach ($forms[$form] as $line) {
                    self::assertContains($line, $sections[$root], $root . ' ' . $form . ' line is not in the file.');
                    self::assertTrue(
                        self::hasShapeOf($form, $root, $line),
                        $root . ': "' . $line . '" does not look like a ' . $form . ' candidate.'
                    );
                }
            }
        }

        self::assertSame(\array_keys(self::NO_SAMPLE), $missing);
    }

    /** @test */
    public function everyRootHasAtLeastOneLineAndOneVariantCombinationEach(): void
    {
        foreach (Inventory::FAMILIES as $family) {
            foreach (self::sections(self::read(self::path($family))) as $root => $lines) {
                self::assertNotEmpty($lines, $root);

                $variants = 0;

                foreach ($lines as $line) {
                    foreach (self::VARIANT_PREFIXES as $prefix) {
                        if (\strncmp($line, $prefix, \strlen($prefix)) === 0) {
                            ++$variants;
                        }
                    }
                }

                self::assertGreaterThanOrEqual(\count(self::VARIANT_PREFIXES), $variants, $root);
            }
        }
    }

    /** @test */
    public function theFamiliesHoldExactlyTheRootsOfTheInventoryInOrder(): void
    {
        foreach (self::inventory()->families() as $family => $roots) {
            $found = \array_keys(self::sections(self::read(self::path($family))));

            self::assertSame($roots, $found, $family);
        }
    }

    // ------------------------------------------------------------- the lines

    /**
     * @test
     *
     * @dataProvider families
     */
    public function everyLineUsesAKnownRootAndBelongsToItsSection(string $family): void
    {
        $roots = self::inventory()->roots();

        foreach (self::sections(self::read(self::path($family))) as $section => $lines) {
            foreach ($lines as $line) {
                self::assertTrue(
                    self::inventory()->assertCandidateUsesKnownRoot($line),
                    $family . ': "' . $line . '" names no inventory root.'
                );
                self::assertSame(
                    $section,
                    self::longestRoot($line, $roots),
                    $family . ': "' . $line . '" is filed under the wrong root.'
                );
            }
        }
    }

    /** @test */
    public function noLineIsRepeatedWithinAFamilyOrAcrossFamilies(): void
    {
        $seen = [];

        foreach (Inventory::FAMILIES as $family) {
            foreach (self::lines(self::read(self::path($family))) as $line) {
                self::assertArrayNotHasKey(
                    $line,
                    $seen,
                    $line . ' appears twice (also in ' . ($seen[$line] ?? '') . ').'
                );

                $seen[$line] = $family;
            }
        }
    }

    /**
     * @test
     *
     * @dataProvider families
     */
    public function everyLineIsOneCleanToken(string $family): void
    {
        foreach (self::lines(self::read(self::path($family))) as $line) {
            self::assertSame(1, \preg_match('/^[\x21-\x7e]+$/', $line), $family . ': "' . $line . '"');
        }
    }

    // ------------------------------------------------------------ the goldens

    /**
     * @test
     *
     * @dataProvider families
     */
    public function theCliAcceptedEveryLineOfTheFamily(string $family): void
    {
        $paths = new CorpusPaths();
        $key = 'utilities/' . $family;

        self::assertFileExists($paths->goldenPath($key));
        self::assertFileExists($paths->goldenPath($key, true));

        $accepted = \array_flip(GoldenGenerator::acceptedCandidates(self::read($paths->goldenPath($key))));
        $rejected = [];

        foreach (self::lines(self::read(self::path($family))) as $line) {
            if (!isset($accepted[$line])) {
                $rejected[] = $line;
            }
        }

        self::assertSame([], $rejected, $family . ': the pinned CLI rejected these corpus lines.');
    }

    /** @test */
    public function theGoldensAreTheCliOutputForTheCurrentCorpus(): void
    {
        // The goldens are produced from the corpus by `generate-goldens.php`; this
        // asserts the cheap half of "in sync": every golden's accepted set is
        // exactly its corpus' lines, so a corpus change without a regeneration fails.
        $paths = new CorpusPaths();

        foreach (Inventory::FAMILIES as $family) {
            $lines = self::lines(self::read(self::path($family)));
            $accepted = GoldenGenerator::acceptedCandidates(
                self::read($paths->goldenPath('utilities/' . $family))
            );

            \sort($lines, \SORT_STRING);
            \sort($accepted, \SORT_STRING);

            self::assertSame($lines, $accepted, $family . ' golden is out of step with its corpus.');
        }
    }

    // ----------------------------------------------------------- the guards

    /** @test */
    public function theGeneratorNeverReadsTheEngineUtilityOrVariantTables(): void
    {
        $source = self::read(\dirname(__DIR__, 2) . '/tools/CorpusGenerator.php');

        foreach (['Data\\Utilities', 'Data\\Variants', 'Engine\\Utility', 'Engine\\Variant'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'The corpus must not be derived from the implementation (ENG-A12).'
            );
        }
    }

    /** @test */
    public function theThemeTheScriptPassesIsTheDefaultTheme(): void
    {
        // The script injects `DefaultTheme::vars()`; the generator reads theme keys
        // from it only. A theme without the colour names would shrink the corpus.
        $vars = DefaultTheme::vars();

        $names = ['--color-black', '--color-white', '--color-red-500', '--color-blue-500', '--color-zinc-500'];

        foreach ($names as $name) {
            self::assertArrayHasKey($name, $vars);
        }
    }

    // ---------------------------------------------------------------- helpers

    private static function generator(): CorpusGenerator
    {
        if (self::$generator === null) {
            self::$generator = new CorpusGenerator(
                self::inventory(),
                DefaultTheme::vars(),
                \dirname(__DIR__) . '/Conformance/corpus/utilities'
            );
        }

        return self::$generator;
    }

    private static function inventory(): Inventory
    {
        if (self::$inventory === null) {
            self::$inventory = new Inventory();
        }

        return self::$inventory;
    }

    private static function path(string $family): string
    {
        return \dirname(__DIR__) . '/Conformance/corpus/utilities/' . $family . '.txt';
    }

    private static function read(string $path): string
    {
        $contents = \file_get_contents($path);

        self::assertNotFalse($contents, 'Unreadable file.');

        return (string) $contents;
    }

    /**
     * The candidate lines of a file: no comments, no blanks.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = [];

        foreach (\explode("\n", $text) as $line) {
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The lines of each `# <root>` section, by root, in file order.
     *
     * @return array<string, list<string>>
     */
    private static function sections(string $text): array
    {
        $sections = [];
        $current = null;

        foreach (\explode("\n", $text) as $line) {
            if ($line === '' || $line === CorpusGenerator::HEADER) {
                continue;
            }

            if ($line[0] === '#') {
                $current = (string) \substr($line, 2);
                $sections[$current] = [];

                continue;
            }

            self::assertNotNull($current, 'A candidate line comes before any section comment.');

            $sections[(string) $current][] = $line;
        }

        return $sections;
    }

    /**
     * The inventory root a candidate belongs to, the longest that fits.
     *
     * @param list<string> $roots
     */
    private static function longestRoot(string $candidate, array $roots): ?string
    {
        $utility = self::utilityOf($candidate);

        if ($utility === '') {
            return null;
        }

        if ($utility[0] === '[') {
            return Inventory::ARBITRARY_PROPERTY;
        }

        $best = null;

        foreach ($roots as $root) {
            if ($root === Inventory::ARBITRARY_PROPERTY) {
                continue;
            }

            if (
                ($utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0)
                && ($best === null || \strlen($root) > \strlen($best))
            ) {
                $best = $root;
            }
        }

        return $best;
    }

    /**
     * What a candidate says about the utility: after the last top-level `:`,
     * without the important bangs, the negative dash and a `/modifier`.
     */
    private static function utilityOf(string $candidate): string
    {
        $depth = 0;
        $start = 0;

        for ($i = 0, $length = \strlen($candidate); $i < $length; ++$i) {
            $byte = $candidate[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif (($byte === ']' || $byte === ')') && $depth > 0) {
                --$depth;
            } elseif ($byte === ':' && $depth === 0) {
                $start = $i + 1;
            }
        }

        $utility = (string) \substr($candidate, $start);

        if ($utility !== '' && $utility[0] === '!') {
            $utility = (string) \substr($utility, 1);
        }

        if ($utility !== '' && \substr($utility, -1) === '!') {
            $utility = (string) \substr($utility, 0, -1);
        }

        if ($utility !== '' && $utility[0] === '-') {
            $utility = (string) \substr($utility, 1);
        }

        $depth = 0;
        $slash = -1;

        for ($i = 0, $length = \strlen($utility); $i < $length; ++$i) {
            $byte = $utility[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif (($byte === ']' || $byte === ')') && $depth > 0) {
                --$depth;
            } elseif ($byte === '/' && $depth === 0) {
                $slash = $i;
            }
        }

        return $slash > 0 ? (string) \substr($utility, 0, $slash) : $utility;
    }

    /**
     * Whether a line has the shape of its form: a cheap independent check on what
     * the generator calls a theme sample, a fraction, a variable and so on.
     */
    private static function hasShapeOf(string $form, string $root, string $line): bool
    {
        $isProperty = $root === Inventory::ARBITRARY_PROPERTY;

        switch ($form) {
            case 'static':
                return $line === $root;
            case 'theme':
            case 'bare':
                return \strncmp($line, $root . '-', \strlen($root) + 1) === 0
                    && \strpbrk($line, '[(') === false
                    && \strpos($line, '/') === false;
            case 'fraction':
                return \strncmp($line, $root . '-', \strlen($root) + 1) === 0
                    && \preg_match('/-\d+\/\d+$/', $line) === 1;
            case 'arbitrary':
                if ($isProperty) {
                    return $line[0] === '[';
                }

                return \strpos($line, $root . '-[') === 0 && \substr($line, -1) === ']';
            case 'var':
                return $line === $root . '-(--my-var)';
            case 'negative':
                return $line[0] === '-' && \strncmp($line, '-' . $root . '-', \strlen($root) + 2) === 0;
            case 'important':
                return \substr($line, -1) === '!';
            case 'modifier':
                return \strncmp($line, $root . '-', \strlen($root) + 1) === 0
                    && \preg_match('/\/(50|7)$/', $line) === 1;
            default:
                return false;
        }
    }
}
