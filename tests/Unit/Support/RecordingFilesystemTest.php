<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The lifecycle matrix asserts on this double's counters, so a miscount here
 * would show up as a *wrong* matrix rather than a failing one — the reason a
 * test double gets tested at all.
 */
final class RecordingFilesystemTest extends TestCase
{
    /**
     * @var string
     */
    private $root;

    /**
     * @var RecordingFilesystem
     */
    private $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-recording-fs');
        TempTree::registerShutdownCleanup($this->root);

        $this->fs = new RecordingFilesystem();
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(RecordingFilesystem::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function countsNothingBeforeAnythingHappens(): void
    {
        self::assertSame(0, $this->fs->reads());
        self::assertSame(0, $this->fs->stats());
        self::assertSame([], $this->fs->calls());
    }

    /** @test */
    public function readsAndStatsIncrementPerCall(): void
    {
        $file = $this->write('a.css', '.a{}');

        $this->fs->read($file, 1024);

        self::assertSame(1, $this->fs->reads());
        self::assertSame(0, $this->fs->stats());

        $this->fs->read($file, 1024);
        $this->fs->stat($file);

        self::assertSame(2, $this->fs->reads());
        self::assertSame(1, $this->fs->stats());
    }

    /**
     * Every method on the interface has to be counted; one that delegates
     * without recording would make a matrix cell silently optimistic.
     *
     * @test
     */
    public function callsReportsEveryMethodUsed(): void
    {
        $dir = $this->root . '/sub';
        $file = $dir . '/a.css';

        $this->fs->mkdir($dir);
        $this->fs->touch($file);
        $this->fs->clearStatCache($file);
        $this->fs->exists($file);
        $this->fs->isFile($file);
        $this->fs->isDir($dir);
        $this->fs->stat($file);
        $this->fs->read($file, 1024);
        $this->fs->unlink($file);

        $calls = $this->fs->calls();
        \ksort($calls);

        self::assertSame([
            'clearStatCache' => 1,
            'exists' => 1,
            'isDir' => 1,
            'isFile' => 1,
            'mkdir' => 1,
            'read' => 1,
            'stat' => 1,
            'touch' => 1,
            'unlink' => 1,
        ], $calls);
    }

    /** @test */
    public function callsCountsRepeatedUseOfTheSameMethod(): void
    {
        $file = $this->write('a.css', '.a{}');

        $this->fs->isFile($file);
        $this->fs->isFile($file);
        $this->fs->isFile($file);

        self::assertSame(['isFile' => 3], $this->fs->calls());
    }

    /** @test */
    public function resetZeroesTheCounts(): void
    {
        $file = $this->write('a.css', '.a{}');

        $this->fs->read($file, 1024);
        $this->fs->stat($file);

        $this->fs->reset();

        self::assertSame(0, $this->fs->reads());
        self::assertSame(0, $this->fs->stats());
        self::assertSame([], $this->fs->calls());

        // Still usable afterwards: setup happens, then the request under
        // examination is measured from zero.
        $this->fs->stat($file);

        self::assertSame(['stat' => 1], $this->fs->calls());
    }

    /**
     * The whole point of decorating the real filesystem rather than faking one:
     * the answers have to be the genuine ones.
     *
     * @test
     */
    public function delegatesToTheRealFilesystem(): void
    {
        $css = '.a{color:red}';
        $file = $this->write('a.css', $css);

        self::assertTrue($this->fs->exists($file));
        self::assertTrue($this->fs->isFile($file));
        self::assertFalse($this->fs->isDir($file));
        self::assertTrue($this->fs->isDir($this->root));
        self::assertSame($css, $this->fs->read($file, 1024));

        $stat = $this->fs->stat($file);

        self::assertNotNull($stat);
        self::assertSame(\strlen($css), $stat['size']);
        self::assertSame(\filemtime($file), $stat['mtime']);
    }

    /** @test */
    public function delegatesWritesAndRemovalsToTheRealFilesystem(): void
    {
        $dir = $this->root . '/made/deeper';
        $file = $dir . '/a.css';

        $this->fs->mkdir($dir);

        self::assertDirectoryExists($dir);

        $this->fs->touch($file, 1600000000);

        self::assertFileExists($file);
        self::assertSame(1600000000, \filemtime($file));

        self::assertTrue($this->fs->unlink($file));
        self::assertFileDoesNotExist($file);
        self::assertFalse($this->fs->unlink($file));
    }

    /**
     * The inner implementation is injectable so a recorder can sit on top of a
     * failing or read-only double; the calls have to reach it.
     *
     * @test
     */
    public function delegatesToAnInjectedInnerImplementation(): void
    {
        $inner = new RecordingFilesystem();
        $outer = new RecordingFilesystem($inner);

        $outer->isDir($this->root);
        $outer->exists($this->root);

        self::assertSame(['isDir' => 1, 'exists' => 1], $inner->calls());
        self::assertSame($inner->calls(), $outer->calls());
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->root . '/' . $name;

        \file_put_contents($path, $contents);
        \clearstatcache(true, $path);

        return $path;
    }
}
