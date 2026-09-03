<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FilesystemInterface;

/**
 * Integration rather than unit: every method of the facade is a syscall, so the
 * only meaningful test is against a real temporary tree (design doc 16
 * §Integration test conventions).
 */
final class FilesystemTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var Filesystem
     */
    private $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $base = \realpath(\sys_get_temp_dir());

        if ($base === false) {
            self::markTestSkipped('The system temp directory could not be resolved.');
        }

        $this->root = \rtrim(\str_replace('\\', '/', $base), '/')
            . '/tailwindphp-fs-' . \bin2hex(\random_bytes(6));

        \mkdir($this->root, 0777, true);

        $this->fs = new Filesystem();

        // Backup cleanup: tearDown handles the normal path, this catches a
        // fatal that skips it (design doc 16 §Integration test conventions).
        $dir = $this->root;

        \register_shutdown_function(static function () use ($dir): void {
            if (\is_dir($dir)) {
                self::removeTree($dir);
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            self::removeTree($this->root);
        }

        parent::tearDown();
    }

    /** @test */
    public function classIsFinalAndImplementsTheInterface(): void
    {
        $reflection = new ReflectionClass(Filesystem::class);

        self::assertTrue($reflection->isFinal());
        self::assertInstanceOf(FilesystemInterface::class, $this->fs);
    }

    // ----------------------------------------------------- exists/isFile/isDir

    /** @test */
    public function existsAnswersForFilesDirectoriesAndMissingPaths(): void
    {
        $file = $this->write('a.css', 'x');

        self::assertTrue($this->fs->exists($file));
        self::assertTrue($this->fs->exists($this->root));
        self::assertFalse($this->fs->exists($this->root . '/nope'));
    }

    /** @test */
    public function isFileIsTrueOnlyForFiles(): void
    {
        $file = $this->write('a.css', 'x');

        self::assertTrue($this->fs->isFile($file));
        self::assertFalse($this->fs->isFile($this->root));
        self::assertFalse($this->fs->isFile($this->root . '/nope'));
    }

    /** @test */
    public function isDirIsTrueOnlyForDirectories(): void
    {
        $file = $this->write('a.css', 'x');

        self::assertTrue($this->fs->isDir($this->root));
        self::assertFalse($this->fs->isDir($file));
        self::assertFalse($this->fs->isDir($this->root . '/nope'));
    }

    // -------------------------------------------------------------------- stat

    /** @test */
    public function statReturnsMtimeAndSize(): void
    {
        $file = $this->write('a.css', '0123456789');
        $when = 1600000000;

        \touch($file, $when);
        \clearstatcache(true, $file);

        $stat = $this->fs->stat($file);

        self::assertNotNull($stat);
        self::assertSame($when, $stat['mtime']);
        self::assertSame(10, $stat['size']);
    }

    /** @test */
    public function statReturnsNullForAMissingPath(): void
    {
        self::assertNull($this->fs->stat($this->root . '/nope'));
    }

    /** @test */
    public function statWorksForADirectory(): void
    {
        $stat = $this->fs->stat($this->root);

        self::assertNotNull($stat);
        self::assertGreaterThan(0, $stat['mtime']);
    }

    // -------------------------------------------------------------------- read

    /** @test */
    public function readReturnsTheWholeFileWhenItFitsTheBudget(): void
    {
        $file = $this->write('a.css', '0123456789');

        self::assertSame('0123456789', $this->fs->read($file, 1024));
    }

    /**
     * The budget is a hard stop, not a hint: a file over `limits.max_file_size`
     * must not be pulled into memory whole (design doc 12 T-05).
     *
     * @test
     */
    public function readStopsAtTheByteBudget(): void
    {
        $file = $this->write('a.css', '0123456789');

        $content = $this->fs->read($file, 3);

        self::assertSame('012', $content);
        self::assertSame(3, \strlen($content));
    }

    /** @test */
    public function readReturnsAnEmptyStringForAZeroBudget(): void
    {
        $file = $this->write('a.css', '0123456789');

        self::assertSame('', $this->fs->read($file, 0));
    }

    /** @test */
    public function readHandlesAnEmptyFile(): void
    {
        $file = $this->write('empty.css', '');

        self::assertSame('', $this->fs->read($file, 1024));
    }

    /** @test */
    public function readIsByteExactForBinaryContent(): void
    {
        $bytes = "\x00\xFF\xFE\x0A\x0D";
        $file = $this->write('bin.dat', $bytes);

        self::assertSame($bytes, $this->fs->read($file, 1024));
    }

    /** @test */
    public function readThrowsWhenTheFileCannotBeOpened(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->fs->read($this->root . '/nope', 1024);
    }

    // ------------------------------------------------------------------- mkdir

    /** @test */
    public function mkdirCreatesNestedDirectories(): void
    {
        $dir = $this->root . '/a/b/c';

        $this->fs->mkdir($dir);

        self::assertTrue($this->fs->isDir($dir));
    }

    /** @test */
    public function mkdirIsIdempotent(): void
    {
        $dir = $this->root . '/a/b';

        $this->fs->mkdir($dir);
        $this->fs->mkdir($dir);

        self::assertTrue($this->fs->isDir($dir));
    }

    /** @test */
    public function mkdirAppliesTheModeOnPosix(): void
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $dir = $this->root . '/perm';

        $this->fs->mkdir($dir, 0700);
        \clearstatcache(true, $dir);

        $permissions = \fileperms($dir);

        self::assertNotFalse($permissions);
        // mkdir() applies mode & ~umask, so the umask has to be accounted for.
        self::assertSame(0700 & ~\umask(), $permissions & 0777);
    }

    /** @test */
    public function mkdirThrowsWhenTheDirectoryCannotBeCreated(): void
    {
        // A file sits where the directory should go, so mkdir cannot succeed
        // and the path is not a directory afterwards either.
        $blocked = $this->write('blocker', 'x');

        $this->expectException(\RuntimeException::class);

        $this->fs->mkdir($blocked . '/child');
    }

    // ------------------------------------------------------------------- touch

    /** @test */
    public function touchCreatesAMissingFile(): void
    {
        $file = $this->root . '/new.css';

        $this->fs->touch($file);

        self::assertTrue($this->fs->isFile($file));
    }

    /** @test */
    public function touchSetsTheMtime(): void
    {
        $file = $this->write('a.css', 'x');
        $when = 1500000000;

        $this->fs->touch($file, $when);
        $this->fs->clearStatCache($file);

        $stat = $this->fs->stat($file);

        self::assertNotNull($stat);
        self::assertSame($when, $stat['mtime']);
    }

    /** @test */
    public function touchThrowsWhenTheParentDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->fs->touch($this->root . '/missing-dir/x.css');
    }

    // ------------------------------------------------------------------ unlink

    /** @test */
    public function unlinkReportsTrueWhenItRemovedTheFile(): void
    {
        $file = $this->write('a.css', 'x');

        self::assertTrue($this->fs->unlink($file));
        self::assertFalse($this->fs->exists($file));
    }

    /**
     * A missing file is not an error: pruning a cache entry that another
     * process already removed is normal (design doc 12 T-13).
     *
     * @test
     */
    public function unlinkReportsFalseWhenThereWasNothingToRemove(): void
    {
        self::assertFalse($this->fs->unlink($this->root . '/nope'));
    }

    /** @test */
    public function unlinkThrowsWhenThePathExistsButCannotBeRemoved(): void
    {
        // A directory is not removable with unlink() on any platform.
        $dir = $this->root . '/a-directory';

        $this->fs->mkdir($dir);

        $this->expectException(\RuntimeException::class);

        $this->fs->unlink($dir);
    }

    // ---------------------------------------------------------- clearStatCache

    /**
     * The freshness layer stats a file, writes it, and stats again within one
     * request; without clearing, PHP would hand back the first answer
     * (design doc 05 §Layer 1).
     *
     * @test
     */
    public function clearStatCacheMakesAChangedMtimeVisible(): void
    {
        $file = $this->write('a.css', 'x');

        $this->fs->stat($file);

        \touch($file, 1400000000);

        $this->fs->clearStatCache($file);

        $stat = $this->fs->stat($file);

        self::assertNotNull($stat);
        self::assertSame(1400000000, $stat['mtime']);
    }

    /** @test */
    public function clearStatCacheOnAMissingPathIsHarmless(): void
    {
        $this->fs->clearStatCache($this->root . '/nope');

        self::assertNull($this->fs->stat($this->root . '/nope'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->root . '/' . $name;

        \file_put_contents($path, $contents);

        return $path;
    }

    private static function removeTree(string $dir): void
    {
        $entries = \scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if (\is_dir($full) && !\is_link($full)) {
                self::removeTree($full);

                continue;
            }

            \unlink($full);
        }

        \rmdir($dir);
    }
}
