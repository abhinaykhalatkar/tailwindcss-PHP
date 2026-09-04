<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Freshness\FileSnapshot;

/**
 * Layer 1 of the freshness pipeline: turns a list of {@see Root}s into a
 * {@see FileSnapshot} (design doc 05 §Layer 1, design doc 13 §Snapshot and
 * scan #9-11).
 *
 * Never follows a symlink (files skipped via `isLink()`; symlinked
 * directories are never descended because `FOLLOW_SYMLINKS` is not part of
 * the iterator's flags at all — design doc 12 T-14). A missing root is
 * recorded rather than thrown (the pipeline decides fail-soft-with-cache vs.
 * `ConfigurationException`, design doc 04 §4.6) so one absent directory never
 * stops the rest of the scan.
 */
final class FileFinder
{
    /**
     * @var string
     */
    private $cacheDirAbs;

    /**
     * @var list<string>
     */
    private $ignore;

    /**
     * @var int
     */
    private $maxFiles;

    /**
     * @param string $cacheDirAbs Absolute cache directory (never descended).
     * @param list<string> $ignore design doc 11 `ignore` entries.
     * @param int $maxFiles design doc 11 `limits.max_files`.
     */
    public function __construct(string $cacheDirAbs, array $ignore, int $maxFiles)
    {
        $this->cacheDirAbs = \rtrim(\str_replace('\\', '/', $cacheDirAbs), '/');
        $this->ignore = $ignore;
        $this->maxFiles = $maxFiles;
    }

    /**
     * @param list<Root> $roots
     *
     * @throws ScanLimitException When the accumulated file count across every
     *                            root reaches `limits.max_files`.
     */
    public function snapshot(array $roots): FileSnapshot
    {
        $files = [];
        $missingRoots = [];

        foreach ($roots as $root) {
            if (!\is_dir($root->abs())) {
                $missingRoots[] = $root->rel();

                continue;
            }

            foreach ($this->scanRoot($root, \count($files)) as $rel => $record) {
                $files[$rel] = $record;
            }
        }

        return new FileSnapshot($files, $missingRoots);
    }

    /**
     * @return array<string, array{k: string, m: int, s: int}>
     */
    private function scanRoot(Root $root, int $alreadyFound): array
    {
        $rootAbs = \rtrim(\str_replace('\\', '/', $root->abs()), '/');
        $filter = new IgnoreFilter($rootAbs, $this->cacheDirAbs, $this->ignore);

        $directory = new \RecursiveDirectoryIterator(
            $root->abs(),
            \FilesystemIterator::SKIP_DOTS
                | \FilesystemIterator::UNIX_PATHS
                | \FilesystemIterator::CURRENT_AS_FILEINFO
        );
        $filtered = new \RecursiveCallbackFilterIterator($directory, [$filter, 'accept']);
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);

        $prefix = $rootAbs . '/';

        /** @var array<string, array{k: string, m: int, s: int}> $files */
        $files = [];

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isLink()) {
                continue;
            }

            $basename = $fileInfo->getFilename();

            // Extension check before any stat call (design doc 13 #10):
            // getFilename() is free, getMTime()/getSize() are not.
            if (!self::matchesExtension($basename, $root)) {
                continue;
            }

            // Review round 1 item 4: a directory junction reports
            // isLink() === false, so a junction named e.g. "styles.twig"
            // would otherwise pass the extension check and be recorded as a
            // file. One stat here, paid only for names that already matched.
            if (!$fileInfo->isFile()) {
                continue;
            }

            if ($alreadyFound + \count($files) >= $this->maxFiles) {
                throw new ScanLimitException('max_files', $this->maxFiles);
            }

            $abs = \str_replace('\\', '/', $fileInfo->getPathname());
            $relToRoot = \strncmp($abs, $prefix, \strlen($prefix)) === 0
                ? \substr($abs, \strlen($prefix))
                : \ltrim(\substr($abs, \strlen($rootAbs)), '/');

            $rel = $root->rel() === '' ? $relToRoot : $root->rel() . '/' . $relToRoot;

            $files[$rel] = [
                'k' => $root->kind(),
                'm' => (int) $fileInfo->getMTime(),
                's' => (int) $fileInfo->getSize(),
            ];
        }

        return $files;
    }

    /**
     * Case-insensitive match on the last dotted segment of $basename,
     * trying the two-segment form first (`blade.php`) so it can be
     * distinguished from plain `.php` (design doc 09 §File discovery,
     * design doc 13 #10). Computed with `strpos`/`strrpos`, never
     * `pathinfo()`, which is locale-sensitive.
     */
    private static function matchesExtension(string $basename, Root $root): bool
    {
        $firstDot = \strpos($basename, '.');

        if ($firstDot === false) {
            return false;
        }

        $twoSegment = \strtolower(\substr($basename, $firstDot + 1));

        if ($root->hasExtension($twoSegment)) {
            return true;
        }

        $lastDot = \strrpos($basename, '.');
        $lastSegment = \strtolower(\substr($basename, $lastDot + 1));

        return $root->hasExtension($lastSegment);
    }
}
