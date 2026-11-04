<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The corpus-to-golden path map (design doc 16 §Corpus layout, ENG-A1/A11).
 *
 * Two halves. The first builds a miniature corpus in a temp directory and
 * pins the mapping rules, including the one that matters for security: a key
 * is only ever looked up in a listing, so no argument becomes a path (T-01).
 * The second half checks the **committed** corpus against its goldens, so a
 * corpus line added without regenerating goldens fails on every developer
 * machine and not only in the `goldens` CI job.
 */
final class CorpusPathsTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-corpus-paths');

        $this->put('conformance/corpus/utilities/README.txt', "generated later\n");
        $this->put('conformance/corpus/utilities/spacing.txt', "p-4\nm-2\n");
        $this->put('conformance/corpus/variants/responsive.txt', "# comment\nsm:p-4\n\nmd:p-4 lg:p-4\n");
        $this->put('conformance/corpus/directives/theme-basic.css', "@theme { --x: 1; }\n");
        $this->put('conformance/corpus/directives/theme-basic.candidates.txt', "p-4\n");
        $this->put('conformance/corpus/directives/partial.css', ".card { }\n");
        $this->put('conformance/corpus/directives/notes.txt', "not corpus\n");
        $this->put('conformance/golden/VERSION', "4.1.18\n");
        $this->put('fixtures/extractor/a.twig', '<div class="p-4">');
        $this->put('fixtures/extractor/blade.blade.php', '<div class="p-4">');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function keysListEveryEntryAndNothingElse(): void
    {
        self::assertSame(
            [
                'directives/theme-basic',
                'extractor/a',
                'extractor/blade',
                'utilities/spacing',
                'variants/responsive',
            ],
            $this->paths()->keys()
        );
    }

    /** @test */
    public function aDirectiveCssWithoutACandidatesSiblingIsAPartialNotAnEntry(): void
    {
        $paths = $this->paths();

        self::assertFalse($paths->has('directives/partial'));
        self::assertSame(['partial.css'], \array_keys($paths->partials()));
        self::assertSame($this->root . '/conformance/corpus/directives/partial.css', $paths->partials()['partial.css']);
    }

    /** @test */
    public function kindsFollowTheGroup(): void
    {
        $paths = $this->paths();

        self::assertSame(CorpusPaths::KIND_CANDIDATES, $paths->kind('utilities/spacing'));
        self::assertSame(CorpusPaths::KIND_CANDIDATES, $paths->kind('variants/responsive'));
        self::assertSame(CorpusPaths::KIND_DIRECTIVE, $paths->kind('directives/theme-basic'));
        self::assertSame(CorpusPaths::KIND_EXTRACTOR, $paths->kind('extractor/blade'));
    }

    /** @test */
    public function sourceAndGoldenPathsFollowTheDocumentedLayout(): void
    {
        $paths = $this->paths();
        $c = $this->root . '/conformance';

        self::assertSame($c . '/corpus/variants/responsive.txt', $paths->sourcePath('variants/responsive'));
        self::assertSame($c . '/golden/variants/responsive.css', $paths->goldenPath('variants/responsive'));
        self::assertSame($c . '/golden/variants/responsive.min.css', $paths->goldenPath('variants/responsive', true));

        self::assertSame($c . '/corpus/directives/theme-basic.css', $paths->sourcePath('directives/theme-basic'));
        self::assertSame(
            $c . '/corpus/directives/theme-basic.candidates.txt',
            $paths->candidatesPath('directives/theme-basic')
        );
        self::assertSame($c . '/golden/directives/theme-basic.css', $paths->goldenPath('directives/theme-basic'));

        self::assertNull($paths->candidatesPath('variants/responsive'));
    }

    /** @test */
    public function anExtractorGoldenIsNamedByTheFixtureStem(): void
    {
        $paths = $this->paths();

        self::assertSame($this->root . '/fixtures/extractor/blade.blade.php', $paths->sourcePath('extractor/blade'));
        self::assertSame(
            $this->root . '/conformance/golden/extractor/blade.json',
            $paths->goldenPath('extractor/blade')
        );
    }

    /** @test */
    public function anExtractorGoldenHasNoMinifiedVariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->paths()->goldenPath('extractor/a', true);
    }

    /**
     * T-01: nothing a caller types is ever joined into a path.
     *
     * @test
     *
     * @dataProvider hostileKeys
     */
    public function aKeyOutsideTheListingIsRejected(string $key): void
    {
        $paths = $this->paths();

        self::assertFalse($paths->has($key));
        self::assertFalse($paths->isSelector($key));
        self::assertSame([], $paths->select($key));

        $this->expectException(\InvalidArgumentException::class);

        $paths->sourcePath($key);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileKeys(): array
    {
        return [
            'parent traversal' => ['variants/../../../etc/passwd'],
            'bare traversal' => ['../x'],
            'absolute path' => ['/etc/passwd'],
            'windows drive' => ['C:/Windows/win.ini'],
            'unknown entry' => ['variants/no-such-file'],
            'nul byte' => ["variants/responsive\0.txt"],
            'empty' => [''],
            'group with trailing slash' => ['variants/'],
        ];
    }

    /** @test */
    public function aGroupSelectorExpandsToItsEntries(): void
    {
        $paths = $this->paths();

        self::assertTrue($paths->isSelector('extractor'));
        self::assertSame(['extractor/a', 'extractor/blade'], $paths->select('extractor'));
        self::assertSame(['variants/responsive'], $paths->select('variants/responsive'));
        self::assertSame([], $paths->select('variant'));
    }

    /** @test */
    public function candidateListsDropCommentsAndBlankLinesAndSplitOnWhitespace(): void
    {
        self::assertSame(
            ['sm:p-4', 'md:p-4', 'lg:p-4', 'flex'],
            CorpusPaths::parseCandidates("# comment\nsm:p-4\n\n  md:p-4\t lg:p-4  \r\n#another\nflex\n")
        );
        self::assertSame([], CorpusPaths::parseCandidates("\n# only a comment\n"));
    }

    /** @test */
    public function theVersionFileIsTrimmed(): void
    {
        self::assertSame('4.1.18', $this->paths()->version());
    }

    /** @test */
    public function aMissingOrEmptyVersionFileIsAnError(): void
    {
        \file_put_contents($this->root . '/conformance/golden/VERSION', "  \n");

        $this->expectException(\RuntimeException::class);

        $this->paths()->version();
    }

    /** @test */
    public function twoExtractorFixturesWithTheSameStemAreRefused(): void
    {
        $this->put('fixtures/extractor/a.vue', '<div class="p-4">');

        $this->expectException(\RuntimeException::class);

        $this->paths()->keys();
    }

    // ------------------------------------------------- the committed corpus

    /** @test */
    public function theCommittedCorpusHasAGoldenForEveryEntry(): void
    {
        $paths = new CorpusPaths();

        self::assertNotSame([], $paths->keys(), 'The committed corpus is empty.');

        $missing = [];

        foreach ($paths->keys() as $key) {
            self::assertFileExists($paths->sourcePath($key));

            if ($paths->kind($key) === CorpusPaths::KIND_DIRECTIVE) {
                self::assertFileExists((string) $paths->candidatesPath($key));
            }

            if (!\is_file($paths->goldenPath($key))) {
                $missing[] = $paths->goldenPath($key, false);
            }

            if ($paths->kind($key) !== CorpusPaths::KIND_EXTRACTOR && !\is_file($paths->goldenPath($key, true))) {
                $missing[] = $paths->goldenPath($key, true);
            }
        }

        self::assertSame([], $missing, 'Goldens are missing; run php tools/generate-goldens.php.');
    }

    /** @test */
    public function theCommittedGoldensAreLfOnlyAndStampedWithThePinnedVersion(): void
    {
        $paths = new CorpusPaths();

        self::assertSame(Checksums::DEFAULT_VERSION, $paths->version());

        foreach ($paths->keys() as $key) {
            $files = [$paths->goldenPath($key)];

            if ($paths->kind($key) !== CorpusPaths::KIND_EXTRACTOR) {
                $files[] = $paths->goldenPath($key, true);
            }

            foreach ($files as $file) {
                $contents = (string) \file_get_contents($file);

                self::assertStringNotContainsString("\r", $contents, $file . ' contains a CR byte.');
                self::assertStringNotContainsString('tailwindcss v', $contents, $file . ' carries the CLI banner.');
                self::assertStringNotContainsString(\dirname(__DIR__, 3), $contents, $file . ' leaks a local path.');
            }
        }
    }

    private function paths(): CorpusPaths
    {
        return new CorpusPaths($this->root . '/conformance', $this->root . '/fixtures/extractor');
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0777, true);
        }

        \file_put_contents($path, $contents);
    }
}
