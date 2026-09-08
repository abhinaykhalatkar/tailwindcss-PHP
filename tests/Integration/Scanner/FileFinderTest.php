<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Real temporary directories throughout (design doc 16 §Integration test
 * conventions): {@see FileFinder} walks the filesystem via SPL iterators, so
 * a fake root would not exercise the extension/ignore/symlink logic at all.
 *
 * @covers-requirement FR-FRESH-2
 */
final class FileFinderTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-filefinder');

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function findsFilesMatchingTheRootsExtensions(): void
    {
        $this->write('assets/styles/main.css', 'body{}');
        $this->write('assets/styles/notes.txt', 'ignored, wrong extension');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/assets/styles', 'assets/styles', Root::INPUT, ['css', 'scss']),
        ]);

        self::assertSame(['assets/styles/main.css'], \array_keys($snapshot->files()));

        $record = $snapshot->get('assets/styles/main.css');
        self::assertNotNull($record);
        self::assertSame('i', $record['k']);
    }

    /** @test */
    public function extensionMatchingIsCaseInsensitive(): void
    {
        $this->write('templates/a.TWIG', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertTrue($snapshot->has('templates/a.TWIG'));
    }

    /** @test */
    public function twoSegmentExtensionsLikeBladePhpAreMatchedBeforePlainPhp(): void
    {
        $this->write('templates/a.blade.php', 'x');
        $this->write('templates/b.php', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['blade.php']),
        ]);

        self::assertSame(['templates/a.blade.php'], \array_keys($snapshot->files()));
    }

    /** @test */
    public function plainPhpExtensionDoesNotSwallowBladeFilesUnlessConfigured(): void
    {
        $this->write('templates/a.blade.php', 'x');
        $this->write('templates/b.php', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['php']),
        ]);

        // "a.blade.php" still ends in ".php" as its last segment, so it is
        // found too — only the *priority* between the two forms is ordered,
        // not exclusivity (design doc 13 #10, design doc 09 §File discovery).
        self::assertSame(
            ['templates/a.blade.php', 'templates/b.php'],
            \array_keys($snapshot->files())
        );
    }

    /**
     * @test
     *
     * @dataProvider alwaysIgnoredDirectoryCases
     */
    public function ignoredDirectoriesAreNeverEntered(string $ignoredDir): void
    {
        $this->write($ignoredDir . '/hidden.twig', 'x');
        $this->write('templates/visible.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root, '', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(['templates/visible.twig'], \array_keys($snapshot->files()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function alwaysIgnoredDirectoryCases(): array
    {
        return [
            '.git' => ['.git'],
            'node_modules' => ['node_modules'],
            'vendor' => ['vendor'],
        ];
    }

    /** @test */
    public function customIgnoreEntryDirectoryIsNeverEntered(): void
    {
        $this->write('dist/hidden.twig', 'x');
        $this->write('templates/visible.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', ['dist'], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root, '', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(['templates/visible.twig'], \array_keys($snapshot->files()));
    }

    /** @test */
    public function theConfiguredCacheDirectoryIsNeverEntered(): void
    {
        $this->write('cache/tailwind/styles.meta.json', '{}');
        // A file matching the (unusual but possible) source extension inside
        // the cache directory must never surface as a candidate source.
        $this->write('cache/tailwind/leaked.twig', 'x');
        $this->write('templates/visible.twig', 'x');

        $finder = new FileFinder($this->root . '/cache/tailwind', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root, '', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(['templates/visible.twig'], \array_keys($snapshot->files()));
    }

    /** @test */
    public function reachingMaxFilesThrows(): void
    {
        $this->write('templates/a.twig', 'x');
        $this->write('templates/b.twig', 'x');
        $this->write('templates/c.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 2);

        $this->expectException(ScanLimitException::class);

        $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);
    }

    /** @test */
    public function aMissingRootIsRecordedRatherThanThrown(): void
    {
        $this->write('templates/a.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
            new Root($this->root . '/does-not-exist', 'does-not-exist', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(['templates/a.twig'], \array_keys($snapshot->files()));
        self::assertSame(['does-not-exist'], $snapshot->missingRoots());
    }

    /** @test */
    public function resultIsOrderedByRelativePath(): void
    {
        $this->write('templates/z.twig', 'x');
        $this->write('templates/a.twig', 'x');
        $this->write('templates/m.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(
            ['templates/a.twig', 'templates/m.twig', 'templates/z.twig'],
            \array_keys($snapshot->files())
        );
    }

    /** @test */
    public function multipleRootsAreMergedIntoOneSnapshot(): void
    {
        $this->write('assets/styles/main.css', 'x');
        $this->write('templates/a.twig', 'x');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/assets/styles', 'assets/styles', Root::INPUT, ['css']),
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame(2, $snapshot->count());

        $inputRecord = $snapshot->get('assets/styles/main.css');
        $sourceRecord = $snapshot->get('templates/a.twig');
        self::assertNotNull($inputRecord);
        self::assertNotNull($sourceRecord);
        self::assertSame('i', $inputRecord['k']);
        self::assertSame('s', $sourceRecord['k']);
    }

    /** @test */
    public function recordsTheRealMtimeAndSize(): void
    {
        $this->write('templates/a.twig', '0123456789');
        \touch($this->root . '/templates/a.twig', 1700000000);
        \clearstatcache(true, $this->root . '/templates/a.twig');

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        $record = $snapshot->get('templates/a.twig');

        self::assertNotNull($record);
        self::assertSame(1700000000, $record['m']);
        self::assertSame(10, $record['s']);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        \file_put_contents($path, $contents);

        return $path;
    }
}
