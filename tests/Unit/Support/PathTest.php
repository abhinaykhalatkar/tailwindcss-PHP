<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Path;

/**
 * Covers design doc 12 threats T-01 (traversal via configuration), T-02
 * (traversal via HTTP) and T-14 (symlink escape).
 *
 * The lexical rows run on every platform because {@see Path::normalize()} never
 * touches the filesystem; only the rows that genuinely need `realpath` create a
 * temporary tree, and the symlink rows skip on Windows.
 */
final class PathTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * Junctions created during a test, removed before the tree walk.
     *
     * @var list<string>
     */
    private $junctions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $base = \realpath(\sys_get_temp_dir());

        if ($base === false) {
            self::markTestSkipped('The system temp directory could not be resolved.');
        }

        $this->root = \rtrim(\str_replace('\\', '/', $base), '/')
            . '/tailwindphp-path-' . \bin2hex(\random_bytes(6));

        \mkdir($this->root . '/assets/styles', 0777, true);
        \mkdir($this->root . '/templates', 0777, true);
        \file_put_contents($this->root . '/assets/styles/main.css', '@import "tailwindcss";');
    }

    protected function tearDown(): void
    {
        // Deepest first, so a junction inside another one is gone before its
        // parent is unlinked.
        foreach (\array_reverse($this->junctions) as $junction) {
            // A junction whose target has gone still occupies a directory entry
            // but reports is_dir() === false, so existence is checked by looking
            // for the name in its parent rather than by stat-ing it.
            if (self::entryExists($junction)) {
                \rmdir($junction);
            }
        }

        $this->junctions = [];

        if ($this->root !== '' && \is_dir($this->root)) {
            self::removeTree($this->root);
        }

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Path::class);

        self::assertTrue($reflection->isFinal());
    }

    // ---------------------------------------------------------------- hasNul

    /** @test */
    public function nulBytesAreDetectedAnywhereInTheString(): void
    {
        self::assertTrue(Path::hasNul("safe.css\0../../etc/passwd"));
        self::assertTrue(Path::hasNul("\0"));
        self::assertFalse(Path::hasNul('assets/styles/main.css'));
    }

    // ------------------------------------------------------------- isWrapper

    /**
     * @test
     *
     * @dataProvider wrapperCases
     */
    public function streamWrappersAreRecognised(string $path, bool $expected): void
    {
        self::assertSame($expected, Path::isWrapper($path));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function wrapperCases(): array
    {
        return [
            'phar' => ['phar://archive.phar/x.css', true],
            'PHAR uppercase' => ['PHAR://archive.phar/x.css', true],
            'PhAr mixed case' => ['PhAr://archive.phar/x.css', true],
            'file' => ['file:///etc/passwd', true],
            'http' => ['http://example.com/x.css', true],
            'data' => ['data://text/plain;base64,AAAA', true],
            'compress.zlib with a dot' => ['compress.zlib://x', true],
            'php input' => ['php://input', true],
            'plain relative path' => ['assets/styles/main.css', false],
            'plain absolute path' => ['/var/www/app/x.css', false],
            'windows drive' => ['C:/www/app/x.css', false],
            'colon without slashes' => ['a:b', false],
            'scheme not at the start' => ['x/phar://y', false],
        ];
    }

    // ------------------------------------------------------------- normalize

    /**
     * @test
     *
     * @dataProvider normalizeCases
     */
    public function normalizeCollapsesLexically(string $input, string $expected): void
    {
        self::assertSame($expected, Path::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeCases(): array
    {
        return [
            'already clean' => ['a/b/c', 'a/b/c'],
            'backslashes become slashes' => ['a\\b\\c', 'a/b/c'],
            'mixed separators' => ['a\\b/c\\d', 'a/b/c/d'],
            'duplicate separators' => ['a//b///c', 'a/b/c'],
            'single dots' => ['a/./b/./c', 'a/b/c'],
            'trailing separator' => ['a/b/', 'a/b'],
            'trailing separators' => ['a/b///', 'a/b'],
            'dot dot inside' => ['a/b/../c', 'a/c'],
            'dot dot collapses to empty' => ['a/..', ''],
            'leading dot dot is kept when relative' => ['../a', '../a'],
            'multiple leading dot dots kept' => ['../../a', '../../a'],
            'dot dot cannot climb above posix root' => ['/../../etc', '/etc'],
            'absolute stays absolute' => ['/a/b', '/a/b'],
            'absolute root' => ['/', '/'],
            'drive letter is upper-cased' => ['c:/a/b', 'C:/a/b'],
            'drive with backslashes' => ['C:\\a\\b', 'C:/a/b'],
            'drive root' => ['C:\\', 'C:/'],
            'drive dot dot cannot climb out' => ['C:/../../x', 'C:/x'],
            'drive relative is preserved' => ['C:a/b', 'C:a/b'],
            'empty string' => ['', ''],
        ];
    }

    /**
     * UNC and `\\?\` prefixes are parsed on Windows only — on POSIX a leading
     * `//` collapses to a root by design (see the POSIX rows above), so these
     * expectations are platform-specific and must not run on Linux CI.
     *
     * @test
     *
     * @dataProvider windowsPrefixCases
     */
    public function windowsPrefixesArePreserved(string $input, string $expected): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('UNC and extended-length prefixes are parsed on Windows only.');
        }

        self::assertSame($expected, Path::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function windowsPrefixCases(): array
    {
        return [
            'unc share' => ['\\\\server\\share\\a', '//server/share/a'],
            'unc share root' => ['\\\\server\\share', '//server/share'],
            'unc dot dot cannot climb out' => ['\\\\server\\share\\..\\..\\x', '//server/share/x'],
            'extended length drive' => ['\\\\?\\C:\\a\\b', '//?/C:/a/b'],
            'extended length unc' => ['\\\\?\\UNC\\server\\share\\a', '//?/UNC/server/share/a'],
            'dot dot as server is not a share' => ['\\\\..\\x', '/x'],
            'dot as server is not a share' => ['\\\\.\\..\\x', '/x'],
        ];
    }

    // --------------------------------------------------------------- resolve

    /** @test */
    public function resolveReturnsTheRealPathOfAnExistingFile(): void
    {
        $resolved = Path::resolve($this->root, 'assets/styles/main.css', [$this->root], true);

        self::assertSame($this->root . '/assets/styles/main.css', $resolved);
    }

    /** @test */
    public function resolveAcceptsAnAbsolutePathInsideTheRoots(): void
    {
        $resolved = Path::resolve(
            $this->root,
            $this->root . '/assets/styles/main.css',
            [$this->root],
            true
        );

        self::assertSame($this->root . '/assets/styles/main.css', $resolved);
    }

    /** @test */
    public function resolveToleratesTrailingSeparatorsOnBaseAndPath(): void
    {
        $resolved = Path::resolve($this->root . '/', 'assets/styles/', [$this->root], true);

        self::assertSame($this->root . '/assets/styles', $resolved);
    }

    /**
     * T-01: the headline traversal case.
     *
     * @test
     */
    public function resolveRejectsClimbingOutOfTheRoots(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path resolves outside the allowed roots');

        Path::resolve($this->root, '../../etc/passwd', [$this->root], false);
    }

    /** @test */
    public function resolveRejectsANulByte(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path contains a NUL byte');

        Path::resolve($this->root, "assets/styles/main.css\0.png", [$this->root], true);
    }

    /**
     * @test
     *
     * @dataProvider wrapperRejections
     */
    public function resolveRejectsStreamWrappers(string $path): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Stream wrappers are not allowed');

        Path::resolve($this->root, $path, [$this->root], false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wrapperRejections(): array
    {
        return [
            'phar' => ['phar://payload.phar/x.css'],
            'PHAR uppercase' => ['PHAR://payload.phar/x.css'],
            'file' => ['file:///etc/passwd'],
            'http' => ['http://example.com/x.css'],
        ];
    }

    /** @test */
    public function resolveRejectsAnEmptyPath(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path is empty');

        Path::resolve($this->root, '', [$this->root], false);
    }

    /** @test */
    public function resolveRejectsARelativeBase(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Base directory must be an absolute path');

        Path::resolve('relative/base', 'x.css', [$this->root], false);
    }

    /** @test */
    public function resolveRejectsDriveRelativePaths(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Drive-relative paths are not supported');

        Path::resolve($this->root, 'C:x.css', [$this->root], false);
    }

    /**
     * `realpath()` returns false both for a genuinely missing file and for one
     * hidden by `open_basedir`; with $mustExist the safe reading of both is
     * "not there" (T-01).
     *
     * @test
     */
    public function resolveRejectsAMissingPathWhenItMustExist(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path does not exist or is not readable');

        Path::resolve($this->root, 'assets/styles/nope.css', [$this->root], true);
    }

    /**
     * Design doc 11 §5 allows a missing target for `cache_dir` and `cli.binary`;
     * those fall back to the lexical form but are still confined.
     *
     * @test
     */
    public function resolveFallsBackToLexicalNormalisationWhenTheTargetMayBeMissing(): void
    {
        $resolved = Path::resolve($this->root, 'cache/tailwind', [$this->root], false);

        self::assertSame($this->root . '/cache/tailwind', $resolved);
    }

    /** @test */
    public function resolveStillConfinesAMissingPath(): void
    {
        $this->expectException(PathException::class);

        Path::resolve($this->root, '../sibling/cache', [$this->root], false);
    }

    /**
     * The byte-prefix trap called out in T-01: `/app` must not match `/app2`.
     *
     * @test
     */
    public function resolveDoesNotTreatASiblingWithASharedPrefixAsInside(): void
    {
        \mkdir($this->root . '/app');
        \mkdir($this->root . '/app2');
        \file_put_contents($this->root . '/app2/secret.css', 'x');

        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path resolves outside the allowed roots');

        Path::resolve($this->root . '/app', '../app2/secret.css', [$this->root . '/app'], true);
    }

    /** @test */
    public function resolveAcceptsASecondAllowedRoot(): void
    {
        \mkdir($this->root . '/shared');
        \file_put_contents($this->root . '/shared/theme.css', 'x');

        $resolved = Path::resolve(
            $this->root . '/assets',
            $this->root . '/shared/theme.css',
            [$this->root . '/assets', $this->root . '/shared'],
            true
        );

        self::assertSame($this->root . '/shared/theme.css', $resolved);
    }

    /** @test */
    public function resolveAllowsNothingWhenThereAreNoRoots(): void
    {
        $this->expectException(PathException::class);

        Path::resolve($this->root, 'assets/styles/main.css', [], true);
    }

    // ------------------------------------------- ancestor walk (finding 1)

    /**
     * A path whose tail does not exist yet must still be resolved through the
     * real filesystem as far as it goes, otherwise a link part-way along is
     * invisible. This is the `cache_dir` case — the only write path.
     *
     * @test
     */
    public function resolveWalksUpToTheLongestExistingAncestor(): void
    {
        $expected = \str_replace('\\', '/', (string) \realpath($this->root . '/assets'))
            . '/new/deeper';

        $resolved = Path::resolve($this->root, 'assets/new/deeper', [$this->root], false);

        self::assertSame($expected, $resolved);
    }

    /** @test */
    public function resolveStillWorksWhenNothingOnThePathExists(): void
    {
        $resolved = Path::resolve($this->root, 'nowhere/at/all', [$this->root], false);

        self::assertSame($this->root . '/nowhere/at/all', $resolved);
    }

    /**
     * T-14, the case a purely lexical fallback misses: a link inside the root
     * pointing outside it, with a not-yet-created tail.
     *
     * @test
     */
    public function resolveRefusesAMissingTailBehindAnEscapingSymlink(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows needs elevation; covered on Linux CI.');
        }

        $project = $this->root . '/proj';
        $outside = $this->root . '/outside';

        \mkdir($project);
        \mkdir($outside);

        if (!\symlink($outside, $project . '/link')) {
            self::markTestSkipped('This filesystem does not support symlinks.');
        }

        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path resolves outside the allowed roots');

        Path::resolve($project, 'link/cache/tw', [$project], false);
    }

    /** @test */
    public function isSymlinkEscapeSeesALinkAboveAMissingTail(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows needs elevation; covered on Linux CI.');
        }

        $project = $this->root . '/proj';
        $outside = $this->root . '/outside';

        \mkdir($project);
        \mkdir($outside);

        if (!\symlink($outside, $project . '/link')) {
            self::markTestSkipped('This filesystem does not support symlinks.');
        }

        self::assertTrue(Path::isSymlinkEscape($project . '/link/cache/tw', [$project]));
    }

    /**
     * The mirror case: when the project root itself is reached through a link,
     * a resolvable path and a not-yet-created one must agree.
     *
     * @test
     */
    public function aSymlinkedProjectRootResolvesConsistently(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows needs elevation; covered on Linux CI.');
        }

        $current = $this->root . '/current';

        if (!\symlink($this->root, $current)) {
            self::markTestSkipped('This filesystem does not support symlinks.');
        }

        // Existing target resolves.
        $existing = Path::resolve($current, 'assets', [$current], true);

        // Missing target must resolve too, not throw.
        $missing = Path::resolve($current, 'cache/tw', [$current], false);

        self::assertStringEndsWith('/assets', $existing);
        self::assertStringEndsWith('/cache/tw', $missing);
    }

    /**
     * Windows `realpath()` resolves ONE reparse point per call, so a chain of
     * junctions used to look confined while a write followed it all the way
     * out. Junctions need no elevation, unlike `symlink()`, so this runs for
     * real on Windows rather than skipping.
     *
     * @test
     */
    public function aTwoHopJunctionChainCannotEscape(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept; POSIX is covered by the symlink rows.');
        }

        $project = $this->root . '/proj';
        $outside = $this->root . '/outside';

        \mkdir($project);
        \mkdir($outside);

        if (
            !self::makeJunction($project . '/link', $outside)
            || !self::makeJunction($project . '/link2', $project . '/link')
        ) {
            self::markTestSkipped('mklink /J is unavailable on this host.');
        }

        \file_put_contents($outside . '/probe.txt', 'x');

        self::assertTrue(
            Path::isSymlinkEscape($project . '/link2/cache/tw', [$project]),
            'A two-hop junction chain must be reported as an escape.'
        );

        $threwForMissingTail = false;

        try {
            Path::resolve($project, 'link2/cache/tw', [$project], false);
        } catch (PathException $e) {
            $threwForMissingTail = true;
        }

        self::assertTrue($threwForMissingTail, 'A missing tail behind a junction chain must be refused.');

        $threwForExistingFile = false;

        try {
            Path::resolve($project, 'link2/probe.txt', [$project], true);
        } catch (PathException $e) {
            $threwForExistingFile = true;
        }

        self::assertTrue($threwForExistingFile, 'An existing file behind a junction chain must be refused.');
    }

    /**
     * A chain longer than the old bound used to be indistinguishable from
     * "does not exist": the caller fell back to the ancestor walk, re-appended
     * the tail lexically, and a write followed the chain out of the project.
     * Non-convergence must now fail closed.
     *
     * @test
     */
    public function aLongJunctionChainEndingOutsideCannotEscape(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept.');
        }

        $project = $this->root . '/proj';
        $outside = $this->root . '/outside';

        \mkdir($project);
        \mkdir($outside);

        $last = $outside;

        for ($hop = 0; $hop < 40; ++$hop) {
            $link = $project . '/o' . $hop;

            if (!$this->makeJunction($link, $last)) {
                self::markTestSkipped('mklink /J is unavailable on this host.');
            }

            $last = $link;
        }

        self::assertTrue(
            Path::isSymlinkEscape($last . '/cache/tw', [$project]),
            'A 40-hop chain ending outside must be reported as an escape.'
        );

        $this->expectException(PathException::class);

        Path::resolve($project, 'o39/cache/tw', [$project], false);
    }

    /**
     * The same depth staying inside the project must still resolve — the bound
     * is above what the OS itself will follow, so depth alone is never the
     * reason to refuse.
     *
     * @test
     */
    public function aLongJunctionChainStayingInsideStillResolves(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept.');
        }

        $project = $this->root . '/proj';

        \mkdir($project);
        \mkdir($project . '/real');

        $last = $project . '/real';

        for ($hop = 0; $hop < 40; ++$hop) {
            $link = $project . '/i' . $hop;

            if (!$this->makeJunction($link, $last)) {
                self::markTestSkipped('mklink /J is unavailable on this host.');
            }

            $last = $link;
        }

        self::assertFalse(Path::isSymlinkEscape($last, [$project]));

        $resolved = Path::resolve($project, 'i39/cache/tw', [$project], false);

        self::assertStringEndsWith('/real/cache/tw', $resolved);
    }

    /**
     * `mklink /J` happily creates a cycle. It never settles, so it must be
     * refused rather than silently treated as missing.
     *
     * @test
     */
    public function aJunctionLoopIsRefused(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept.');
        }

        $a = $this->root . '/loopA';
        $b = $this->root . '/loopB';

        // B must exist for the first junction; the second closes the cycle.
        \mkdir($b);

        if (!$this->makeJunction($a, $b)) {
            self::markTestSkipped('mklink /J is unavailable on this host.');
        }

        \rmdir($b);

        if (!$this->makeJunction($b, $a)) {
            self::markTestSkipped('A junction cycle could not be created on this host.');
        }

        self::assertTrue(Path::isSymlinkEscape($a . '/x', [$this->root]));

        $this->expectException(PathException::class);

        Path::resolve($this->root, 'loopA/x', [$this->root], false);
    }

    /**
     * Beyond the operating system's own reparse limit (63 on Windows)
     * `realpath()` fails outright, which reads as "does not exist" — so the
     * ancestor walk runs and `resolve()` returns a confined, lexical path.
     *
     * That is safe rather than an escape: any write through the same chain hits
     * the identical OS limit and fails, so the path can be produced but never
     * followed. Pinned here so the behaviour is visible and a future change to
     * the bound is caught.
     *
     * @test
     */
    public function aChainBeyondTheOsLimitIsUnusableRatherThanAnEscape(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept.');
        }

        $project = $this->root . '/proj';
        $outside = $this->root . '/outside';

        \mkdir($project);
        \mkdir($outside);

        $last = $outside;

        for ($hop = 0; $hop < 70; ++$hop) {
            $link = $project . '/x' . $hop;

            if (!$this->makeJunction($link, $last)) {
                // Past the OS limit mklink itself starts refusing; that is the
                // same wall, reached earlier.
                break;
            }

            $last = $link;
        }

        // Whatever the chain length reached, the OS refuses to traverse it, so
        // the target is unreachable in practice.
        self::assertFalse(
            \is_dir($project . '/x69'),
            'A chain past the OS limit must not be traversable.'
        );
    }

    /**
     * A junction that stays inside the roots is legitimate and must still work.
     *
     * @test
     */
    public function aJunctionInsideTheRootsIsNotAnEscape(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows concept.');
        }

        if (!self::makeJunction($this->root . '/inner', $this->root . '/assets')) {
            self::markTestSkipped('mklink /J is unavailable on this host.');
        }

        self::assertFalse(Path::isSymlinkEscape($this->root . '/inner/styles', [$this->root]));
    }

    /**
     * Item 8: a root that does not exist yet, reached through a link, must be
     * normalised the same way the path side is.
     *
     * @test
     */
    public function aNotYetExistingRootBehindALinkIsResolvedThroughIt(): void
    {
        $current = $this->root . '/current';
        $made = \DIRECTORY_SEPARATOR === '\\'
            ? self::makeJunction($current, $this->root)
            : \symlink($this->root, $current);

        if (!$made) {
            self::markTestSkipped('This host cannot create a link for the test.');
        }

        // Root "current/newroot" does not exist; the path under it does not
        // either. Both sides must resolve through `current` and agree.
        $resolved = Path::resolve($current . '/newroot', 'cache/tw', [$current . '/newroot'], false);

        self::assertStringEndsWith('/newroot/cache/tw', $resolved);
    }

    /**
     * Create a Windows directory junction, remembering it so `tearDown()` can
     * unlink it before the tree walk.
     *
     * Junctions must be removed explicitly and first: they report
     * `is_link() === false` and `is_dir() === true`, so a naive recursive delete
     * would follow one and empty the *target* instead.
     *
     * Returns false when junctions are unavailable on this host.
     */
    private function makeJunction(string $link, string $target): bool
    {
        $command = \sprintf(
            'mklink /J "%s" "%s"',
            \str_replace('/', '\\', $link),
            \str_replace('/', '\\', $target)
        );

        $output = [];
        $status = 0;

        // Test-only helper; the library itself never spawns a process.
        \exec('cmd /c ' . $command . ' 2>&1', $output, $status);

        if ($status !== 0) {
            return false;
        }

        // Recorded as soon as mklink reports success, before the usability
        // check: a junction to a dangling target still occupies a directory
        // entry and must be cleaned up even though is_dir() denies it exists.
        $this->junctions[] = $link;

        return \is_dir($link);
    }

    // ------------------------------------ POSIX '//' confinement (finding 2)

    /**
     * On POSIX a leading `//` is just a root. Treating it as a UNC share froze
     * a traversal sequence into the prefix, where `..` collapsing could no
     * longer remove it, and `//cache/../etc/passwd` sailed through confinement.
     *
     * @test
     *
     * @dataProvider posixDoubleSlashCases
     */
    public function aLeadingDoubleSlashIsJustARootOnPosix(string $input, string $expected): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('UNC prefixes are parsed on Windows; this row asserts POSIX behaviour.');
        }

        self::assertSame($expected, Path::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function posixDoubleSlashCases(): array
    {
        return [
            'traversal out of a fake share' => ['//cache/../etc/passwd', '/etc/passwd'],
            'plain double slash' => ['//cache/x', '/cache/x'],
            'dot dot as the server' => ['//../x', '/x'],
            'dot as the server' => ['//./../x', '/x'],
            'server then traversal' => ['//server/../x', '/x'],
            'triple slash' => ['///a/b', '/a/b'],
        ];
    }

    /** @test */
    public function confineRejectsTraversalThroughAFakeShareOnPosix(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('UNC prefixes are parsed on Windows; this row asserts POSIX behaviour.');
        }

        $this->expectException(PathException::class);

        Path::confine('//cache/../etc/passwd', '//cache');
    }

    /** @test */
    public function resolveRejectsTraversalFromADoubleSlashRootOnPosix(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('UNC prefixes are parsed on Windows; this row asserts POSIX behaviour.');
        }

        $this->expectException(PathException::class);

        Path::resolve('//app', '../etc/passwd', ['//app'], false);
    }

    // ------------------------------- confine/relative validation (finding 3)

    /**
     * @test
     *
     * @dataProvider unusableRoots
     */
    public function confineRejectsAnUnusableRoot(string $root): void
    {
        $this->expectException(PathException::class);

        Path::confine('/app/a.css', $root);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableRoots(): array
    {
        return [
            'empty is not allow-all' => [''],
            'relative' => ['relative/root'],
            'wrapper' => ['phar://evil.phar'],
            'nul byte' => ["/app\0"],
        ];
    }

    /**
     * @test
     *
     * @dataProvider unusablePaths
     */
    public function confineRejectsAnUnusablePath(string $abs): void
    {
        $this->expectException(PathException::class);

        Path::confine($abs, '/app');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusablePaths(): array
    {
        return [
            'empty' => [''],
            'relative' => ['relative/x'],
            'wrapper' => ['phar://evil.phar/x'],
            'nul byte' => ["/app/x\0"],
        ];
    }

    /** @test */
    public function relativeRejectsAnEmptyRoot(): void
    {
        $this->expectException(PathException::class);

        Path::relative('', '/etc/passwd');
    }

    /** @test */
    public function isSymlinkEscapeTreatsUnusableInputAsAnEscape(): void
    {
        self::assertTrue(Path::isSymlinkEscape('', [$this->root]));
        self::assertTrue(Path::isSymlinkEscape('relative/x', [$this->root]));
        self::assertTrue(Path::isSymlinkEscape('phar://evil.phar/x', [$this->root]));
    }

    // ------------------------------------------- relative roots (finding 5)

    /**
     * A relative root used to be realpath()ed against the process working
     * directory, which made confinement depend on getcwd().
     *
     * @test
     */
    public function aRelativeAllowedRootIsDroppedRatherThanResolvedAgainstTheCwd(): void
    {
        $previous = \getcwd();

        self::assertNotFalse($previous);

        \chdir($this->root);

        try {
            $this->expectException(PathException::class);

            Path::resolve($this->root, 'assets/styles/main.css', ['assets'], true);
        } finally {
            \chdir($previous);
        }
    }

    // ---------------------------------------------- data: wrapper (finding 6)

    /**
     * @test
     *
     * @dataProvider dataWrapperCases
     */
    public function theSchemelessDataWrapperIsRecognised(string $path): void
    {
        self::assertTrue(Path::isWrapper($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dataWrapperCases(): array
    {
        return [
            'lowercase' => ['data:text/plain;base64,AAAA'],
            'uppercase' => ['DATA:text/plain,AAAA'],
            'mixed case' => ['DaTa:text/plain,AAAA'],
        ];
    }

    // ------------------------------------------ non-ASCII drive (finding 8)

    /**
     * `ctype_alpha()` is locale-dependent: under some locales a high byte counts
     * as alphabetic, which would let "\xC9:/x" be read as a drive prefix.
     *
     * @test
     */
    public function aNonAsciiByteIsNotADriveLetter(): void
    {
        self::assertSame("\xC9:/x", Path::normalize("\xC9:/x"));

        $this->expectException(PathException::class);

        Path::confine("\xC9:/x", '/app');
    }

    // --------------------------------------------------------------- confine

    /** @test */
    public function confineReturnsTheNormalisedPath(): void
    {
        self::assertSame('/app/a/b', Path::confine('/app/./x/../a/b', '/app'));
    }

    /** @test */
    public function confineAcceptsTheRootItself(): void
    {
        self::assertSame('/app', Path::confine('/app', '/app'));
    }

    /** @test */
    public function confineRejectsASiblingWithASharedPrefix(): void
    {
        $this->expectException(PathException::class);
        $this->expectExceptionMessage('Path is outside the permitted directory');

        Path::confine('/app2/secret.css', '/app');
    }

    /**
     * T-02: a crafted route must not escape the cache directory.
     *
     * @test
     */
    public function confineRejectsTraversalOutOfTheCacheDirectory(): void
    {
        $this->expectException(PathException::class);

        Path::confine('/var/cache/../../etc/passwd', '/var/cache');
    }

    // -------------------------------------------------------------- relative

    /**
     * @test
     *
     * @dataProvider relativeCases
     */
    public function relativeStripsTheRoot(string $root, string $abs, string $expected): void
    {
        self::assertSame($expected, Path::relative($root, $abs));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function relativeCases(): array
    {
        return [
            'nested file' => ['/app', '/app/assets/main.css', 'assets/main.css'],
            'root itself' => ['/app', '/app', ''],
            'trailing separator on root' => ['/app/', '/app/a.css', 'a.css'],
            'backslashes are normalised' => ['/app', '/app\\a\\b.css', 'a/b.css'],
            'drive root' => ['C:/app', 'C:/app/a.css', 'a.css'],
        ];
    }

    /** @test */
    public function relativeRejectsAPathOutsideTheRoot(): void
    {
        $this->expectException(PathException::class);

        Path::relative('/app', '/app2/a.css');
    }

    // ------------------------------------------------------- isSymlinkEscape

    /** @test */
    public function aPlainFileInsideTheRootsIsNotAnEscape(): void
    {
        self::assertFalse(
            Path::isSymlinkEscape($this->root . '/assets/styles/main.css', [$this->root])
        );
    }

    /** @test */
    public function anUnresolvablePathIsNotReportedAsAnEscape(): void
    {
        self::assertFalse(Path::isSymlinkEscape($this->root . '/nope.css', [$this->root]));
    }

    /** @test */
    public function aNulBytePathIsAlwaysAnEscape(): void
    {
        self::assertTrue(Path::isSymlinkEscape("a\0b", [$this->root]));
    }

    /** @test */
    public function anExistingPathOutsideTheRootsIsAnEscape(): void
    {
        \mkdir($this->root . '/inside');

        self::assertTrue(
            Path::isSymlinkEscape($this->root . '/assets', [$this->root . '/inside'])
        );
    }

    /**
     * T-14 proper: a link inside the roots whose target is outside them.
     *
     * @test
     */
    public function aSymlinkPointingOutsideTheRootsIsAnEscape(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows needs elevation; covered on Linux CI.');
        }

        $inside = $this->root . '/inside';
        $outside = $this->root . '/outside';

        \mkdir($inside);
        \mkdir($outside);
        \file_put_contents($outside . '/secret.css', 'x');

        $link = $inside . '/link.css';

        if (!\symlink($outside . '/secret.css', $link)) {
            self::markTestSkipped('This filesystem does not support symlinks.');
        }

        self::assertTrue(Path::isSymlinkEscape($link, [$inside]));
        self::assertFalse(Path::isSymlinkEscape($link, [$this->root]));
    }

    /**
     * A symlink that stays inside the roots is legitimate and must not be
     * flagged, otherwise a deploy that symlinks `current -> releases/x` breaks.
     *
     * @test
     */
    public function aSymlinkStayingInsideTheRootsIsNotAnEscape(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows needs elevation; covered on Linux CI.');
        }

        $link = $this->root . '/current.css';

        if (!\symlink($this->root . '/assets/styles/main.css', $link)) {
            self::markTestSkipped('This filesystem does not support symlinks.');
        }

        self::assertFalse(Path::isSymlinkEscape($link, [$this->root]));
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

            if (\is_link($full) || \is_file($full)) {
                \unlink($full);

                continue;
            }

            if (\is_dir($full)) {
                // A Windows junction reports is_link() === false and
                // is_dir() === true, so recursing here would follow the link and
                // delete the *target's* contents. Remove the reparse point
                // itself instead; rmdir() unlinks it without touching the target.
                if (self::isReparsePoint($full)) {
                    \rmdir($full);

                    continue;
                }

                self::removeTree($full);

                continue;
            }

            // Matches none of the stat predicates: a junction whose target has
            // gone. It still occupies an entry, so the directory cannot be
            // removed until it is unlinked.
            \rmdir($full);
        }

        \rmdir($dir);
    }

    /**
     * Whether a directory entry of this name exists, including a dangling
     * junction that `is_dir()`/`file_exists()` both report as absent.
     */
    private static function entryExists(string $path): bool
    {
        $parent = \dirname($path);

        if (!\is_dir($parent)) {
            return false;
        }

        $entries = \scandir($parent);

        return $entries !== false && \in_array(\basename($path), $entries, true);
    }

    /**
     * True when a directory entry redirects elsewhere — a junction or a symlink
     * to a directory. Detected by comparing the resolved path with the lexical
     * one rather than by `readlink()`, which warns on ordinary directories.
     */
    private static function isReparsePoint(string $dir): bool
    {
        $real = \realpath($dir);

        if ($real === false) {
            return true;
        }

        $normalized = \str_replace('\\', '/', $real);

        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp($normalized, $dir) !== 0
            : $normalized !== $dir;
    }
}
