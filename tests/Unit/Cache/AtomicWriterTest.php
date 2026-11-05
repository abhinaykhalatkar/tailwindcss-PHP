<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Exception\CacheWriteException;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The atomic write protocol of design doc 05 (FR-CACHE-2, threats T-13/T-14/T-17).
 *
 * A real temporary directory rather than a double: the whole point of the class
 * is the `fopen('xb')` / `chmod` / `rename` sequence, and a fake filesystem
 * would only assert that the test author remembered the sequence.
 *
 * @covers-requirement FR-CACHE-2
 */
final class AtomicWriterTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var AtomicWriter
     */
    private $writer;

    /**
     * Directories whose mode a test changed; restored so cleanup can run.
     *
     * @var list<string>
     */
    private $restoreModes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-atomic');
        $this->writer = new AtomicWriter($this->root);

        // Backup cleanup: tearDown handles the normal path, this catches a
        // fatal that skips it (design doc 16 §Integration test conventions).
        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->restoreModes as $dir) {
            if (\is_dir($dir)) {
                \chmod($dir, 0700);
            }
        }

        $this->restoreModes = [];

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(AtomicWriter::class);

        self::assertTrue($reflection->isFinal());
    }

    // ------------------------------------------------------------- happy paths

    /** @test */
    public function writeCreatesTheTargetWithTheExactBytes(): void
    {
        $target = $this->root . '/app.css';

        self::assertFalse(\is_file($target));

        $this->writer->write($target, ".a{color:red}\n");

        self::assertTrue(\is_file($target));
        self::assertSame(".a{color:red}\n", (string) \file_get_contents($target));
    }

    /** @test */
    public function writeReplacesAnExistingTargetAndLeavesNoTempFile(): void
    {
        $target = $this->root . '/app.css';

        $this->writer->write($target, 'first');
        $this->writer->write($target, 'second');

        self::assertSame('second', (string) \file_get_contents($target));
        self::assertSame(['app.css'], $this->entries($this->root));
    }

    /**
     * The target may already exist — only the *temp* name is created
     * exclusively. A cache rebuild overwrites the previous artefact on every
     * deploy, so this is the ordinary case rather than an edge one.
     *
     * @test
     */
    public function writeSucceedsWhenTheTargetAlreadyExists(): void
    {
        $target = $this->root . '/app.css';

        \file_put_contents($target, 'planted');

        $this->writer->write($target, 'written');

        self::assertSame('written', (string) \file_get_contents($target));
    }

    /** @test */
    public function writeAcceptsAnEmptyStringAndCreatesAZeroByteFile(): void
    {
        $target = $this->root . '/index.html';

        $this->writer->write($target, '');

        self::assertTrue(\is_file($target));
        self::assertSame(0, (int) \filesize($target));
        self::assertSame(['index.html'], $this->entries($this->root));
    }

    /**
     * Exercises the `fwrite` loop: a payload this size is written in several
     * chunks, and a loop that mishandled a short write would truncate it.
     *
     * @test
     */
    public function writeRoundTripsALargePayloadByteIdentically(): void
    {
        $payload = \random_bytes(2 * 1024 * 1024);
        $target = $this->root . '/large.bin';

        $this->writer->write($target, $payload);

        $read = (string) \file_get_contents($target);

        self::assertSame(\strlen($payload), \strlen($read));
        self::assertSame(\hash('sha256', $payload), \hash('sha256', $read));
    }

    // ------------------------------------------------------- the 'x' semantics

    /**
     * The temp name is built from `getmypid()` and `random_bytes(4)`, so it
     * cannot be predicted from outside and the guard cannot be proven by
     * pre-planting the exact name. Its *shape* is the testable part: a
     * neighbour of the target, prefixed with the target's basename so a stale
     * temp file is traceable back to it (decision CCP-A5).
     *
     * @test
     */
    public function theTemporaryNameIsATraceableNeighbourOfTheTarget(): void
    {
        $target = $this->root . '/app.css';

        $method = new ReflectionMethod(AtomicWriter::class, 'temporaryNameFor');

        $temp = $method->invoke($this->writer, $target);

        self::assertIsString($temp);
        self::assertMatchesRegularExpression('#\.tmp\.\d+\.[a-f0-9]{8}$#', $temp);
        self::assertSame(\dirname($target), \dirname($temp));
        self::assertStringStartsWith(\basename($target) . '.tmp.', \basename($temp));
    }

    /**
     * The other half of the guard: the mode the temp file is opened with is the
     * non-clobbering one, so a symlink planted at the temp name makes the write
     * fail instead of redirecting it (threat T-14).
     *
     * @test
     */
    public function exclusiveCreateModeRefusesAPathThatAlreadyExists(): void
    {
        $planted = $this->root . '/planted';

        \file_put_contents($planted, 'x');

        // Silenced deliberately: 'x' on an existing path raises a warning and
        // the return value is the assertion, not the warning.
        $handle = @\fopen($planted, 'xb');

        self::assertFalse($handle);
    }

    /**
     * A source-order assertion, which is unusual and deliberate.
     *
     * **What this pins:** that the temp file is opened with the non-clobbering
     * `'xb'` mode (T-14), that `chmod` is called before `rename` (T-17), and
     * that the `copy()` fallback has not returned (CCP-A26).
     *
     * **What it cannot pin:** the *behaviour*, only this spelling of it. Both
     * properties are unobservable from outside — the temp name is unpredictable
     * by design, so nothing can race the window between `chmod` and `rename`,
     * and exclusive-create leaves no trace when it succeeds. A mutant that
     * swapped `'xb'` for `'wb'`, or moved the `chmod` after the `rename`,
     * survived the entire suite, which is why this exists at all.
     *
     * **The trade-off, stated plainly:** rewriting the call — extracting the
     * mode into a constant, aliasing `chmod`, moving the rename into a helper —
     * silently un-pins it, because the strings below stop matching while the
     * assertions still pass. If you change the shape of `write()`, change these
     * needles in the same commit.
     *
     * @test
     */
    public function theWriteProtocolIsExclusiveCreateThenChmodThenRename(): void
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/src/Cache/AtomicWriter.php');

        self::assertIsString($source);

        $open = \strpos($source, "@\\fopen(\$temp, 'xb')");
        $chmod = \strpos($source, '@\\chmod($temp, $mode)');
        $rename = \strpos($source, 'renameWithRetry($temp, $target)');

        self::assertNotFalse($open, "The temp file must be opened with the non-clobbering 'xb' mode (T-14).");
        self::assertNotFalse($chmod, 'The temp file must be chmod-ed before it is renamed (T-17).');
        self::assertNotFalse($rename);

        self::assertLessThan($chmod, $open, 'The file must be created before it is chmod-ed.');
        self::assertLessThan(
            $rename,
            $chmod,
            'chmod must precede rename, or the artefact is briefly visible under its final name at the wrong mode.'
        );

        // The copy fallback published a partial file under the final name
        // (decision CCP-A26); it must not come back.
        self::assertStringNotContainsString('\\copy(', $source, 'The copy fallback was reintroduced.');
    }

    // ---------------------------------------------------------------- failures

    /** @test */
    public function writeThrowsWhenTheTargetDirectoryDoesNotExist(): void
    {
        $target = $this->root . '/missing-dir/app.css';

        try {
            $this->writer->write($target, 'x');

            self::fail('Expected a CacheWriteException for a missing directory.');
        } catch (CacheWriteException $e) {
            self::assertSame('create', $e->operation());
        }
    }

    /**
     * Paths in exception messages are project-relative (ADR-014): an absolute
     * one would leak the server layout into a log (threat T-06).
     *
     * @test
     */
    public function theFailureMessageCarriesNoAbsolutePath(): void
    {
        $target = $this->root . '/missing-dir/app.css';

        try {
            $this->writer->write($target, 'x');

            self::fail('Expected a CacheWriteException for a missing directory.');
        } catch (CacheWriteException $e) {
            $message = $e->getMessage();

            self::assertStringNotContainsString($this->root, $message);
            self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $message);

            $relative = $e->relativePath();

            self::assertNotNull($relative);
            self::assertStringStartsNotWith('/', $relative);
            self::assertDoesNotMatchRegularExpression('#^[A-Za-z]:[\\\\/]#', $relative);
        }
    }

    /** @test */
    public function writeThrowsWhenTheTargetDirectoryIsReadOnly(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file modes are not modelled on Windows.');
        }

        $dir = $this->root . '/read-only';

        \mkdir($dir, 0700);
        \chmod($dir, 0500);
        $this->restoreModes[] = $dir;
        \clearstatcache(true, $dir);

        if (\is_writable($dir)) {
            self::markTestSkipped('The directory is still writable; the test user bypasses file modes.');
        }

        $this->expectException(CacheWriteException::class);

        $this->writer->write($dir . '/app.css', 'x');
    }

    // ------------------------------------------------------------------- modes

    /** @test */
    public function theWrittenFileIsMode0644ByDefault(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file modes are not modelled on Windows.');
        }

        $target = $this->root . '/app.css';

        $this->writer->write($target, 'x');
        \clearstatcache(true, $target);

        $permissions = \fileperms($target);

        self::assertNotFalse($permissions);
        self::assertSame(0644, $permissions & 0777);
    }

    /** @test */
    public function writeHonoursACustomMode(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file modes are not modelled on Windows.');
        }

        $target = $this->root . '/app.css';

        $this->writer->write($target, 'x', 0600);
        \clearstatcache(true, $target);

        $permissions = \fileperms($target);

        self::assertNotFalse($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    /**
     * Directory entries, sorted, without the dot entries.
     *
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $names = \scandir($dir);

        self::assertNotFalse($names);

        $result = [];

        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $result[] = $name;
            }
        }

        \sort($result);

        return $result;
    }
}
