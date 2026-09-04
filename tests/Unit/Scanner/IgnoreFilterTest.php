<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\IgnoreFilter;
use TailwindPhp\Tests\Support\TempTree;

final class IgnoreFilterTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-ignorefilter');

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function filesAreAlwaysAccepted(): void
    {
        $file = $this->touch('anything.css');
        $filter = new IgnoreFilter($this->root, $this->root . '/cache', ['anything.css']);

        self::assertTrue($filter->accept($this->info($file), null, null));
    }

    /**
     * @test
     *
     * @dataProvider alwaysIgnoredDirectoryCases
     */
    public function alwaysIgnoredDirectoriesAreRejected(string $name): void
    {
        $dir = $this->mkdir($name);
        $filter = new IgnoreFilter($this->root, $this->root . '/cache', []);

        self::assertFalse($filter->accept($this->info($dir), null, null));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function alwaysIgnoredDirectoryCases(): array
    {
        return [
            '.git' => ['.git'],
            'node_modules' => ['node_modules'],
        ];
    }

    /** @test */
    public function vendorDirectoryIsRejectedWhenTheRootIsOutsideIt(): void
    {
        $dir = $this->mkdir('vendor');
        $filter = new IgnoreFilter($this->root, $this->root . '/cache', []);

        self::assertFalse($filter->accept($this->info($dir), null, null));
    }

    /**
     * A `sources: ['vendor/acme/theme']` root must still be walkable: the
     * exception is keyed on whether the *scan root* sits inside the vendor
     * directory being evaluated, not on the name alone (CSF-A6).
     *
     * @test
     */
    public function vendorDirectoryIsAcceptedWhenTheRootIsInsideIt(): void
    {
        $vendorDir = $this->mkdir('vendor');
        $themeDir = $this->mkdir('vendor/acme/theme');

        $filter = new IgnoreFilter($themeDir, $this->root . '/cache', []);

        self::assertTrue($filter->accept($this->info($vendorDir), null, null));
    }

    /** @test */
    public function theCacheDirectoryItselfIsRejected(): void
    {
        $cacheDir = $this->mkdir('cache/tailwind');
        $filter = new IgnoreFilter($this->root, $cacheDir, []);

        self::assertFalse($filter->accept($this->info($cacheDir), null, null));
    }

    /** @test */
    public function aCacheDirectorySomewhereElseIsNotRejectedByName(): void
    {
        // Only the *configured* cache directory is special-cased; a directory
        // that merely happens to be named "cache" needs an explicit `ignore`
        // entry (design decision CSF-A6: the default `ignore` list already
        // includes "cache" for exactly that reason, but this filter's job is
        // just to compare against the one path it was given).
        $otherCacheDir = $this->mkdir('cache');
        $filter = new IgnoreFilter($this->root, $this->root . '/elsewhere/tailwind', []);

        self::assertTrue($filter->accept($this->info($otherCacheDir), null, null));
    }

    /**
     * @test
     *
     * @dataProvider configuredIgnoreCases
     *
     * @param list<string> $ignore
     */
    public function configuredIgnoreEntriesAreHonoured(string $dirPath, array $ignore, bool $expectedAccepted): void
    {
        $dir = $this->mkdir($dirPath);
        $filter = new IgnoreFilter($this->root, $this->root . '/cache', $ignore);

        self::assertSame($expectedAccepted, $filter->accept($this->info($dir), null, null));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: bool}>
     */
    public static function configuredIgnoreCases(): array
    {
        return [
            'exact basename match is rejected' => ['dist', ['dist'], false],
            'non-matching basename is accepted' => ['build', ['dist'], true],
            'glob basename match is rejected' => ['dist-v2', ['dist-*'], false],
            'glob basename mismatch is accepted' => ['source', ['dist-*'], true],
            'slash entry matches the root-relative path' => ['assets/dist', ['assets/dist'], false],
            'slash entry does not match a different path' => ['assets/build', ['assets/dist'], true],
            'slash entry with glob matches nested path' => ['assets/dist', ['assets/dist*'], false],
        ];
    }

    /** @test */
    public function slashEntryDoesNotMatchAgainstBareBasename(): void
    {
        // "assets/dist" must not accidentally match a same-named directory
        // that lives somewhere else in the tree.
        $dir = $this->mkdir('other/dist');
        $filter = new IgnoreFilter($this->root, $this->root . '/cache', ['assets/dist']);

        self::assertTrue($filter->accept($this->info($dir), null, null));
    }

    private function mkdir(string $relative): string
    {
        $path = $this->root . '/' . $relative;

        \mkdir($path, 0777, true);

        return $path;
    }

    private function touch(string $relative): string
    {
        $path = $this->root . '/' . $relative;

        \file_put_contents($path, '');

        return $path;
    }

    private function info(string $path): \SplFileInfo
    {
        return new \SplFileInfo($path);
    }
}
