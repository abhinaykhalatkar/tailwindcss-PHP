<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Threat T14 (design doc 12): the scanner must never follow a symlink out of
 * the tree it was told to walk.
 *
 * POSIX: a real symlink is skipped via `SplFileInfo::isLink()` (design doc
 * 09 §File discovery). Windows has no unprivileged *file* symlink, only
 * directory junctions; see {@see self::aJunctionOnWindowsIsNotDescended()}
 * for what this host actually does with one (not what CSF-A17 assumed).
 */
final class T14SymlinkTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $outside = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t14-root');
        $this->outside = TempTree::make('tailwindphp-t14-outside');

        TempTree::registerShutdownCleanup($this->root);
        TempTree::registerShutdownCleanup($this->outside);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
        TempTree::remove($this->outside);

        parent::tearDown();
    }

    /** @test */
    public function aSymlinkedFileInsideTheRootIsNotFollowed(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Unprivileged file symlinks are not available on Windows.');
        }

        \mkdir($this->root . '/templates', 0777, true);
        \file_put_contents($this->outside . '/secret.twig', 'flex');

        self::assertTrue(\symlink($this->outside . '/secret.twig', $this->root . '/templates/linked.twig'));

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame([], \array_keys($snapshot->files()));
    }

    /** @test */
    public function aSymlinkedDirectoryInsideTheRootIsNotDescended(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped(
                'Unprivileged directory symlinks are not available on Windows (see the junction test below).'
            );
        }

        \mkdir($this->root . '/templates', 0777, true);
        \mkdir($this->outside . '/secret-dir', 0777, true);
        \file_put_contents($this->outside . '/secret-dir/secret.twig', 'flex');

        self::assertTrue(\symlink($this->outside . '/secret-dir', $this->root . '/templates/linked-dir'));

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame([], \array_keys($snapshot->files()));
    }

    /**
     * design decision CSF-A17 (as written in `PLAN-m1-pipeline.md`) assumes a
     * directory junction *is* descended on Windows, because `is_link()`
     * cannot distinguish one from an ordinary directory — an accepted,
     * best-effort gap. Empirically, on this host (PHP 8.2.12 ZTS / Windows),
     * that assumption does not hold: `RecursiveDirectoryIterator` built with
     * `CURRENT_AS_FILEINFO` reports `hasChildren() === false` for a junction
     * entry (confirmed directly against the iterator, not just against a
     * detached `SplFileInfo` copy of it), so it is walked as an opaque leaf
     * with no extension and never descended — the same practical outcome as
     * a real symlink, just for a different reason. This test asserts the
     * verified behaviour; see the task report for the doc correction this
     * implies for CSF-A17 / design doc 09.
     *
     * @test
     */
    public function aJunctionOnWindowsIsNotDescended(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows-only construct.');
        }

        \mkdir($this->root . '/templates', 0777, true);
        \mkdir($this->outside . '/secret-dir', 0777, true);
        \file_put_contents($this->outside . '/secret-dir/secret.twig', 'flex');

        if (!self::makeJunction($this->root . '/templates/linked-dir', $this->outside . '/secret-dir')) {
            self::markTestSkipped('mklink /J is unavailable on this host.');
        }

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame([], \array_keys($snapshot->files()));
    }

    /**
     * Review round 1 item 4: a junction *named* with a matching extension
     * (`junc.twig`) passes the extension check on name alone; without an
     * `isFile()` check afterwards it would be recorded as a source file
     * (and then fail to read, which is exactly the item 3 scenario this
     * closes off at the source instead).
     *
     * @test
     */
    public function aJunctionNamedWithAMatchingExtensionIsNotRecorded(): void
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Junctions are a Windows-only construct.');
        }

        \mkdir($this->root . '/templates', 0777, true);
        \mkdir($this->outside . '/secret-dir', 0777, true);
        \file_put_contents($this->outside . '/secret-dir/secret.twig', 'flex');

        if (!self::makeJunction($this->root . '/templates/junc.twig', $this->outside . '/secret-dir')) {
            self::markTestSkipped('mklink /J is unavailable on this host.');
        }

        $finder = new FileFinder($this->root . '/cache', [], 100);
        $snapshot = $finder->snapshot([
            new Root($this->root . '/templates', 'templates', Root::SOURCE, ['twig']),
        ]);

        self::assertSame([], \array_keys($snapshot->files()));
    }

    /**
     * Returns false when junctions are unavailable on this host (test-only
     * helper; the same pattern as `Unit\Support\PathTest`).
     */
    private static function makeJunction(string $link, string $target): bool
    {
        $command = \sprintf(
            'mklink /J "%s" "%s"',
            \str_replace('/', '\\', $link),
            \str_replace('/', '\\', $target)
        );

        $output = [];
        $status = 0;

        \exec('cmd /c ' . $command . ' 2>&1', $output, $status);

        return $status === 0;
    }
}
