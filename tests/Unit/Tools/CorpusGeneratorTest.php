<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\Inventory;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\CorpusGenerator;

/**
 * The corpus generator (design doc 08 §Acceptance thresholds, doc 16 §Corpus
 * layout; decisions ENG-A10, ENG-A12; threat T-01).
 *
 * Everything here runs on a small synthetic inventory file and a small synthetic
 * theme, so each rule of the generator is asserted on its own and nothing depends
 * on the real inventory, the default theme or a Tailwind binary. The real corpus
 * is covered by `tests/Conformance/CorpusCoverageTest`.
 */
final class CorpusGeneratorTest extends TestCase
{
    private const THEME = [
        '--color-black' => '#000',
        '--color-white' => '#fff',
        '--color-red-500' => 'oklch(0.637 0.237 25.331)',
        '--color-blue-500' => 'oklch(0.623 0.214 259.815)',
        '--color-zinc-500' => 'oklch(0.552 0.016 285.938)',
        '--container-3xs' => '16rem',
        '--container-2xs' => '18rem',
        '--container-xs' => '20rem',
        '--container-sm' => '24rem',
        '--container-md' => '28rem',
        '--container-lg' => '32rem',
        '--container-xl' => '36rem',
        '--container-2xl' => '42rem',
        '--container-3xl' => '48rem',
        '--container-4xl' => '56rem',
        '--font-sans' => 'ui-sans-serif',
        '--font-mono' => 'ui-monospace',
        '--font-weight-bold' => '700',
        '--font-weight-thin' => '100',
        '--text-xs' => '0.75rem',
        '--text-xs--line-height' => 'calc(1 / 0.75)',
        '--text-shadow-md' => '0 1px 3px',
    ];

    /**
     * @var string
     */
    private $root = '';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/CorpusGenerator.php';
    }

    protected function setUp(): void
    {
        $this->root = TempTree::make('corpus-generator-');
        \mkdir($this->root . '/corpus/utilities', 0777, true);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    // -------------------------------------------------------------------- forms

    /** @test */
    public function aStaticRootIsItselfAndItsImportantForm(): void
    {
        $by = $this->generator()->linesByForm('hidden');

        self::assertSame(['hidden'], $by['static']);
        self::assertSame(['hidden!'], $by['important']);
        self::assertSame(
            ['md:hidden', 'hover:hidden', 'md:hover:hidden', 'dark:hidden', 'group-hover:hidden', '[&>*]:hidden'],
            $by['variant']
        );
        self::assertSame([], $by['bare']);
        self::assertSame([], $by['theme']);
    }

    /** @test */
    public function spacingMultiplierBareSamples(): void
    {
        $by = $this->generator()->linesByForm('p');

        self::assertSame(['p-0', 'p-px', 'p-0.5', 'p-1.5', 'p-4', 'p-96'], $by['bare']);
    }

    /** @test */
    public function eachBareKindHasItsOwnSamples(): void
    {
        $generator = $this->generator();

        self::assertSame(['z-1', 'z-2', 'z-3', 'z-10', 'z-auto'], $generator->linesByForm('z')['bare']);
        self::assertSame(['opacity-0', 'opacity-50', 'opacity-100'], $generator->linesByForm('opacity')['bare']);
        self::assertSame(['rotate-0', 'rotate-45', 'rotate-180'], $generator->linesByForm('rotate')['bare']);
        self::assertSame(
            ['duration-75', 'duration-150', 'duration-300', 'duration-1000'],
            $generator->linesByForm('duration')['bare']
        );
        self::assertSame(
            ['line-clamp-1', 'line-clamp-3', 'line-clamp-6'],
            $generator->linesByForm('line-clamp')['bare']
        );
    }

    /** @test */
    public function aRootOverridesTheSamplesOfItsKind(): void
    {
        self::assertSame(
            ['from-0%', 'from-10%', 'from-50%', 'from-100%'],
            $this->generator()->linesByForm('from')['bare']
        );
    }

    /** @test */
    public function aKeywordRootTakesItsKeywordsFromTheTable(): void
    {
        $bare = $this->generator()->linesByForm('float')['bare'];

        self::assertSame(['float-right', 'float-left', 'float-none', 'float-start', 'float-end'], $bare);
    }

    /** @test */
    public function aKeywordRootWithoutATableEntryIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No keyword samples for root "mystery"');

        $this->generator(['mystery' => self::entry('functional', 'keyword', ['bare', 'important'])])
            ->linesByForm('mystery');
    }

    /** @test */
    public function numericRootsMayAddKeywordsToTheirBareSamples(): void
    {
        $bare = $this->generator()->linesByForm('w')['bare'];

        self::assertSame(
            ['w-0', 'w-px', 'w-0.5', 'w-1.5', 'w-4', 'w-96', 'w-auto', 'w-full', 'w-screen', 'w-min', 'w-max', 'w-fit'],
            $bare
        );
    }

    /** @test */
    public function themeSamplesComeFromTheNamespaceInThemeOrderWithFirstAndLastKept(): void
    {
        $theme = $this->generator()->linesByForm('w')['theme'];

        // Ten keys: the first, the last and an even spread, eight in all.
        self::assertSame(
            [
                'w-3xs', 'w-2xs', 'w-xs', 'w-sm', 'w-lg', 'w-xl', 'w-2xl', 'w-4xl',
            ],
            $theme
        );
    }

    /** @test */
    public function aShortNamespaceIsSampledWhole(): void
    {
        $generator = $this->generator([
            'x' => self::entry('functional', null, ['theme'], ['--font-*'], false, false, false),
        ]);

        self::assertSame(['x-sans', 'x-mono'], $generator->linesByForm('x')['theme']);
    }

    /** @test */
    public function colourNamespacesGetTheSpecialNamesAndOnePaletteShadeEach(): void
    {
        $theme = $this->generator()->linesByForm('bg')['theme'];

        self::assertSame(
            [
                'bg-inherit', 'bg-current', 'bg-transparent', 'bg-black', 'bg-white',
                'bg-red-500', 'bg-blue-500', 'bg-zinc-500',
            ],
            $theme
        );
    }

    /** @test */
    public function aPaletteMissingFromTheThemeIsLeftOut(): void
    {
        $generator = new CorpusGenerator(
            $this->inventory(),
            ['--color-black' => '#000', '--color-red-500' => 'red'],
            $this->root . '/corpus/utilities'
        );

        self::assertSame(
            ['bg-inherit', 'bg-current', 'bg-transparent', 'bg-black', 'bg-red-500'],
            $generator->linesByForm('bg')['theme']
        );
    }

    /** @test */
    public function aLongerNamespaceInTheSameEntryIsNotMixedIntoAShorterOne(): void
    {
        $generator = $this->generator([
            'font' => self::entry('functional', null, ['theme'], ['--font-*', '--font-weight-*'], false, false, false),
        ]);

        // `font-weight-bold` is not a font family, so it must not come from `--font-*`.
        self::assertSame(
            ['font-sans', 'font-mono', 'font-bold', 'font-thin'],
            $generator->linesByForm('font')['theme']
        );
    }

    /** @test */
    public function companionVariablesAndKnownSubNamespacesAreNotKeys(): void
    {
        $generator = $this->generator([
            'text' => self::entry('functional', 'keyword', ['theme'], ['--text-*'], false, false, false),
        ]);

        self::assertSame(['text-xs'], $generator->linesByForm('text')['theme']);
    }

    /** @test */
    public function fractionsAreTheThreeFixedOnes(): void
    {
        self::assertSame(['w-1/2', 'w-2/3', 'w-11/12'], $this->generator()->linesByForm('w')['fraction']);
        self::assertSame([], $this->generator()->linesByForm('p')['fraction']);
    }

    /** @test */
    public function arbitrarySamplesFollowTheBareKind(): void
    {
        $generator = $this->generator();

        self::assertSame(['p-[10px]', 'p-[calc(100%-2rem)]'], $generator->linesByForm('p')['arbitrary']);
        self::assertSame(['z-[7]'], $generator->linesByForm('z')['arbitrary']);
        self::assertSame(['opacity-[0.5]'], $generator->linesByForm('opacity')['arbitrary']);
        self::assertSame(['rotate-[45deg]'], $generator->linesByForm('rotate')['arbitrary']);
        self::assertSame(['duration-[250ms]'], $generator->linesByForm('duration')['arbitrary']);
    }

    /** @test */
    public function aRootMayHaveItsOwnArbitrarySamplesIncludingTypeHints(): void
    {
        $arbitrary = $this->generator()->linesByForm('bg')['arbitrary'];

        self::assertContains('bg-[#bada55]', $arbitrary);
        self::assertContains('bg-[color:red]', $arbitrary);
        self::assertContains('bg-[length:10px]', $arbitrary);
    }

    /** @test */
    public function aKeywordRootWithNoArbitraryTableEntryIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No arbitrary samples for root "mystery"');

        $this->generator(['mystery' => self::entry('functional', null, ['arbitrary'], [], false, false, true)])
            ->linesByForm('mystery');
    }

    /** @test */
    public function aVariableIsAlwaysTheSameForm(): void
    {
        self::assertSame(['p-(--my-var)'], $this->generator()->linesByForm('p')['var']);
        self::assertSame(['bg-(--my-var)'], $this->generator()->linesByForm('bg')['var']);
    }

    /** @test */
    public function negativeSamplesAreTheNonZeroBareSamplesWithADash(): void
    {
        $generator = $this->generator();

        self::assertSame(['-p-px', '-p-0.5', '-p-1.5', '-p-4', '-p-96'], $generator->linesByForm('p')['negative']);
        self::assertSame(['-z-1', '-z-2', '-z-3', '-z-10'], $generator->linesByForm('z')['negative']);
    }

    /** @test */
    public function aNegativeRootWithAFractionAlsoGetsANegativeFraction(): void
    {
        $generator = $this->generator([
            'inset' => self::entry(
                'functional',
                'spacing-multiplier',
                ['bare', 'fraction', 'negative'],
                [],
                true,
                true,
                false
            ),
        ]);

        self::assertContains('-inset-1/2', $generator->linesByForm('inset')['negative']);
    }

    /** @test */
    public function importantIsTheRepresentativeSampleWithABang(): void
    {
        $generator = $this->generator();

        self::assertSame(['p-4!'], $generator->linesByForm('p')['important']);
        self::assertSame(['z-2!'], $generator->linesByForm('z')['important']);
        self::assertSame(['opacity-50!'], $generator->linesByForm('opacity')['important']);
        self::assertSame(['float-right!'], $generator->linesByForm('float')['important']);
    }

    /** @test */
    public function importantFallsBackToAThemeThenAnArbitrarySample(): void
    {
        $generator = $this->generator([
            'a' => self::entry('functional', null, ['theme', 'important'], ['--font-*'], false, false, false),
            'b' => self::entry('functional', 'integer', ['arbitrary', 'important'], [], false, false, true),
        ]);

        self::assertSame(['a-sans!'], $generator->linesByForm('a')['important']);
        self::assertSame(['b-[7]!'], $generator->linesByForm('b')['important']);
    }

    /** @test */
    public function anOpacityModifierGoesOnColourSamples(): void
    {
        $modifier = $this->generator()->linesByForm('bg')['modifier'];

        self::assertContains('bg-red-500/50', $modifier);
        self::assertContains('bg-black/50', $modifier);
        self::assertNotContains('bg-inherit/50', $modifier);
        self::assertNotContains('bg-transparent/50', $modifier);
    }

    /** @test */
    public function aLineHeightModifierGoesOnTheSizeSamplesAndOpacityOnTheColours(): void
    {
        $generator = $this->generator([
            'text' => self::entry(
                'functional',
                'keyword',
                ['theme', 'modifier'],
                ['--text-*', '--color-*'],
                false,
                false,
                false,
                'line-height'
            ),
        ]);

        $modifier = $generator->linesByForm('text')['modifier'];

        self::assertContains('text-xs/7', $modifier);
        self::assertContains('text-red-500/50', $modifier);
        self::assertNotContains('text-xs/50', $modifier);
    }

    /** @test */
    public function oneRepresentativeIsCombinedWithEachVariantAndNotEveryFormWithEveryVariant(): void
    {
        $variant = $this->generator()->linesByForm('p')['variant'];

        self::assertSame(
            ['md:p-4', 'hover:p-4', 'md:hover:p-4', 'dark:p-4', 'group-hover:p-4', '[&>*]:p-4'],
            $variant
        );
    }

    /** @test */
    public function theArbitraryPropertyRootHasItsOwnSamples(): void
    {
        $by = $this->generator()->linesByForm(Inventory::ARBITRARY_PROPERTY);

        self::assertContains('[mask-type:luminance]', $by['arbitrary']);
        self::assertContains('[--my-var:1px]', $by['arbitrary']);
        self::assertSame(['[mask-type:luminance]!'], $by['important']);
        self::assertSame('md:[mask-type:luminance]', $by['variant'][0]);
    }

    /** @test */
    public function formsTheEntryDoesNotListAreNotGenerated(): void
    {
        $by = $this->generator()->linesByForm('p');

        self::assertSame([], $by['theme']);
        self::assertSame([], $by['modifier']);
        self::assertSame([], $by['static']);
    }

    /** @test */
    public function linesAreTheFormsInOrderWithoutDuplicates(): void
    {
        $generator = $this->generator([
            'flex' => self::entry('functional', 'integer', ['static', 'bare', 'important'], [], false, false, false),
        ]);
        $lines = $generator->lines('flex');

        self::assertSame($lines, \array_values(\array_unique($lines)));
        self::assertSame('flex', $lines[0]);
        self::assertSame('flex-1', $lines[1]);
    }

    /**
     * @test
     *
     * @dataProvider everyLineIsClean
     */
    public function everyGeneratedLineIsASingleCleanToken(string $root): void
    {
        foreach ($this->generator()->lines($root) as $line) {
            self::assertSame(1, \preg_match('/^[\x21-\x7e]+$/', $line), $root . ': "' . $line . '"');
            self::assertStringNotContainsString("\n", $line);
            self::assertStringNotContainsString('#', \substr($line, 0, 1));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyLineIsClean(): array
    {
        return [
            'hidden' => ['hidden'],
            'p' => ['p'],
            'w' => ['w'],
            'bg' => ['bg'],
            'z' => ['z'],
            'float' => ['float'],
            'the arbitrary property' => [Inventory::ARBITRARY_PROPERTY],
        ];
    }

    // --------------------------------------------------------------------- file

    /** @test */
    public function aFileHasTheHeaderAndOneSectionPerRootInInventoryOrder(): void
    {
        $text = $this->generator()->render('layout');
        $lines = \explode("\n", $text);

        self::assertSame(CorpusGenerator::HEADER, $lines[0]);
        self::assertTrue(CorpusGenerator::isGenerated($text));
        self::assertSame('', \end($lines));
        self::assertStringContainsString("\n# hidden\nhidden\n", $text);
        self::assertLessThan(\strpos($text, "\n# float\n"), \strpos($text, "\n# hidden\n"));
        self::assertStringNotContainsString("\r", $text);
    }

    /** @test */
    public function renderingTwiceIsByteIdentical(): void
    {
        self::assertSame($this->generator()->render('layout'), $this->generator()->render('layout'));
        self::assertSame($this->generator()->render('spacing-sizing'), $this->generator()->render('spacing-sizing'));
    }

    /** @test */
    public function aFamilyWithNoRootsRendersJustTheHeader(): void
    {
        self::assertSame(CorpusGenerator::HEADER . "\n", $this->generator()->render('typography'));
    }

    /** @test */
    public function theFamiliesAreTheInventoryOnes(): void
    {
        self::assertSame(Inventory::FAMILIES, $this->generator()->families());
    }

    /** @test */
    public function aHeaderIsRecognisedOnlyAsTheFirstLine(): void
    {
        self::assertTrue(CorpusGenerator::isGenerated(CorpusGenerator::HEADER . "\nflex\n"));
        self::assertFalse(CorpusGenerator::isGenerated("# hand written\nflex\n"));
        self::assertFalse(CorpusGenerator::isGenerated("flex\n" . CorpusGenerator::HEADER . "\n"));
        self::assertFalse(CorpusGenerator::isGenerated(''));
        self::assertFalse(CorpusGenerator::isGenerated(\substr(CorpusGenerator::HEADER, 0, 20)));
    }

    // ---------------------------------------------------------------------- run

    /** @test */
    public function runWritesEveryFamilyFileAndReportsCounts(): void
    {
        $results = $this->generator()->run(null, false);

        self::assertCount(\count(Inventory::FAMILIES), $results);

        foreach ($results as $result) {
            self::assertSame('written', $result['status']);
            self::assertFileExists($this->root . '/corpus/utilities/' . $result['family'] . '.txt');
        }

        self::assertGreaterThan(0, $results[0]['lines']);
        self::assertSame('layout', $results[0]['family']);
        self::assertSame(
            $this->generator()->render('layout'),
            \file_get_contents($this->root . '/corpus/utilities/layout.txt')
        );
    }

    /** @test */
    public function runTwiceChangesNothing(): void
    {
        $this->generator()->run(null, false);
        $second = $this->generator()->run(null, false);

        foreach ($second as $result) {
            self::assertSame('unchanged', $result['status']);
        }
    }

    /** @test */
    public function checkWritesNothingAndReportsMissingThenUnchangedThenDrift(): void
    {
        $missing = $this->generator()->run('layout', true);

        self::assertSame('missing', $missing[0]['status']);
        self::assertFileDoesNotExist($this->root . '/corpus/utilities/layout.txt');

        $this->generator()->run('layout', false);

        self::assertSame('unchanged', $this->generator()->run('layout', true)[0]['status']);

        \file_put_contents(
            $this->root . '/corpus/utilities/layout.txt',
            CorpusGenerator::HEADER . "\nsomething-else\n"
        );

        self::assertSame('drift', $this->generator()->run('layout', true)[0]['status']);
        self::assertSame(
            CorpusGenerator::HEADER . "\nsomething-else\n",
            \file_get_contents($this->root . '/corpus/utilities/layout.txt')
        );
        self::assertTrue(CorpusGenerator::hasDrift($this->generator()->run('layout', true)));
        self::assertFalse(
            CorpusGenerator::hasDrift([['family' => 'x', 'file' => 'y', 'status' => 'unchanged', 'lines' => 1]])
        );
    }

    /** @test */
    public function aFileWithoutTheHeaderIsNeverOverwritten(): void
    {
        $path = $this->root . '/corpus/utilities/layout.txt';
        \file_put_contents($path, "# hand written edge cases\nflex\n");

        try {
            $this->generator()->run('layout', false);
            self::fail('The generator overwrote a hand-written file.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('layout', $e->getMessage());
            self::assertStringContainsString('not generated', $e->getMessage());
        }

        self::assertSame("# hand written edge cases\nflex\n", \file_get_contents($path));
    }

    /** @test */
    public function aRefusalPartWayThroughWritesNothingForTheFamiliesAfterIt(): void
    {
        \file_put_contents($this->root . '/corpus/utilities/spacing-sizing.txt', "flex\n");

        try {
            $this->generator()->run(null, false);
            self::fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('spacing-sizing', $e->getMessage());
        }

        // Everything is built and checked before anything is written.
        self::assertFileDoesNotExist($this->root . '/corpus/utilities/layout.txt');
        self::assertSame("flex\n", \file_get_contents($this->root . '/corpus/utilities/spacing-sizing.txt'));
    }

    /** @test */
    public function checkOnAHandWrittenFileAlsoRefuses(): void
    {
        \file_put_contents($this->root . '/corpus/utilities/layout.txt', "flex\n");

        $this->expectException(\RuntimeException::class);

        $this->generator()->run('layout', true);
    }

    /**
     * @test
     *
     * @dataProvider badSelectors
     */
    public function onlyIsMatchedAgainstTheFamilyListAndNeverJoinedToAPath(string $only): void
    {
        try {
            $this->generator()->run($only, false);
            self::fail('"' . $only . '" should have been refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('--only', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }

        $written = \glob($this->root . '/corpus/utilities/*') ?: [];

        self::assertSame([], $written);
        self::assertSame([], \glob($this->root . '/*.txt') ?: []);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badSelectors(): array
    {
        return [
            'a traversal' => ['../x'],
            'a traversal with a family' => ['layout/..'],
            'a nested path' => ['layout/x'],
            'an absolute path' => ['/etc/passwd'],
            'a drive path' => ['C:\\Windows'],
            'an unknown family' => ['nonsense'],
            'an empty selector' => [''],
            'the case differs' => ['Layout'],
            'a trailing space' => ['layout '],
            'a null byte' => ["layout\0x"],
            'a very long one' => [\str_repeat('a', 5000)],
        ];
    }

    /** @test */
    public function aLongBadSelectorIsTruncatedInTheMessage(): void
    {
        try {
            $this->generator()->run(\str_repeat('a', 5000), false);
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            self::assertLessThan(400, \strlen($e->getMessage()));
        }
    }

    /** @test */
    public function onlyAFamilyWritesOnlyThatFamily(): void
    {
        $this->generator()->run('spacing-sizing', false);

        self::assertSame(
            [$this->root . '/corpus/utilities/spacing-sizing.txt'],
            \glob($this->root . '/corpus/utilities/*')
        );
    }

    /** @test */
    public function pathsAreBuiltFromTheFamilyListOnly(): void
    {
        $generator = $this->generator();

        foreach (Inventory::FAMILIES as $family) {
            self::assertSame($this->root . '/corpus/utilities/' . $family . '.txt', $generator->path($family));
        }

        $this->expectException(\InvalidArgumentException::class);

        $generator->path('../escape');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, array<string, mixed>>|null $entries Replaces the default synthetic inventory.
     */
    private function generator(?array $entries = null): CorpusGenerator
    {
        return new CorpusGenerator($this->inventory($entries), self::THEME, $this->root . '/corpus/utilities');
    }

    /**
     * @param array<string, array<string, mixed>>|null $extra Entries added to the default ones, by root.
     */
    private function inventory(?array $extra = null): Inventory
    {
        $entries = [
            'hidden' => self::entry('static', null, ['static', 'important']),
            'p' => self::entry(
                'functional',
                'spacing-multiplier',
                ['bare', 'arbitrary', 'var', 'negative', 'important'],
                [],
                false,
                true,
                true
            ),
            'w' => self::entry(
                'functional',
                'spacing-multiplier',
                ['theme', 'bare', 'fraction', 'arbitrary', 'var', 'important'],
                ['--container-*'],
                true,
                false,
                true
            ),
            'bg' => self::entry(
                'functional',
                'keyword',
                ['theme', 'bare', 'arbitrary', 'var', 'important', 'modifier'],
                ['--color-*'],
                false,
                false,
                true,
                'opacity'
            ),
            'z' => self::entry(
                'functional',
                'integer',
                ['bare', 'arbitrary', 'var', 'negative', 'important'],
                [],
                false,
                true,
                true
            ),
            'opacity' => self::entry(
                'functional',
                'percentage',
                ['bare', 'arbitrary', 'important'],
                [],
                false,
                false,
                true
            ),
            'rotate' => self::entry('functional', 'angle', ['bare', 'arbitrary'], [], false, false, true),
            'duration' => self::entry('functional', 'duration', ['bare', 'arbitrary'], [], false, false, true),
            'line-clamp' => self::entry('functional', 'line-count', ['bare'], [], false, false, false),
            'from' => self::entry('functional', 'percentage', ['bare'], [], false, false, false),
            'float' => self::entry('functional', 'keyword', ['bare', 'important'], [], false, false, false),
            Inventory::ARBITRARY_PROPERTY => self::entry(
                'functional',
                null,
                ['arbitrary', 'important'],
                [],
                false,
                false,
                true,
                null,
                'arbitrary-property'
            ),
        ];

        foreach ($extra ?? [] as $root => $entry) {
            $entries[$root] = $entry;
        }

        $families = [
            'hidden' => 'layout',
            'float' => 'layout',
            'p' => 'spacing-sizing',
            'w' => 'spacing-sizing',
        ];

        foreach ($entries as $root => &$entry) {
            $entry['family'] = $entry['family'] ?? ($families[$root] ?? 'layout');
        }

        unset($entry);

        $file = $this->root . '/inventory-' . \md5(\serialize($entries)) . '.php';

        \file_put_contents($file, '<?php return ' . \var_export($entries, true) . ';');

        return new Inventory($file);
    }

    /**
     * @param list<string> $forms
     * @param list<string> $theme
     *
     * @return array<string, mixed>
     */
    private static function entry(
        string $kind,
        ?string $bare,
        array $forms,
        array $theme = [],
        bool $fraction = false,
        bool $negative = false,
        bool $arbitrary = false,
        ?string $modifier = null,
        ?string $family = null
    ): array {
        $entry = [
            'kind' => $kind,
            'theme' => $theme,
            'bare' => $bare,
            'fraction' => $fraction,
            'negative' => $negative,
            'arbitrary' => $arbitrary,
            'modifier' => $modifier,
            'tier' => 'T1',
            'forms' => $forms,
        ];

        if ($family !== null) {
            $entry['family'] = $family;
        }

        return $entry;
    }
}
